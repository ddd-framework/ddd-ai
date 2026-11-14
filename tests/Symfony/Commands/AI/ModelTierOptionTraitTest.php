<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\FixedCatalogAIModelsService;
use DDD\Tests\AI\Symfony\Commands\AI\Fixtures\ModelTierOptionProbeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--model`, `--tier` and `--vendor`: an explicit model wins, a tier selects through AIModelsService (preferring the
 * vendor), nothing given names no model.
 */
class ModelTierOptionTraitTest extends TestCase
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
     * @return array{0: ModelTierOptionProbeCommand, 1: string} The command after the run and its output
     */
    protected function runProbeCommand(array $options): array
    {
        $modelTierOptionProbeCommand = new ModelTierOptionProbeCommand(FixedCatalogAIModelsService::createWithSmallCatalog());
        $commandTester = new CommandTester($modelTierOptionProbeCommand);
        $commandTester->execute($options);
        return [$modelTierOptionProbeCommand, $commandTester->getDisplay()];
    }

    public function testTheModelWinsOverTheTier(): void
    {
        [$probeCommand, $display] = $this->runProbeCommand(['--model' => 'SOME.PINNED_MODEL', '--tier' => ModelTier::PREMIUM]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . 'SOME.PINNED_MODEL', $display);
        $this->assertStringContainsString('--model pin', $display);
        $this->assertNull($probeCommand->getLastModelSelectionDecision());
    }

    public function testATierWithoutVendorSelectsTheTiersPick(): void
    {
        [$probeCommand, $display] = $this->runProbeCommand(['--tier' => ModelTier::CHEAP]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . FixedCatalogAIModelsService::CHEAP_ALPHA, $display);
        $this->assertStringContainsString('AIModelsService (tier policy)', $display);
        $this->assertStringNotContainsString('Vendor pref: applied', $display);
        $decision = $probeCommand->getLastModelSelectionDecision();
        $this->assertNotNull($decision);
        $this->assertSame(ModelTier::CHEAP, $decision->effectiveTier);
        $this->assertFalse($decision->vendorPreferenceApplied);
    }

    public function testATierWithVendorPrefersTheVendor(): void
    {
        [$probeCommand, $display] = $this->runProbeCommand(['--tier' => ModelTier::CHEAP, '--vendor' => AIModel::VENDOR_GOOGLE]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . FixedCatalogAIModelsService::CHEAP_BETA, $display);
        $this->assertStringContainsString('Vendor pref: applied', $display);
        $this->assertTrue($probeCommand->getLastModelSelectionDecision()?->vendorPreferenceApplied);
    }

    public function testAVendorWithoutAModelInTheTierKeepsTheTiersPick(): void
    {
        [$probeCommand, $display] = $this->runProbeCommand(['--tier' => ModelTier::STANDARD, '--vendor' => AIModel::VENDOR_GOOGLE]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . FixedCatalogAIModelsService::STANDARD_ONE, $display);
        $this->assertFalse($probeCommand->getLastModelSelectionDecision()?->vendorPreferenceApplied);
    }

    public function testNothingGivenNamesNoModel(): void
    {
        [$probeCommand, $display] = $this->runProbeCommand([]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . 'null', $display);
        $this->assertStringContainsString('default (no --model / --tier)', $display);
        $this->assertNull($probeCommand->getLastModelSelectionDecision());
    }

    public function testAnEmptyModelFallsThroughToTheTier(): void
    {
        [, $display] = $this->runProbeCommand(['--model' => '', '--tier' => ModelTier::PREMIUM]);

        $this->assertStringContainsString(ModelTierOptionProbeCommand::RESOLVED_MODEL_LINE_PREFIX . FixedCatalogAIModelsService::PREMIUM_ONE, $display);
    }

    public function testAnUnknownTierIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown --tier "BEST"');

        $this->runProbeCommand(['--tier' => 'BEST']);
    }

    public function testAnUnknownVendorIsRefused(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown --vendor "google"');

        $this->runProbeCommand(['--tier' => ModelTier::CHEAP, '--vendor' => 'google']);
    }

    public function testTheOptionsAreDeclaredAndACommandsOwnModelOptionIsKept(): void
    {
        $definitionWithTraitModelOption = (new ModelTierOptionProbeCommand())->getDefinition();
        $definitionWithOwnModelOption = (new ModelTierOptionProbeCommand(null, 'The model of this command.'))->getDefinition();

        foreach (['model', 'tier', 'vendor'] as $optionName) {
            $this->assertTrue($definitionWithTraitModelOption->hasOption($optionName), $optionName);
        }
        $this->assertStringContainsString('wins over --tier', $definitionWithTraitModelOption->getOption('model')->getDescription());
        $this->assertSame('The model of this command.', $definitionWithOwnModelOption->getOption('model')->getDescription());
    }

    public function testASecondResolutionDropsTheEarlierDecision(): void
    {
        $modelTierOptionProbeCommand = new ModelTierOptionProbeCommand(FixedCatalogAIModelsService::createWithSmallCatalog());
        $commandTester = new CommandTester($modelTierOptionProbeCommand);
        $commandTester->execute(['--tier' => ModelTier::CHEAP]);
        $commandTester->execute(['--model' => 'SOME.PINNED_MODEL']);

        $this->assertNull($modelTierOptionProbeCommand->getLastModelSelectionDecision());
        $this->assertStringContainsString('--model pin', $commandTester->getDisplay());
    }
}
