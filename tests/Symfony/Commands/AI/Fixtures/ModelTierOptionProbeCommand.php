<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI\Fixtures;

use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionDecision;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** A command that uses {@see ModelTierOptionTrait} and prints the model it resolves */
class ModelTierOptionProbeCommand extends Command
{
    use ModelTierOptionTrait {
        getAIModelsService as getAIModelsServiceFromServiceLookup;
    }

    public const string RESOLVED_MODEL_LINE_PREFIX = 'RESOLVED=';

    /** @var AIModelsService|null The catalog service the trait gets; null = the service lookup */
    public ?AIModelsService $fixedAIModelsService = null;

    /** @var string|null A `--model` description the command declares itself before the trait's options */
    public ?string $ownModelOptionDescription = null;

    public function __construct(?AIModelsService $fixedAIModelsService = null, ?string $ownModelOptionDescription = null)
    {
        $this->fixedAIModelsService = $fixedAIModelsService;
        $this->ownModelOptionDescription = $ownModelOptionDescription;
        parent::__construct('probe:model-tier-option');
    }

    protected function configure(): void
    {
        if ($this->ownModelOptionDescription !== null) {
            $this->addOption('model', null, InputOption::VALUE_OPTIONAL, $this->ownModelOptionDescription);
        }
        $this->addModelTierOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resolvedModelName = $this->resolveEffectiveModelName($input);
        $this->writeModelSelection($input, $output, $resolvedModelName);
        $output->writeln(self::RESOLVED_MODEL_LINE_PREFIX . ($resolvedModelName ?? 'null'));
        return Command::SUCCESS;
    }

    /**
     * @return ModelSelectionDecision|null The decision the last resolution kept
     */
    public function getLastModelSelectionDecision(): ?ModelSelectionDecision
    {
        return $this->lastModelSelectionDecision;
    }

    protected function getAIModelsService(): AIModelsService
    {
        return $this->fixedAIModelsService ?? $this->getAIModelsServiceFromServiceLookup();
    }
}
