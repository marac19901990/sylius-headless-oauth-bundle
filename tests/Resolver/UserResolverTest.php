<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Resolver;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPreUserCreateEvent;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthProviderLinkedEvent;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\ProviderFieldMapper;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolver;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolveResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class UserResolverTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private FactoryInterface&MockObject $customerFactory;
    private FactoryInterface&MockObject $shopUserFactory;
    private EntityManagerInterface&MockObject $entityManager;
    private ProviderFieldMapper $fieldMapper;
    private ClockInterface $clock;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private UserResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory = $this->createMock(FactoryInterface::class);
        $this->shopUserFactory = $this->createMock(FactoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fieldMapper = new ProviderFieldMapper();
        $this->clock = new MockClock();
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->resolver = new UserResolver(
            customerRepository: $this->customerRepository,
            customerFactory: $this->customerFactory,
            shopUserFactory: $this->shopUserFactory,
            entityManager: $this->entityManager,
            fieldMapper: $this->fieldMapper,
            clock: $this->clock,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    public function testFindsExistingUserByGoogleId(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-123',
            email: 'existing@example.com',
            firstName: 'John',
            lastName: 'Doe',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);

        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['googleId' => 'google-123'])
            ->willReturn($customer);

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($shopUser, $result->shopUser);
        $this->assertFalse($result->isNewUser);
    }

    public function testFindsExistingUserByAppleId(): void
    {
        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-456',
            email: 'existing@example.com',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);

        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['appleId' => 'apple-456'])
            ->willReturn($customer);

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($shopUser, $result->shopUser);
        $this->assertFalse($result->isNewUser);
    }

    public function testLinksProviderToExistingCustomerFoundByEmail(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-new-789',
            email: 'email-match@example.com',
            firstName: 'Jane',
            lastName: 'Smith',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Jane');
        $customer->method('getLastName')->willReturn('Smith');

        // First lookup by provider ID returns null
        $this->customerRepository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($customer) {
                if (isset($criteria['googleId'])) {
                    return null; // Not found by provider ID
                }
                if (isset($criteria['email'])) {
                    return $customer; // Found by email
                }

                return null;
            });

        // Expect the provider ID to be linked
        $customer->expects($this->once())
            ->method('setGoogleId')
            ->with('google-new-789');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        // Expect provider linked event to be dispatched
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(OAuthProviderLinkedEvent::class),
                OAuthProviderLinkedEvent::NAME,
            );

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($shopUser, $result->shopUser);
        $this->assertFalse($result->isNewUser);
    }

    public function testCreatesNewCustomerAndShopUser(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-brand-new',
            email: 'newuser@example.com',
            firstName: 'New',
            lastName: 'User',
        );

        // Not found by provider ID or email
        $this->customerRepository
            ->method('findOneBy')
            ->willReturn(null);

        $newCustomer = $this->createCustomerWithOAuthMock();
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory
            ->expects($this->once())
            ->method('createNew')
            ->willReturn($newCustomer);

        $this->shopUserFactory
            ->expects($this->once())
            ->method('createNew')
            ->willReturn($newShopUser);

        // Expect customer to be configured
        $newCustomer->expects($this->once())->method('setEmail')->with('newuser@example.com');
        $newCustomer->expects($this->once())->method('setFirstName')->with('New');
        $newCustomer->expects($this->once())->method('setLastName')->with('User');
        $newCustomer->expects($this->once())->method('setGoogleId')->with('google-brand-new');

        // Expect shop user to be configured
        $newShopUser->expects($this->once())->method('setCustomer')->with($newCustomer);
        $newShopUser->expects($this->once())->method('setUsername')->with('newuser@example.com');
        $newShopUser->expects($this->once())->method('setEnabled')->with(true);
        $newShopUser->expects($this->once())->method('setVerifiedAt')->with($this->isInstanceOf(DateTimeInterface::class));
        $newShopUser->expects($this->once())->method('setPlainPassword')->with($this->isType('string'));

        // Expect pre-create event to be dispatched
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(OAuthPreUserCreateEvent::class),
                OAuthPreUserCreateEvent::NAME,
            );

        // Expect persistence
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($newShopUser, $result->shopUser);
        $this->assertTrue($result->isNewUser);
    }

    public function testCreatesShopUserForCustomerWithoutUser(): void
    {
        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-existing-no-user',
            email: 'customeronly@example.com',
        );

        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn(null); // Customer exists but no ShopUser

        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['appleId' => 'apple-existing-no-user'])
            ->willReturn($customer);

        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->shopUserFactory
            ->expects($this->once())
            ->method('createNew')
            ->willReturn($newShopUser);

        $newShopUser->expects($this->once())->method('setCustomer')->with($customer);
        $newShopUser->expects($this->once())->method('setUsername')->with('customeronly@example.com');
        $newShopUser->expects($this->once())->method('setEnabled')->with(true);
        $newShopUser->expects($this->once())->method('setVerifiedAt')->with($this->isInstanceOf(DateTimeInterface::class));

        $this->entityManager->expects($this->once())->method('persist')->with($newShopUser);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($newShopUser, $result->shopUser);
        $this->assertFalse($result->isNewUser);
    }

    public function testUpdatesNameOnFirstAppleLogin(): void
    {
        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-first-login',
            email: 'firsttime@example.com',
            firstName: 'First',
            lastName: 'Timer',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn(null); // Name not set yet
        $customer->method('getLastName')->willReturn(null);

        // Found by email, not by provider ID
        $this->customerRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($customer) {
                if (isset($criteria['appleId'])) {
                    return null;
                }
                if (isset($criteria['email'])) {
                    return $customer;
                }

                return null;
            });

        // Expect name to be set since it was null
        $customer->expects($this->once())->method('setFirstName')->with('First');
        $customer->expects($this->once())->method('setLastName')->with('Timer');
        $customer->expects($this->once())->method('setAppleId')->with('apple-first-login');

        // Provider linked event should be dispatched
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(OAuthProviderLinkedEvent::class),
                OAuthProviderLinkedEvent::NAME,
            );

        $result = $this->resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertSame($shopUser, $result->shopUser);
        $this->assertFalse($result->isNewUser);
    }

    public function testDoesNotOverwriteExistingName(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-with-name',
            email: 'hasname@example.com',
            firstName: 'OAuth',
            lastName: 'Name',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Existing'); // Already has name
        $customer->method('getLastName')->willReturn('Name');

        $this->customerRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($customer) {
                if (isset($criteria['googleId'])) {
                    return null;
                }
                if (isset($criteria['email'])) {
                    return $customer;
                }

                return null;
            });

        // Name should NOT be overwritten
        $customer->expects($this->never())->method('setFirstName');
        $customer->expects($this->never())->method('setLastName');

        $this->resolver->resolve($userData);
    }

    public function testUnknownProviderUsesOidcIdField(): void
    {
        // Unknown providers (like custom OIDC) should fall back to using the oidcId field
        $userData = new OAuthUserData(
            provider: 'keycloak',
            providerId: 'keycloak-user-123',
            email: 'keycloak-user@example.com',
        );

        // Setup: new user flow - not found by provider ID or email
        $this->customerRepository
            ->method('findOneBy')
            ->willReturn(null);

        // Create a mock that implements both interfaces
        $customer = $this->createMock(CustomerWithOAuth::class);

        // Track what values are set
        $oidcIdSet = null;

        $customer->method('getUser')->willReturn(null);
        $customer->method('setOidcId')->willReturnCallback(function ($id) use (&$oidcIdSet): void {
            $oidcIdSet = $id;
        });

        $this->customerFactory
            ->method('createNew')
            ->willReturn($customer);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('setUsername')->willReturnSelf();
        $shopUser->method('setPlainPassword')->willReturnSelf();
        $shopUser->method('setEnabled')->willReturnSelf();

        $this->shopUserFactory
            ->method('createNew')
            ->willReturn($shopUser);

        $result = $this->resolver->resolve($userData);

        // Verify the oidcId field was set (unknown providers fall back to oidcId)
        self::assertSame('keycloak-user-123', $oidcIdSet);
        self::assertTrue($result->isNewUser);
    }

    public function testThrowsExceptionWhenCustomerDoesNotImplementInterface(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-interface-test',
            email: 'interface@example.com',
        );

        // Not found by provider ID, found by email
        $regularCustomer = $this->createMock(CustomerInterface::class);
        $regularCustomer->method('getUser')->willReturn($this->createMock(ShopUserInterface::class));

        $this->customerRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($regularCustomer) {
                if (isset($criteria['googleId'])) {
                    return null;
                }
                if (isset($criteria['email'])) {
                    return $regularCustomer; // Returns customer without OAuthIdentityInterface
                }

                return null;
            });

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('must implement');

        $this->resolver->resolve($userData);
    }

    public function testSetsVerifiedAtOnNewUser(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-verified-test',
            email: 'verified@example.com',
            firstName: 'Verified',
            lastName: 'User',
        );

        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createCustomerWithOAuthMock();
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        // The key assertion: setVerifiedAt should be called with a DateTimeInterface
        $newShopUser
            ->expects($this->once())
            ->method('setVerifiedAt')
            ->with($this->callback(function ($dateTime) {
                return $dateTime instanceof DateTimeInterface
                    && abs($dateTime->getTimestamp() - time()) < 5; // Within 5 seconds
            }));

        $this->resolver->resolve($userData);
    }

    public function testDispatchesPreUserCreateEventWithUserData(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-event-test',
            email: 'event@example.com',
            firstName: 'Event',
            lastName: 'Test',
        );

        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createCustomerWithOAuthMock();
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function ($event) use ($userData) {
                    return $event instanceof OAuthPreUserCreateEvent
                        && $event->userData === $userData;
                }),
                OAuthPreUserCreateEvent::NAME,
            );

        $this->resolver->resolve($userData);
    }

    public function testDispatchesProviderLinkedEventWithCorrectData(): void
    {
        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-link-test',
            email: 'link@example.com',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $customer = $this->createCustomerWithOAuthMock();
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Existing');
        $customer->method('getLastName')->willReturn('Name');

        $this->customerRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($customer) {
                if (isset($criteria['googleId'])) {
                    return null;
                }
                if (isset($criteria['email'])) {
                    return $customer;
                }

                return null;
            });

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function ($event) use ($customer) {
                    return $event instanceof OAuthProviderLinkedEvent
                        && $event->customer === $customer
                        && $event->provider === 'google'
                        && $event->providerId === 'google-link-test';
                }),
                OAuthProviderLinkedEvent::NAME,
            );

        $this->resolver->resolve($userData);
    }

    public function testWorksWithoutEventDispatcher(): void
    {
        // Create resolver without event dispatcher
        $resolver = new UserResolver(
            customerRepository: $this->customerRepository,
            customerFactory: $this->customerFactory,
            shopUserFactory: $this->shopUserFactory,
            entityManager: $this->entityManager,
            fieldMapper: $this->fieldMapper,
            clock: $this->clock,
            eventDispatcher: null, // No event dispatcher
        );

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-no-dispatcher',
            email: 'nodispatcher@example.com',
        );

        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createCustomerWithOAuthMock();
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        // Should not throw even without event dispatcher
        $result = $resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertTrue($result->isNewUser);
    }

    /**
     * Creates a mock that implements both CustomerInterface and OAuthIdentityInterface.
     */
    private function createCustomerWithOAuthMock(): CustomerInterface&OAuthIdentityInterface&MockObject
    {
        return $this->createMock(CustomerWithOAuth::class);
    }
}

/**
 * Helper interface for creating mocks that implement both required interfaces.
 */
interface CustomerWithOAuth extends CustomerInterface, OAuthIdentityInterface
{
}
