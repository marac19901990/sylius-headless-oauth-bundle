<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Repository;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * Repository interface for managing OAuth identities.
 */
interface OAuthIdentityRepositoryInterface extends RepositoryInterface
{
    /**
     * Find an OAuth identity by provider and identifier (the OAuth provider's user ID).
     */
    public function findByProviderIdentifier(string $provider, string $identifier): ?OAuthIdentityInterface;

    /**
     * Find an OAuth identity for a specific customer and provider.
     */
    public function findByCustomerAndProvider(CustomerInterface $customer, string $provider): ?OAuthIdentityInterface;

    /**
     * Find all OAuth identities for a customer.
     *
     * @return array<OAuthIdentityInterface>
     */
    public function findAllByCustomer(CustomerInterface $customer): array;

    /**
     * Check if a customer has other providers connected besides the specified one.
     */
    public function hasOtherProviders(CustomerInterface $customer, string $excludeProvider): bool;
}
