<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Unit\Twig;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Twig\OAuthExtension;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OAuthExtensionTest extends TestCase
{
    private OAuthExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new OAuthExtension();
    }

    public function testGetFunctionsReturnsExpectedFunctions(): void
    {
        $functions = $this->extension->getFunctions();

        $this->assertCount(4, $functions);

        $functionNames = array_map(
            fn ($function) => $function->getName(),
            $functions,
        );

        $this->assertContains('oauth_has_providers', $functionNames);
        $this->assertContains('oauth_connected_providers', $functionNames);
        $this->assertContains('oauth_provider_config', $functionNames);
        $this->assertContains('oauth_all_providers', $functionNames);
    }

    public function testHasConnectedProvidersReturnsFalseForNonOAuthIdentity(): void
    {
        $this->assertFalse($this->extension->hasConnectedProviders(new stdClass()));
        $this->assertFalse($this->extension->hasConnectedProviders(null));
        $this->assertFalse($this->extension->hasConnectedProviders('string'));
    }

    public function testHasConnectedProvidersReturnsFalseWhenNoProvidersConnected(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn(null);
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn(null);

        $this->assertFalse($this->extension->hasConnectedProviders($customer));
    }

    public function testHasConnectedProvidersReturnsTrueWhenGoogleConnected(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn('google-123');
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn(null);

        $this->assertTrue($this->extension->hasConnectedProviders($customer));
    }

    public function testHasConnectedProvidersReturnsTrueWhenAppleConnected(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn(null);
        $customer->method('getAppleId')->willReturn('apple-456');
        $customer->method('getFacebookId')->willReturn(null);

        $this->assertTrue($this->extension->hasConnectedProviders($customer));
    }

    public function testHasConnectedProvidersReturnsTrueWhenFacebookConnected(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn(null);
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn('facebook-789');

        $this->assertTrue($this->extension->hasConnectedProviders($customer));
    }

    public function testGetConnectedProvidersReturnsEmptyArrayForNonOAuthIdentity(): void
    {
        $this->assertSame([], $this->extension->getConnectedProviders(new stdClass()));
        $this->assertSame([], $this->extension->getConnectedProviders(null));
    }

    public function testGetConnectedProvidersReturnsEmptyArrayWhenNoProviders(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn(null);
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn(null);

        $this->assertSame([], $this->extension->getConnectedProviders($customer));
    }

    public function testGetConnectedProvidersReturnsGoogleWhenConnected(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn('google-123');
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn(null);

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertArrayHasKey('google', $providers);
        $this->assertSame('Google', $providers['google']['name']);
        $this->assertSame('google-123', $providers['google']['id']);
        $this->assertSame('#4285F4', $providers['google']['color']);
    }

    public function testGetConnectedProvidersReturnsAllConnectedProviders(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn('google-123');
        $customer->method('getAppleId')->willReturn('apple-456');
        $customer->method('getFacebookId')->willReturn('facebook-789');

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertCount(3, $providers);
        $this->assertArrayHasKey('google', $providers);
        $this->assertArrayHasKey('apple', $providers);
        $this->assertArrayHasKey('facebook', $providers);

        $this->assertSame('google-123', $providers['google']['id']);
        $this->assertSame('apple-456', $providers['apple']['id']);
        $this->assertSame('facebook-789', $providers['facebook']['id']);
    }

    public function testGetConnectedProvidersIgnoresEmptyStrings(): void
    {
        $customer = $this->createMock(OAuthIdentityInterface::class);
        $customer->method('getGoogleId')->willReturn('');
        $customer->method('getAppleId')->willReturn(null);
        $customer->method('getFacebookId')->willReturn('facebook-789');

        $providers = $this->extension->getConnectedProviders($customer);

        $this->assertCount(1, $providers);
        $this->assertArrayNotHasKey('google', $providers);
        $this->assertArrayHasKey('facebook', $providers);
    }

    public function testGetProviderConfigReturnsConfigForValidProvider(): void
    {
        $googleConfig = $this->extension->getProviderConfig('google');

        $this->assertIsArray($googleConfig);
        $this->assertSame('Google', $googleConfig['name']);
        $this->assertSame('google', $googleConfig['icon']);
        $this->assertSame('#4285F4', $googleConfig['color']);
        $this->assertSame('getGoogleId', $googleConfig['getter']);
    }

    public function testGetProviderConfigReturnsNullForInvalidProvider(): void
    {
        $this->assertNull($this->extension->getProviderConfig('invalid'));
        $this->assertNull($this->extension->getProviderConfig(''));
    }

    public function testGetAllProvidersReturnsAllProviderConfigs(): void
    {
        $providers = $this->extension->getAllProviders();

        $this->assertCount(4, $providers);
        $this->assertArrayHasKey('google', $providers);
        $this->assertArrayHasKey('apple', $providers);
        $this->assertArrayHasKey('facebook', $providers);
        $this->assertArrayHasKey('oidc', $providers);
    }

    public function testAppleProviderConfig(): void
    {
        $appleConfig = $this->extension->getProviderConfig('apple');

        $this->assertNotNull($appleConfig);
        $this->assertSame('Apple', $appleConfig['name']);
        $this->assertSame('apple', $appleConfig['icon']);
        $this->assertSame('#000000', $appleConfig['color']);
        $this->assertSame('getAppleId', $appleConfig['getter']);
    }

    public function testFacebookProviderConfig(): void
    {
        $facebookConfig = $this->extension->getProviderConfig('facebook');

        $this->assertNotNull($facebookConfig);
        $this->assertSame('Facebook', $facebookConfig['name']);
        $this->assertSame('facebook', $facebookConfig['icon']);
        $this->assertSame('#1877F2', $facebookConfig['color']);
        $this->assertSame('getFacebookId', $facebookConfig['getter']);
    }

    public function testOidcProviderConfig(): void
    {
        $oidcConfig = $this->extension->getProviderConfig('oidc');

        $this->assertNotNull($oidcConfig);
        $this->assertSame('OIDC', $oidcConfig['name']);
        $this->assertSame('openid', $oidcConfig['icon']);
        $this->assertSame('#F78C40', $oidcConfig['color']);
        $this->assertSame('getOidcId', $oidcConfig['getter']);
    }
}
