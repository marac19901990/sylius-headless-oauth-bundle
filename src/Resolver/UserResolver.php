<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentity;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPreUserCreateEvent;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthProviderLinkedEvent;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Resolves or creates a ShopUser from OAuth user data.
 *
 * Resolution logic:
 * 1. Find by provider ID (via OAuthIdentity entity)
 * 2. If not found, find by email
 * 3. If found by email, link provider ID to existing customer
 * 4. If not found at all, create new Customer + ShopUser
 */
final class UserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly FactoryInterface $customerFactory,
        private readonly FactoryInterface $shopUserFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthIdentityRepositoryInterface $oauthIdentityRepository,
        private readonly ClockInterface $clock,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function resolve(OAuthUserData $userData): UserResolveResult
    {
        // 1. Try to find by provider ID (via OAuthIdentity)
        $customer = $this->findByProviderId($userData->provider, $userData->providerId);

        if ($customer !== null) {
            return new UserResolveResult(
                shopUser: $this->getOrCreateShopUser($customer, $userData),
                isNewUser: false,
            );
        }

        // 2. Try to find by email
        $customerByEmail = $this->customerRepository->findOneBy(['email' => $userData->email]);

        if ($customerByEmail instanceof CustomerInterface) {
            // 3. Link provider ID to existing customer
            $this->linkProviderToCustomer($customerByEmail, $userData);

            return new UserResolveResult(
                shopUser: $this->getOrCreateShopUser($customerByEmail, $userData),
                isNewUser: false,
            );
        }

        // 4. Create new Customer + ShopUser
        return new UserResolveResult(
            shopUser: $this->createNewUser($userData),
            isNewUser: true,
        );
    }

    private function findByProviderId(string $provider, string $providerId): ?CustomerInterface
    {
        $oauthIdentity = $this->oauthIdentityRepository->findByProviderIdentifier($provider, $providerId);

        return $oauthIdentity?->getCustomer();
    }

    private function linkProviderToCustomer(CustomerInterface $customer, OAuthUserData $userData): void
    {
        $oauthIdentity = new OAuthIdentity();
        $oauthIdentity->setProvider($userData->provider);
        $oauthIdentity->setIdentifier($userData->providerId);
        $oauthIdentity->setCustomer($customer);
        $oauthIdentity->setConnectedAt($this->clock->now());

        $this->entityManager->persist($oauthIdentity);

        // Update name if not set and we have data (especially important for Apple's first-login-only name)
        if ($userData->firstName !== null && $customer->getFirstName() === null) {
            $customer->setFirstName($userData->firstName);
        }
        if ($userData->lastName !== null && $customer->getLastName() === null) {
            $customer->setLastName($userData->lastName);
        }

        $this->entityManager->flush();

        // Dispatch event for provider linking
        $this->eventDispatcher?->dispatch(
            new OAuthProviderLinkedEvent($customer, $userData->provider, $userData->providerId),
            OAuthProviderLinkedEvent::NAME,
        );
    }

    private function getOrCreateShopUser(CustomerInterface $customer, OAuthUserData $userData): ShopUserInterface
    {
        $shopUser = $customer->getUser();

        if ($shopUser instanceof ShopUserInterface) {
            return $shopUser;
        }

        // Customer exists but has no ShopUser - create one
        /** @var ShopUserInterface $shopUser */
        $shopUser = $this->shopUserFactory->createNew();
        $shopUser->setCustomer($customer);
        $shopUser->setUsername($userData->email);
        $shopUser->setEnabled(true);

        // Mark as verified since OAuth providers have already verified the email
        $shopUser->setVerifiedAt($this->clock->now());

        // Generate a random password since OAuth users don't need one
        $shopUser->setPlainPassword(bin2hex(random_bytes(16)));

        $this->entityManager->persist($shopUser);
        $this->entityManager->flush();

        return $shopUser;
    }

    private function createNewUser(OAuthUserData $userData): ShopUserInterface
    {
        // Dispatch pre-create event
        $event = new OAuthPreUserCreateEvent($userData);
        $this->eventDispatcher?->dispatch($event, OAuthPreUserCreateEvent::NAME);

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->createNew();

        $customer->setEmail($userData->email);
        $customer->setFirstName($userData->firstName);
        $customer->setLastName($userData->lastName);

        // Create OAuth identity to link provider
        $oauthIdentity = new OAuthIdentity();
        $oauthIdentity->setProvider($userData->provider);
        $oauthIdentity->setIdentifier($userData->providerId);
        $oauthIdentity->setCustomer($customer);
        $oauthIdentity->setConnectedAt($this->clock->now());

        /** @var ShopUserInterface $shopUser */
        $shopUser = $this->shopUserFactory->createNew();
        $shopUser->setCustomer($customer);
        $shopUser->setUsername($userData->email);
        $shopUser->setEnabled(true);

        // Mark as verified since OAuth providers have already verified the email
        $shopUser->setVerifiedAt($this->clock->now());

        // Generate a random password since OAuth users don't need one
        $shopUser->setPlainPassword(bin2hex(random_bytes(16)));

        $this->entityManager->persist($customer);
        $this->entityManager->persist($oauthIdentity);
        $this->entityManager->persist($shopUser);
        $this->entityManager->flush();

        return $shopUser;
    }
}
