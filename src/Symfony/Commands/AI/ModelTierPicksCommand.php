<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\AI;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelManagerPolicy;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Services\DDDService;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Prints the model AIModelsService selects from the model catalog for each tier (optionally with a vendor
 * preference), for each model scope, and the Pareto frontier of the agent-eligible models. Run it before and after a
 * catalog or score change and compare the output: a single wrong score that would route every caller of a tier or
 * scope to another model shows up as a changed pick.
 *
 *   php bin/console app:ai-model:tier-picks [--vendor=GOOGLE]
 *
 * Reads the model catalog only: no database, no network call.
 */
#[AsCommand(
    name: 'app:ai-model:tier-picks',
    description: 'List the AIModelsService pick per tier and per scope (catalog mis-route guard) and the Pareto frontier.',
)]
class ModelTierPicksCommand extends Command
{
    /** @var string The reason printed for a scope whose model is a pinned default rather than a selection */
    public const string PINNED_SCOPE_DEFAULT_REASON = 'pinned default of the scope (no selection)';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption(
            'vendor',
            null,
            InputOption::VALUE_OPTIONAL,
            'Apply a soft vendor preference to the tier picks (an AIModel::VENDOR_* value, e.g. GOOGLE).'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws ReflectionException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $vendor = $input->getOption('vendor');
        $vendor = is_string($vendor) && $vendor !== '' ? $vendor : null;
        if ($vendor !== null && !in_array($vendor, AIModel::getModelVendors(), true)) {
            $output->writeln('<error>Unknown --vendor "' . $vendor . '". Valid: ' . implode('|', AIModel::getModelVendors()) . '</error>');
            return Command::FAILURE;
        }

        $aIModelsService = $this->getAIModelsService();
        $this->writeTierPicks($output, $aIModelsService, $vendor);
        $output->writeln('');
        $this->writeScopePicks($output, $aIModelsService);
        $output->writeln('');
        $this->writeParetoFrontier($output, $aIModelsService);

        return Command::SUCCESS;
    }

    /**
     * Prints one row per tier: the selected model, its agentic score, its blended cost and the decision's reason.
     *
     * @param OutputInterface $output
     * @param AIModelsService $aIModelsService
     * @param string|null $vendor The soft vendor preference, an AIModel::VENDOR_* value
     * @return void
     */
    protected function writeTierPicks(OutputInterface $output, AIModelsService $aIModelsService, ?string $vendor): void
    {
        $output->writeln('<info>AIModelsService tier picks</info>' . ($vendor !== null ? " (vendor preference: $vendor)" : ''));
        $output->writeln(sprintf('%-10s %-32s %6s %10s  %s', 'TIER', 'MODEL', 'SCORE', '$bl/1M', 'REASON'));
        $output->writeln(str_repeat('-', 100));
        foreach ($this->getTiers() as $tier) {
            try {
                $policy = new ModelManagerPolicy(preferredTier: $tier, preferredVendor: $vendor);
                $aIModel = $aIModelsService->selectModel(new ModelSelectionContext($policy));
                $decision = $aIModelsService->getLastDecision();
                $output->writeln(sprintf(
                    '%-10s %-32s %6s %10.2f  %s',
                    $tier,
                    $aIModel !== null ? $aIModel->name : '(null)',
                    $decision?->agenticScore !== null ? (string)$decision->agenticScore : '-',
                    $decision !== null ? $decision->blendedCostPer1MUsd : 0.0,
                    $decision !== null ? $decision->reason : ''
                ));
            } catch (Throwable $throwable) {
                $output->writeln(sprintf('%-10s <error>%s</error>', $tier, $throwable->getMessage()));
            }
        }
    }

    /**
     * Prints one row per scope: the model {@see AIModelsService::getModelForScope()} resolves. A scope with a pinned
     * default returns its model without a selection; its row names the pin instead of repeating the decision of the
     * previous selection.
     *
     * @param OutputInterface $output
     * @param AIModelsService $aIModelsService
     * @return void
     */
    protected function writeScopePicks(OutputInterface $output, AIModelsService $aIModelsService): void
    {
        $output->writeln('<info>Scope picks (the model getModelForScope resolves):</info>');
        $output->writeln(sprintf('%-20s %-32s %10s %10s  %s', 'SCOPE', 'MODEL', 'tok/s', '$bl/1M', 'REASON'));
        $output->writeln(str_repeat('-', 100));
        foreach ($this->getScopes() as $scope) {
            try {
                $decisionBeforeScope = $aIModelsService->getLastDecision();
                $aIModel = $aIModelsService->getModelForScope($scope);
                $decision = $aIModelsService->getLastDecision();
                // getModelForScope() records a decision only when it selects; a pinned default leaves the previous one
                $scopeDecision = $decision !== null && $decision !== $decisionBeforeScope && $decision->modelName === $aIModel?->name
                    ? $decision
                    : null;
                $tokensPerSecond = $aIModel?->getTokensPerSecond();
                $output->writeln(sprintf(
                    '%-20s %-32s %10s %10s  %s',
                    $scope,
                    $aIModel !== null ? $aIModel->name : '(null)',
                    $tokensPerSecond !== null ? (string)round($tokensPerSecond) : '-',
                    $scopeDecision !== null ? number_format($scopeDecision->blendedCostPer1MUsd, 2) : '-',
                    $scopeDecision !== null ? $scopeDecision->reason : ($aIModel !== null ? self::PINNED_SCOPE_DEFAULT_REASON : '')
                ));
            } catch (Throwable $throwable) {
                $output->writeln(sprintf('%-20s <error>%s</error>', $scope, $throwable->getMessage()));
            }
        }
    }

    /**
     * Prints the names of the rated, non-dominated agent-eligible models.
     *
     * @param OutputInterface $output
     * @param AIModelsService $aIModelsService
     * @return void
     */
    protected function writeParetoFrontier(OutputInterface $output, AIModelsService $aIModelsService): void
    {
        $output->writeln('<info>Pareto frontier (rated, non-dominated):</info>');
        try {
            foreach ($aIModelsService->getAgenticParetoFrontier()->getElements() as $aIModel) {
                $output->writeln('  ' . $aIModel->name);
            }
        } catch (Throwable $throwable) {
            $output->writeln('<error>' . $throwable->getMessage() . '</error>');
        }
    }

    /**
     * The tiers to print, weakest first.
     *
     * @return string[]
     */
    protected function getTiers(): array
    {
        return ModelTier::getAll();
    }

    /**
     * The scopes to print; an application with scopes of its own returns them here.
     *
     * @return string[]
     */
    protected function getScopes(): array
    {
        return ModelScope::ALL;
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
