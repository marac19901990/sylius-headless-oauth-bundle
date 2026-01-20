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
use Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\RefreshableOAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;

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
    ) {
    }

    /**
     * @param OAuthRefreshRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OAuthResponse
    {
        $providerName = $uriVariables['provider'] ?? '';

        $provider = $this->findRefreshableProvider($providerName);
        $tokenData = $provider->refreshTokens($data->refreshToken);

        // Get user data from the refreshed tokens
        $userData = $this->getUserDataFromTokenData($provider, $tokenData);

        // Resolve the user (should already exist since they had a refresh token)
        $shopUser = $this->userResolver->resolve($userData);

        $token = $this->jwtManager->create($shopUser);

        return new OAuthResponse(
            token: $token,
            refreshToken: $tokenData->refreshToken,
            customerId: $shopUser->getCustomer()?->getId(),
        );
    }

    private function getUserDataFromTokenData(
        RefreshableOAuthProviderInterface $provider,
        OAuthTokenData $tokenData,
    ): OAuthUserData {
        // Apple requires id_token, Google uses access_token
        if ($provider instanceof AppleProvider) {
            if ($tokenData->idToken === null) {
                throw new OAuthException(
                    'Apple refresh response did not include id_token. Cannot identify user.'
                );
            }

            return $provider->getUserDataFromIdToken($tokenData->idToken);
        }

        // For other providers (Google), use the access token to fetch user info
        return $provider->getUserDataFromAccessToken($tokenData->accessToken);
    }

    private function findRefreshableProvider(string $providerName): RefreshableOAuthProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerName)) {
                if (!$provider instanceof RefreshableOAuthProviderInterface) {
                    throw new OAuthException(
                        sprintf('OAuth provider "%s" does not support token refresh', $providerName)
                    );
                }

                if (!$provider->supportsRefresh()) {
                    throw new OAuthException(
                        sprintf('OAuth provider "%s" has refresh support disabled', $providerName)
                    );
                }

                return $provider;
            }
        }

        throw new ProviderNotSupportedException($providerName);
    }
}
