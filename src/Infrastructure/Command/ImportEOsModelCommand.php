<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Application\CommandBusInterface;
use App\Application\Manufacturer\Command\CreateManufacturerCommand;
use App\Application\Model\Command\CreateModelCommand;
use App\Application\Serie\Command\CreateSerieCommand;
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
    name: 'app:import:eos-model',
    description: 'Import /e/OS model to database',
)]
class ImportEOsModelCommand extends Command
{
    private ?Model $mainModel = null;
    private SymfonyStyle $io;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManufacturerRepositoryInterface $manufacturerRepository,
        private readonly SerieRepositoryInterface $serieRepository,
        private readonly ModelRepositoryInterface $modelRepository,
        private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly CommandBusInterface $commandBus,
        private readonly SerializerInterface $serializer,
        private readonly AddSupportedOsCommandHandler $addSupportedOs,
        private readonly DeleteSupportedOsCommandHandler $deleteSupportedOs,
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

        $yamlString = $this->fixYaml($filename);
        /** @var EOsModel $eOsModel */
        $eOsModel = $this->serializer->deserialize(
            $yamlString,
            EOsModel::class,
            YamlEncoder::FORMAT,
        );
        if ($this->isCodeNameBlocked($eOsModel->codename)) {
            $this->io->warning('codename excluded');

            return Command::SUCCESS;
        }
        if (isset(self::MAP_MODELS[$eOsModel->codename])) {
            $eOsModel->models = self::MAP_MODELS[$eOsModel->codename];
        }

        // Check if version is supported
        $osList = new OsVersionList();
        $osList->getEOsVersion($eOsModel->buildVersionDev ?: $eOsModel->buildVersionStable);

        $existingManufacturerUuid = $this->manufacturerRepository->findUuidByName($eOsModel->vendor);
        if ($existingManufacturerUuid === null) {
            $question = \sprintf('"%s" manufacturer not found, do you want to create it?', $eOsModel->vendor);
            $response = $this->io->askQuestion(new ConfirmationQuestion($question));
            if ($response === false) {
                $this->io->warning('Model not imported');

                return Command::FAILURE;
            }

            /** @var Manufacturer $manufacturer */
            $manufacturer = $this->commandBus->handle(new CreateManufacturerCommand($eOsModel->vendor));
            $manufacturerUuid = $manufacturer->getUuid();
            $this->io->info("Manufacturer {$manufacturer->getName()} created");
        } else {
            $manufacturerUuid = $existingManufacturerUuid;
        }

        $serieName = self::MAP_SERIE_NAME[strtolower($eOsModel->codename)] ?? $eOsModel->name;
        if ($serieName === 'IGNORE') {
            $this->io->warning('Codename ignored');

            return Command::SUCCESS;
        }
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
            $serie = $this->commandBus->handle(new CreateSerieCommand($serieName, $manufacturerReference));
            $serieUuid = $serie->getUuid();
            $this->io->info("Serie {$serie->getName()} created");
        } else {
            $serieUuid = $existingSerieUuid;
        }

        $modelReferences = $eOsModel->models;
        if (empty($modelReferences)) {
            $modelReferences = [null];
        }
        foreach ($modelReferences as $modelReference) {
            $this->io->info(
                "{$eOsModel->vendor} {$eOsModel->name} {$modelReference} [{$eOsModel->codename}] " .
                " eos buildVersionStable: {$eOsModel->buildVersionStable} - eos buildVersionDev: {$eOsModel->buildVersionDev}",
            );

            if ($modelReference === null) {
                $existingModel = $this->modelRepository->findModelByAndroidCodeName($serieUuid, $eOsModel->codename);
            } else {
                $existingModel = $this->modelRepository->findModelByReference($serieUuid, $modelReference);
            }

            if (\is_null($existingModel)) {
                $this->createModel($serieUuid, $modelReference, $eOsModel->codename, $eOsModel->buildVersionDev, $eOsModel->buildVersionStable);
                $this->io->info("Model {$serieName} {$modelReference} has been imported.");
            } elseif ($this->mainModel === null) {
                $attributes = $this->attributeRepository->getModelAttributes($existingModel);

                $this->checkLatestEOsVersion($existingModel, $attributes, $eOsModel->buildVersionDev ?: $eOsModel->buildVersionStable);

                $this->mainModel = $existingModel;
            }
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    private function addEOsSupportedAttributeToModel(
        Model $model,
        string $latestEOsVersion,
        string $codeName,
        ?string $comment = null,
    ): void {
        $osList = new OsVersionList();
        $osVersion = $osList->getEOsVersion($latestEOsVersion);
        $baseURl = 'https://doc.e.foundation/devices/';
        ($this->addSupportedOs)(new AddSupportedOsCommand(
            $model,
            $osVersion,
            $baseURl . $codeName,
            $comment,
        ));
    }

    private function checkLatestEOsVersion(Model $model, AttributeCollection $attributes, string $latestEOsVersion): void
    {
        if (!$attributes->has(SupportedOsList::NAME)) {
            return;
        }

        $osList = $attributes->get(SupportedOsList::NAME);
        /** @var ?SupportedOs $currentVersion */
        $currentVersion = null;
        $otherOses = [];
        /** @var SupportedOs $supportedOs */
        foreach ($osList->getValue() as $supportedOs) {
            if ($supportedOs->osVersion->getOs()->getId() !== OsVersionList::E_OS) {
                $otherOses[] = $supportedOs;
                continue;
            }
            if ($currentVersion === null || (int) $supportedOs->osVersion->getName() > (int) $currentVersion->osVersion->getName()) {
                $currentVersion = $supportedOs;
            }
        }

        if ($currentVersion === null) {
            $this->io->warning(
                "No /e/OS version found for {$model->getSerie()->getName()} " .
                "{$model->getReference()} {$model->getAndroidCodeName()}",
            );
            $this->addEOsSupportedAttributeToModel(
                $model,
                $latestEOsVersion,
                $model->getAndroidCodeName(),
            );

            return;
        }

        if ($currentVersion->osVersion->getName() !== $latestEOsVersion) {
            $this->io->info(
                "{$model->getSerie()} {$model->getReference()} {$model->getAndroidCodeName()} " .
                "Upgrade /e/OS version from {$currentVersion->osVersion->getName()} to {$latestEOsVersion}",
            );
            $comment = $currentVersion->comment && $currentVersion->comment !== 'Stable version';
            if ($comment) {
                $this->io->info("There is a comment: {$currentVersion->comment}");
            }
            ($this->deleteSupportedOs)(new DeleteSupportedOsCommand($model, $currentVersion->id));
            $this->addEOsSupportedAttributeToModel(
                $model,
                $latestEOsVersion,
                $model->getAndroidCodeName(),
                $currentVersion->comment,
            );
        }
    }

    private function createModel(
        string $serieUuid,
        ?string $reference,
        string $codeName,
        ?string $latestEOsVersion,
        ?string $stableVersion,
    ): void {
        $serie = $this->entityManager->getReference(Serie::class, $serieUuid);

        $model = $this->commandBus->handle(new CreateModelCommand($serie, $reference, $codeName, parentModel: $this->mainModel));
        if ($this->mainModel === null) {
            $this->mainModel = $model;
            $this->addEOsSupportedAttributeToModel($model, $latestEOsVersion ?: $stableVersion, $codeName);
        }
    }

    private const array MAP_SERIE_NAME = [
        'fp2' => '2',
        'fp3' => '3',
        'fp4' => '4',
        'fp5' => '5',
        'a3xelte' => 'Galaxy A3 (2016)',
        'a5xelte' => 'Galaxy A5 (2016)',
        'a5y17lte' => 'Galaxy A5 (2017)',
        'alioth' => 'POCO F3',
        'apollon' => 'Mi 10T',
        'cancro' => 'Mi 3',
        'chef' => 'one power',
        'emerald' => 'IGNORE',
        'davinci' => 'Mi 9T',
        'gauguin' => 'Mi 10T Lite',
        'ginkgo' => 'Redmi Note 8',
        'haydn' => 'Mi 11i',
        'hlte' => 'Galaxy Note 3 LTE (N9005/P)',
        'j7elte' => 'Galaxy J7 (2015)',
        'jfltexx' => 'Galaxy S4 (GT-I9505, SGH-I337M, SGH-M919/V)',
        'kane' => 'one vision',
        'kiev' => 'moto g 5G',
        'klte' => 'Galaxy S5 LTE (G900F/M/R4/R7/T/T3/V/W8)',
        'klteactivexx' => 'Galaxy S5 Active (G870F)',
        'land' => 'Redmi 3S / 3X',
        'lisa' => '11 Lite 5G NE',
        'lmi' => 'POCO F2 Pro',
        'mi439' => 'Redmi 7A',
        'mi8917' => 'Redmi 4A',
        'miatoll' => 'POCO M2 Pro',
        'nairo' => 'moto g 5G plus',
        'nio' => 'edge s',
        'oneplus3' => '3',
        'osprey' => 'moto g (2015)',
        'r5' => 'R5 (International)',
        'sapphire' => 'IGNORE',
        'surnia' => 'moto e LTE (2015)',
        'titan' => 'moto g (2014)',
        'taimen' => 'Pixel 2 XL',
        'troika' => 'one action',
        'us996' => 'V20 (GSM Unlocked)',
        'victara' => 'moto x (2014)',
        'zl1' => 'Le Pro3',
    ];

    private const array MAP_MODELS = [
        'guacamole' => ['GM1910'],
        'zirconia' => [],
    ];

    private function isCodeNameBlocked(string $codename): bool
    {
        return \in_array($codename, [
            's3ve3gxx',
        ]);
    }

    private function fixYaml(string $filePath): string
    {
        $yaml = file_get_contents($filePath);

        $allowed = [
            'vendor',
            'name',
            'models',
            'codename',
            'build_version_dev',
            'build_version_stable',
        ];

        $lines = preg_split('/\r?\n/', $yaml);

        $result = [];
        $collectModels = false;

        foreach ($lines as $line) {
            // détecte une propriété YAML simple
            if (preg_match('/^([a-zA-z_\-]+\s*):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = $matches[2];
                if (empty($value)) {
                    continue;
                }

                if (!\in_array($key, $allowed, true)) {
                    $collectModels = false;
                    continue;
                }

                if ($key === 'models') {
                    $result['models'] = ["models: $value"];
                    $collectModels = true;

                    continue;
                }

                $result[$key] = ["$key: $value"];
                $collectModels = false;

                continue;
            }

            // collecte les lignes de liste sous models
            if ($collectModels) {
                if (preg_match('/^\s*-\s*(.*)$/', $line)) {
                    $result['models'][] = $line;
                    continue;
                }

                // stop si on atteint une autre propriété
                if (preg_match('/^[a-zA-Z0-9_]+:/', $line)) {
                    $collectModels = false;
                }
            }
        }

        // reconstruction YAML
        $out = [];

        foreach ($allowed as $key) {
            if (isset($result[$key])) {
                foreach ($result[$key] as $l) {
                    $out[] = $l;
                }
            }
        }

        return implode("\n", $out) . "\n";
    }
}
