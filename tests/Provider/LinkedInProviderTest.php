<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\LinkedInProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

class LinkedInProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private CredentialValidator $credentialValidator;
    private LinkedInProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->credentialValidator = new CredentialValidator();
        $this->provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testSupportsLinkedInProvider(): void
    {
        $this->assertTrue($this->provider->supports('linkedin'));
        $this->assertTrue($this->provider->supports('LinkedIn'));
        $this->assertTrue($this->provider->supports('LINKEDIN'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('google'));
        $this->assertFalse($this->provider->supports('apple'));
        $this->assertFalse($this->provider->supports('facebook'));
        $this->assertFalse($this->provider->supports('github'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: false,
        );

        $this->assertFalse($disabledProvider->supports('linkedin'));
    }

    public function testSuccessfulAuthentication(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'sub' => 'linkedin-user-123',
            'email' => 'john.doe@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'name' => 'John Doe',
            'picture' => 'https://media.licdn.com/dms/image/profile.jpg',
            'email_verified' => true,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url) use ($tokenResponse, $userInfoResponse) {
                if ($method === 'POST' && str_contains($url, 'linkedin.com/oauth/v2/accessToken')) {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.linkedin.com/v2/userinfo')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request');
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('linkedin', $userData->provider);
        $this->assertSame('linkedin-user-123', $userData->providerId);
        $this->assertSame('john.doe@example.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
        $this->assertSame('test-refresh-token', $userData->refreshToken);
    }

    public function testAuthenticationWithoutOptionalFields(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'sub' => 'linkedin-user-456',
            'email' => 'jane@example.com',
            // No given_name or family_name
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('linkedin-user-456', $userData->providerId);
        $this->assertSame('jane@example.com', $userData->email);
        $this->assertNull($userData->firstName);
        $this->assertNull($userData->lastName);
        $this->assertNull($userData->refreshToken);
    }

    public function testThrowsExceptionOnMissingSub(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'email' => 'test@example.com',
            // Missing 'sub'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required field (sub)');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingEmail(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'sub' => 'linkedin-user-789',
            // Missing 'email'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required field (email)');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnLinkedInError(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code has expired',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('LinkedIn authentication failed');

        $this->provider->getUserData('invalid-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingAccessToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnInvalidJson(): void
    {
        $tokenResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse LinkedIn token response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGuzzleError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('POST', 'https://www.linkedin.com/oauth/v2/accessToken'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange LinkedIn authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('LINKEDIN_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientSecret(): void
    {
        $provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: '',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('LINKEDIN_CLIENT_SECRET is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '%env(LINKEDIN_CLIENT_ID)%',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('LINKEDIN_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testProviderCanBeCreatedWithMissingCredentialsWhenDisabled(): void
    {
        $provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('linkedin'));
    }

    public function testGetNameReturnsLinkedIn(): void
    {
        $this->assertSame('linkedin', $this->provider->getName());
    }

    public function testGetDisplayNameReturnsLinkedIn(): void
    {
        $this->assertSame('LinkedIn', $this->provider->getDisplayName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $disabledProvider = new LinkedInProvider(
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

    public function testGetCredentialStatusWithMissingCredentials(): void
    {
        $provider = new LinkedInProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: '%env(LINKEDIN_CLIENT_SECRET)%',
            enabled: true,
        );

        $status = $provider->getCredentialStatus();

        $this->assertFalse($status['client_id']);
        $this->assertFalse($status['client_secret']);
    }

    public function testSupportsRefreshReturnsTrue(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testRefreshTokensSuccess(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'new-refresh-token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://www.linkedin.com/oauth/v2/accessToken', $this->anything())
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('old-refresh-token');

        $this->assertSame('new-access-token', $tokenData->accessToken);
        $this->assertSame('new-refresh-token', $tokenData->refreshToken);
        $this->assertSame(3600, $tokenData->expiresIn);
        $this->assertSame('Bearer', $tokenData->tokenType);
    }

    public function testRefreshTokensKeepsOldRefreshTokenIfNotReturned(): void
    {
        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
            // No refresh_token in response
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('old-refresh-token');

        $this->assertSame('new-access-token', $tokenData->accessToken);
        $this->assertSame('old-refresh-token', $tokenData->refreshToken);
    }

    public function testRefreshTokensThrowsOnError(): void
    {
        $errorResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is expired',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($errorResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('LinkedIn token refresh failed');

        $this->provider->refreshTokens('expired-refresh-token');
    }

    public function testRefreshTokensThrowsOnNetworkError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('POST', 'https://www.linkedin.com/oauth/v2/accessToken'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh LinkedIn tokens');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testGetUserDataFromAccessToken(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'sub' => 'linkedin-user-abc',
            'email' => 'test@example.com',
            'given_name' => 'Test',
            'family_name' => 'User',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.linkedin.com/v2/userinfo', $this->anything())
            ->willReturn($userInfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertSame('linkedin-user-abc', $userData->providerId);
        $this->assertSame('test@example.com', $userData->email);
        $this->assertSame('Test', $userData->firstName);
        $this->assertSame('User', $userData->lastName);
    }

    public function testFetchUserInfoThrowsOnNetworkError(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url) use ($tokenResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }

                throw new ConnectException(
                    'Connection refused',
                    new Request('GET', $url),
                );
            });

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to fetch LinkedIn user info');

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
        $this->expectExceptionMessage('Failed to parse LinkedIn user info response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }
}
