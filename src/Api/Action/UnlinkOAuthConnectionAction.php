<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Action;

use Doctrine\ORM\EntityManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Repository\OAuthIdentityRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * API action to unlink an OAuth provider from the current user's account.
 *
 * Allows users to disconnect OAuth providers from their account.
 * The OAuthIdentity record will be removed.
 *
 * Usage:
 *   DELETE /api/v2/auth/oauth/connections/{provider}
 *
 * Response (success):
 *   {
 *     "message": "Provider disconnected successfully",
 *     "provider": "google"
 *   }
 *
 * Response (error):
 *   {
 *     "code": 400,
 *     "message": "Provider is not connected to this account"
 *   }
 */
#[AsController]
final class UnlinkOAuthConnectionAction
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly OAuthIdentityRepositoryInterface $oauthIdentityRepository,
    ) {
    }

    public function __invoke(string $provider): JsonResponse
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
                'code' => Response::HTTP_BAD_REQUEST,
                'message' => 'Customer not found',
            ], Response::HTTP_BAD_REQUEST);
        }

        $providerName = strtolower($provider);
        $oauthIdentity = $this->oauthIdentityRepository->findByCustomerAndProvider($customer, $providerName);

        if ($oauthIdentity === null) {
            return new JsonResponse([
                'code' => Response::HTTP_BAD_REQUEST,
                'message' => 'Provider is not connected to this account',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if user would still have a way to login after unlinking
        if (!$this->canUnlinkSafely($customer, $providerName, $user)) {
            return new JsonResponse([
                'code' => Response::HTTP_BAD_REQUEST,
                'message' => 'Cannot unlink the last authentication method. Please set a password first or connect another provider.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Remove the OAuth identity
        $this->entityManager->remove($oauthIdentity);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Provider disconnected successfully',
            'provider' => $providerName,
        ]);
    }

    /**
     * Check if the user can safely unlink a provider.
     *
     * Returns true if:
     * - User has a password set, OR
     * - User has at least one other OAuth provider connected
     */
    private function canUnlinkSafely(
        CustomerInterface $customer,
        string $providerToRemove,
        ShopUserInterface $user,
    ): bool {
        // Check if user has a password
        $password = $user->getPassword();
        if ($password !== null && $password !== '') {
            return true;
        }

        // Check for other connected providers using the repository
        return $this->oauthIdentityRepository->hasOtherProviders($customer, $providerToRemove);
    }
}
