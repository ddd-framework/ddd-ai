<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\AI\ModelTierPicksCommand;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\FixedCatalogAIModelsService;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\ModelTierPicksProbeCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** The tier, scope and Pareto lines `app:ai-model:tier-picks` prints for a small catalog. */
class ModelTierPicksCommandTest extends TestCase
{
    protected function setUp(): void
    {
        AIModelsService::clearSelectionCache();
    }

    protected function tearDown(): void
    {
        AIModelsService::clearSelectionCache();
    }

    /**
     * @param array<string, string> $options
     * @return array{0: int, 1: string[]} The exit code and the output lines
     */
    protected function runTierPicks(array $options, ?AIModelsService $aIModelsService): array
    {
        $commandTester = new CommandTester(new ModelTierPicksProbeCommand($aIModelsService));
        $exitCode = $commandTester->execute($options);
        return [$exitCode, explode("\n", $commandTester->getDisplay())];
    }

    /**
     * @param string[] $outputLines
     */
    protected function findLineStartingWith(array $outputLines, string $lineStart): string
    {
        foreach ($outputLines as $outputLine) {
            if (preg_match('/^' . preg_quote($lineStart, '/') . '\s/', $outputLine) === 1) {
                return $outputLine;
            }
        }
        $this->fail('No output line starts with ' . $lineStart . ":\n" . implode("\n", $outputLines));
    }

    public function testTheCommandKeepsItsName(): void
    {
        $asCommandAttributes = (new ReflectionClass(ModelTierPicksCommand::class))->getAttributes(AsCommand::class);

        $this->assertCount(1, $asCommandAttributes);
        $this->assertSame('app:ai-model:tier-picks', $asCommandAttributes[0]->newInstance()->name);
    }

    public function testEveryTierPrintsItsPick(): void
    {
        [$exitCode, $outputLines] = $this->runTierPicks([], FixedCatalogAIModelsService::createWithSmallCatalog());

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertMatchesRegularExpression(
            '/^CHEAP\s+TEST\.CHEAP_ALPHA\s+70\s+0\.19\s+tier=CHEAP obj=COST/',
            $this->findLineStartingWith($outputLines, ModelTier::CHEAP)
        );
        $this->assertStringContainsString(FixedCatalogAIModelsService::STANDARD_ONE, $this->findLineStartingWith($outputLines, ModelTier::STANDARD));
        $this->assertStringContainsString(FixedCatalogAIModelsService::PREMIUM_ONE, $this->findLineStartingWith($outputLines, ModelTier::PREMIUM));
    }

    public function testTheVendorPreferenceChangesTheTierPicks(): void
    {
        [$exitCode, $outputLines] = $this->runTierPicks(['--vendor' => AIModel::VENDOR_GOOGLE], FixedCatalogAIModelsService::createWithSmallCatalog());

        $this->assertSame(Command::SUCCESS, $exitCode);
        $cheapLine = $this->findLineStartingWith($outputLines, ModelTier::CHEAP);
        $this->assertStringContainsString(FixedCatalogAIModelsService::CHEAP_BETA, $cheapLine);
        $this->assertStringContainsString('vendorPref=GOOGLE(applied)', $cheapLine);
        $standardLine = $this->findLineStartingWith($outputLines, ModelTier::STANDARD);
        $this->assertStringContainsString(FixedCatalogAIModelsService::STANDARD_ONE, $standardLine);
        $this->assertStringContainsString('vendorPref=GOOGLE(no-qualifier)', $standardLine);
    }

    public function testEveryScopePrintsTheModelItResolves(): void
    {
        [, $outputLines] = $this->runTierPicks([], FixedCatalogAIModelsService::createWithSmallCatalog());

        $agenticLine = $this->findLineStartingWith($outputLines, ModelScope::AGENTIC);
        $this->assertStringContainsString(FixedCatalogAIModelsService::CHEAP_ALPHA, $agenticLine);
        $this->assertStringContainsString('tier=CHEAP obj=CAPABILITY', $agenticLine);
        $this->assertMatchesRegularExpression(
            '/^FAST_CHEAP\s+TEST\.CHEAP_BETA\s+120\s+0\.38\s+tier=CHEAP obj=SPEED/',
            $this->findLineStartingWith($outputLines, ModelScope::FAST_CHEAP)
        );
        foreach (ModelScope::ALL as $scope) {
            $this->assertStringNotContainsString('no model classified', $this->findLineStartingWith($outputLines, $scope));
        }
    }

    public function testAPinnedScopeNamesThePinInsteadOfThePreviousDecision(): void
    {
        [, $outputLines] = $this->runTierPicks([], FixedCatalogAIModelsService::createWithSmallCatalog());

        $orchestratorLine = $this->findLineStartingWith($outputLines, ModelScope::ORCHESTRATOR);
        $this->assertMatchesRegularExpression('/^ORCHESTRATOR\s+MINIMAX\.M3\s+-\s+-\s+/', $orchestratorLine);
        $this->assertStringContainsString(ModelTierPicksCommand::PINNED_SCOPE_DEFAULT_REASON, $orchestratorLine);
        $this->assertStringNotContainsString('tier=', $orchestratorLine);
    }

    public function testTheParetoFrontierListsTheRatedAgentEligibleModels(): void
    {
        [, $outputLines] = $this->runTierPicks([], FixedCatalogAIModelsService::createWithSmallCatalog());

        $frontierStart = array_search('Pareto frontier (rated, non-dominated):', $outputLines, true);
        $this->assertIsInt($frontierStart);
        $frontierModelNames = array_map('trim', array_filter(array_slice($outputLines, $frontierStart + 1), 'strlen'));
        sort($frontierModelNames);
        $this->assertSame(
            [FixedCatalogAIModelsService::CHEAP_ALPHA, FixedCatalogAIModelsService::CHEAP_BETA, FixedCatalogAIModelsService::PREMIUM_ONE, FixedCatalogAIModelsService::STANDARD_ONE],
            $frontierModelNames
        );
    }

    public function testAnUnknownVendorFails(): void
    {
        [$exitCode, $outputLines] = $this->runTierPicks(['--vendor' => 'google'], FixedCatalogAIModelsService::createWithSmallCatalog());

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unknown --vendor "google"', implode("\n", $outputLines));
    }

    public function testTheShippedCatalogHasAPickForEveryTier(): void
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        [$exitCode, $outputLines] = $this->runTierPicks([], null);

        $this->assertSame(Command::SUCCESS, $exitCode);
        foreach (ModelTier::getAll() as $tier) {
            $tierLineParts = preg_split('/\s+/', $this->findLineStartingWith($outputLines, $tier));
            $this->assertNotNull($aIModelsService->getAIModelByName($tierLineParts[1]), $tier . ' picks a catalog model');
        }
    }
}
