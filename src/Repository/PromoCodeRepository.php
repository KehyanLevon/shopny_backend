<?php

namespace App\Repository;

use App\Entity\PromoCode;
use App\Enum\PromoScopeType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    public function createFilteredQuery(
        ?string $search,
        ?string $scopeType,
        ?bool $isActive,
        ?int $isExpiredFlag,
        string $sortBy,
        string $sortDir
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p');
        if ($search !== null && $search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $qb
                ->andWhere('LOWER(p.code) LIKE :term OR LOWER(p.description) LIKE :term')
                ->setParameter('term', $term);
        }
        if ($scopeType !== null && $scopeType !== '') {
            if (in_array($scopeType, array_column(PromoScopeType::cases(), 'value'), true)) {
                $qb
                    ->andWhere('p.scopeType = :scopeType')
                    ->setParameter('scopeType', $scopeType);
            }
        }
        if ($isActive !== null) {
            $qb
                ->andWhere('p.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }
        if ($isExpiredFlag !== null) {
            $now = new \DateTimeImmutable();

            if ($isExpiredFlag === 1) {
                $qb
                    ->andWhere('p.expiresAt IS NOT NULL AND p.expiresAt < :now')
                    ->setParameter('now', $now);
            } elseif ($isExpiredFlag === 0) {
                $qb
                    ->andWhere('p.expiresAt IS NULL OR p.expiresAt >= :now')
                    ->setParameter('now', $now);
            }
        }
        $allowedSorts = ['createdAt', 'startsAt', 'expiresAt'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'createdAt';
        }
        $qb->orderBy('p.' . $sortBy, $sortDir);
        return $qb;
    }
}
