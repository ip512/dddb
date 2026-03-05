<?php

declare(strict_types=1);

namespace App\Domain\Model\Repository;

use App\Application\Model\View\ModelFlatView;
use App\Application\Model\View\ModelHeader;
use App\Domain\Model\Manufacturer;
use App\Domain\Model\Model;
use App\Domain\Model\Serie;
use Doctrine\ORM\Tools\Pagination\Paginator;

interface ModelRepositoryInterface
{
    public function add(Model $model): Model;

    public function update(Model $model): Model;

    public function isReferenceUsed(Manufacturer $manufacturer, string $reference, int $variant): bool;

    public function findModelByCodeTac(string $codeTac): ?ModelHeader;

    public function findModelByUuid(string $modelUuid): ?Model;

    public function findModelByReference(string $serieUuid, string $reference, ?int $variant = null): ?Model;

    public function findModelByAndroidCodeName(string $serieUuid, string $codeName, ?int $variant = null): ?Model;

    /** @return Paginator<Model> */
    public function findPaginatedModels(Serie $serie, int $page, int $pageSize): Paginator;

    /** @return ModelHeader[] */
    public function findAllModelHeaders(Serie $serie): iterable;

    /** @return ModelFlatView[] */
    public function findAllModels(): iterable;
}
