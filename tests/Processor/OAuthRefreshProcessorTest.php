<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Processor;

use ApiPlatform\Metadata\Post;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRefreshRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Exception\ProviderNotSupportedException;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthRefreshProcessor;
use Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\RefreshableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

/**
 * Mock interface for Google-like providers (not AppleProvider).
 */
interface MockGoogleRefreshableProvider extends RefreshableOAuthProviderInterface
{
    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData;
}

/**
 * Mock interface for Apple providers.
 */
interface MockAppleRefreshableProvider extends RefreshableOAuthProviderInterface
{
    public function getUserDataFromIdToken(string $idToken): OAuthUserData;
}

class OAuthRefreshProcessorTest extends TestCase
{
    private UserResolverInterface&MockObject $userResolver;
    private JWTTokenManagerInterface&MockObject $jwtManager;

    protected function setUp(): void
    {
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
    }

    public function testSuccessfulGoogleTokenRefresh(): void
    {
        $provider = $this->createMock(MockGoogleRefreshableProvider::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn(new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'same-refresh-token',
            expiresIn: 3600,
        ));
        $provider->method('getUserDataFromAccessToken')->willReturn(new OAuthUserData(
            provider: 'google',
            providerId: 'google-123',
            email: 'user@gmail.com',
        ));

        $shopUser = $this->createMockShopUser(123);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('new-jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'original-refresh-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'google'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('new-jwt-token', $response->token);
        $this->assertSame('same-refresh-token', $response->refreshToken);
        $this->assertSame(123, $response->customerId);
    }

    public function testSuccessfulRefreshWithProviderReturningUserData(): void
    {
        // Test a generic provider that implements getUserDataFromAccessToken correctly
        $provider = $this->createMock(MockGoogleRefreshableProvider::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn(new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'new-refresh-token',
            expiresIn: 3600,
            idToken: 'some-id-token', // Include id_token but not AppleProvider
        ));
        $provider->method('getUserDataFromAccessToken')->willReturn(new OAuthUserData(
            provider: 'custom',
            providerId: 'custom-user-456',
            email: 'user@example.com',
        ));

        $shopUser = $this->createMockShopUser(456);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('new-jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'original-refresh-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'custom'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('new-jwt-token', $response->token);
        $this->assertSame('new-refresh-token', $response->refreshToken);
        $this->assertSame(456, $response->customerId);
    }

    public function testThrowsExceptionForUnsupportedProvider(): void
    {
        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(false);

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'some-token';

        $this->expectException(ProviderNotSupportedException::class);

        $processor->process(
            $request,
            new Post(),
            ['provider' => 'facebook'],
        );
    }

    public function testThrowsExceptionForNonRefreshableProvider(): void
    {
        $nonRefreshableProvider = $this->createMock(OAuthProviderInterface::class);
        $nonRefreshableProvider->method('supports')->willReturn(true);

        $processor = new OAuthRefreshProcessor(
            providers: [$nonRefreshableProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'some-token';

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not support token refresh');

        $processor->process(
            $request,
            new Post(),
            ['provider' => 'custom'],
        );
    }

    public function testThrowsExceptionForDisabledRefresh(): void
    {
        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(false);

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'some-token';

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('has refresh support disabled');

        $processor->process(
            $request,
            new Post(),
            ['provider' => 'custom'],
        );
    }

    private function createMockShopUser(int $customerId): ShopUserInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        return $shopUser;
    }
}
