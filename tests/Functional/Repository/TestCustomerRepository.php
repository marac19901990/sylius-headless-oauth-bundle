<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Repository;

use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestCustomer;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Resource\Model\ResourceInterface;

use function sprintf;

/**
 * @extends ServiceEntityRepository<TestCustomer>
 *
 * @implements CustomerRepositoryInterface<TestCustomer>
 */
final class TestCustomerRepository extends ServiceEntityRepository implements CustomerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TestCustomer::class);
    }

    public function countCustomers(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCustomersInPeriod(DateTimeInterface $startDate, DateTimeInterface $endDate): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.createdAt >= :startDate')
            ->andWhere('c.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatest(int $count): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string> $sorting
     *
     * @return iterable<TestCustomer>
     */
    public function createPaginator(array $criteria = [], array $sorting = []): iterable
    {
        $qb = $this->createQueryBuilder('c');

        foreach ($criteria as $field => $value) {
            $qb->andWhere(sprintf('c.%s = :%s', $field, $field))
               ->setParameter($field, $value);
        }

        foreach ($sorting as $field => $order) {
            $qb->addOrderBy(sprintf('c.%s', $field), $order);
        }

        return $qb->getQuery()->getResult();
    }

    public function add(ResourceInterface $resource): void
    {
        $this->getEntityManager()->persist($resource);
        $this->getEntityManager()->flush();
    }

    public function remove(ResourceInterface $resource): void
    {
        $this->getEntityManager()->remove($resource);
        $this->getEntityManager()->flush();
    }
}
