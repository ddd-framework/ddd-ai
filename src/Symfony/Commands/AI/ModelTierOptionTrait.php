<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\AI;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelManagerPolicy;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionDecision;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The options `--model`, `--tier` and `--vendor` of a console command that runs a language model, and the model they
 * select. For a class extending {@see \Symfony\Component\Console\Command\Command}.
 *
 * Precedence, the same as the runtime model resolution of the Argus language-model trait: an explicit `--model` wins;
 * otherwise `--tier` selects through {@see AIModelsService::selectModel()} (with `--vendor` as a soft preference);
 * otherwise no model is named and the caller's own default applies.
 */
trait ModelTierOptionTrait
{
    /** @var ModelSelectionDecision|null The selection decision of the last `--tier` resolution; null after `--model` or without options */
    protected ?ModelSelectionDecision $lastModelSelectionDecision = null;

    /**
     * Declares `--tier` and `--vendor`, and `--model` unless the command declared its own `--model` before (a command
     * that wants a description of its own declares `--model` first, then calls this method).
     *
     * @param string $modelOptionDescription The description of `--model` when this method declares it
     * @return void
     */
    protected function addModelTierOptions(
        string $modelOptionDescription = 'Use this AIModel by catalog name (e.g. OPENAI.GPT6_LUNA); wins over --tier.'
    ): void {
        if (!$this->getDefinition()->hasOption('model')) {
            $this->addOption('model', null, InputOption::VALUE_OPTIONAL, $modelOptionDescription);
        }
        $this->addOption(
            'tier',
            null,
            InputOption::VALUE_OPTIONAL,
            'Select the model through AIModelsService for this tier (' . implode('|', ModelTier::getAll()) . '). Ignored when --model is set.'
        );
        $this->addOption(
            'vendor',
            null,
            InputOption::VALUE_OPTIONAL,
            'Soft vendor preference for --tier (an AIModel::VENDOR_* value, e.g. GOOGLE).'
        );
    }

    /**
     * The AIModel name the command runs on: the explicit `--model`, else the AIModelsService pick for `--tier`
     * (preferring `--vendor`), else null (the caller's default model).
     *
     * @param InputInterface $input
     * @return string|null
     * @throws InvalidOptionException when `--tier` or `--vendor` is not a known value
     * @throws BadRequestException when no model of the tier is eligible
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function resolveEffectiveModelName(InputInterface $input): ?string
    {
        $this->lastModelSelectionDecision = null;

        $pinnedModelName = $this->readNonEmptyStringOption($input, 'model');
        if ($pinnedModelName !== null) {
            return $pinnedModelName;
        }

        $tier = $this->readNonEmptyStringOption($input, 'tier');
        if ($tier === null) {
            return null;
        }
        if (!ModelTier::isValid($tier)) {
            throw new InvalidOptionException('Unknown --tier "' . $tier . '". Valid: ' . implode('|', ModelTier::getAll()));
        }

        $vendor = $this->readNonEmptyStringOption($input, 'vendor');
        if ($vendor !== null && !in_array($vendor, AIModel::getModelVendors(), true)) {
            throw new InvalidOptionException(
                'Unknown --vendor "' . $vendor . '". Valid: ' . implode('|', AIModel::getModelVendors())
            );
        }

        $aIModelsService = $this->getAIModelsService();
        $aIModel = $aIModelsService->selectModel(new ModelSelectionContext($this->createTierSelectionPolicy($tier, $vendor)));
        $this->lastModelSelectionDecision = $aIModelsService->getLastDecision();

        return $aIModel?->name;
    }

    /**
     * The selection policy `--tier` / `--vendor` select with. Default: the tier as the preferred band, the vendor as
     * the soft preference, every other knob at the policy's default.
     *
     * @param string $tier A {@see ModelTier} value
     * @param string|null $vendor An AIModel::VENDOR_* value
     * @return ModelManagerPolicy
     */
    protected function createTierSelectionPolicy(string $tier, ?string $vendor): ModelManagerPolicy
    {
        return new ModelManagerPolicy(preferredTier: $tier, preferredVendor: $vendor);
    }

    /**
     * Prints which model the command runs on and why: the `--model` pin, the `--tier` decision of AIModelsService,
     * or the default.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string|null $resolvedModelName The name {@see resolveEffectiveModelName()} returned, or the default the caller resolved
     * @return void
     */
    protected function writeModelSelection(InputInterface $input, OutputInterface $output, ?string $resolvedModelName): void
    {
        $output->writeln('<info>── Model selection ───────────────────────────────────</info>');
        $pinnedModelName = $this->readNonEmptyStringOption($input, 'model');
        if ($pinnedModelName !== null) {
            $output->writeln('  Source : <comment>--model pin</comment>');
            $output->writeln('  Model  : ' . $pinnedModelName);
        } elseif ($this->lastModelSelectionDecision instanceof ModelSelectionDecision) {
            $this->writeTierModelSelection($output, $this->lastModelSelectionDecision);
        } else {
            $output->writeln('  Source : ' . $this->describeDefaultModelSource()
                . ($resolvedModelName !== null ? ' → ' . $resolvedModelName : ''));
        }
        $output->writeln('<info>──────────────────────────────────────────────────────</info>');
    }

    /**
     * Prints the lines of a `--tier` decision.
     *
     * @param OutputInterface $output
     * @param ModelSelectionDecision $modelSelectionDecision
     * @return void
     */
    protected function writeTierModelSelection(OutputInterface $output, ModelSelectionDecision $modelSelectionDecision): void
    {
        $output->writeln('  Source     : <comment>AIModelsService (tier policy)</comment>');
        $output->writeln('  Tier       : ' . $modelSelectionDecision->effectiveTier . '   (objective ' . $modelSelectionDecision->objective . ')');
        $output->writeln('  Model      : <comment>' . $modelSelectionDecision->modelName . '</comment>'
            . ($modelSelectionDecision->vendor !== null ? '   [' . $modelSelectionDecision->vendor . ']' : ''));
        $output->writeln('  Agentic    : ' . ($modelSelectionDecision->agenticScore !== null ? $modelSelectionDecision->agenticScore . ' / 100' : 'n/a'));
        $output->writeln('  Blended    : $' . number_format($modelSelectionDecision->blendedCostPer1MUsd, 3) . ' / 1M tokens');
        if ($modelSelectionDecision->vendorPreferenceApplied) {
            $output->writeln('  Vendor pref: applied');
        }
        $output->writeln('  Candidates : ' . $modelSelectionDecision->candidateCount . ' qualified, ' . $modelSelectionDecision->inTierCount . ' in tier');
        $output->writeln('  Reason     : ' . $modelSelectionDecision->reason);
    }

    /**
     * How {@see writeModelSelection()} names the source when neither `--model` nor `--tier` is given.
     *
     * @return string
     */
    protected function describeDefaultModelSource(): string
    {
        return 'default (no --model / --tier)';
    }

    /**
     * The value of a string option, null when the option is absent or empty.
     *
     * @param InputInterface $input
     * @param string $optionName
     * @return string|null
     */
    protected function readNonEmptyStringOption(InputInterface $input, string $optionName): ?string
    {
        $optionValue = $input->getOption($optionName);
        return is_string($optionValue) && $optionValue !== '' ? $optionValue : null;
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
