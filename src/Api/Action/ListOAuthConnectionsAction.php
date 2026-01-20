<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Action;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\ProviderFieldMapper;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * API action that returns connected OAuth providers for the current user.
 *
 * Returns a list of OAuth providers that are connected to the authenticated user's account.
 * Requires authentication.
 *
 * Usage:
 *   GET /api/v2/auth/oauth/connections
 *
 * Response:
 *   {
 *     "connections": [
 *       {"provider": "google", "displayName": "Google", "connectedAt": null},
 *       {"provider": "apple", "displayName": "Apple", "connectedAt": null}
 *     ]
 *   }
 */
#[AsController]
final class ListOAuthConnectionsAction
{
    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        private readonly Security $security,
        private readonly iterable $providers,
        private readonly ProviderFieldMapper $fieldMapper,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof ShopUserInterface) {
            return new JsonResponse([
                'code' => Response::HTTP_UNAUTHORIZED,
                'message' => 'Authentication required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $customer = $user->getCustomer();

        if (!$customer instanceof OAuthIdentityInterface) {
            return new JsonResponse([
                'connections' => [],
            ]);
        }

        $connections = [];

        foreach ($this->providers as $provider) {
            // Only check isEnabled() if provider is configurable, otherwise assume enabled
            if ($provider instanceof ConfigurableOAuthProviderInterface && !$provider->isEnabled()) {
                continue;
            }

            $providerName = $provider instanceof ConfigurableOAuthProviderInterface
                ? $provider->getName()
                : 'unknown';
            $displayName = $provider instanceof ConfigurableOAuthProviderInterface
                ? $provider->getDisplayName()
                : ucfirst($providerName);

            $providerId = $this->fieldMapper->getProviderId($customer, $providerName);

            if ($providerId !== null) {
                $connections[] = [
                    'provider' => $providerName,
                    'displayName' => $displayName,
                    'connectedAt' => null, // Could be enhanced with timestamp if stored
                ];
            }
        }

        return new JsonResponse([
            'connections' => $connections,
        ]);
    }
}
