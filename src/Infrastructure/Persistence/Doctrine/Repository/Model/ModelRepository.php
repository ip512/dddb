<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository\Model;

use App\Application\Model\View\ModelFlatView;
use App\Application\Model\View\ModelHeader;
use App\Domain\Model\CodeTac;
use App\Domain\Model\Manufacturer;
use App\Domain\Model\Model;
use App\Domain\Model\Repository\ModelRepositoryInterface;
use App\Domain\Model\Serie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

final class ModelRepository extends ServiceEntityRepository implements ModelRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly \DateTimeInterface $now,
    ) {
        parent::__construct($registry, Model::class);
    }

    public function add(Model $model): Model
    {
        $model->setUpdatedAt($this->now);
        $this->getEntityManager()->persist($model);

        return $model;
    }

    public function update(Model $model): Model
    {
        $model->setUpdatedAt($this->now);
        $this->getEntityManager()->persist($model);

        return $model;
    }

    public function isReferenceUsed(Manufacturer $manufacturer, string $reference, int $variant): bool
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m)')
            ->join('m.serie', 's')
            ->join('s.manufacturer', 'mf', 'WITH', 'mf = :manufacturer')->setParameter('manufacturer', $manufacturer->getUuid())
            ->andWhere('LOWER(m.reference) LIKE LOWER(:reference)')->setParameter('reference', $reference)
            ->andWhere('m.variant = :variant')->setParameter('variant', $variant)
            ->getQuery()
            ->getSingleScalarResult() > 0
        ;
    }

    public function findModelByReference(string $serieUuid, string $reference, ?int $variant = null): ?Model
    {
        $builder = $this->createQueryBuilder('m')
            ->select('m')
            ->andWhere('m.serie = :serie')->setParameter('serie', $serieUuid)
            ->andWhere('LOWER(m.reference) LIKE LOWER(:reference)')->setParameter('reference', $reference)
        ;
        if ($variant !== null) {
            $builder->andWhere('m.variant = :variant')->setParameter('variant', $variant);
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    public function findModelByAndroidCodeName(string $serieUuid, string $codeName, ?int $variant = null): ?Model
    {
        $builder = $this->createQueryBuilder('m');
        $builder->select('m')
            ->andWhere('m.serie = :serie')->setParameter('serie', $serieUuid)
            ->andWhere('LOWER(m.androidCodeName) LIKE LOWER(:codeName)')->setParameter('codeName', $codeName)
        ;
        if ($variant !== null) {
            $builder->andWhere('m.variant = :variant')->setParameter('variant', $variant);
        }
        $builder->orderBy('m.parentModel', 'ASC')
            ->orderBy('m.variant', 'ASC')
            ->setMaxResults(1)
        ;

        return $builder->getQuery()->getOneOrNullResult();
    }

    public function findModelByCodeTac(string $codeTac): ?ModelHeader
    {
        /** @var ?CodeTac */
        $codeTac = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('tac', 'model', 's', 'manufacturer')
            ->from(CodeTac::class, 'tac')
            ->join('tac.model', 'model')
            ->join('model.serie', 's')
            ->join('s.manufacturer', 'manufacturer')
            ->andWhere('tac.code = :code')->setParameter('code', $codeTac)
            ->getQuery()
            ->getOneOrNullResult()
        ;
        if (\is_null($codeTac)) {
            return null;
        }

        $model = $codeTac->getModel();

        return new ModelHeader(
            $model->getUuid(),
            $model->getReference(),
            $model->getAndroidCodeName(),
            $model->getVariant(),
            $model->getSerie()->getName(),
            $model->getSerie()->getUuid(),
            $model->getSerie()->getManufacturer()->getName(),
        );
    }

    public function findModelByUuid(string $modelUuid): ?Model
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m = :uuid')->setParameter('uuid', $modelUuid)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function findPaginatedModels(Serie $serie, int $page, int $pageSize): Paginator
    {
        $query = $this->createQueryBuilder('m')
            ->andWhere('m.serie = :serie')->setParameter('serie', $serie)
            ->orderBy('m.reference', Order::Ascending->value)
            ->setFirstResult($pageSize * ($page - 1)) // set the offset
            ->setMaxResults($pageSize)
            ->getQuery()
        ;

        $paginator = new Paginator($query);

        return $paginator;
    }

    /** @return ModelHeader[] */
    public function findAllModelHeaders(Serie $serie): iterable
    {
        return $this->createQueryBuilder('m')
            ->select(
                \sprintf('NEW %s(m.uuid, m.reference, m.androidCodeName, m.variant)', ModelHeader::class),
            )
            ->andWhere('m.serie = :serie')
            ->setParameter('serie', $serie)
            ->orderBy('m.reference', Order::Ascending->value)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findAllModels(): iterable
    {
        $result = $this->createQueryBuilder('m')
            ->select(['m', 's', 'manufacturer'])
            ->join('m.serie', 's')
            ->join('s.manufacturer', 'manufacturer')
            ->orderBy('m.reference', Order::Ascending->value)
            ->getQuery()
            ->toIterable()
        ;

        /** @var Model $model */
        foreach ($result as $model) {
            yield new ModelFlatView(
                $model->getUuid(),
                $model->getSerie()->getManufacturer()->getName(),
                $model->getSerie()->getName(),
                $model->getAndroidCodeName(),
                $model->getVariant(),
                $model->getReference(),
                $model->getParentModel()?->getReference() ?: '',
            );
        }
    }

    /** @return iterable<array{uuid: string, attributes: mixed}> */
    public function findAllAttributesIterable(): iterable
    {
        return $this->createQueryBuilder('m')
            ->select(['m.uuid', 'm.attributes'])
            ->orderBy('m.reference', Order::Ascending->value)
            ->addOrderBy('m.androidCodeName', Order::Ascending->value)
            ->getQuery()
            ->toIterable()
        ;
    }
}
