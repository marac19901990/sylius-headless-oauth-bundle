<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGeneratorInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppleProviderRefreshTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private AppleClientSecretGeneratorInterface&MockObject $clientSecretGenerator;
    private AppleProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->clientSecretGenerator = $this->createMock(AppleClientSecretGeneratorInterface::class);
        $this->clientSecretGenerator->method('generate')->willReturn('generated-client-secret');

        $this->provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: 'com.example.app',
            enabled: true,
        );
    }

    public function testSupportsRefresh(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testSuccessfulTokenRefresh(): void
    {
        // Create a valid id_token JWT
        $idTokenPayload = base64_encode(json_encode([
            'sub' => 'apple-user-123',
            'email' => 'john@privaterelay.appleid.com',
        ]));
        $idToken = "header.$idTokenPayload.signature";

        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'new-refresh-token',
            'id_token' => $idToken,
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://appleid.apple.com/auth/token',
                $this->callback(function ($options) {
                    return $options['form_params']['grant_type'] === 'refresh_token'
                        && $options['form_params']['refresh_token'] === 'original-refresh-token'
                        && $options['form_params']['client_secret'] === 'generated-client-secret';
                }),
            )
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-refresh-token');

        $this->assertInstanceOf(OAuthTokenData::class, $tokenData);
        $this->assertSame('new-access-token', $tokenData->accessToken);
        // Apple rotates refresh tokens
        $this->assertSame('new-refresh-token', $tokenData->refreshToken);
        $this->assertSame(3600, $tokenData->expiresIn);
        $this->assertSame($idToken, $tokenData->idToken);
    }

    public function testRefreshTokenThrowsExceptionOnMissingAccessToken(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
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
                new \GuzzleHttp\Psr7\Request('POST', 'https://appleid.apple.com/auth/token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh Apple tokens');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testGetUserDataFromIdToken(): void
    {
        $payload = base64_encode(json_encode([
            'sub' => 'apple-user-123',
            'email' => 'john@privaterelay.appleid.com',
        ]));
        $idToken = "header.$payload.signature";

        $userData = $this->provider->getUserDataFromIdToken($idToken);

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('apple', $userData->provider);
        $this->assertSame('apple-user-123', $userData->providerId);
        $this->assertSame('john@privaterelay.appleid.com', $userData->email);
    }

    public function testGetUserDataFromAccessTokenThrowsException(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Apple does not support fetching user data from access token');

        $this->provider->getUserDataFromAccessToken('some-access-token');
    }

    public function testGetUserDataIncludesRefreshToken(): void
    {
        $idTokenPayload = base64_encode(json_encode([
            'sub' => 'apple-user-123',
            'email' => 'john@privaterelay.appleid.com',
        ]));
        $idToken = "header.$idTokenPayload.signature";

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-token',
            'id_token' => $idToken,
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('test-refresh-token', $userData->refreshToken);
    }

    public function testConfigurableInterfaceMethods(): void
    {
        $this->assertSame('apple', $this->provider->getName());
        $this->assertTrue($this->provider->isEnabled());

        $credentials = $this->provider->getCredentialStatus();
        $this->assertTrue($credentials['client_id']);
    }

    public function testCredentialStatusWithMissingClientId(): void
    {
        $provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: '%env(APPLE_CLIENT_ID)%',
            enabled: false, // Disabled to avoid validation
        );

        $credentials = $provider->getCredentialStatus();
        $this->assertFalse($credentials['client_id']);
    }

    public function testGetUserDataFromIdTokenWithInvalidFormat(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Invalid Apple id_token format');

        $this->provider->getUserDataFromIdToken('invalid-token');
    }

    public function testGetUserDataFromIdTokenWithMissingClaims(): void
    {
        $payload = base64_encode(json_encode([
            'sub' => 'apple-user-123',
            // Missing 'email' claim
        ]));
        $idToken = "header.$payload.signature";

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required claims');

        $this->provider->getUserDataFromIdToken($idToken);
    }
}
