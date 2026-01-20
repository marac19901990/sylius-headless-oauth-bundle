<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Integration;

use ApiPlatform\Metadata\Post;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRefreshRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthProcessor;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthRefreshProcessor;
use Marac\SyliusHeadlessOAuthBundle\Provider\GoogleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

/**
 * Integration tests for the full OAuth flow.
 *
 * These tests verify that all components work together correctly:
 * - Provider correctly exchanges code for tokens
 * - Processor orchestrates the full authentication flow
 * - User resolver is called with correct data
 * - JWT token is generated and returned
 */
final class FullOAuthFlowTest extends TestCase
{
    private UserResolverInterface&MockObject $userResolver;
    private JWTTokenManagerInterface&MockObject $jwtManager;

    protected function setUp(): void
    {
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
    }

    #[Test]
    public function fullGoogleAuthenticationFlow(): void
    {
        // Setup mock HTTP responses
        $mockHandler = new MockHandler([
            // Token exchange response
            new Response(200, [], json_encode([
                'access_token' => 'google-access-token-123',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'google-refresh-token-456',
            ])),
            // User info response
            new Response(200, [], json_encode([
                'id' => 'google-user-id-789',
                'email' => 'integration@gmail.com',
                'given_name' => 'Integration',
                'family_name' => 'Test',
                'verified_email' => true,
            ])),
        ]);

        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $googleProvider = new GoogleProvider(
            httpClient: $httpClient,
            clientId: 'test-google-client-id',
            clientSecret: 'test-google-client-secret',
            enabled: true,
        );

        // Setup user resolver mock
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        $this->userResolver
            ->expects($this->once())
            ->method('resolve')
            ->with($this->callback(function (OAuthUserData $userData) {
                return $userData->provider === 'google'
                    && $userData->providerId === 'google-user-id-789'
                    && $userData->email === 'integration@gmail.com'
                    && $userData->firstName === 'Integration'
                    && $userData->lastName === 'Test'
                    && $userData->refreshToken === 'google-refresh-token-456';
            }))
            ->willReturn($shopUser);

        $this->jwtManager
            ->expects($this->once())
            ->method('create')
            ->with($shopUser)
            ->willReturn('jwt-token-for-user');

        // Create processor and execute flow
        $processor = new OAuthProcessor(
            providers: [$googleProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRequest();
        $request->code = 'google-authorization-code';
        $request->redirectUri = 'https://app.example.com/oauth/callback';

        $response = $processor->process(
            data: $request,
            operation: new Post(),
            uriVariables: ['provider' => 'google'],
        );

        // Verify response
        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('jwt-token-for-user', $response->token);
        $this->assertSame(42, $response->customerId);
        $this->assertSame('google-refresh-token-456', $response->refreshToken);
    }

    #[Test]
    public function fullGoogleRefreshFlow(): void
    {
        // Setup mock HTTP responses for token refresh
        $mockHandler = new MockHandler([
            // Token refresh response
            new Response(200, [], json_encode([
                'access_token' => 'new-google-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
            // User info response (called via getUserDataFromTokenData)
            new Response(200, [], json_encode([
                'id' => 'google-user-id-refresh',
                'email' => 'refresh@gmail.com',
                'given_name' => 'Refresh',
                'family_name' => 'User',
            ])),
        ]);

        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $googleProvider = new GoogleProvider(
            httpClient: $httpClient,
            clientId: 'test-google-client-id',
            clientSecret: 'test-google-client-secret',
            enabled: true,
        );

        // Setup user resolver mock
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(99);

        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn($customer);

        $this->userResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($shopUser);

        $this->jwtManager
            ->expects($this->once())
            ->method('create')
            ->with($shopUser)
            ->willReturn('refreshed-jwt-token');

        // Create refresh processor and execute flow
        $processor = new OAuthRefreshProcessor(
            providers: [$googleProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRefreshRequest();
        $request->refreshToken = 'existing-refresh-token';

        $response = $processor->process(
            data: $request,
            operation: new Post(),
            uriVariables: ['provider' => 'google'],
        );

        // Verify response
        $this->assertInstanceOf(OAuthResponse::class, $response);
        $this->assertSame('refreshed-jwt-token', $response->token);
        // Google reuses refresh tokens
        $this->assertSame('existing-refresh-token', $response->refreshToken);
        $this->assertSame(99, $response->customerId);
    }

    #[Test]
    public function multipleProvidersAreCorrectlyRouted(): void
    {
        // Setup mock HTTP for Google (should NOT be called)
        $googleMockHandler = new MockHandler([]);
        $googleHttpClient = new Client(['handler' => HandlerStack::create($googleMockHandler)]);

        $googleProvider = new GoogleProvider(
            httpClient: $googleHttpClient,
            clientId: 'google-client-id',
            clientSecret: 'google-client-secret',
            enabled: true,
        );

        // Create a mock provider for 'custom' that will be selected instead of Google
        $customProvider = $this->createMock(\Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface::class);
        $customProvider->method('supports')
            ->willReturnCallback(fn (string $p) => strtolower($p) === 'custom');
        $customProvider->method('getUserData')
            ->willReturn(new OAuthUserData(
                provider: 'custom',
                providerId: 'custom-user-id',
                email: 'custom@example.com',
            ));

        // Setup user resolver mock
        $shopUser = $this->createMock(ShopUserInterface::class);
        $shopUser->method('getCustomer')->willReturn(null);

        $this->userResolver->method('resolve')->willReturn($shopUser);
        $this->jwtManager->method('create')->willReturn('token');

        // Create processor with multiple providers
        $processor = new OAuthProcessor(
            providers: [$googleProvider, $customProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRequest();
        $request->code = 'custom-auth-code';
        $request->redirectUri = 'https://example.com/callback';

        // This should route to custom provider, not Google
        $response = $processor->process(
            data: $request,
            operation: new Post(),
            uriVariables: ['provider' => 'custom'],
        );

        $this->assertInstanceOf(OAuthResponse::class, $response);
    }

    #[Test]
    public function errorResponsesArePropagatedCorrectly(): void
    {
        // Setup mock HTTP to return an error
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'The authorization code has expired.',
            ])),
        ]);

        $httpClient = new Client(['handler' => HandlerStack::create($mockHandler)]);

        $googleProvider = new GoogleProvider(
            httpClient: $httpClient,
            clientId: 'test-google-client-id',
            clientSecret: 'test-google-client-secret',
            enabled: true,
        );

        $processor = new OAuthProcessor(
            providers: [$googleProvider],
            userResolver: $this->userResolver,
            jwtManager: $this->jwtManager,
        );

        $request = new OAuthRequest();
        $request->code = 'expired-code';
        $request->redirectUri = 'https://example.com/callback';

        $this->expectException(\Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException::class);

        $processor->process(
            data: $request,
            operation: new Post(),
            uriVariables: ['provider' => 'google'],
        );
    }
}
