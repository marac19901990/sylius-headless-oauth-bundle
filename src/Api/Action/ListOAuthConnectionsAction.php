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
    private readonly ProviderFieldMapper $fieldMapper;

    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        private readonly Security $security,
        private readonly iterable $providers,
        ?ProviderFieldMapper $fieldMapper = null,
    ) {
        $this->fieldMapper = $fieldMapper ?? new ProviderFieldMapper();
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
            if (!$provider instanceof ConfigurableOAuthProviderInterface) {
                continue;
            }

            if (!$provider->isEnabled()) {
                continue;
            }

            $providerName = $provider->getName();
            $providerId = $this->fieldMapper->getProviderId($customer, $providerName);

            if ($providerId !== null) {
                $connections[] = [
                    'provider' => $providerName,
                    'displayName' => $this->getDisplayName($providerName),
                    'connectedAt' => null, // Could be enhanced with timestamp if stored
                ];
            }
        }

        return new JsonResponse([
            'connections' => $connections,
        ]);
    }

    private function getDisplayName(string $name): string
    {
        $displayNames = [
            'google' => 'Google',
            'apple' => 'Apple',
            'facebook' => 'Facebook',
            'github' => 'GitHub',
            'keycloak' => 'Keycloak',
            'auth0' => 'Auth0',
            'okta' => 'Okta',
            'azure' => 'Microsoft Azure',
        ];

        return $displayNames[$name] ?? ucfirst($name);
    }
}
