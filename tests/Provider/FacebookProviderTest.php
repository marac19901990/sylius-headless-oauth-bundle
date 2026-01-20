<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\FacebookProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FacebookProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private FacebookProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->provider = new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testSupportsFacebookProvider(): void
    {
        $this->assertTrue($this->provider->supports('facebook'));
        $this->assertTrue($this->provider->supports('Facebook'));
        $this->assertTrue($this->provider->supports('FACEBOOK'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('google'));
        $this->assertFalse($this->provider->supports('apple'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: false,
        );

        $this->assertFalse($disabledProvider->supports('facebook'));
    }

    public function testSuccessfulAuthentication(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'facebook-user-123',
            'email' => 'john.doe@facebook.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse) {
                if ($method === 'POST' && str_contains($url, 'graph.facebook.com')) {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'graph.facebook.com/me')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request');
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('facebook', $userData->provider);
        $this->assertSame('facebook-user-123', $userData->providerId);
        $this->assertSame('john.doe@facebook.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
    }

    public function testAuthenticationWithMinimalUserInfo(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'facebook-user-456',
            'email' => 'minimal@facebook.com',
        ]));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('facebook-user-456', $userData->providerId);
        $this->assertSame('minimal@facebook.com', $userData->email);
        $this->assertNull($userData->firstName);
        $this->assertNull($userData->lastName);
    }

    public function testThrowsExceptionOnMissingAccessToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'error' => [
                'message' => 'Invalid verification code format.',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ]));

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
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'facebook-user-789',
            // Missing 'email' field
        ]));

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
        $this->expectExceptionMessage('Failed to parse Facebook token response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGuzzleError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://graph.facebook.com/v19.0/oauth/access_token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange Facebook authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FACEBOOK_CLIENT_ID is not configured');

        new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: '',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testThrowsExceptionOnEmptyClientSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FACEBOOK_CLIENT_SECRET is not configured');

        new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: 'test-client-id',
            clientSecret: '',
            enabled: true,
        );
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FACEBOOK_CLIENT_ID is not configured');

        new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: '%env(FACEBOOK_CLIENT_ID)%',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testNoValidationWhenDisabled(): void
    {
        $provider = new FacebookProvider(
            httpClient: $this->httpClient,
            clientId: '',
            clientSecret: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('facebook'));
    }

    public function testGetNameReturnsFacebook(): void
    {
        $this->assertSame('facebook', $this->provider->getName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $disabledProvider = new FacebookProvider(
            httpClient: $this->httpClient,
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
            'id' => 'facebook-user-access-token',
            'email' => 'accesstoken@facebook.com',
            'first_name' => 'Access',
            'last_name' => 'Token',
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://graph.facebook.com/me', $this->anything())
            ->willReturn($userInfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertSame('facebook-user-access-token', $userData->providerId);
        $this->assertSame('accesstoken@facebook.com', $userData->email);
        $this->assertSame('Access', $userData->firstName);
        $this->assertSame('Token', $userData->lastName);
    }

    public function testGetUserDataFromTokenData(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'facebook-user-token-data',
            'email' => 'tokendata@facebook.com',
        ]));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($userInfoResponse);

        $tokenData = new \Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
        );

        $userData = $this->provider->getUserDataFromTokenData($tokenData);

        $this->assertSame('facebook-user-token-data', $userData->providerId);
        $this->assertSame('tokendata@facebook.com', $userData->email);
    }

    public function testRefreshTokens(): void
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
                'https://graph.facebook.com/v19.0/oauth/access_token',
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
            'error' => [
                'message' => 'Invalid OAuth access token.',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ]));

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
                new \GuzzleHttp\Psr7\Request('POST', 'https://graph.facebook.com/v19.0/oauth/access_token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh Facebook tokens');

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
        $this->expectExceptionMessage('Failed to parse Facebook refresh response');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testAuthenticationWithRefreshToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'provided-refresh-token',
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'facebook-user-with-refresh',
            'email' => 'refresh@facebook.com',
        ]));

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
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'email' => 'noid@facebook.com',
            // Missing 'id'
        ]));

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
        ]));

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
        $this->expectExceptionMessage('Failed to fetch Facebook user info');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testFetchUserInfoThrowsOnInvalidJson(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ]));

        $invalidJsonResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $invalidJsonResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Facebook user info response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }
}
