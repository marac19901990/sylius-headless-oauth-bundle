<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Resolver;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentity;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPreUserCreateEvent;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthProviderLinkedEvent;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
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
    private OAuthIdentityRepositoryInterface&MockObject $oauthIdentityRepository;
    private ClockInterface $clock;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private UserResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory = $this->createMock(FactoryInterface::class);
        $this->shopUserFactory = $this->createMock(FactoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->oauthIdentityRepository = $this->createMock(OAuthIdentityRepositoryInterface::class);
        $this->clock = new MockClock();
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->resolver = new UserResolver(
            customerRepository: $this->customerRepository,
            customerFactory: $this->customerFactory,
            shopUserFactory: $this->shopUserFactory,
            entityManager: $this->entityManager,
            oauthIdentityRepository: $this->oauthIdentityRepository,
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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);

        $oauthIdentity = $this->createMock(OAuthIdentityInterface::class);
        $oauthIdentity->method('getCustomer')->willReturn($customer);

        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->with('google', 'google-123')
            ->willReturn($oauthIdentity);

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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);

        $oauthIdentity = $this->createMock(OAuthIdentityInterface::class);
        $oauthIdentity->method('getCustomer')->willReturn($customer);

        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->with('apple', 'apple-456')
            ->willReturn($oauthIdentity);

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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Jane');
        $customer->method('getLastName')->willReturn('Smith');

        // Not found by provider ID
        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->with('google', 'google-new-789')
            ->willReturn(null);

        // Found by email
        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'email-match@example.com'])
            ->willReturn($customer);

        // Expect the OAuthIdentity to be persisted
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(OAuthIdentity::class));

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

        // Not found by provider ID
        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->willReturn(null);

        // Not found by email
        $this->customerRepository
            ->method('findOneBy')
            ->willReturn(null);

        $newCustomer = $this->createMock(CustomerInterface::class);
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

        // Expect persistence of customer, oauth identity, and shop user
        $this->entityManager->expects($this->exactly(3))->method('persist');
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

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn(null); // Customer exists but no ShopUser

        $oauthIdentity = $this->createMock(OAuthIdentityInterface::class);
        $oauthIdentity->method('getCustomer')->willReturn($customer);

        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->with('apple', 'apple-existing-no-user')
            ->willReturn($oauthIdentity);

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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn(null); // Name not set yet
        $customer->method('getLastName')->willReturn(null);

        // Not found by provider ID
        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->willReturn(null);

        // Found by email
        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'firsttime@example.com'])
            ->willReturn($customer);

        // Expect name to be set since it was null
        $customer->expects($this->once())->method('setFirstName')->with('First');
        $customer->expects($this->once())->method('setLastName')->with('Timer');

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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Existing'); // Already has name
        $customer->method('getLastName')->willReturn('Name');

        // Not found by provider ID
        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->willReturn(null);

        // Found by email
        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn($customer);

        // Name should NOT be overwritten
        $customer->expects($this->never())->method('setFirstName');
        $customer->expects($this->never())->method('setLastName');

        $this->resolver->resolve($userData);
    }

    public function testUnknownProviderCreatesOAuthIdentity(): void
    {
        // Unknown providers (like custom OIDC) should create OAuthIdentity records
        $userData = new OAuthUserData(
            provider: 'keycloak',
            providerId: 'keycloak-user-123',
            email: 'keycloak-user@example.com',
        );

        // Setup: new user flow - not found by provider ID or email
        $this->oauthIdentityRepository
            ->method('findByProviderIdentifier')
            ->willReturn(null);

        $this->customerRepository
            ->method('findOneBy')
            ->willReturn(null);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn(null);

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

        // Track persisted entities
        $persistedEntities = [];
        $this->entityManager
            ->method('persist')
            ->willReturnCallback(static function ($entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });

        $result = $this->resolver->resolve($userData);

        // Verify an OAuthIdentity was persisted with correct provider and identifier
        $oauthIdentity = null;
        foreach ($persistedEntities as $entity) {
            if ($entity instanceof OAuthIdentity) {
                $oauthIdentity = $entity;

                break;
            }
        }

        $this->assertNotNull($oauthIdentity, 'OAuthIdentity should be persisted');
        $this->assertSame('keycloak', $oauthIdentity->getProvider());
        $this->assertSame('keycloak-user-123', $oauthIdentity->getIdentifier());
        $this->assertTrue($result->isNewUser);
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

        $this->oauthIdentityRepository->method('findByProviderIdentifier')->willReturn(null);
        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createMock(CustomerInterface::class);
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        // The key assertion: setVerifiedAt should be called with a DateTimeInterface
        $newShopUser
            ->expects($this->once())
            ->method('setVerifiedAt')
            ->with($this->callback(static function ($dateTime) {
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

        $this->oauthIdentityRepository->method('findByProviderIdentifier')->willReturn(null);
        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createMock(CustomerInterface::class);
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(static function ($event) use ($userData) {
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
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getUser')->willReturn($shopUser);
        $customer->method('getFirstName')->willReturn('Existing');
        $customer->method('getLastName')->willReturn('Name');

        // Not found by provider ID
        $this->oauthIdentityRepository
            ->expects($this->once())
            ->method('findByProviderIdentifier')
            ->willReturn(null);

        // Found by email
        $this->customerRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn($customer);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(static function ($event) use ($customer) {
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
            oauthIdentityRepository: $this->oauthIdentityRepository,
            clock: $this->clock,
            eventDispatcher: null, // No event dispatcher
        );

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-no-dispatcher',
            email: 'nodispatcher@example.com',
        );

        $this->oauthIdentityRepository->method('findByProviderIdentifier')->willReturn(null);
        $this->customerRepository->method('findOneBy')->willReturn(null);

        $newCustomer = $this->createMock(CustomerInterface::class);
        $newShopUser = $this->createMock(ShopUserInterface::class);

        $this->customerFactory->method('createNew')->willReturn($newCustomer);
        $this->shopUserFactory->method('createNew')->willReturn($newShopUser);

        // Should not throw even without event dispatcher
        $result = $resolver->resolve($userData);

        $this->assertInstanceOf(UserResolveResult::class, $result);
        $this->assertTrue($result->isNewUser);
    }
}
