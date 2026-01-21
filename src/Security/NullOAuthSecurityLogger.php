<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

/**
 * Null Object implementation of OAuthSecurityLoggerInterface.
 *
 * Used when no security logging is desired or configured.
 * All methods are no-ops that silently accept and discard log data.
 */
final class NullOAuthSecurityLogger implements OAuthSecurityLoggerInterface
{
    public function logAuthSuccess(
        string $provider,
        string $email,
        ?int $customerId,
        bool $isNewUser = false,
    ): void {
    }

    public function logAuthFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
    }

    public function logRefreshSuccess(
        string $provider,
        ?int $customerId,
    ): void {
    }

    public function logRefreshFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
    }

    public function logSuspiciousActivity(
        string $type,
        array $context = [],
    ): void {
    }

    public function logJwtVerificationFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
    }

    public function logRedirectUriRejected(
        string $redirectUri,
        string $provider,
    ): void {
    }
}
