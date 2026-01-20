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
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\RefreshableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

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
        $tokenData = new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'same-refresh-token',
            expiresIn: 3600,
        );

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-123',
            email: 'user@gmail.com',
        );

        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn($tokenData);
        $provider->method('getUserDataFromTokenData')->with($tokenData)->willReturn($userData);

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

    public function testSuccessfulAppleTokenRefresh(): void
    {
        $tokenData = new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'new-refresh-token',
            expiresIn: 3600,
            idToken: 'apple-id-token',
        );

        $userData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-456',
            email: 'user@privaterelay.appleid.com',
        );

        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn($tokenData);
        $provider->method('getUserDataFromTokenData')->with($tokenData)->willReturn($userData);

        $shopUser = $this->createMockShopUser(456);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('apple-jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'original-apple-refresh-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'apple'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('apple-jwt-token', $response->token);
        $this->assertSame('new-refresh-token', $response->refreshToken);
        $this->assertSame(456, $response->customerId);
    }

    public function testSuccessfulRefreshWithProviderReturningUserData(): void
    {
        $tokenData = new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'new-refresh-token',
            expiresIn: 3600,
            idToken: 'some-id-token',
        );

        $userData = new OAuthUserData(
            provider: 'custom',
            providerId: 'custom-user-456',
            email: 'user@example.com',
        );

        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn($tokenData);
        $provider->method('getUserDataFromTokenData')->willReturn($userData);

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

    public function testHandlesMissingProviderInUriVariables(): void
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
            [], // Missing provider key
        );
    }

    public function testHandlesEmptyProviderName(): void
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
            ['provider' => ''],
        );
    }

    public function testHandlesNullRefreshTokenInResponse(): void
    {
        $tokenData = new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: null, // Provider doesn't return new refresh token
            expiresIn: 3600,
        );

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-789',
            email: 'user@gmail.com',
        );

        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn($tokenData);
        $provider->method('getUserDataFromTokenData')->willReturn($userData);

        $shopUser = $this->createMockShopUser(789);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'original-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'google'],
        );

        $this->assertNull($response->refreshToken);
    }

    public function testHandlesShopUserWithNullCustomer(): void
    {
        $tokenData = new OAuthTokenData(
            accessToken: 'new-access-token',
            refreshToken: 'refresh-token',
        );

        $userData = new OAuthUserData(
            provider: 'google',
            providerId: 'google-999',
            email: 'user@gmail.com',
        );

        $provider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('supportsRefresh')->willReturn(true);
        $provider->method('refreshTokens')->willReturn($tokenData);
        $provider->method('getUserDataFromTokenData')->willReturn($userData);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn(null);

        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$provider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'original-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'google'],
        );

        $this->assertNull($response->customerId);
    }

    public function testSelectsCorrectProviderFromMultiple(): void
    {
        $googleProvider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $googleProvider->method('supports')
            ->willReturnCallback(fn (string $p) => strtolower($p) === 'google');
        $googleProvider->expects($this->never())->method('refreshTokens');

        $appleTokenData = new OAuthTokenData(
            accessToken: 'apple-access-token',
            refreshToken: 'apple-refresh-token',
            idToken: 'apple-id-token',
        );

        $appleUserData = new OAuthUserData(
            provider: 'apple',
            providerId: 'apple-multi',
            email: 'user@icloud.com',
        );

        $appleProvider = $this->createMock(RefreshableOAuthProviderInterface::class);
        $appleProvider->method('supports')
            ->willReturnCallback(fn (string $p) => strtolower($p) === 'apple');
        $appleProvider->method('supportsRefresh')->willReturn(true);
        $appleProvider->expects($this->once())->method('refreshTokens')->willReturn($appleTokenData);
        $appleProvider->method('getUserDataFromTokenData')->willReturn($appleUserData);

        $shopUser = $this->createMockShopUser(111);
        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('jwt-token');

        $processor = new OAuthRefreshProcessor(
            providers: [$googleProvider, $appleProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'apple-token';

        $response = $processor->process(
            $request,
            new Post(),
            ['provider' => 'apple'],
        );

        $this->assertSame('apple-refresh-token', $response->refreshToken);
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
