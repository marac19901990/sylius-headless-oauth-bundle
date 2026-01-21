<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGeneratorInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Security\NullAppleJwksVerifier;
use Marac\SyliusHeadlessOAuthBundle\Security\NullOAuthSecurityLogger;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;

class AppleProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private AppleClientSecretGeneratorInterface&MockObject $clientSecretGenerator;
    private CredentialValidator $credentialValidator;
    private AppleProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->clientSecretGenerator = $this->createMock(AppleClientSecretGeneratorInterface::class);
        $this->credentialValidator = new CredentialValidator();

        $this->clientSecretGenerator
            ->method('generate')
            ->willReturn('mocked-client-secret');

        $this->provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: 'com.test.app',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: true,
        );
    }

    public function testSupportsAppleProvider(): void
    {
        $this->assertTrue($this->provider->supports('apple'));
        $this->assertTrue($this->provider->supports('Apple'));
        $this->assertTrue($this->provider->supports('APPLE'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('google'));
        $this->assertFalse($this->provider->supports('facebook'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: 'com.test.app',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: false,
        );

        $this->assertFalse($disabledProvider->supports('apple'));
    }

    public function testSuccessfulAuthentication(): void
    {
        // Create a valid JWT id_token payload
        $idTokenPayload = [
            'iss' => 'https://appleid.apple.com',
            'sub' => 'apple-user-001.abc123.def456',
            'aud' => 'com.test.app',
            'iat' => time(),
            'exp' => time() + 3600,
            'email' => 'jane.doe@privaterelay.appleid.com',
            'email_verified' => true,
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://appleid.apple.com/auth/token', $this->anything())
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('apple', $userData->provider);
        $this->assertSame('apple-user-001.abc123.def456', $userData->providerId);
        $this->assertSame('jane.doe@privaterelay.appleid.com', $userData->email);
    }

    public function testAuthenticationWithName(): void
    {
        // Apple only sends name on first authorization
        $idTokenPayload = [
            'iss' => 'https://appleid.apple.com',
            'sub' => 'apple-user-002',
            'aud' => 'com.test.app',
            'iat' => time(),
            'exp' => time() + 3600,
            'email' => 'john.smith@icloud.com',
            'email_verified' => true,
            'firstName' => 'John',
            'lastName' => 'Smith',
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Smith', $userData->lastName);
    }

    public function testThrowsExceptionOnMissingIdToken(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            // Missing 'id_token'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required fields');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnInvalidIdTokenFormat(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => 'not.a.valid.jwt.with.five.parts',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Invalid Apple id_token format');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingClaims(): void
    {
        // Create id_token missing required 'sub' claim
        $idTokenPayload = [
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.test.app',
            'email' => 'test@example.com',
            // Missing 'sub'
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required claims');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingEmail(): void
    {
        $idTokenPayload = [
            'iss' => 'https://appleid.apple.com',
            'sub' => 'apple-user-003',
            'aud' => 'com.test.app',
            // Missing 'email'
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required claims');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnGuzzleError(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://appleid.apple.com/auth/token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange Apple authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('APPLE_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: '%env(APPLE_CLIENT_ID)%',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('APPLE_CLIENT_ID is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testProviderCanBeCreatedWithMissingCredentialsWhenDisabled(): void
    {
        $provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: false,
        );

        $this->assertFalse($provider->supports('apple'));
    }

    public function testGetNameReturnsApple(): void
    {
        $this->assertSame('apple', $this->provider->getName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $disabledProvider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            credentialValidator: $this->credentialValidator,
            clientId: 'com.test.app',
            securityLogger: new NullOAuthSecurityLogger(),
            jwksVerifier: new NullAppleJwksVerifier(),
            enabled: false,
        );

        $this->assertFalse($disabledProvider->isEnabled());
    }

    public function testGetCredentialStatusWithValidCredentials(): void
    {
        $status = $this->provider->getCredentialStatus();

        $this->assertTrue($status['client_id']);
    }

    public function testSupportsRefreshReturnsTrue(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testGetUserDataFromAccessTokenThrowsException(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Apple does not support fetching user data from access token');

        $this->provider->getUserDataFromAccessToken('some-access-token');
    }

    public function testGetUserDataFromIdToken(): void
    {
        $idTokenPayload = [
            'sub' => 'apple-user-id-token-test',
            'email' => 'idtoken@icloud.com',
            'firstName' => 'Id',
            'lastName' => 'Token',
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $userData = $this->provider->getUserDataFromIdToken($idToken);

        $this->assertSame('apple-user-id-token-test', $userData->providerId);
        $this->assertSame('idtoken@icloud.com', $userData->email);
        $this->assertSame('Id', $userData->firstName);
        $this->assertSame('Token', $userData->lastName);
    }

    public function testGetUserDataFromTokenData(): void
    {
        $idTokenPayload = [
            'sub' => 'apple-user-token-data-test',
            'email' => 'tokendata@icloud.com',
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenData = new \Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
            idToken: $idToken,
        );

        $userData = $this->provider->getUserDataFromTokenData($tokenData);

        $this->assertSame('apple-user-token-data-test', $userData->providerId);
        $this->assertSame('tokendata@icloud.com', $userData->email);
    }

    public function testGetUserDataFromTokenDataThrowsOnMissingIdToken(): void
    {
        $tokenData = new \Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
            idToken: null,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('did not include id_token');

        $this->provider->getUserDataFromTokenData($tokenData);
    }

    public function testRefreshTokens(): void
    {
        $idTokenPayload = [
            'sub' => 'apple-refresh-user',
            'email' => 'refresh@icloud.com',
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-apple-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'new-apple-refresh-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://appleid.apple.com/auth/token',
                $this->callback(function ($options) {
                    return isset($options['form_params']['grant_type'])
                        && $options['form_params']['grant_type'] === 'refresh_token';
                }),
            )
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-apple-refresh-token');

        $this->assertSame('new-apple-access-token', $tokenData->accessToken);
        $this->assertSame('new-apple-refresh-token', $tokenData->refreshToken);
        $this->assertSame($idToken, $tokenData->idToken);
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
                new \GuzzleHttp\Psr7\Request('POST', 'https://appleid.apple.com/auth/token'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh Apple tokens');

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
        $this->expectExceptionMessage('Failed to parse Apple refresh response');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testThrowsOnIdTokenWithOnly2Parts(): void
    {
        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => 'only.twoparts',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Invalid Apple id_token format');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testThrowsOnMalformedBase64InJwt(): void
    {
        // Create a JWT with invalid base64 in payload
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $invalidPayload = '!!!invalid-base64!!!';
        $signature = $this->base64UrlEncode('fake-signature');

        $malformedJwt = $header . '.' . $invalidPayload . '.' . $signature;

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $malformedJwt,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    public function testAuthenticationWithRefreshToken(): void
    {
        $idTokenPayload = [
            'sub' => 'apple-user-with-refresh',
            'email' => 'withrefresh@icloud.com',
        ];

        $idToken = $this->createFakeJwt($idTokenPayload);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
            'refresh_token' => 'apple-refresh-token-from-auth',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-auth-code', 'https://example.com/callback');

        $this->assertSame('apple-refresh-token-from-auth', $userData->refreshToken);
    }

    public function testThrowsOnInvalidTokenResponseJson(): void
    {
        $tokenResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Apple token response');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    /**
     * Creates a fake JWT with the given payload for testing purposes.
     * Note: This is NOT a valid signed JWT, just a properly formatted one.
     *
     * @param array<string, mixed> $payload
     */
    private function createFakeJwt(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode('fake-signature');

        return $header . '.' . $payloadEncoded . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
