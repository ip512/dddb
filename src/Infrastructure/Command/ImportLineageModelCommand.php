<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Application\Manufacturer\Command\CreateManufacturerCommand;
use App\Application\Manufacturer\Command\CreateManufacturerCommandHandler;
use App\Application\Model\Command\CreateModelCommand;
use App\Application\Model\Command\CreateModelCommandHandler;
use App\Application\Serie\Command\CreateSerieCommand;
use App\Application\Serie\Command\CreateSerieCommandHandler;
use App\Application\SupportedOsList\Command\AddSupportedOsCommand;
use App\Application\SupportedOsList\Command\AddSupportedOsCommandHandler;
use App\Application\SupportedOsList\Command\DeleteSupportedOsCommand;
use App\Application\SupportedOsList\Command\DeleteSupportedOsCommandHandler;
use App\Domain\Manufacturer\Repository\ManufacturerRepositoryInterface;
use App\Domain\Model\Attribute\AttributeCollection;
use App\Domain\Model\Attribute\AttributeRepositoryInterface;
use App\Domain\Model\Attribute\SupportedOs;
use App\Domain\Model\Attribute\SupportedOsList;
use App\Domain\Model\Manufacturer;
use App\Domain\Model\Model;
use App\Domain\Model\Repository\ModelRepositoryInterface;
use App\Domain\Model\Serie;
use App\Domain\Os\OsVersionList;
use App\Domain\Serie\Repository\SerieRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Serializer\Encoder\YamlEncoder;
use Symfony\Component\Serializer\SerializerInterface;

#[AsCommand(
    name: 'app:import:lineage-model',
    description: 'Import lineage model to database',
)]
class ImportLineageModelCommand extends Command
{
    private ?Model $mainModel = null;
    private SymfonyStyle $io;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManufacturerRepositoryInterface $manufacturerRepository,
        private readonly SerieRepositoryInterface $serieRepository,
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly CreateManufacturerCommandHandler $createManufacturer,
        private readonly CreateSerieCommandHandler $createSerie,
        private readonly CreateModelCommandHandler $createModel,
        private readonly AddSupportedOsCommandHandler $addSupportedOs,
        private readonly DeleteSupportedOsCommandHandler $deleteSupportedOs,
        private readonly SerializerInterface $serializer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('filename', InputArgument::REQUIRED, 'Yaml file from lineage wiki')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $filename = $input->getArgument('filename');

        if (!file_exists($filename)) {
            $this->io->error("{$filename}: File not found");

            return Command::FAILURE;
        }

        /** @var LineageModel $lineageModel */
        $lineageModel = $this->serializer->deserialize(file_get_contents($filename), LineageModel::class, YamlEncoder::FORMAT);

        if ($this->isCodeNameBlocked($lineageModel->codename)) {
            $this->io->warning('codename excluded');

            return Command::SUCCESS;
        }

        $existingManufacturerUuid = $this->manufacturerRepository->findUuidByName($lineageModel->vendor);
        if ($existingManufacturerUuid === null) {
            $question = \sprintf('"%s" manufacturer not found, do you want to create it?', $lineageModel->vendor);
            $response = $this->io->askQuestion(new ConfirmationQuestion($question));
            if ($response === false) {
                $this->io->warning('Model not imported');

                return Command::FAILURE;
            }

            /** @var Manufacturer $manufacturer */
            $manufacturer = ($this->createManufacturer)(new CreateManufacturerCommand($lineageModel->vendor));
            $manufacturerUuid = $manufacturer->getUuid();
            $this->io->info("Manufacturer {$manufacturer->getName()} created");
        } else {
            $manufacturerUuid = $existingManufacturerUuid;
        }

        $serieName = $this->getNameAlias($lineageModel->name);

        $existingSerieUuid = $this->serieRepository->findUuidByName($manufacturerUuid, $serieName);
        if ($existingSerieUuid === null) {
            $question = \sprintf('"%s" serie not found, do you want to create it?', $serieName);
            $response = $this->io->askQuestion(new ConfirmationQuestion($question));
            if ($response === false) {
                $this->io->warning('Model not imported');

                return Command::FAILURE;
            }

            $manufacturerReference = $this->entityManager->getReference(Manufacturer::class, $manufacturerUuid);
            /** @var Serie $serie */
            $serie = ($this->createSerie)(new CreateSerieCommand($serieName, $manufacturerReference));
            $serieUuid = $serie->getUuid();
            $this->io->info("Serie {$serie->getName()} created");
        } else {
            $serieUuid = $existingSerieUuid;
        }

        $mainVersion = strtok($lineageModel->currentBranch, '.');

        $modelReferences = empty($lineageModel->models) ? [null] : $lineageModel->models;
        foreach ($modelReferences as $modelReference) {
            $this->io->info(
                "{$lineageModel->vendor} {$serieName} {$modelReference} [{$lineageModel->codename}-{$lineageModel->variant}] " .
                " lineage currentBranch: {$mainVersion}",
            );

            if ($modelReference === null) {
                $existingModel = $this->modelRepository->findModelByAndroidCodeName($serieUuid, $lineageModel->codename, $lineageModel->variant);
            } else {
                $existingModel = $this->modelRepository->findModelByReference($serieUuid, $modelReference, $lineageModel->variant);
            }
            if (\is_null($existingModel)) {
                $this->createModel($serieUuid, $modelReference, $lineageModel->codename, $lineageModel->variant, $mainVersion);
                $this->io->info("Model {$serieName} {$modelReference} has been imported.");
            } elseif ($this->mainModel === null) {
                $attributes = $this->attributeRepository->getModelAttributes($existingModel);
                $this->checkLatestLineageVersion($existingModel, $attributes, $mainVersion);
                $this->mainModel = $existingModel;
            }
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    private function createModel(string $serieUuid, ?string $reference, string $codeName, int $variant, string $latestLineageVersion): void
    {
        $serie = $this->entityManager->getReference(Serie::class, $serieUuid);

        $model = ($this->createModel)(new CreateModelCommand($serie, $reference, $codeName, $variant, $this->mainModel));
        if ($this->mainModel === null) {
            $this->mainModel = $model;
            $this->addLineageSupportedAttributeToModel($model, $latestLineageVersion, $codeName, $variant);
        }
    }

    private function addLineageSupportedAttributeToModel(
        Model $model,
        string $latestLineageVersion,
        string $codeName,
        int $variant,
        ?string $comment = null,
    ): void {
        $osList = new OsVersionList();
        $osVersion = $osList->getLineageOsVersion($latestLineageVersion);
        $variantSuffix = $variant > 0 ? "variant{$variant}/" : '';
        ($this->addSupportedOs)(new AddSupportedOsCommand(
            $model,
            $osVersion,
            "https://wiki.lineageos.org/devices/{$codeName}/{$variantSuffix}",
            $comment,
        ));
    }

    private function checkLatestLineageVersion(Model $model, AttributeCollection $attributes, string $upToDateVersion): void
    {
        $osList = $attributes->get(SupportedOsList::NAME);
        /** @var ?SupportedOs $currentVersion */
        $currentVersion = null;
        $otherOses = [];
        /** @var SupportedOs $supportedOs */
        foreach ($osList->getValue() as $supportedOs) {
            if ($supportedOs->osVersion->getOs()->getId() !== OsVersionList::LINEAGE) {
                $otherOses[] = $supportedOs;
                continue;
            }
            if ($currentVersion === null || (int) $supportedOs->osVersion->getName() > (int) $currentVersion->osVersion->getName()) {
                $currentVersion = $supportedOs;
            }
        }

        if ($currentVersion === null) {
            $this->io->warning(
                "No lineage version found for {$model->getSerie()->getName()} " .
                "{$model->getReference()} {$model->getAndroidCodeName()} {$model->getVariant()}",
            );
            $this->addLineageSupportedAttributeToModel(
                $model,
                $upToDateVersion,
                $model->getAndroidCodeName(),
                $model->getVariant(),
            );

            return;
        }
        if ($currentVersion !== null && $currentVersion->osVersion->getName() !== $upToDateVersion) {
            $this->io->info(
                "{$model->getSerie()} {$model->getReference()} {$model->getAndroidCodeName()} [{$model->getVariant()}] " .
                "Upgrade Lineage version from {$currentVersion->osVersion->getName()} to {$upToDateVersion}",
            );
            if ($currentVersion->comment) {
                $this->io->info("There is a comment: {$currentVersion->comment}");
            }
            ($this->deleteSupportedOs)(new DeleteSupportedOsCommand($model, $currentVersion->id));
            $this->addLineageSupportedAttributeToModel(
                $model,
                $upToDateVersion,
                $model->getAndroidCodeName(),
                $model->getVariant(),
                $currentVersion->comment,
            );
        }
    }

    private function isCodeNameBlocked(string $codename): bool
    {
        return \in_array($codename, [
            'foster_tab',
            'mdarcy_tab',
            'nx_tab',
            'porg_tab',
            'quill_tab',
            's3ve3gxx',
        ]);
    }

    private function getNameAlias(string $sourceName): string
    {
        return match ($sourceName) {
            'moto g9' => 'moto g9 play',
            'G8X ThinQ (Global)' => 'G8X ThinQ (G850EM/EMW)',
            'G8X ThinQ (North America)' => 'G8X ThinQ (G850QM/UM)',
            'Galaxy S20 FE (Snapdragon)' => 'Galaxy S20 FE',
            default => $sourceName,
        };
    }
}
