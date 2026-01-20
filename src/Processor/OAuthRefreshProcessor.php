<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRefreshRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Exception\ProviderNotSupportedException;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\RefreshableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use Marac\SyliusHeadlessOAuthBundle\Security\OAuthSecurityLogger;
use Throwable;

use function sprintf;

/**
 * API Platform state processor for OAuth token refresh.
 *
 * Flow:
 * 1. Find the matching provider from tagged services
 * 2. Verify provider supports refresh
 * 3. Refresh the tokens
 * 4. Get user data (Google: from userinfo endpoint, Apple: from id_token)
 * 5. Resolve the user from user data
 * 6. Generate and return a new JWT token
 *
 * @implements ProcessorInterface<OAuthRefreshRequest, OAuthResponse>
 */
final class OAuthRefreshProcessor implements ProcessorInterface
{
    /**
     * @param iterable<OAuthProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly UserResolverInterface $userResolver,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ?OAuthSecurityLogger $securityLogger = null,
    ) {
    }

    /**
     * @param OAuthRefreshRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OAuthResponse
    {
        /** @var string $providerName */
        $providerName = $uriVariables['provider'] ?? '';

        try {
            $provider = $this->findRefreshableProvider($providerName);
            $tokenData = $provider->refreshTokens($data->refreshToken);

            // Get user data from the refreshed tokens (each provider handles this differently)
            $userData = $provider->getUserDataFromTokenData($tokenData);

            // Resolve the user (should already exist since they had a refresh token)
            $shopUser = $this->userResolver->resolve($userData);

            $token = $this->jwtManager->create($shopUser);
            $customerId = $shopUser->getCustomer()?->getId();

            // Log successful refresh
            $this->securityLogger?->logRefreshSuccess($providerName, $customerId);

            return new OAuthResponse(
                token: $token,
                refreshToken: $tokenData->refreshToken,
                customerId: $customerId,
            );
        } catch (ProviderNotSupportedException $e) {
            $this->securityLogger?->logRefreshFailure(
                $providerName,
                'Provider not supported',
            );
            throw $e;
        } catch (OAuthException $e) {
            $this->securityLogger?->logRefreshFailure(
                $providerName,
                $e->getMessage(),
            );
            throw $e;
        } catch (Throwable $e) {
            $this->securityLogger?->logRefreshFailure(
                $providerName,
                'Unexpected error: ' . $e->getMessage(),
            );
            throw $e;
        }
    }

    private function findRefreshableProvider(string $providerName): RefreshableOAuthProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerName)) {
                if (!$provider instanceof RefreshableOAuthProviderInterface) {
                    throw new OAuthException(
                        sprintf('OAuth provider "%s" does not support token refresh', $providerName),
                    );
                }

                if (!$provider->supportsRefresh()) {
                    throw new OAuthException(
                        sprintf('OAuth provider "%s" has refresh support disabled', $providerName),
                    );
                }

                return $provider;
            }
        }

        throw new ProviderNotSupportedException($providerName);
    }
}
