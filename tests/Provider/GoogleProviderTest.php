<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\GoogleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GoogleProviderTest extends TestCase
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
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-123',
            'email' => 'john.doe@gmail.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'verified_email' => true,
        ]));

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
                throw new \RuntimeException('Unexpected request');
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
        ]));

        $userInfoResponse = new Response(200, [], json_encode([
            'id' => 'google-user-456',
            'email' => 'minimal@gmail.com',
        ]));

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
            'id' => 'google-user-789',
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
                new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token')
            ));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to exchange Google authorization code');

        $this->provider->getUserData('test-auth-code', 'https://example.com/callback');
    }
}
