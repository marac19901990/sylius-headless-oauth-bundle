<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\GitHubProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

class GitHubProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private CredentialValidator $credentialValidator;
    private GitHubProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->credentialValidator = new CredentialValidator();
        $this->provider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: true,
        );
    }

    public function testSupportsGitHubProvider(): void
    {
        $this->assertTrue($this->provider->supports('github'));
        $this->assertTrue($this->provider->supports('GitHub'));
        $this->assertTrue($this->provider->supports('GITHUB'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('google'));
        $this->assertFalse($this->provider->supports('apple'));
        $this->assertFalse($this->provider->supports('facebook'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            enabled: false,
        );

        $this->assertFalse($disabledProvider->supports('github'));
    }

    public function testSuccessfulAuthentication(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'bearer',
            'scope' => 'user:email',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/12345678',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse) {
                if ($method === 'POST' && str_contains($url, 'github.com/login/oauth/access_token')) {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.github.com/user')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request');
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('github', $userData->provider);
        $this->assertSame('12345678', $userData->providerId);
        $this->assertSame('john.doe@example.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
        $this->assertNull($userData->refreshToken); // GitHub doesn't provide refresh tokens
    }

    public function testAuthenticationWithPrivateEmail(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'name' => 'John Doe',
            'email' => null, // Private email
        ], JSON_THROW_ON_ERROR));

        $emailsResponse = new Response(200, [], json_encode([
            ['email' => 'secondary@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'primary@example.com', 'primary' => true, 'verified' => true],
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse, $emailsResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.github.com/user/emails')) {
                    return $emailsResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.github.com/user')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request: ' . $method . ' ' . $url);
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('primary@example.com', $userData->email);
    }

    public function testAuthenticationWithSingleName(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'name' => 'John',
            'email' => 'john@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('John', $userData->firstName);
        $this->assertNull($userData->lastName);
    }

    public function testAuthenticationWithNoName(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'name' => null,
            'email' => 'john@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertNull($userData->firstName);
        $this->assertNull($userData->lastName);
    }

    public function testThrowsExceptionWhenNoEmailAvailable(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'email' => null,
        ], JSON_THROW_ON_ERROR));

        $emailsResponse = new Response(200, [], json_encode([], JSON_THROW_ON_ERROR)); // No emails

        $this->httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse, $emailsResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.github.com/user/emails')) {
                    return $emailsResponse;
                }
                if ($method === 'GET' && str_contains($url, 'api.github.com/user')) {
                    return $userInfoResponse;
                }

                throw new RuntimeException('Unexpected request');
            });

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GitHub account does not have a verified email address');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGitHubError(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GitHub authentication failed');

        $this->provider->getUserData('invalid-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingAccessToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'token_type' => 'bearer',
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
        $this->expectExceptionMessage('Failed to parse GitHub token response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGuzzleError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('POST', 'https://github.com/login/oauth/access_token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange GitHub authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $provider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GITHUB_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientSecret(): void
    {
        $provider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: '',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GITHUB_CLIENT_SECRET is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $provider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '%env(GITHUB_CLIENT_ID)%',
            clientSecret: 'test-client-secret',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GITHUB_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testProviderCanBeCreatedWithMissingCredentialsWhenDisabled(): void
    {
        $provider = new GitHubProvider(
            httpClient: $this->httpClient,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('github'));
    }

    public function testGetNameReturnsGitHub(): void
    {
        $this->assertSame('github', $this->provider->getName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $disabledProvider = new GitHubProvider(
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

    public function testSupportsRefreshReturnsFalse(): void
    {
        // GitHub doesn't support refresh tokens by default
        $this->assertFalse($this->provider->supportsRefresh());
    }

    public function testRefreshTokensThrowsException(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('GitHub does not support token refresh');

        $this->provider->refreshTokens('any-refresh-token');
    }

    public function testGetUserDataFromAccessToken(): void
    {
        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 87654321,
            'login' => 'janedoe',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://api.github.com/user', $this->anything())
            ->willReturn($userInfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertSame('87654321', $userData->providerId);
        $this->assertSame('jane@example.com', $userData->email);
        $this->assertSame('Jane', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
    }

    public function testThrowsOnMissingIdInUserInfo(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'login' => 'noid',
            'email' => 'noid@example.com',
            // Missing 'id'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userInfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required field');

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

                throw new ConnectException(
                    'Connection refused',
                    new Request('GET', $url),
                );
            });

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to fetch GitHub user info');

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
        $this->expectExceptionMessage('Failed to parse GitHub user info response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testFallsBackToVerifiedEmailWhenNoPrimary(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 12345678,
            'login' => 'johndoe',
            'email' => null,
        ], JSON_THROW_ON_ERROR));

        $emailsResponse = new Response(200, [], json_encode([
            ['email' => 'verified@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'unverified@example.com', 'primary' => true, 'verified' => false],
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($tokenResponse, $userInfoResponse, $emailsResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'emails')) {
                    return $emailsResponse;
                }

                return $userInfoResponse;
            });

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('verified@example.com', $userData->email);
    }
}
