<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGeneratorInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppleProviderTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private AppleClientSecretGeneratorInterface&MockObject $clientSecretGenerator;
    private AppleProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->clientSecretGenerator = $this->createMock(AppleClientSecretGeneratorInterface::class);

        $this->clientSecretGenerator
            ->method('generate')
            ->willReturn('mocked-client-secret');

        $this->provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: 'com.test.app',
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
            clientId: 'com.test.app',
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
        ]));

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
        ]));

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
        ]));

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
        ]));

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
        ]));

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
        ]));

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
                new \GuzzleHttp\Psr7\Request('POST', 'https://appleid.apple.com/auth/token')
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange Apple authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }

    /**
     * Creates a fake JWT with the given payload for testing purposes.
     * Note: This is NOT a valid signed JWT, just a properly formatted one.
     */
    private function createFakeJwt(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode('fake-signature');

        return $header . '.' . $payloadEncoded . '.' . $signature;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testThrowsExceptionOnEmptyClientId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APPLE_CLIENT_ID is not configured');

        new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: '',
            enabled: true,
        );
    }

    public function testThrowsExceptionOnUnresolvedEnvPlaceholder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APPLE_CLIENT_ID is not configured');

        new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: '%env(APPLE_CLIENT_ID)%',
            enabled: true,
        );
    }

    public function testNoValidationWhenDisabled(): void
    {
        $provider = new AppleProvider(
            httpClient: $this->httpClient,
            clientSecretGenerator: $this->clientSecretGenerator,
            clientId: '',
            enabled: false,
        );

        $this->assertFalse($provider->supports('apple'));
    }
}
