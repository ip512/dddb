<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository\Model;

use App\Domain\Model\CodeTac;
use App\Domain\Model\Model;
use App\Domain\Model\Repository\CodeTacRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

final class CodeTacRepository extends ServiceEntityRepository implements CodeTacRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeTac::class);
    }

    public function add(CodeTac $codeTac): CodeTac
    {
        $this->getEntityManager()->persist($codeTac);

        return $codeTac;
    }

    public function remove(int $code): void
    {
        $codeTac = $this->find($code);
        $this->getEntityManager()->remove($codeTac);
    }

    public function isCodeTacUsed(int $code): bool
    {
        return $this->count(['code' => $code]) > 0;
    }

    public function findCodeTacs(Model $model): array
    {
        $queryBuilder = $this->createQueryBuilder('ct');
        $queryBuilder->select('ct.code')
            ->andWhere('ct.model = :model')->setParameter('model', $model)
            ->addOrderBy('ct.code', Order::Ascending->value)
        ;

        return $queryBuilder->getQuery()->getSingleColumnResult();
    }
}
