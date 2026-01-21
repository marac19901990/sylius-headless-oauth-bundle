<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Twig;

use DateTimeInterface;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
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
        ],
        'apple' => [
            'name' => 'Apple',
            'icon' => 'apple',
            'color' => '#000000',
        ],
        'facebook' => [
            'name' => 'Facebook',
            'icon' => 'facebook',
            'color' => '#1877F2',
        ],
        'github' => [
            'name' => 'GitHub',
            'icon' => 'github',
            'color' => '#333333',
        ],
        'linkedin' => [
            'name' => 'LinkedIn',
            'icon' => 'linkedin',
            'color' => '#0A66C2',
        ],
        'oidc' => [
            'name' => 'OIDC',
            'icon' => 'openid',
            'color' => '#F78C40',
        ],
    ];

    public function __construct(
        private readonly OAuthIdentityRepositoryInterface $oauthIdentityRepository,
    ) {
    }

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
        if (!$customer instanceof CustomerInterface) {
            return false;
        }

        return count($this->getConnectedProviders($customer)) > 0;
    }

    /**
     * Get list of connected OAuth providers for a customer.
     *
     * @return array<string, array{name: string, icon: string, color: string, identifier: string, connectedAt: string|null}>
     */
    public function getConnectedProviders(mixed $customer): array
    {
        if (!$customer instanceof CustomerInterface) {
            return [];
        }

        $identities = $this->oauthIdentityRepository->findAllByCustomer($customer);
        $connected = [];

        foreach ($identities as $identity) {
            $provider = $identity->getProvider();
            $config = self::PROVIDER_CONFIG[$provider] ?? [
                'name' => ucfirst($provider),
                'icon' => 'key',
                'color' => '#666666',
            ];

            $connectedAt = $identity->getConnectedAt();

            $connected[$provider] = [
                'name' => $config['name'],
                'icon' => $config['icon'],
                'color' => $config['color'],
                'identifier' => $identity->getIdentifier(),
                'connectedAt' => $connectedAt?->format(DateTimeInterface::ATOM),
            ];
        }

        return $connected;
    }

    /**
     * Get configuration for a specific provider.
     *
     * @return array{name: string, icon: string, color: string}|null
     */
    public function getProviderConfig(string $provider): ?array
    {
        return self::PROVIDER_CONFIG[$provider] ?? null;
    }

    /**
     * Get all supported providers configuration.
     *
     * @return array<string, array{name: string, icon: string, color: string}>
     */
    public function getAllProviders(): array
    {
        return self::PROVIDER_CONFIG;
    }
}
