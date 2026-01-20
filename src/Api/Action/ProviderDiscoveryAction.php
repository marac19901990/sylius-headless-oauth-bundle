<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Action;

use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * API action that returns the list of enabled OAuth providers.
 *
 * This allows frontend applications to dynamically render OAuth login buttons
 * based on which providers are configured and enabled in the backend.
 *
 * Usage:
 *   GET /api/v2/auth/oauth/providers
 *
 * Response:
 *   {
 *     "providers": [
 *       {"name": "google", "displayName": "Google"},
 *       {"name": "apple", "displayName": "Apple"}
 *     ]
 *   }
 */
#[AsController]
final class ProviderDiscoveryAction
{
    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $enabledProviders = [];

        foreach ($this->providers as $provider) {
            // Only check isEnabled() if provider is configurable, otherwise assume enabled
            if ($provider instanceof ConfigurableOAuthProviderInterface && !$provider->isEnabled()) {
                continue;
            }

            $name = $provider instanceof ConfigurableOAuthProviderInterface
                ? $provider->getName()
                : 'unknown';
            $displayName = $provider instanceof ConfigurableOAuthProviderInterface
                ? $provider->getDisplayName()
                : ucfirst($name);

            $enabledProviders[] = [
                'name' => $name,
                'displayName' => $displayName,
            ];
        }

        return new JsonResponse([
            'providers' => $enabledProviders,
        ]);
    }
}
