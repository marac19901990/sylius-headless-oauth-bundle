<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\OpenIdConnectProvider;
use Marac\SyliusHeadlessOAuthBundle\Service\OidcDiscoveryServiceInterface;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

class OpenIdConnectProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private OidcDiscoveryServiceInterface&MockObject $discoveryService;
    private CredentialValidator $credentialValidator;
    private OpenIdConnectProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->discoveryService = $this->createMock(OidcDiscoveryServiceInterface::class);
        $this->credentialValidator = new CredentialValidator();

        $this->provider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            issuerUrl: 'https://keycloak.example.com/realms/test',
            enabled: true,
            verifyJwt: false, // Disable JWT verification for most tests
            providerName: 'keycloak',
        );
    }

    public function testSupportsConfiguredProviderName(): void
    {
        $this->assertTrue($this->provider->supports('keycloak'));
        $this->assertTrue($this->provider->supports('Keycloak'));
        $this->assertTrue($this->provider->supports('KEYCLOAK'));
    }

    public function testDoesNotSupportOtherProviders(): void
    {
        $this->assertFalse($this->provider->supports('google'));
        $this->assertFalse($this->provider->supports('auth0'));
        $this->assertFalse($this->provider->supports('oidc'));
        $this->assertFalse($this->provider->supports(''));
    }

    public function testDoesNotSupportWhenDisabled(): void
    {
        $disabledProvider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            issuerUrl: 'https://keycloak.example.com/realms/test',
            enabled: false,
            providerName: 'keycloak',
        );

        $this->assertFalse($disabledProvider->supports('keycloak'));
    }

    public function testGetNameReturnsConfiguredProviderName(): void
    {
        $this->assertSame('keycloak', $this->provider->getName());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->provider->isEnabled());
    }

    public function testGetCredentialStatusWithValidCredentials(): void
    {
        $status = $this->provider->getCredentialStatus();

        $this->assertTrue($status['client_id']);
        $this->assertTrue($status['client_secret']);
        $this->assertTrue($status['issuer_url']);
    }

    public function testGetIssuerUrl(): void
    {
        $this->assertSame('https://keycloak.example.com/realms/test', $this->provider->getIssuerUrl());
    }

    public function testGetScopes(): void
    {
        $this->assertSame('openid email profile', $this->provider->getScopes());
    }

    public function testSupportsRefresh(): void
    {
        $this->assertTrue($this->provider->supportsRefresh());
    }

    public function testSuccessfulAuthenticationWithIdToken(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        // Create a valid JWT-like id_token (base64 encoded payload)
        $payload = base64_encode(json_encode([
            'sub' => 'user-123',
            'email' => 'john@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'iss' => 'https://keycloak.example.com/realms/test',
            'aud' => 'test-client-id',
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://keycloak.example.com/realms/test/protocol/openid-connect/token')
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertInstanceOf(OAuthUserData::class, $userData);
        $this->assertSame('keycloak', $userData->provider);
        $this->assertSame('user-123', $userData->providerId);
        $this->assertSame('john@example.com', $userData->email);
        $this->assertSame('John', $userData->firstName);
        $this->assertSame('Doe', $userData->lastName);
        $this->assertSame('test-refresh-token', $userData->refreshToken);
    }

    public function testAuthenticationFallsBackToUserinfoEndpoint(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            // No id_token - will fall back to userinfo
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-456',
            'email' => 'jane@example.com',
            'given_name' => 'Jane',
            'family_name' => 'Smith',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url) use ($tokenResponse, $userinfoResponse) {
                if ($method === 'POST') {
                    return $tokenResponse;
                }
                if ($method === 'GET' && str_contains($url, 'userinfo')) {
                    return $userinfoResponse;
                }

                throw new RuntimeException('Unexpected request: ' . $method . ' ' . $url);
            });

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('user-456', $userData->providerId);
        $this->assertSame('jane@example.com', $userData->email);
    }

    public function testAuthenticationWithNameInSingleField(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-789',
            'email' => 'fullname@example.com',
            'name' => 'Full Name',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('Full', $userData->firstName);
        $this->assertSame('Name', $userData->lastName);
    }

    public function testThrowsExceptionOnMissingAccessToken(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $tokenResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->getUserData('invalid-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingSubject(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'email' => 'nosub@example.com',
            // Missing 'sub'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required field: sub');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnMissingEmail(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-noemail',
            // Missing 'email'
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing required field: email');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnNetworkError(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('POST', 'https://keycloak.example.com'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange OIDC authorization code');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnInvalidJson(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $tokenResponse = new Response(200, [], 'not-valid-json');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse OIDC token response');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnNoUserinfoEndpoint(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn(null);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            // No id_token
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not expose a userinfo endpoint');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testRefreshTokens(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => 'new-id-token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://keycloak.example.com/realms/test/protocol/openid-connect/token',
                $this->callback(static function ($options) {
                    return isset($options['form_params']['grant_type'])
                        && $options['form_params']['grant_type'] === 'refresh_token'
                        && $options['form_params']['refresh_token'] === 'original-refresh-token';
                }),
            )
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-refresh-token');

        $this->assertInstanceOf(OAuthTokenData::class, $tokenData);
        $this->assertSame('new-access-token', $tokenData->accessToken);
        $this->assertSame('new-refresh-token', $tokenData->refreshToken);
        $this->assertSame(3600, $tokenData->expiresIn);
        $this->assertSame('new-id-token', $tokenData->idToken);
    }

    public function testRefreshTokensKeepsOriginalWhenNotRotated(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $refreshResponse = new Response(200, [], json_encode([
            'access_token' => 'new-access-token',
            // No new refresh_token - provider doesn't rotate
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $tokenData = $this->provider->refreshTokens('original-refresh-token');

        $this->assertSame('original-refresh-token', $tokenData->refreshToken);
    }

    public function testRefreshTokensThrowsOnMissingAccessToken(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $refreshResponse = new Response(200, [], json_encode([
            'error' => 'invalid_grant',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($refreshResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('missing access_token');

        $this->provider->refreshTokens('expired-refresh-token');
    }

    public function testRefreshTokensThrowsOnNetworkError(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException(
                'Connection refused',
                new Request('POST', 'https://keycloak.example.com'),
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to refresh OIDC tokens');

        $this->provider->refreshTokens('some-refresh-token');
    }

    public function testGetUserDataFromAccessToken(): void
    {
        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-access-token',
            'email' => 'accesstoken@example.com',
            'given_name' => 'Access',
            'family_name' => 'Token',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo', $this->anything())
            ->willReturn($userinfoResponse);

        $userData = $this->provider->getUserDataFromAccessToken('test-access-token');

        $this->assertSame('user-access-token', $userData->providerId);
        $this->assertSame('accesstoken@example.com', $userData->email);
    }

    public function testGetUserDataFromTokenDataWithIdToken(): void
    {
        $payload = base64_encode(json_encode([
            'sub' => 'user-from-id-token',
            'email' => 'idtoken@example.com',
            'given_name' => 'Id',
            'family_name' => 'Token',
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenData = new OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
            idToken: $idToken,
        );

        $userData = $this->provider->getUserDataFromTokenData($tokenData);

        $this->assertSame('user-from-id-token', $userData->providerId);
        $this->assertSame('idtoken@example.com', $userData->email);
        $this->assertSame('some-refresh-token', $userData->refreshToken);
    }

    public function testGetUserDataFromTokenDataFallsBackToAccessToken(): void
    {
        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-from-userinfo',
            'email' => 'userinfo@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($userinfoResponse);

        $tokenData = new OAuthTokenData(
            accessToken: 'some-access-token',
            refreshToken: 'some-refresh-token',
            idToken: null,
        );

        $userData = $this->provider->getUserDataFromTokenData($tokenData);

        $this->assertSame('user-from-userinfo', $userData->providerId);
        $this->assertSame('some-refresh-token', $userData->refreshToken);
    }

    public function testThrowsExceptionOnEmptyIssuerUrl(): void
    {
        $provider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            issuerUrl: '',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('issuer URL is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $provider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            issuerUrl: '%env(KEYCLOAK_ISSUER_URL)%',
            enabled: true,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('issuer URL is not configured');

        $provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testNoValidationWhenDisabled(): void
    {
        $provider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: '',
            clientSecret: '',
            issuerUrl: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('oidc'));
    }

    public function testCustomScopes(): void
    {
        $provider = new OpenIdConnectProvider(
            httpClient: $this->httpClient,
            discoveryService: $this->discoveryService,
            credentialValidator: $this->credentialValidator,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            issuerUrl: 'https://keycloak.example.com/realms/test',
            enabled: true,
            verifyJwt: false,
            providerName: 'keycloak',
            scopes: 'openid email profile custom_scope',
        );

        $this->assertSame('openid email profile custom_scope', $provider->getScopes());
    }

    public function testIdTokenWithMissingSubjectFallsBackToUserinfo(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $payload = base64_encode(json_encode([
            'email' => 'nosub@example.com',
            // Missing 'sub' - will fall back to userinfo
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-from-userinfo',
            'email' => 'nosub@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        // Should fall back to userinfo endpoint when id_token is invalid
        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('user-from-userinfo', $userData->providerId);
    }

    public function testIdTokenWithMissingSubjectThrowsWhenNoUserinfoEndpoint(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn(null);

        $payload = base64_encode(json_encode([
            'email' => 'nosub@example.com',
            // Missing 'sub'
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not expose a userinfo endpoint');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testIdTokenWithMissingEmailFallsBackToUserinfo(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $payload = base64_encode(json_encode([
            'sub' => 'user-noemail',
            // Missing 'email' - will fall back to userinfo
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-noemail',
            'email' => 'recovered@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        // Should fall back to userinfo endpoint when id_token is invalid
        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('user-noemail', $userData->providerId);
        $this->assertSame('recovered@example.com', $userData->email);
    }

    public function testIdTokenWithMissingEmailThrowsWhenNoUserinfoEndpoint(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn(null);

        $payload = base64_encode(json_encode([
            'sub' => 'user-noemail',
            // Missing 'email'
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not expose a userinfo endpoint');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }

    public function testIdTokenWithAlternativeNameFields(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $payload = base64_encode(json_encode([
            'sub' => 'user-altnames',
            'email' => 'altnames@example.com',
            'first_name' => 'First',
            'last_name' => 'Last',
        ], JSON_THROW_ON_ERROR));
        $idToken = 'header.' . $payload . '.signature';

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => $idToken,
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('First', $userData->firstName);
        $this->assertSame('Last', $userData->lastName);
    }

    public function testInvalidIdTokenFormatFallsBackToUserinfo(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/userinfo');

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => 'invalid-token-format', // Not 3 parts - will fall back to userinfo
        ], JSON_THROW_ON_ERROR));

        $userinfoResponse = new Response(200, [], json_encode([
            'sub' => 'user-from-userinfo',
            'email' => 'recovered@example.com',
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($tokenResponse, $userinfoResponse);

        // Should fall back to userinfo endpoint when id_token format is invalid
        $userData = $this->provider->getUserData('test-code', 'https://example.com/callback');

        $this->assertSame('user-from-userinfo', $userData->providerId);
    }

    public function testInvalidIdTokenFormatThrowsWhenNoUserinfoEndpoint(): void
    {
        $this->discoveryService
            ->method('getTokenEndpoint')
            ->willReturn('https://keycloak.example.com/realms/test/protocol/openid-connect/token');

        $this->discoveryService
            ->method('getUserinfoEndpoint')
            ->willReturn(null);

        $tokenResponse = new Response(200, [], json_encode([
            'access_token' => 'test-access-token',
            'id_token' => 'invalid-token-format', // Not 3 parts
        ], JSON_THROW_ON_ERROR));

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($tokenResponse);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not expose a userinfo endpoint');

        $this->provider->getUserData('test-code', 'https://example.com/callback');
    }
}
