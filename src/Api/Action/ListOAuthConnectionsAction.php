<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Action;

use DateTimeInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\ConfigurableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
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
 *       {"provider": "google", "displayName": "Google", "connectedAt": "2024-01-15T10:30:00+00:00"},
 *       {"provider": "apple", "displayName": "Apple", "connectedAt": "2024-01-10T08:00:00+00:00"}
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
        private readonly OAuthIdentityRepositoryInterface $oauthIdentityRepository,
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

        if (!$customer instanceof CustomerInterface) {
            return new JsonResponse([
                'connections' => [],
            ]);
        }

        // Get all OAuth identities for this customer
        $identities = $this->oauthIdentityRepository->findAllByCustomer($customer);

        // Build a lookup map of connected providers
        $connectedProviders = [];
        foreach ($identities as $identity) {
            $connectedProviders[$identity->getProvider()] = $identity;
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

            if (isset($connectedProviders[$providerName])) {
                $identity = $connectedProviders[$providerName];
                $connectedAt = $identity->getConnectedAt();

                $connections[] = [
                    'provider' => $providerName,
                    'displayName' => $displayName,
                    'connectedAt' => $connectedAt?->format(DateTimeInterface::ATOM),
                ];
            }
        }

        return new JsonResponse([
            'connections' => $connections,
        ]);
    }
}
