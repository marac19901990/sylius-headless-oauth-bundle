<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Marac\SyliusHeadlessOAuthBundle\Api\Resource\OAuthRequest;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPostAuthenticationEvent;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Exception\ProviderNotSupportedException;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Resolver\UserResolverInterface;
use Marac\SyliusHeadlessOAuthBundle\Security\OAuthSecurityLoggerInterface;
use Marac\SyliusHeadlessOAuthBundle\Security\RedirectUriValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * API Platform state processor for OAuth authentication.
 *
 * Flow:
 * 1. Validate redirect URI against allowlist
 * 2. Find the matching provider from tagged services
 * 3. Exchange the code for user data
 * 4. Resolve or create the user
 * 5. Generate and return a JWT token
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
        private readonly ?RedirectUriValidatorInterface $redirectUriValidator = null,
        private readonly ?OAuthSecurityLoggerInterface $securityLogger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param OAuthRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OAuthResponse
    {
        /** @var string $providerName */
        $providerName = $uriVariables['provider'] ?? '';

        // Validate redirect URI against allowlist (fail fast)
        if ($this->redirectUriValidator !== null) {
            try {
                $this->redirectUriValidator->validate($data->redirectUri);
            } catch (OAuthException $e) {
                $this->securityLogger?->logRedirectUriRejected($data->redirectUri, $providerName);

                throw $e;
            }
        }

        try {
            $provider = $this->findProvider($providerName);
            $userData = $provider->getUserData($data->code, $data->redirectUri);
            $resolveResult = $this->userResolver->resolve($userData);

            $shopUser = $resolveResult->shopUser;
            $token = $this->jwtManager->create($shopUser);
            $customerId = $shopUser->getCustomer()?->getId();

            // Dispatch post-authentication event
            $this->eventDispatcher?->dispatch(
                new OAuthPostAuthenticationEvent($shopUser, $userData, $resolveResult->isNewUser),
                OAuthPostAuthenticationEvent::NAME,
            );

            // Log successful authentication
            $this->securityLogger?->logAuthSuccess(
                $providerName,
                $userData->email,
                $customerId,
            );

            return new OAuthResponse(
                token: $token,
                refreshToken: $userData->refreshToken,
                customerId: $customerId,
                state: $data->state,
            );
        } catch (ProviderNotSupportedException $e) {
            $this->securityLogger?->logAuthFailure(
                $providerName,
                'Provider not supported',
            );

            throw $e;
        } catch (OAuthException $e) {
            $this->securityLogger?->logAuthFailure(
                $providerName,
                $e->getMessage(),
            );

            throw $e;
        } catch (Throwable $e) {
            $this->securityLogger?->logAuthFailure(
                $providerName,
                'Unexpected error: ' . $e->getMessage(),
            );

            throw $e;
        }
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
