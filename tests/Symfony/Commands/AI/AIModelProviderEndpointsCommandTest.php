<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI;

use DDD\Symfony\Commands\AI\AIModelProviderEndpointsCommand;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\AIModelProviderEndpointsProbeCommand;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\FixedCatalogAIModelsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The provider table `app:ai-models:provider-endpoints` prints for a fixed endpoints answer; no network. */
class AIModelProviderEndpointsCommandTest extends TestCase
{
    /**
     * Three endpoints: Pricey (tools + tool_choice, 99.9 % uptime), Cheapo (tools only, 99.5 %), Flaky (tools +
     * tool_choice, 90 %, the cheapest).
     */
    protected function createEndpointsResponseBody(): string
    {
        return json_encode(['data' => ['endpoints' => [
            [
                'provider_name' => 'Pricey', 'supported_parameters' => ['tools', 'tool_choice'],
                'uptime_last_30m' => 100, 'uptime_last_1d' => 99.9, 'throughput_last_30m' => 88.25, 'latency_last_30m' => 0.456,
                'quantization' => 'fp8', 'context_length' => 262144, 'pricing' => ['prompt' => '0.0000005', 'completion' => '0.000002'],
            ],
            [
                'provider_name' => 'Cheapo', 'supported_parameters' => ['tools'],
                'uptime_last_30m' => 99, 'uptime_last_1d' => 99.5, 'throughput_last_30m' => null, 'latency_last_30m' => null,
                'quantization' => 'fp4', 'context_length' => 131072, 'pricing' => ['prompt' => '0.0000002', 'completion' => '0.0000006'],
            ],
            [
                'provider_name' => 'Flaky', 'supported_parameters' => ['tools', 'tool_choice'],
                'uptime_last_30m' => 80, 'uptime_last_1d' => 90.0, 'throughput_last_30m' => 20, 'latency_last_30m' => 3,
                'quantization' => 'bf16', 'context_length' => 65536, 'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000001'],
            ],
        ]]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: int, 1: string, 2: AIModelProviderEndpointsProbeCommand} Exit code, output and the command
     */
    protected function runProviderEndpoints(array $input, ?string $endpointsResponseBody): array
    {
        $probeCommand = new AIModelProviderEndpointsProbeCommand(FixedCatalogAIModelsService::createWithSmallCatalog(), $endpointsResponseBody);
        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute($input);
        return [$exitCode, $commandTester->getDisplay(), $probeCommand];
    }

    public function testTheCommandKeepsItsName(): void
    {
        $asCommandAttributes = (new ReflectionClass(AIModelProviderEndpointsCommand::class))->getAttributes(AsCommand::class);

        $this->assertCount(1, $asCommandAttributes);
        $this->assertSame('app:ai-models:provider-endpoints', $asCommandAttributes[0]->newInstance()->name);
    }

    public function testACatalogNameIsTranslatedAndTheProvidersAreListedCheapestFirst(): void
    {
        [$exitCode, $display, $probeCommand] = $this->runProviderEndpoints(['model' => FixedCatalogAIModelsService::CHEAP_ALPHA], $this->createEndpointsResponseBody());

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(
            ['https://openrouter.ai/api/v1/models/' . FixedCatalogAIModelsService::CHEAP_ALPHA_OPENROUTER_ID . '/endpoints'],
            $probeCommand->requestedUrls
        );
        $this->assertStringContainsString(FixedCatalogAIModelsService::CHEAP_ALPHA_OPENROUTER_ID . ' — 3 provider endpoint(s)', $display);
        $flakyPosition = strpos($display, 'Flaky');
        $cheapoPosition = strpos($display, 'Cheapo');
        $priceyPosition = strpos($display, 'Pricey');
        $this->assertTrue($flakyPosition < $cheapoPosition && $cheapoPosition < $priceyPosition, $display);
        $this->assertMatchesRegularExpression(
            '/\| Pricey\s+\| ✓\s+\| ✓\s+\| 100%\s+\| 99\.9%\s+\| 88\.3 t\/s\s+\| 0\.46 s\s+\| fp8\s+\| 262144\s+\| \$0\.5\s+\| \$2\s+\|/u',
            $display
        );
        $this->assertMatchesRegularExpression('/\| Cheapo\s+\| ✓\s+\| ✗\s+\| 99%\s+\| 99\.5%\s+\| —\s+\| —\s+\|/u', $display);
    }

    public function testToolsOnlyDropsProvidersWithoutToolChoice(): void
    {
        [, $display] = $this->runProviderEndpoints(['model' => 'test-author/raw-model', '--tools-only' => true], $this->createEndpointsResponseBody());

        $this->assertStringContainsString('test-author/raw-model — 2 provider endpoint(s) [tools-only]', $display);
        $this->assertStringNotContainsString('Cheapo', $display);
    }

    public function testMinUptimeDropsUnreliableProviders(): void
    {
        [, $display] = $this->runProviderEndpoints(['model' => 'test-author/raw-model', '--min-uptime' => '99'], $this->createEndpointsResponseBody());

        $this->assertStringContainsString('2 provider endpoint(s) [uptime≥99%]', $display);
        $this->assertStringNotContainsString('Flaky', $display);
    }

    public function testJsonPrintsTheRawAnswer(): void
    {
        $endpointsResponseBody = $this->createEndpointsResponseBody();
        [$exitCode, $display] = $this->runProviderEndpoints(['model' => 'test-author/raw-model', '--json' => true], $endpointsResponseBody);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame($endpointsResponseBody, trim($display));
    }

    public function testAnUnknownCatalogNameFailsWithoutARequest(): void
    {
        [$exitCode, $display, $probeCommand] = $this->runProviderEndpoints(['model' => 'TEST.NOT_IN_THE_CATALOG'], $this->createEndpointsResponseBody());

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("Could not resolve an OpenRouter model id for 'TEST.NOT_IN_THE_CATALOG'", $display);
        $this->assertSame([], $probeCommand->requestedUrls);
    }

    public function testACatalogModelWithoutOpenRouterIdFails(): void
    {
        [$exitCode, , $probeCommand] = $this->runProviderEndpoints(['model' => FixedCatalogAIModelsService::STANDARD_ONE], $this->createEndpointsResponseBody());

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $probeCommand->requestedUrls);
    }

    public function testAMalformedRawIdFailsWithoutARequest(): void
    {
        [$exitCode, , $probeCommand] = $this->runProviderEndpoints(['model' => 'author/../other/path'], $this->createEndpointsResponseBody());

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $probeCommand->requestedUrls);
    }

    public function testAFailedRequestFails(): void
    {
        [$exitCode, $display] = $this->runProviderEndpoints(['model' => 'test-author/raw-model'], null);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('OpenRouter endpoints request failed', $display);
    }

    public function testAnAnswerOfAnotherShapeFails(): void
    {
        [$exitCode, $display] = $this->runProviderEndpoints(['model' => 'test-author/raw-model'], '{"error":{"code":404}}');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unexpected response shape', $display);
    }
}
