<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

/**
 * Interface for OAuth security event logging.
 *
 * Provides structured logging for authentication events, failures,
 * and suspicious activity to enable security monitoring and audit trails.
 *
 * @phpstan-type SecurityContext array{
 *     email?: string,
 *     customer_id?: int,
 *     ip_address?: string,
 *     user_agent?: string,
 *     code?: string,
 *     redirect_uri?: string,
 * }
 */
interface OAuthSecurityLoggerInterface
{
    /**
     * Log a successful OAuth authentication.
     */
    public function logAuthSuccess(
        string $provider,
        string $email,
        ?int $customerId,
        bool $isNewUser = false,
    ): void;

    /**
     * Log a failed OAuth authentication.
     *
     * @param SecurityContext $context
     */
    public function logAuthFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void;

    /**
     * Log a successful token refresh.
     */
    public function logRefreshSuccess(
        string $provider,
        ?int $customerId,
    ): void;

    /**
     * Log a failed token refresh.
     *
     * @param SecurityContext $context
     */
    public function logRefreshFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void;

    /**
     * Log suspicious activity that may indicate an attack.
     *
     * Examples:
     * - Forged JWT tokens
     * - Invalid redirect URIs
     * - Provider ID mismatch during refresh
     *
     * @param SecurityContext $context
     */
    public function logSuspiciousActivity(
        string $type,
        array $context = [],
    ): void;

    /**
     * Log JWT verification failure (potential forgery attempt).
     *
     * @param SecurityContext $context
     */
    public function logJwtVerificationFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void;

    /**
     * Log redirect URI validation failure.
     */
    public function logRedirectUriRejected(
        string $redirectUri,
        string $provider,
    ): void;
}
