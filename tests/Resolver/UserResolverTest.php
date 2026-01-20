<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

class UserResolverTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private FactoryInterface&MockObject $customerFactory;
    private FactoryInterface&MockObject $shopUserFactory;
    private EntityManagerInterface&MockObject $entityManager;
    private UserResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory = $this->createMock(FactoryInterface::class);
        $this->shopUserFactory = $this->createMock(FactoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->resolver = new UserResolver(
            customerRepository: $this->customerRepository,
            customerFactory: $this->customerFactory,
            shopUserFactory: $this->shopUserFactory,
            entityManager: $this->entityManager,
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

        $this->assertSame($shopUser, $result);
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

        $this->assertSame($shopUser, $result);
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

        $result = $this->resolver->resolve($userData);

        $this->assertSame($shopUser, $result);
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
        $newShopUser->expects($this->once())->method('setPlainPassword')->with($this->isType('string'));

        // Expect persistence
        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->resolver->resolve($userData);

        $this->assertSame($newShopUser, $result);
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

        $this->entityManager->expects($this->once())->method('persist')->with($newShopUser);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->resolver->resolve($userData);

        $this->assertSame($newShopUser, $result);
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

        $result = $this->resolver->resolve($userData);

        $this->assertSame($shopUser, $result);
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

    public function testThrowsExceptionForUnknownProvider(): void
    {
        $userData = new OAuthUserData(
            provider: 'unknown',
            providerId: 'unknown-123',
            email: 'test@example.com',
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unknown provider');

        $this->resolver->resolve($userData);
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
