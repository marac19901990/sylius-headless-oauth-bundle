<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider\Apple;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGenerator;
use PHPUnit\Framework\TestCase;

class AppleClientSecretGeneratorTest extends TestCase
{
    private string $testKeyPath = '';
    private string $testPrivateKey = '';
    private string $testPublicKey = '';

    protected function setUp(): void
    {
        // Generate a test EC key pair for testing
        $keyPair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyPair === false) {
            $this->markTestSkipped('Unable to generate EC key pair for testing');
        }

        $privateKey = '';
        openssl_pkey_export($keyPair, $privateKey);
        $this->testPrivateKey = $privateKey;

        $details = openssl_pkey_get_details($keyPair);
        $this->testPublicKey = $details['key'];

        // Write the private key to a temp file
        $this->testKeyPath = sys_get_temp_dir() . '/test_apple_key_' . uniqid() . '.p8';
        file_put_contents($this->testKeyPath, $this->testPrivateKey);
    }

    protected function tearDown(): void
    {
        if ($this->testKeyPath !== '' && file_exists($this->testKeyPath)) {
            unlink($this->testKeyPath);
        }
    }

    public function testGeneratesValidJwt(): void
    {
        $generator = new AppleClientSecretGenerator(
            clientId: 'com.test.app',
            teamId: 'TEAM123456',
            keyId: 'KEY123456',
            privateKeyPath: $this->testKeyPath,
        );

        $token = $generator->generate();

        // Verify it's a valid JWT structure
        $this->assertIsString($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT should have 3 parts');

        // Decode and verify using the public key
        $decoded = JWT::decode($token, new Key($this->testPublicKey, 'ES256'));

        $this->assertSame('https://appleid.apple.com', $decoded->aud);
        $this->assertSame('TEAM123456', $decoded->iss);
        $this->assertSame('com.test.app', $decoded->sub);
        $this->assertIsInt($decoded->iat);
        $this->assertIsInt($decoded->exp);
        $this->assertGreaterThan($decoded->iat, $decoded->exp);
    }

    public function testJwtHasCorrectHeader(): void
    {
        $generator = new AppleClientSecretGenerator(
            clientId: 'com.test.app',
            teamId: 'TEAM123456',
            keyId: 'KEY123456',
            privateKeyPath: $this->testKeyPath,
        );

        $token = $generator->generate();
        $parts = explode('.', $token);
        $header = json_decode($this->base64UrlDecode($parts[0]), true);

        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertSame('KEY123456', $header['kid']);
    }

    public function testExpiryIsRespected(): void
    {
        $generator = new AppleClientSecretGenerator(
            clientId: 'com.test.app',
            teamId: 'TEAM123456',
            keyId: 'KEY123456',
            privateKeyPath: $this->testKeyPath,
        );

        $expirySeconds = 7200; // 2 hours
        $beforeGeneration = time();
        $token = $generator->generate($expirySeconds);
        $afterGeneration = time();

        $decoded = JWT::decode($token, new Key($this->testPublicKey, 'ES256'));

        // exp should be iat + expirySeconds (with some tolerance for test execution time)
        $expectedExpMin = $beforeGeneration + $expirySeconds;
        $expectedExpMax = $afterGeneration + $expirySeconds;

        $this->assertGreaterThanOrEqual($expectedExpMin, $decoded->exp);
        $this->assertLessThanOrEqual($expectedExpMax, $decoded->exp);
    }

    public function testExpiryIsCappedAtSixMonths(): void
    {
        $generator = new AppleClientSecretGenerator(
            clientId: 'com.test.app',
            teamId: 'TEAM123456',
            keyId: 'KEY123456',
            privateKeyPath: $this->testKeyPath,
        );

        $maxExpiry = 15777000; // ~6 months
        $requestedExpiry = 31536000; // 1 year (exceeds max)

        $beforeGeneration = time();
        $token = $generator->generate($requestedExpiry);

        $decoded = JWT::decode($token, new Key($this->testPublicKey, 'ES256'));

        // Expiry should be capped at max
        $actualExpiry = $decoded->exp - $decoded->iat;
        $this->assertLessThanOrEqual($maxExpiry, $actualExpiry);
    }

    public function testThrowsExceptionForMissingKeyFile(): void
    {
        $generator = new AppleClientSecretGenerator(
            clientId: 'com.test.app',
            teamId: 'TEAM123456',
            keyId: 'KEY123456',
            privateKeyPath: '/nonexistent/path/key.p8',
        );

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('not found');

        $generator->generate();
    }

    public function testDifferentConfigurationsProduceDifferentTokens(): void
    {
        $generator1 = new AppleClientSecretGenerator(
            clientId: 'com.app.one',
            teamId: 'TEAM111111',
            keyId: 'KEY111111',
            privateKeyPath: $this->testKeyPath,
        );

        $generator2 = new AppleClientSecretGenerator(
            clientId: 'com.app.two',
            teamId: 'TEAM222222',
            keyId: 'KEY222222',
            privateKeyPath: $this->testKeyPath,
        );

        $token1 = $generator1->generate();
        $token2 = $generator2->generate();

        $this->assertNotSame($token1, $token2);

        // Verify payloads are different
        $decoded1 = JWT::decode($token1, new Key($this->testPublicKey, 'ES256'));
        $decoded2 = JWT::decode($token2, new Key($this->testPublicKey, 'ES256'));

        $this->assertSame('com.app.one', $decoded1->sub);
        $this->assertSame('com.app.two', $decoded2->sub);
        $this->assertSame('TEAM111111', $decoded1->iss);
        $this->assertSame('TEAM222222', $decoded2->iss);
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
