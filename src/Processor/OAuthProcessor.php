<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Exception\ProviderNotSupportedException;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;

/**
 * API Platform state processor for OAuth authentication.
 *
 * Flow:
 * 1. Find the matching provider from tagged services
 * 2. Exchange the code for user data
 * 3. Resolve or create the user
 * 4. Generate and return a JWT token
 *
 * @implements ProcessorInterface<OAuthRequest, OAuthResponse>
 */
final class OAuthProcessor implements ProcessorInterface
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
     * @param OAuthRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OAuthResponse
    {
        $providerName = $uriVariables['provider'] ?? '';

        $provider = $this->findProvider($providerName);
        $userData = $provider->getUserData($data->code, $data->redirectUri);
        $shopUser = $this->userResolver->resolve($userData);

        $token = $this->jwtManager->create($shopUser);

        return new OAuthResponse(
            token: $token,
            refreshToken: $userData->refreshToken,
            customerId: $shopUser->getCustomer()?->getId(),
        );
    }

    private function findProvider(string $providerName): OAuthProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($providerName)) {
                return $provider;
            }
        }

        throw new ProviderNotSupportedException($providerName);
    }
}
