<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Security;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Security\AppleJwksVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

use const JSON_THROW_ON_ERROR;

/**
 * Tests for AppleJwksVerifier.
 *
 * Note: Full JWT verification tests are limited because they require actual
 * Apple JWKS keys. We test the error handling and edge cases thoroughly.
 */
final class AppleJwksVerifierTest extends TestCase
{
    private ClientInterface&MockObject $httpClient;
    private CacheInterface&MockObject $cache;
    private string $clientId = 'com.example.app';

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testVerifyThrowsOnJwksFetchError(): void
    {
        $this->httpClient->method('request')
            ->willThrowException(new RequestException(
                'Network error',
                new Request('GET', 'https://appleid.apple.com/auth/keys'),
            ));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to fetch Apple JWKS');

        $verifier->verify('some.jwt.token');
    }

    public function testVerifyThrowsOnInvalidJwksResponse(): void
    {
        $this->httpClient->method('request')
            ->willReturn(new Response(200, [], 'not valid json'));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Failed to parse Apple JWKS');

        $verifier->verify('some.jwt.token');
    }

    public function testVerifyThrowsOnEmptyJwksKeys(): void
    {
        $this->httpClient->method('request')
            ->willReturn(new Response(200, [], json_encode(['keys' => []], JSON_THROW_ON_ERROR)));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('JWKS response missing keys');

        $verifier->verify('some.jwt.token');
    }

    public function testVerifyThrowsOnMissingKeysProperty(): void
    {
        $this->httpClient->method('request')
            ->willReturn(new Response(200, [], json_encode(['other' => 'data'], JSON_THROW_ON_ERROR)));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('JWKS response missing keys');

        $verifier->verify('some.jwt.token');
    }

    public function testVerifyThrowsOnInvalidJwtFormat(): void
    {
        // Valid JWKS but invalid JWT token
        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => 'test',
                    'e' => 'AQAB',
                ],
            ],
        ];

        $this->httpClient->method('request')
            ->willReturn(new Response(200, [], json_encode($jwks, JSON_THROW_ON_ERROR)));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('verification failed');

        $verifier->verify('not.a.valid.jwt');
    }

    public function testClearCacheDeletesCacheEntry(): void
    {
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('apple_jwks_keys');

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            $this->cache,
            $this->clientId,
        );

        $verifier->clearCache();
    }

    public function testClearCacheHandlesNullCache(): void
    {
        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        // Should not throw when cache is null
        $verifier->clearCache();

        $this->assertTrue(true);
    }

    public function testConstructorAcceptsRequiredParameters(): void
    {
        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            $this->cache,
            $this->clientId,
        );

        $this->assertInstanceOf(AppleJwksVerifier::class, $verifier);
    }

    public function testConstructorAcceptsNullCache(): void
    {
        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null,
            $this->clientId,
        );

        $this->assertInstanceOf(AppleJwksVerifier::class, $verifier);
    }

    public function testVerifyUsesCache(): void
    {
        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => 'test',
                    'e' => 'AQAB',
                ],
            ],
        ];

        // Cache should be called
        $this->cache->expects($this->once())
            ->method('get')
            ->with('apple_jwks_keys', $this->isType('callable'))
            ->willReturn($jwks);

        // HTTP client should NOT be called when cache hits
        $this->httpClient->expects($this->never())->method('request');

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            $this->cache,
            $this->clientId,
        );

        // This will still fail on JWT verification, but we've tested the caching path
        try {
            $verifier->verify('some.jwt.token');
        } catch (OAuthException $e) {
            // Expected - we can't sign a valid JWT in tests easily
            $this->assertStringContainsString('verification failed', $e->getMessage());
        }
    }

    public function testVerifyWithoutCacheFetchesJwks(): void
    {
        $jwks = [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'kid' => 'test-key-id',
                    'use' => 'sig',
                    'alg' => 'RS256',
                    'n' => 'test',
                    'e' => 'AQAB',
                ],
            ],
        ];

        // HTTP client SHOULD be called when no cache
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://appleid.apple.com/auth/keys', $this->anything())
            ->willReturn(new Response(200, [], json_encode($jwks, JSON_THROW_ON_ERROR)));

        $verifier = new AppleJwksVerifier(
            $this->httpClient,
            null, // No cache
            $this->clientId,
        );

        // This will still fail on JWT verification, but we've tested the fetch path
        try {
            $verifier->verify('some.jwt.token');
        } catch (OAuthException $e) {
            // Expected - we can't sign a valid JWT in tests easily
            $this->assertStringContainsString('verification failed', $e->getMessage());
        }
    }
}
