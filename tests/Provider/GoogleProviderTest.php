<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\GoogleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

class GoogleProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private CredentialValidator $credentialValidator;
    private GoogleProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->credentialValidator = new CredentialValidator();
        $this->provider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testSupportsGoogleProvider(): void
    {
        $this->assertTrue($this->provider->supports('google'));
        $this->assertTrue($this->provider->supports('Google'));
        $this->assertTrue($this->provider->supports('GOOGLE'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('apple'));
        $this->assertFalse($this->provider->supports('facebook'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: false,
        );

        $this->assertFalse($disabledProvider->supports('google'));
    }

    public function testSuccessfulAuthentication(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-123',
            'email' => 'john.doe@gmail.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'verified_email' => true,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse) {
                if ($method === 'POST' && str_contains($url, 'oauth2.googleapis.com/token')) {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'googleapis.com/oauth2/v2/userinfo')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request');
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('google', $userData->provider);
        $this->assertSame('google-user-123', $userData->providerId);
        $this->assertSame('john.doe@gmail.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
    }

    public function testAuthenticationWithMinimalUserInfo(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-456',
            'email' => 'minimal@gmail.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('google-user-456', $userData->providerId);
        $this->assertSame('minimal@gmail.com', $userData->email);
        $this->assertNull($userData->firstName);
        $this->assertNull($userData->lastName);
    }

    public function testThrowsExceptionOnMissingAccessToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Code was already redeemed.',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->getUserData('invalid-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingUserInfoFields(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-789',
            // Missing 'email' field
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required fields');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnInvalidJson(): void
    {
        $tokenResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Google token response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGuzzleError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange Google authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $provider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GOOGLE_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientSecret(): void
    {
        $provider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: '',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GOOGLE_CLIENT_SECRET is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $provider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '%env(GOOGLE_CLIENT_ID)%',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GOOGLE_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testProviderCanBeCreatedWithMissingCredentialsWhenDisabled(): void
    {
        $provider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('google'));
    }

    public function testGetNameReturnsGoogle(): void
    {
        $this->assertSame('google', $this->provider->getName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $disabledProvider = new GoogleProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: false,
        );

        $this->assertFalse($disabledProvider->isEnabled());
    }

    public function testGetCredentialStatusWithValidCredentials(): void
    {
        $status = $this->provider->getCredentialStatus();

        $this->assertTrue($status['client_id']);
        $this->assertTrue($status['client_secret']);
    }

    public function testSupportsRefreshReturnsTrue(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testGetUserDataFromAccessToken(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-access-token',
            'email' => 'accesstoken@gmail.com',
            'given_name' => 'Access',
            'family_name' => 'Token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', $this->anything())
            ->willReturn($userInfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertSame('google-user-access-token', $userData->providerId);
        $this->assertSame('accesstoken@gmail.com', $userData->email);
        $this->assertSame('Access', $userData->firstName);
        $this->assertSame('Token', $userData->lastName);
    }

    public function testGetUserDataFromTokenData(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-token-data',
            'email' => 'tokendata@gmail.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($userInfoResponse);

        $tokenData = new \Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
        );

        $userData = $this->provider->getUserDataFromTokenData($tokenData);

        $this->assertSame('google-user-token-data', $userData->providerId);
        $this->assertSame('tokendata@gmail.com', $userData->email);
    }

    public function testRefreshTokens(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://oauth2.googleapis.com/token',
                $this->callback(function ($options) {
                    return isset($options['form_params']['grant_type'])
                        && $options['form_params']['grant_type'] === 'refresh_token'
                        && $options['form_params']['refresh_token'] === 'original-refresh-token';
                }),
            )
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-refresh-token');

        $this->assertSame('new-access-token', $tokenData->accessToken);
        $this->assertSame('original-refresh-token', $tokenData->refreshToken);
        $this->assertSame(3600, $tokenData->expiresIn);
    }

    public function testRefreshTokensThrowsOnMissingAccessToken(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->refreshTokens('invalid-refresh-token');
    }

    public function testRefreshTokensThrowsOnNetworkError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh Google tokens');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testRefreshTokensThrowsOnInvalidJson(): void
    {
        $refreshResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Google refresh response');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testAuthenticationWithRefreshToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'provided-refresh-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-with-refresh',
            'email' => 'refresh@gmail.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('provided-refresh-token', $userData->refreshToken);
    }

    public function testThrowsOnMissingIdInUserInfo(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'email' => 'noid@gmail.com',
            // Missing 'id'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required fields');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testFetchUserInfoThrowsOnNetworkError(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }

                throw new \GuzzleHttp\Exception\ConnectException(
                    'Connection refused',
                    new \GuzzleHttp\Psr7\Request('GET', $url),
                );
            });

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to fetch Google user info');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testFetchUserInfoThrowsOnInvalidJson(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $invalidJsonResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $invalidJsonResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Google user info response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }
}
