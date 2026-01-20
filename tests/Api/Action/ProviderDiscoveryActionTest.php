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
        $providers = [
            $this->createConfigurableProvider('google', true),
            $this->createConfigurableProvider('apple', true),
            $this->createConfigurableProvider('facebook', true),
            $this->createConfigurableProvider('keycloak', true),
            $this->createConfigurableProvider('auth0', true),
            $this->createConfigurableProvider('okta', true),
            $this->createConfigurableProvider('azure', true),
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

    public function testIgnoresNonConfigurableProviders(): void
    {
        $nonConfigurable = $this->createMock(OAuthProviderInterface::class);
        $configurable = $this->createConfigurableProvider('google', true);

        $action = new ProviderDiscoveryAction([$nonConfigurable, $configurable]);

        $response = $action();

        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['providers']);
        $this->assertSame('google', $data['providers'][0]['name']);
    }

    public function testResponseHasCorrectContentType(): void
    {
        $action = new ProviderDiscoveryAction([]);

        $response = $action();

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    private function createConfigurableProvider(string $name, bool $enabled): ConfigurableOAuthProviderInterface
    {
        $provider = $this->createMock(ConfigurableOAuthProviderInterface::class);

        $provider->method('getName')->willReturn($name);
        $provider->method('isEnabled')->willReturn($enabled);

        return $provider;
    }
}
