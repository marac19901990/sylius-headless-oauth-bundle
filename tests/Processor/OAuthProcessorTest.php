<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Exception\ProviderNotSupportedException;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthProcessor;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

class OAuthProcessorTest extends TestCase
{
    private OAuthProviderInterface&MockObject $googleProvider;
    private OAuthProviderInterface&MockObject $appleProvider;
    private UserResolverInterface&MockObject $userResolver;
    private JWTTokenManagerInterface&MockObject $jwtManager;
    private OAuthProcessor $processor;
    private Operation $operation;

    protected function setUp(): void
    {
        $this->googleProvider = $this->createMock(OAuthProviderInterface::class);
        $this->appleProvider = $this->createMock(OAuthProviderInterface::class);
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);

        // Configure provider supports() methods
        $this->googleProvider->method('supports')
            ->willReturnCallback(fn(string $p) => strtolower($p) === 'google');
        $this->appleProvider->method('supports')
            ->willReturnCallback(fn(string $p) => strtolower($p) === 'apple');

        $this->processor = new OAuthProcessor(
            providers: [$this->googleProvider, $this->appleProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $this->operation = new Post();
    }

    public function testProcessesGoogleAuthSuccessfully(): void
    {
        $request = new OAuthRequest();
        $request->code = 'google-auth-code';
        $request->redirectUri = 'https://example.com/callback';

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-user-123',
            email: 'user@gmail.com',
            firstName: 'Test',
            lastName: 'User',
        );

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        $this->googleProvider
            ->expects($this->once())
            ->method('getUserData')
            ->with('google-auth-code', 'https://example.com/callback')
            ->willReturn($userData);

        $this->userResolver
            ->expects($this->once())
            ->method('resolve')
            ->with($userData)
            ->willReturn($shopUser);

        $this->jwtManager
            ->expects($this->once())
            ->method('create')
            ->with($shopUser)
            ->willReturn('jwt-token-123');

        $response = $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'google'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('jwt-token-123', $response->token);
        $this->assertSame(42, $response->customerId);
        $this->assertNull($response->refreshToken);
    }

    public function testProcessesAppleAuthSuccessfully(): void
    {
        $request = new OAuthRequest();
        $request->code = 'apple-auth-code';
        $request->redirectUri = 'https://example.com/apple/callback';

        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-user-456',
            email: 'user@privaterelay.appleid.com',
        );

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(99);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        $this->appleProvider
            ->expects($this->once())
            ->method('getUserData')
            ->with('apple-auth-code', 'https://example.com/apple/callback')
            ->willReturn($userData);

        $this->userResolver
            ->expects($this->once())
            ->method('resolve')
            ->with($userData)
            ->willReturn($shopUser);

        $this->jwtManager
            ->expects($this->once())
            ->method('create')
            ->with($shopUser)
            ->willReturn('apple-jwt-token');

        $response = $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'apple'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('apple-jwt-token', $response->token);
        $this->assertSame(99, $response->customerId);
    }

    public function testThrowsExceptionForUnsupportedProvider(): void
    {
        $request = new OAuthRequest();
        $request->code = 'some-code';
        $request->redirectUri = 'https://example.com/callback';

        $this->expectException(ProviderNotSupportedException::class);
        $this->expectExceptionMessage('facebook');

        $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'facebook'],
        );
    }

    public function testThrowsExceptionForEmptyProvider(): void
    {
        $request = new OAuthRequest();
        $request->code = 'some-code';
        $request->redirectUri = 'https://example.com/callback';

        $this->expectException(ProviderNotSupportedException::class);

        $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => ''],
        );
    }

    public function testThrowsExceptionForMissingProvider(): void
    {
        $request = new OAuthRequest();
        $request->code = 'some-code';
        $request->redirectUri = 'https://example.com/callback';

        $this->expectException(ProviderNotSupportedException::class);

        $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: [], // No provider
        );
    }

    public function testHandlesCaseInsensitiveProviderName(): void
    {
        $request = new OAuthRequest();
        $request->code = 'google-auth-code';
        $request->redirectUri = 'https://example.com/callback';

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-user-case',
            email: 'case@gmail.com',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn(null);

        $this->googleProvider
            ->expects($this->once())
            ->method('getUserData')
            ->willReturn($userData);

        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('token');

        // Test with uppercase GOOGLE
        $response = $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'GOOGLE'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
    }

    public function testHandlesCustomerWithNullId(): void
    {
        $request = new OAuthRequest();
        $request->code = 'some-code';
        $request->redirectUri = 'https://example.com/callback';

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-no-customer-id',
            email: 'nocustomerid@gmail.com',
        );

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(null); // New customer without persisted ID

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        $this->googleProvider->method('getUserData')->willReturn($userData);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('token');

        $response = $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'google'],
        );

        $this->assertNull($response->customerId);
    }

    public function testHandlesShopUserWithNullCustomer(): void
    {
        $request = new OAuthRequest();
        $request->code = 'some-code';
        $request->redirectUri = 'https://example.com/callback';

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-no-customer',
            email: 'nocustomer@gmail.com',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn(null);

        $this->googleProvider->method('getUserData')->willReturn($userData);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('token');

        $response = $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'google'],
        );

        $this->assertNull($response->customerId);
    }

    public function testSelectsCorrectProviderWhenMultipleAvailable(): void
    {
        $request = new OAuthRequest();
        $request->code = 'apple-specific-code';
        $request->redirectUri = 'https://example.com/callback';

        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-multi',
            email: 'multi@icloud.com',
        );

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn(null);

        // Google provider should NOT be called
        $this->googleProvider
            ->expects($this->never())
            ->method('getUserData');

        // Apple provider should be called
        $this->appleProvider
            ->expects($this->once())
            ->method('getUserData')
            ->willReturn($userData);

        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('token');

        $this->processor->process(
            data: $request,
            operation: $this->operation,
            uriVariables: ['provider' => 'apple'],
        );
    }
}
