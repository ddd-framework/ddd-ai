<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\AI;

use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the OpenRouter provider endpoints that serve a model, with the facts needed to keep a good one and drop the
 * slow, unreliable or tool-incapable ones. Reads OpenRouter's public per-model endpoints API
 * (`GET https://openrouter.ai/api/v1/models/{author}/{slug}/endpoints`, no authentication). Each endpoint lists its
 * `supported_parameters` (which providers accept `tools` and `tool_choice`), uptime windows, pricing, quantization
 * and context length. Throughput and latency are often empty on the public view (OpenRouter fills them only with
 * recent authenticated traffic); uptime, tool support, price and quantization are the dependable filters.
 *
 *   php bin/console app:ai-models:provider-endpoints ALIBABA.QWEN3_235B_INSTRUCT
 *   php bin/console app:ai-models:provider-endpoints qwen/qwen3-235b-a22b-2507 --tools-only --min-uptime=99
 *   php bin/console app:ai-models:provider-endpoints ALIBABA.QWEN3_235B_INSTRUCT --json
 *
 * Reads the model catalog (to translate a catalog name into its OpenRouter id) and makes one HTTP GET; no database.
 */
#[AsCommand(
    name: 'app:ai-models:provider-endpoints',
    description: 'List the OpenRouter providers serving a model (tool support, uptime, price, quantization) so slow, unreliable or tool-incapable ones can be dropped.',
)]
class AIModelProviderEndpointsCommand extends Command
{
    /** @var string The public endpoints API; `%s` is the OpenRouter model id `author/model` */
    public const string ENDPOINTS_API_URL_TEMPLATE = 'https://openrouter.ai/api/v1/models/%s/endpoints';

    /** @var string An OpenRouter model id: `author/model`, optionally with a `:variant` suffix */
    public const string OPENROUTER_MODEL_ID_PATTERN = '#^[A-Za-z0-9._~-]+/[A-Za-z0-9._~:-]+$#';

    /** @var int Seconds the endpoints request may take */
    public const int ENDPOINTS_REQUEST_TIMEOUT_SECONDS = 20;

    /** @var string[] The table header, one entry per cell of {@see buildProviderEndpointRow()} */
    public const array TABLE_HEADERS = [
        'provider', 'tools', 'tool_choice', 'uptime 30m', 'uptime 1d', 'throughput', 'latency', 'quant', 'ctx', 'prompt $/M', 'compl $/M',
    ];

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument('model', InputArgument::REQUIRED, 'A catalog model name (e.g. ALIBABA.QWEN3_235B_INSTRUCT) or the raw OpenRouter id (e.g. qwen/qwen3-235b-a22b-2507).')
            ->addOption('tools-only', null, InputOption::VALUE_NONE, 'Only show providers whose endpoint supports `tools` AND `tool_choice`.')
            ->addOption('min-uptime', null, InputOption::VALUE_OPTIONAL, 'Drop providers whose 1-day uptime is below this percentage (e.g. 99).', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the raw endpoints JSON instead of the table.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modelArgument = (string)$input->getArgument('model');
        $openRouterModelId = $this->resolveOpenRouterModelId($modelArgument);
        if ($openRouterModelId === null) {
            $output->writeln("<error>Could not resolve an OpenRouter model id for '{$modelArgument}'. Pass a catalog name that has an openRouterExternalId, or the raw id 'author/model'.</error>");
            return Command::FAILURE;
        }

        $endpointsUrl = sprintf(self::ENDPOINTS_API_URL_TEMPLATE, $openRouterModelId);
        $endpointsJson = $this->fetchProviderEndpointsJson($endpointsUrl);
        if ($endpointsJson === null) {
            $output->writeln("<error>OpenRouter endpoints request failed: {$endpointsUrl}</error>");
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln($endpointsJson);
            return Command::SUCCESS;
        }

        $decodedResponse = json_decode($endpointsJson, true);
        $endpoints = is_array($decodedResponse) ? ($decodedResponse['data']['endpoints'] ?? null) : null;
        if (!is_array($endpoints)) {
            $output->writeln("<error>Unexpected response shape from OpenRouter for {$openRouterModelId}.</error>");
            return Command::FAILURE;
        }

        $toolsOnly = (bool)$input->getOption('tools-only');
        $minUptime = (float)$input->getOption('min-uptime');
        $selectedEndpoints = $this->selectProviderEndpoints($endpoints, $toolsOnly, $minUptime);

        $output->writeln('');
        $output->writeln("  <info>{$openRouterModelId}</info> — " . count($selectedEndpoints) . ' provider endpoint(s)'
            . ($toolsOnly ? ' [tools-only]' : '') . ($minUptime > 0 ? " [uptime≥{$minUptime}%]" : ''));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(self::TABLE_HEADERS);
        foreach ($selectedEndpoints as $endpoint) {
            $table->addRow($this->buildProviderEndpointRow($endpoint));
        }
        $table->render();

        $output->writeln('');
        $this->writeProviderProbeHint($output, $modelArgument);
        $output->writeln('  throughput/latency are blank when OpenRouter has no recent authenticated traffic stats on the public view.');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * A catalog name → its `openRouterExternalId`; a raw `author/model` id passes through when it has that shape.
     *
     * @param string $modelArgument
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function resolveOpenRouterModelId(string $modelArgument): ?string
    {
        if (str_contains($modelArgument, '/')) {
            return preg_match(self::OPENROUTER_MODEL_ID_PATTERN, $modelArgument) === 1 ? $modelArgument : null;
        }

        $aIModelsService = $this->getAIModelsService();
        $aIModel = $aIModelsService->getAIModelByName($modelArgument);

        return $aIModel?->openRouterExternalId;
    }

    /**
     * The body of the endpoints request, null when the request failed.
     *
     * @param string $endpointsUrl
     * @return string|null
     */
    protected function fetchProviderEndpointsJson(string $endpointsUrl): ?string
    {
        $streamContext = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => self::ENDPOINTS_REQUEST_TIMEOUT_SECONDS,
            ],
        ]);
        $responseBody = @file_get_contents($endpointsUrl, false, $streamContext);

        return $responseBody === false ? null : $responseBody;
    }

    /**
     * The endpoints that pass the filters, cheapest prompt price first (the usual pick for a cheap model is the
     * cheapest provider that clears the filters).
     *
     * @param array<int|string, mixed> $endpoints The decoded `data.endpoints` list of the API response; an entry that is not an object is dropped
     * @param bool $toolsOnly Keep only endpoints that support `tools` and `tool_choice`
     * @param float $minUptime The lowest 1-day uptime in percent to keep
     * @return array<int, array<string, mixed>>
     */
    protected function selectProviderEndpoints(array $endpoints, bool $toolsOnly, float $minUptime): array
    {
        $selectedEndpoints = array_values(array_filter(
            $endpoints,
            fn (mixed $endpoint): bool => is_array($endpoint)
                && (!$toolsOnly || $this->supportsToolCalls($endpoint))
                && (float)($endpoint['uptime_last_1d'] ?? 0) >= $minUptime
        ));
        usort(
            $selectedEndpoints,
            fn (array $firstEndpoint, array $secondEndpoint): int => $this->getPromptPricePerMillionTokens($firstEndpoint)
                <=> $this->getPromptPricePerMillionTokens($secondEndpoint)
        );

        return $selectedEndpoints;
    }

    /**
     * Whether an endpoint accepts both `tools` and `tool_choice`.
     *
     * @param array<string, mixed> $endpoint
     * @return bool
     */
    protected function supportsToolCalls(array $endpoint): bool
    {
        $supportedParameters = (array)($endpoint['supported_parameters'] ?? []);
        return in_array('tools', $supportedParameters, true) && in_array('tool_choice', $supportedParameters, true);
    }

    /**
     * The endpoint's prompt price in USD per million tokens (the API states it per token).
     *
     * @param array<string, mixed> $endpoint
     * @return float
     */
    protected function getPromptPricePerMillionTokens(array $endpoint): float
    {
        return (float)($endpoint['pricing']['prompt'] ?? 0) * 1_000_000;
    }

    /**
     * One table row for an endpoint, in the order of {@see TABLE_HEADERS}.
     *
     * @param array<string, mixed> $endpoint
     * @return string[]
     */
    protected function buildProviderEndpointRow(array $endpoint): array
    {
        $supportedParameters = (array)($endpoint['supported_parameters'] ?? []);
        $throughput = $endpoint['throughput_last_30m'] ?? null;
        $latency = $endpoint['latency_last_30m'] ?? null;
        $completionPricePerMillionTokens = (float)($endpoint['pricing']['completion'] ?? 0) * 1_000_000;

        return [
            (string)($endpoint['provider_name'] ?? '?'),
            in_array('tools', $supportedParameters, true) ? '✓' : '✗',
            in_array('tool_choice', $supportedParameters, true) ? '✓' : '✗',
            round((float)($endpoint['uptime_last_30m'] ?? 0), 1) . '%',
            round((float)($endpoint['uptime_last_1d'] ?? 0), 1) . '%',
            $throughput !== null ? round((float)$throughput, 1) . ' t/s' : '—',
            $latency !== null ? round((float)$latency, 2) . ' s' : '—',
            (string)($endpoint['quantization'] ?? '?'),
            (string)($endpoint['context_length'] ?? 0),
            '$' . round($this->getPromptPricePerMillionTokens($endpoint), 3),
            '$' . round($completionPricePerMillionTokens, 3),
        ];
    }

    /**
     * Prints how to try one of the listed providers. The module has no command that runs a model on a chosen
     * provider, so the default prints nothing; an application with such a command prints its call here.
     *
     * @param OutputInterface $output
     * @param string $modelArgument The model as the caller passed it
     * @return void
     */
    protected function writeProviderProbeHint(OutputInterface $output, string $modelArgument): void
    {
    }

    /**
     * The model catalog service; an application's subclass of the same name is returned by the service lookup.
     *
     * @return AIModelsService
     */
    protected function getAIModelsService(): AIModelsService
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aIModelsService;
    }
}
