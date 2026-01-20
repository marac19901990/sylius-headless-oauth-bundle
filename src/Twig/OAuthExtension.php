<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Twig;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function count;

final class OAuthExtension extends AbstractExtension
{
    private const PROVIDER_CONFIG = [
        'google' => [
            'name' => 'Google',
            'icon' => 'google',
            'color' => '#4285F4',
            'getter' => 'getGoogleId',
        ],
        'apple' => [
            'name' => 'Apple',
            'icon' => 'apple',
            'color' => '#000000',
            'getter' => 'getAppleId',
        ],
        'facebook' => [
            'name' => 'Facebook',
            'icon' => 'facebook',
            'color' => '#1877F2',
            'getter' => 'getFacebookId',
        ],
        'oidc' => [
            'name' => 'OIDC',
            'icon' => 'openid',
            'color' => '#F78C40',
            'getter' => 'getOidcId',
        ],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oauth_has_providers', [$this, 'hasConnectedProviders']),
            new TwigFunction('oauth_connected_providers', [$this, 'getConnectedProviders']),
            new TwigFunction('oauth_provider_config', [$this, 'getProviderConfig']),
            new TwigFunction('oauth_all_providers', [$this, 'getAllProviders']),
        ];
    }

    /**
     * Check if a customer has any connected OAuth providers.
     */
    public function hasConnectedProviders(mixed $customer): bool
    {
        if (!$customer instanceof OAuthIdentityInterface) {
            return false;
        }

        return count($this->getConnectedProviders($customer)) > 0;
    }

    /**
     * Get list of connected OAuth providers for a customer.
     *
     * @return array<string, array{name: string, icon: string, color: string, id: string}>
     */
    public function getConnectedProviders(mixed $customer): array
    {
        if (!$customer instanceof OAuthIdentityInterface) {
            return [];
        }

        $connected = [];

        foreach (self::PROVIDER_CONFIG as $key => $config) {
            $getter = $config['getter'];
            $providerId = $customer->$getter();

            if ($providerId !== null && $providerId !== '') {
                $connected[$key] = [
                    'name' => $config['name'],
                    'icon' => $config['icon'],
                    'color' => $config['color'],
                    'id' => $providerId,
                ];
            }
        }

        return $connected;
    }

    /**
     * Get configuration for a specific provider.
     *
     * @return array{name: string, icon: string, color: string, getter: string}|null
     */
    public function getProviderConfig(string $provider): ?array
    {
        return self::PROVIDER_CONFIG[$provider] ?? null;
    }

    /**
     * Get all supported providers configuration.
     *
     * @return array<string, array{name: string, icon: string, color: string, getter: string}>
     */
    public function getAllProviders(): array
    {
        return self::PROVIDER_CONFIG;
    }
}
