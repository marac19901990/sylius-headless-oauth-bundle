<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPreUserCreateEvent;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthProviderLinkedEvent;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\ProviderFieldMapper;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

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
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly FactoryInterface $customerFactory,
        private readonly FactoryInterface $shopUserFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProviderFieldMapper $fieldMapper,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function resolve(OAuthUserData $userData): UserResolveResult
    {
        // 1. Try to find by provider ID
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
        $field = $this->fieldMapper->getFieldName($provider);
        $result = $this->customerRepository->findOneBy([$field => $providerId]);

        return $result instanceof CustomerInterface ? $result : null;
    }

    private function linkProviderToCustomer(CustomerInterface $customer, OAuthUserData $userData): void
    {
        if (!$customer instanceof OAuthIdentityInterface) {
            throw new OAuthException(sprintf(
                'Customer entity must implement %s. Add "use OAuthIdentityTrait;" to your Customer class.',
                OAuthIdentityInterface::class,
            ));
        }

        $this->fieldMapper->setProviderId($customer, $userData->provider, $userData->providerId);

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
        $shopUser->setVerifiedAt(new DateTime());

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

        /** @var CustomerInterface&OAuthIdentityInterface $customer */
        $customer = $this->customerFactory->createNew();

        if (!$customer instanceof OAuthIdentityInterface) {
            throw new OAuthException(sprintf(
                'Customer entity must implement %s. Add "use OAuthIdentityTrait;" to your Customer class.',
                OAuthIdentityInterface::class,
            ));
        }

        $customer->setEmail($userData->email);
        $customer->setFirstName($userData->firstName);
        $customer->setLastName($userData->lastName);

        // Link provider ID
        $this->fieldMapper->setProviderId($customer, $userData->provider, $userData->providerId);

        /** @var ShopUserInterface $shopUser */
        $shopUser = $this->shopUserFactory->createNew();
        $shopUser->setCustomer($customer);
        $shopUser->setUsername($userData->email);
        $shopUser->setEnabled(true);

        // Mark as verified since OAuth providers have already verified the email
        $shopUser->setVerifiedAt(new DateTime());

        // Generate a random password since OAuth users don't need one
        $shopUser->setPlainPassword(bin2hex(random_bytes(16)));

        $this->entityManager->persist($customer);
        $this->entityManager->persist($shopUser);
        $this->entityManager->flush();

        return $shopUser;
    }
}
