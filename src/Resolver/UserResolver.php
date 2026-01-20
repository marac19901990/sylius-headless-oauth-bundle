<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;

/**
 * Resolves or creates a ShopUser from OAuth user data.
 *
 * Resolution logic:
 * 1. Find by provider ID (googleId, appleId, etc.)
 * 2. If not found, find by email
 * 3. If found by email, link provider ID to existing customer
 * 4. If not found at all, create new Customer + ShopUser
 */
final class UserResolver implements UserResolverInterface
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param UserRepositoryInterface<ShopUserInterface> $shopUserRepository
     * @param FactoryInterface<CustomerInterface> $customerFactory
     * @param FactoryInterface<ShopUserInterface> $shopUserFactory
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly UserRepositoryInterface $shopUserRepository,
        private readonly FactoryInterface $customerFactory,
        private readonly FactoryInterface $shopUserFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolve(OAuthUserData $userData): ShopUserInterface
    {
        // 1. Try to find by provider ID
        $customer = $this->findByProviderId($userData->provider, $userData->providerId);

        if ($customer !== null) {
            return $this->getOrCreateShopUser($customer, $userData);
        }

        // 2. Try to find by email
        $customer = $this->customerRepository->findOneBy(['email' => $userData->email]);

        if ($customer !== null) {
            // 3. Link provider ID to existing customer
            $this->linkProviderToCustomer($customer, $userData);

            return $this->getOrCreateShopUser($customer, $userData);
        }

        // 4. Create new Customer + ShopUser
        return $this->createNewUser($userData);
    }

    private function findByProviderId(string $provider, string $providerId): ?CustomerInterface
    {
        $field = match ($provider) {
            'google' => 'googleId',
            'apple' => 'appleId',
            default => throw new OAuthException(sprintf('Unknown provider: %s', $provider)),
        };

        return $this->customerRepository->findOneBy([$field => $providerId]);
    }

    private function linkProviderToCustomer(CustomerInterface $customer, OAuthUserData $userData): void
    {
        if (!$customer instanceof OAuthIdentityInterface) {
            throw new OAuthException(sprintf(
                'Customer entity must implement %s. Add "use OAuthIdentityTrait;" to your Customer class.',
                OAuthIdentityInterface::class
            ));
        }

        match ($userData->provider) {
            'google' => $customer->setGoogleId($userData->providerId),
            'apple' => $customer->setAppleId($userData->providerId),
            default => throw new OAuthException(sprintf('Unknown provider: %s', $userData->provider)),
        };

        // Update name if not set and we have data (especially important for Apple's first-login-only name)
        if ($userData->firstName !== null && $customer->getFirstName() === null) {
            $customer->setFirstName($userData->firstName);
        }
        if ($userData->lastName !== null && $customer->getLastName() === null) {
            $customer->setLastName($userData->lastName);
        }

        $this->entityManager->flush();
    }

    private function getOrCreateShopUser(CustomerInterface $customer, OAuthUserData $userData): ShopUserInterface
    {
        $shopUser = $customer->getUser();

        if ($shopUser !== null) {
            return $shopUser;
        }

        // Customer exists but has no ShopUser - create one
        /** @var ShopUserInterface $shopUser */
        $shopUser = $this->shopUserFactory->createNew();
        $shopUser->setCustomer($customer);
        $shopUser->setUsername($userData->email);
        $shopUser->setEnabled(true);

        // Generate a random password since OAuth users don't need one
        $shopUser->setPlainPassword(bin2hex(random_bytes(16)));

        $this->entityManager->persist($shopUser);
        $this->entityManager->flush();

        return $shopUser;
    }

    private function createNewUser(OAuthUserData $userData): ShopUserInterface
    {
        /** @var CustomerInterface&OAuthIdentityInterface $customer */
        $customer = $this->customerFactory->createNew();

        if (!$customer instanceof OAuthIdentityInterface) {
            throw new OAuthException(sprintf(
                'Customer entity must implement %s. Add "use OAuthIdentityTrait;" to your Customer class.',
                OAuthIdentityInterface::class
            ));
        }

        $customer->setEmail($userData->email);
        $customer->setFirstName($userData->firstName);
        $customer->setLastName($userData->lastName);

        // Link provider ID
        match ($userData->provider) {
            'google' => $customer->setGoogleId($userData->providerId),
            'apple' => $customer->setAppleId($userData->providerId),
            default => throw new OAuthException(sprintf('Unknown provider: %s', $userData->provider)),
        };

        /** @var ShopUserInterface $shopUser */
        $shopUser = $this->shopUserFactory->createNew();
        $shopUser->setCustomer($customer);
        $shopUser->setUsername($userData->email);
        $shopUser->setEnabled(true);

        // Generate a random password since OAuth users don't need one
        $shopUser->setPlainPassword(bin2hex(random_bytes(16)));

        $this->entityManager->persist($customer);
        $this->entityManager->persist($shopUser);
        $this->entityManager->flush();

        return $shopUser;
    }
}
