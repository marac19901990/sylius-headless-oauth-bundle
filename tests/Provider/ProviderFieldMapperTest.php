<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Provider;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;
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
        yield 'facebook' => ['facebook', 'facebookId'];
        yield 'github' => ['github', 'githubId'];
        yield 'linkedin' => ['linkedin', 'linkedinId'];
        yield 'oidc' => ['oidc', 'oidcId'];
    }

    #[Test]
    #[DataProvider('providerFieldMappingProvider')]
    public function mapsProviderToFieldName(string $provider, string $expectedField): void
    {
        self::assertSame($expectedField, $this->mapper->getFieldName($provider));
    }

    #[Test]
    public function unknownProvidersFallBackToOidcId(): void
    {
        // Unknown providers should fall back to oidcId field
        self::assertSame('oidcId', $this->mapper->getFieldName('keycloak'));
        self::assertSame('oidcId', $this->mapper->getFieldName('auth0'));
        self::assertSame('oidcId', $this->mapper->getFieldName('custom-idp'));
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
    public function setsFacebookIdOnEntity(): void
    {
        $entity = $this->createMockEntity();

        $this->mapper->setProviderId($entity, 'facebook', 'facebook-789');

        self::assertNull($entity->getGoogleId());
        self::assertNull($entity->getAppleId());
        self::assertSame('facebook-789', $entity->getFacebookId());
    }

    #[Test]
    public function setsOidcIdOnEntity(): void
    {
        $entity = $this->createMockEntity();

        $this->mapper->setProviderId($entity, 'oidc', 'oidc-sub-123');

        self::assertNull($entity->getGoogleId());
        self::assertNull($entity->getAppleId());
        self::assertNull($entity->getFacebookId());
        self::assertSame('oidc-sub-123', $entity->getOidcId());
    }

    #[Test]
    public function unknownProviderSetsOidcIdOnEntity(): void
    {
        $entity = $this->createMockEntity();

        $this->mapper->setProviderId($entity, 'keycloak', 'keycloak-user-456');

        self::assertNull($entity->getGoogleId());
        self::assertNull($entity->getAppleId());
        self::assertNull($entity->getFacebookId());
        self::assertSame('keycloak-user-456', $entity->getOidcId());
    }

    #[Test]
    public function getsProviderIdFromEntity(): void
    {
        $entity = $this->createMockEntity();
        $entity->setGoogleId('google-123');
        $entity->setAppleId('apple-456');
        $entity->setFacebookId('facebook-789');
        $entity->setOidcId('oidc-sub');

        self::assertSame('google-123', $this->mapper->getProviderId($entity, 'google'));
        self::assertSame('apple-456', $this->mapper->getProviderId($entity, 'apple'));
        self::assertSame('facebook-789', $this->mapper->getProviderId($entity, 'facebook'));
        self::assertSame('oidc-sub', $this->mapper->getProviderId($entity, 'oidc'));
        self::assertSame('oidc-sub', $this->mapper->getProviderId($entity, 'keycloak'));
    }

    #[Test]
    public function getBuiltInProvidersReturnsAllProviders(): void
    {
        $providers = $this->mapper->getBuiltInProviders();

        self::assertContains('google', $providers);
        self::assertContains('apple', $providers);
        self::assertContains('facebook', $providers);
        self::assertContains('github', $providers);
        self::assertContains('linkedin', $providers);
        self::assertContains('oidc', $providers);
        self::assertCount(6, $providers);
    }

    #[Test]
    public function isBuiltInProviderReturnsTrueForKnownProviders(): void
    {
        self::assertTrue($this->mapper->isBuiltInProvider('google'));
        self::assertTrue($this->mapper->isBuiltInProvider('apple'));
        self::assertTrue($this->mapper->isBuiltInProvider('facebook'));
        self::assertTrue($this->mapper->isBuiltInProvider('github'));
        self::assertTrue($this->mapper->isBuiltInProvider('linkedin'));
        self::assertTrue($this->mapper->isBuiltInProvider('oidc'));
    }

    #[Test]
    public function isBuiltInProviderReturnsFalseForUnknownProviders(): void
    {
        self::assertFalse($this->mapper->isBuiltInProvider('keycloak'));
        self::assertFalse($this->mapper->isBuiltInProvider('auth0'));
        self::assertFalse($this->mapper->isBuiltInProvider(''));
    }

    #[Test]
    public function usesOidcFieldReturnsTrueForOidcAndCustomProviders(): void
    {
        self::assertTrue($this->mapper->usesOidcField('oidc'));
        self::assertTrue($this->mapper->usesOidcField('keycloak'));
        self::assertTrue($this->mapper->usesOidcField('auth0'));
        self::assertTrue($this->mapper->usesOidcField('custom-idp'));
    }

    #[Test]
    public function usesOidcFieldReturnsFalseForBuiltInProviders(): void
    {
        self::assertFalse($this->mapper->usesOidcField('google'));
        self::assertFalse($this->mapper->usesOidcField('apple'));
        self::assertFalse($this->mapper->usesOidcField('facebook'));
        self::assertFalse($this->mapper->usesOidcField('github'));
        self::assertFalse($this->mapper->usesOidcField('linkedin'));
    }

    private function createMockEntity(): OAuthIdentityInterface
    {
        return new class implements OAuthIdentityInterface {
            use OAuthIdentityTrait;
        };
    }
}
