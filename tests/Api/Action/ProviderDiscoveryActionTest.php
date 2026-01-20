<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Api\Action;

use Marac\SyliusHeadlessOAuthBundle\Api\Action\ProviderDiscoveryAction;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

class ProviderDiscoveryActionTest extends TestCase
{
    public function testReturnsEmptyProvidersWhenNoneConfigured(): void
    {
        $action = new ProviderDiscoveryAction([]);

        $response = $action();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame(['providers' => []], $data);
    }

    public function testReturnsOnlyEnabledProviders(): void
    {
        $enabledProvider = $this->createConfigurableProvider('google', true);
        $disabledProvider = $this->createConfigurableProvider('apple', false);

        $action = new ProviderDiscoveryAction([$enabledProvider, $disabledProvider]);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['providers']);
        $this->assertSame('google', $data['providers'][0]['name']);
        $this->assertSame('Google', $data['providers'][0]['displayName']);
    }

    public function testReturnsMultipleEnabledProviders(): void
    {
        $googleProvider = $this->createConfigurableProvider('google', true);
        $appleProvider = $this->createConfigurableProvider('apple', true);
        $facebookProvider = $this->createConfigurableProvider('facebook', true);

        $action = new ProviderDiscoveryAction([$googleProvider, $appleProvider, $facebookProvider]);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        $this->assertCount(3, $data['providers']);

        $names = array_column($data['providers'], 'name');
        $this->assertContains('google', $names);
        $this->assertContains('apple', $names);
        $this->assertContains('facebook', $names);
    }

    public function testMapsDisplayNamesCorrectly(): void
    {
        // Display names now come from the provider's getDisplayName() method
        $providers = [
            $this->createConfigurableProvider('google', true, 'Google'),
            $this->createConfigurableProvider('apple', true, 'Apple'),
            $this->createConfigurableProvider('facebook', true, 'Facebook'),
            $this->createConfigurableProvider('keycloak', true, 'Keycloak'),
            $this->createConfigurableProvider('auth0', true, 'Auth0'),
            $this->createConfigurableProvider('okta', true, 'Okta'),
            $this->createConfigurableProvider('azure', true, 'Microsoft Azure'),
        ];

        $action = new ProviderDiscoveryAction($providers);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        $expectedMappings = [
            'google' => 'Google',
            'apple' => 'Apple',
            'facebook' => 'Facebook',
            'keycloak' => 'Keycloak',
            'auth0' => 'Auth0',
            'okta' => 'Okta',
            'azure' => 'Microsoft Azure',
        ];

        foreach ($data['providers'] as $provider) {
            $this->assertSame($expectedMappings[$provider['name']], $provider['displayName']);
        }
    }

    public function testFallsBackToUcfirstForUnknownProviders(): void
    {
        $customProvider = $this->createConfigurableProvider('mycompany', true);

        $action = new ProviderDiscoveryAction([$customProvider]);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        $this->assertSame('Mycompany', $data['providers'][0]['displayName']);
    }

    public function testIncludesNonConfigurableProvidersAsEnabled(): void
    {
        // Non-configurable providers are assumed to be enabled (no way to check)
        $nonConfigurable = $this->createMock(OAuthProviderInterface::class);
        $configurable = $this->createConfigurableProvider('google', true, 'Google');

        $action = new ProviderDiscoveryAction([$nonConfigurable, $configurable]);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        // Both providers are included - non-configurable assumed enabled
        $this->assertCount(2, $data['providers']);

        $names = array_column($data['providers'], 'name');
        $this->assertContains('unknown', $names); // Non-configurable gets 'unknown' as name
        $this->assertContains('google', $names);
    }

    public function testResponseHasCorrectContentType(): void
    {
        $action = new ProviderDiscoveryAction([]);

        $response = $action();

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    private function createConfigurableProvider(string $name, bool $enabled, ?string $displayName = null): ConfigurableOAuthProviderInterface
    {
        $provider = $this->createMock(ConfigurableOAuthProviderInterface::class);

        $provider->method('getName')->willReturn($name);
        $provider->method('isEnabled')->willReturn($enabled);
        $provider->method('getDisplayName')->willReturn($displayName ?? ucfirst($name));

        return $provider;
    }
}
