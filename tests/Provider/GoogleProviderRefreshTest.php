<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\GoogleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GoogleProviderRefreshTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private GoogleProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->provider = new GoogleProvider(
            httpClient: $this->httpClient,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testSupportsRefresh(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testSuccessfulTokenRefresh(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://oauth2.googleapis.com/token',
                $this->callback(function ($options) {
                    return $options['form_params']['grant_type'] === 'refresh_token'
                        && $options['form_params']['refresh_token'] === 'original-refresh-token';
                })
            )
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-refresh-token');

        $this->assertInstanceOf(OAuthTokenData::class, $tokenData);
        $this->assertSame('new-access-token', $tokenData->accessToken);
        // Google reuses the same refresh token
        $this->assertSame('original-refresh-token', $tokenData->refreshToken);
        $this->assertSame(3600, $tokenData->expiresIn);
        $this->assertSame('Bearer', $tokenData->tokenType);
    }

    public function testRefreshTokenThrowsExceptionOnMissingAccessToken(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->refreshTokens('invalid-refresh-token');
    }

    public function testRefreshTokenThrowsExceptionOnNetworkError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token')
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh Google tokens');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testGetUserDataFromAccessToken(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-123',
            'email' => 'john.doe@gmail.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://www.googleapis.com/oauth2/v2/userinfo',
                $this->callback(function ($options) {
                    return $options['headers']['Authorization'] === 'Bearer test-access-token';
                })
            )
            ->willReturn($userInfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('google', $userData->provider);
        $this->assertSame('google-user-123', $userData->providerId);
        $this->assertSame('john.doe@gmail.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
    }

    public function testGetUserDataIncludesRefreshToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-token',
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-123',
            'email' => 'john.doe@gmail.com',
        ]));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('test-refresh-token', $userData->refreshToken);
    }

    public function testConfigurableInterfaceMethods(): void
    {
        $this->assertSame('google', $this->provider->getName());
        $this->assertTrue($this->provider->isEnabled());

        $credentials = $this->provider->getCredentialStatus();
        $this->assertTrue($credentials['client_id']);
        $this->assertTrue($credentials['client_secret']);
    }

    public function testCredentialStatusWithMissingCredentials(): void
    {
        $provider = new GoogleProvider(
            httpClient: $this->httpClient,
            clientId: '%env(GOOGLE_CLIENT_ID)%',
            clientSecret: 'valid-secret',
            enabled: false, // Disabled to avoid validation
        );

        $credentials = $provider->getCredentialStatus();
        $this->assertFalse($credentials['client_id']);
        $this->assertTrue($credentials['client_secret']);
    }
}
