<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentity;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Sylius\Component\Core\Model\CustomerInterface;

/**
 * Repository for managing OAuth identities.
 */
class OAuthIdentityRepository extends EntityRepository implements OAuthIdentityRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(OAuthIdentity::class));
    }

    public function findByProviderIdentifier(string $provider, string $identifier): ?OAuthIdentityInterface
    {
        /** @var OAuthIdentityInterface|null $result */
        $result = $this->findOneBy([
            'provider' => $provider,
            'identifier' => $identifier,
        ]);

        return $result;
    }

    public function findByCustomerAndProvider(CustomerInterface $customer, string $provider): ?OAuthIdentityInterface
    {
        /** @var OAuthIdentityInterface|null $result */
        $result = $this->findOneBy([
            'customer' => $customer,
            'provider' => $provider,
        ]);

        return $result;
    }

    public function findAllByCustomer(CustomerInterface $customer): array
    {
        /** @var array<OAuthIdentityInterface> $result */
        $result = $this->findBy(['customer' => $customer]);

        return $result;
    }

    public function hasOtherProviders(CustomerInterface $customer, string $excludeProvider): bool
    {
        $count = $this->createQueryBuilder('oi')
            ->select('COUNT(oi.id)')
            ->where('oi.customer = :customer')
            ->andWhere('oi.provider != :provider')
            ->setParameter('customer', $customer)
            ->setParameter('provider', $excludeProvider)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
