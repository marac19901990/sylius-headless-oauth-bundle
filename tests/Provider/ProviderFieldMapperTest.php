<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\ProviderFieldMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderFieldMapper::class)]
final class ProviderFieldMapperTest extends TestCase
{
    private ProviderFieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ProviderFieldMapper();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerFieldMappingProvider(): iterable
    {
        yield 'google' => ['google', 'googleId'];
        yield 'apple' => ['apple', 'appleId'];
    }

    #[Test]
    #[DataProvider('providerFieldMappingProvider')]
    public function mapsProviderToFieldName(string $provider, string $expectedField): void
    {
        self::assertSame($expectedField, $this->mapper->getFieldName($provider));
    }

    #[Test]
    public function throwsExceptionForUnknownProvider(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unknown provider: facebook');

        $this->mapper->getFieldName('facebook');
    }

    #[Test]
    public function setsGoogleIdOnEntity(): void
    {
        $entity = $this->createMockEntity();

        $this->mapper->setProviderId($entity, 'google', 'google-123');

        self::assertSame('google-123', $entity->getGoogleId());
        self::assertNull($entity->getAppleId());
    }

    #[Test]
    public function setsAppleIdOnEntity(): void
    {
        $entity = $this->createMockEntity();

        $this->mapper->setProviderId($entity, 'apple', 'apple-456');

        self::assertNull($entity->getGoogleId());
        self::assertSame('apple-456', $entity->getAppleId());
    }

    #[Test]
    public function throwsExceptionWhenSettingUnknownProvider(): void
    {
        $entity = $this->createMockEntity();

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unknown provider: twitter');

        $this->mapper->setProviderId($entity, 'twitter', 'twitter-789');
    }

    #[Test]
    public function getSupportedProvidersReturnsAllProviders(): void
    {
        $providers = $this->mapper->getSupportedProviders();

        self::assertContains('google', $providers);
        self::assertContains('apple', $providers);
        self::assertCount(2, $providers);
    }

    #[Test]
    public function isSupportedReturnsTrueForKnownProviders(): void
    {
        self::assertTrue($this->mapper->isSupported('google'));
        self::assertTrue($this->mapper->isSupported('apple'));
    }

    #[Test]
    public function isSupportedReturnsFalseForUnknownProviders(): void
    {
        self::assertFalse($this->mapper->isSupported('facebook'));
        self::assertFalse($this->mapper->isSupported('twitter'));
        self::assertFalse($this->mapper->isSupported(''));
    }

    private function createMockEntity(): OAuthIdentityInterface
    {
        return new class implements OAuthIdentityInterface {
            use OAuthIdentityTrait;
        };
    }
}
