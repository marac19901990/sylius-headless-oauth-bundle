<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Unit\Twig;

use DateTimeImmutable;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
use Marac\SyliusHeadlessOAuthBundle\Twig\OAuthExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Sylius\Component\Core\Model\CustomerInterface;

final class OAuthExtensionTest extends TestCase
{
    private OAuthIdentityRepositoryInterface&MockObject $repository;
    private OAuthExtension $extension;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OAuthIdentityRepositoryInterface::class);
        $this->extension = new OAuthExtension($this->repository);
    }

    public function testGetFunctionsReturnsExpectedFunctions(): void
    {
        $functions = $this->extension->getFunctions();

        $this->assertCount(4, $functions);

        $functionNames = array_map(
            static fn ($function) => $function->getName(),
            $functions,
        );

        $this->assertContains('oauth_has_providers', $functionNames);
        $this->assertContains('oauth_connected_providers', $functionNames);
        $this->assertContains('oauth_provider_config', $functionNames);
        $this->assertContains('oauth_all_providers', $functionNames);
    }

    public function testHasConnectedProvidersReturnsFalseForNonCustomerInterface(): void
    {
        $this->assertFalse($this->extension->hasConnectedProviders(new stdClass()));
        $this->assertFalse($this->extension->hasConnectedProviders(null));
        $this->assertFalse($this->extension->hasConnectedProviders('string'));
    }

    public function testHasConnectedProvidersReturnsFalseWhenNoProvidersConnected(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([]);

        $this->assertFalse($this->extension->hasConnectedProviders($customer));
    }

    public function testHasConnectedProvidersReturnsTrueWhenGoogleConnected(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $identity = $this->createMock(OAuthIdentityInterface::class);
        $identity->method('getProvider')->willReturn('google');
        $identity->method('getIdentifier')->willReturn('google-123');
        $identity->method('getConnectedAt')->willReturn(new DateTimeImmutable());

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([$identity]);

        $this->assertTrue($this->extension->hasConnectedProviders($customer));
    }

    public function testHasConnectedProvidersReturnsTrueWhenAppleConnected(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $identity = $this->createMock(OAuthIdentityInterface::class);
        $identity->method('getProvider')->willReturn('apple');
        $identity->method('getIdentifier')->willReturn('apple-456');
        $identity->method('getConnectedAt')->willReturn(new DateTimeImmutable());

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([$identity]);

        $this->assertTrue($this->extension->hasConnectedProviders($customer));
    }

    public function testGetConnectedProvidersReturnsEmptyArrayForNonCustomerInterface(): void
    {
        $this->assertSame([], $this->extension->getConnectedProviders(new stdClass()));
        $this->assertSame([], $this->extension->getConnectedProviders(null));
    }

    public function testGetConnectedProvidersReturnsEmptyArrayWhenNoProviders(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([]);

        $this->assertSame([], $this->extension->getConnectedProviders($customer));
    }

    public function testGetConnectedProvidersReturnsGoogleWhenConnected(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $connectedAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');

        $identity = $this->createMock(OAuthIdentityInterface::class);
        $identity->method('getProvider')->willReturn('google');
        $identity->method('getIdentifier')->willReturn('google-123');
        $identity->method('getConnectedAt')->willReturn($connectedAt);

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([$identity]);

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertArrayHasKey('google', $providers);
        $this->assertSame('Google', $providers['google']['name']);
        $this->assertSame('google-123', $providers['google']['identifier']);
        $this->assertSame('#4285F4', $providers['google']['color']);
        $this->assertSame('2024-01-15T10:30:00+00:00', $providers['google']['connectedAt']);
    }

    public function testGetConnectedProvidersReturnsAllConnectedProviders(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $googleIdentity = $this->createMock(OAuthIdentityInterface::class);
        $googleIdentity->method('getProvider')->willReturn('google');
        $googleIdentity->method('getIdentifier')->willReturn('google-123');
        $googleIdentity->method('getConnectedAt')->willReturn(new DateTimeImmutable());

        $appleIdentity = $this->createMock(OAuthIdentityInterface::class);
        $appleIdentity->method('getProvider')->willReturn('apple');
        $appleIdentity->method('getIdentifier')->willReturn('apple-456');
        $appleIdentity->method('getConnectedAt')->willReturn(new DateTimeImmutable());

        $facebookIdentity = $this->createMock(OAuthIdentityInterface::class);
        $facebookIdentity->method('getProvider')->willReturn('facebook');
        $facebookIdentity->method('getIdentifier')->willReturn('facebook-789');
        $facebookIdentity->method('getConnectedAt')->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([$googleIdentity, $appleIdentity, $facebookIdentity]);

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertCount(3, $providers);
        $this->assertArrayHasKey('google', $providers);
        $this->assertArrayHasKey('apple', $providers);
        $this->assertArrayHasKey('facebook', $providers);

        $this->assertSame('google-123', $providers['google']['identifier']);
        $this->assertSame('apple-456', $providers['apple']['identifier']);
        $this->assertSame('facebook-789', $providers['facebook']['identifier']);
        $this->assertNull($providers['facebook']['connectedAt']);
    }

    public function testGetConnectedProvidersHandlesUnknownProvider(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $identity = $this->createMock(OAuthIdentityInterface::class);
        $identity->method('getProvider')->willReturn('keycloak');
        $identity->method('getIdentifier')->willReturn('keycloak-user-123');
        $identity->method('getConnectedAt')->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('findAllByCustomer')
            ->with($customer)
            ->willReturn([$identity]);

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertArrayHasKey('keycloak', $providers);
        $this->assertSame('Keycloak', $providers['keycloak']['name']);
        $this->assertSame('key', $providers['keycloak']['icon']);
        $this->assertSame('#666666', $providers['keycloak']['color']);
    }

    public function testGetProviderConfigReturnsConfigForValidProvider(): void
    {
        $googleConfig = $this->extension->getProviderConfig('google');

        $this->assertIsArray($googleConfig);
        $this->assertSame('Google', $googleConfig['name']);
        $this->assertSame('google', $googleConfig['icon']);
        $this->assertSame('#4285F4', $googleConfig['color']);
    }

    public function testGetProviderConfigReturnsNullForInvalidProvider(): void
    {
        $this->assertNull($this->extension->getProviderConfig('invalid'));
        $this->assertNull($this->extension->getProviderConfig(''));
    }

    public function testGetAllProvidersReturnsAllProviderConfigs(): void
    {
        $providers = $this->extension->getAllProviders();

        $this->assertCount(6, $providers);
        $this->assertArrayHasKey('google', $providers);
        $this->assertArrayHasKey('apple', $providers);
        $this->assertArrayHasKey('facebook', $providers);
        $this->assertArrayHasKey('github', $providers);
        $this->assertArrayHasKey('linkedin', $providers);
        $this->assertArrayHasKey('oidc', $providers);
    }

    public function testAppleProviderConfig(): void
    {
        $appleConfig = $this->extension->getProviderConfig('apple');

        $this->assertNotNull($appleConfig);
        $this->assertSame('Apple', $appleConfig['name']);
        $this->assertSame('apple', $appleConfig['icon']);
        $this->assertSame('#000000', $appleConfig['color']);
    }

    public function testFacebookProviderConfig(): void
    {
        $facebookConfig = $this->extension->getProviderConfig('facebook');

        $this->assertNotNull($facebookConfig);
        $this->assertSame('Facebook', $facebookConfig['name']);
        $this->assertSame('facebook', $facebookConfig['icon']);
        $this->assertSame('#1877F2', $facebookConfig['color']);
    }

    public function testGitHubProviderConfig(): void
    {
        $githubConfig = $this->extension->getProviderConfig('github');

        $this->assertNotNull($githubConfig);
        $this->assertSame('GitHub', $githubConfig['name']);
        $this->assertSame('github', $githubConfig['icon']);
        $this->assertSame('#333333', $githubConfig['color']);
    }

    public function testLinkedInProviderConfig(): void
    {
        $linkedinConfig = $this->extension->getProviderConfig('linkedin');

        $this->assertNotNull($linkedinConfig);
        $this->assertSame('LinkedIn', $linkedinConfig['name']);
        $this->assertSame('linkedin', $linkedinConfig['icon']);
        $this->assertSame('#0A66C2', $linkedinConfig['color']);
    }

    public function testOidcProviderConfig(): void
    {
        $oidcConfig = $this->extension->getProviderConfig('oidc');

        $this->assertNotNull($oidcConfig);
        $this->assertSame('OIDC', $oidcConfig['name']);
        $this->assertSame('openid', $oidcConfig['icon']);
        $this->assertSame('#F78C40', $oidcConfig['color']);
    }
}
