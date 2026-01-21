<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Psr\Log\LoggerInterface;

use function count;
use function strlen;

/**
 * Security event logger for OAuth operations.
 *
 * Provides structured logging for authentication events, failures,
 * and suspicious activity to enable security monitoring and audit trails.
 *
 * @phpstan-import-type SecurityContext from OAuthSecurityLoggerInterface
 */
final class OAuthSecurityLogger implements OAuthSecurityLoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Log a successful OAuth authentication.
     */
    public function logAuthSuccess(
        string $provider,
        string $email,
        ?int $customerId,
        bool $isNewUser = false,
    ): void {
        $this->logger->info('OAuth authentication successful', [
            'provider' => $provider,
            'email' => $this->maskEmail($email),
            'customer_id' => $customerId,
            'is_new_user' => $isNewUser,
            'event_type' => 'oauth_auth_success',
        ]);
    }

    /**
     * Log a failed OAuth authentication.
     *
     * @param SecurityContext $context
     */
    public function logAuthFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
        $this->logger->warning('OAuth authentication failed', [
            'provider' => $provider,
            'reason' => $reason,
            'event_type' => 'oauth_auth_failure',
            ...$context,
        ]);
    }

    /**
     * Log a successful token refresh.
     */
    public function logRefreshSuccess(
        string $provider,
        ?int $customerId,
    ): void {
        $this->logger->info('OAuth token refresh successful', [
            'provider' => $provider,
            'customer_id' => $customerId,
            'event_type' => 'oauth_refresh_success',
        ]);
    }

    /**
     * Log a failed token refresh.
     *
     * @param SecurityContext $context
     */
    public function logRefreshFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
        $this->logger->warning('OAuth token refresh failed', [
            'provider' => $provider,
            'reason' => $reason,
            'event_type' => 'oauth_refresh_failure',
            ...$context,
        ]);
    }

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
    ): void {
        $this->logger->warning('Suspicious OAuth activity detected', [
            'type' => $type,
            'event_type' => 'oauth_suspicious_activity',
            ...$context,
        ]);
    }

    /**
     * Log JWT verification failure (potential forgery attempt).
     *
     * @param SecurityContext $context
     */
    public function logJwtVerificationFailure(
        string $provider,
        string $reason,
        array $context = [],
    ): void {
        $this->logger->warning('JWT verification failed', [
            'provider' => $provider,
            'reason' => $reason,
            'event_type' => 'oauth_jwt_verification_failure',
            ...$context,
        ]);
    }

    /**
     * Log redirect URI validation failure.
     */
    public function logRedirectUriRejected(
        string $redirectUri,
        string $provider,
    ): void {
        $parsed = parse_url($redirectUri);
        $host = $parsed['host'] ?? 'unknown';

        $this->logger->warning('Redirect URI rejected', [
            'redirect_host' => $host,
            'provider' => $provider,
            'event_type' => 'oauth_redirect_uri_rejected',
        ]);
    }

    /**
     * Mask email for logging (privacy protection).
     *
     * user@example.com -> u***@example.com
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (strlen($local) <= 1) {
            return '***@' . $domain;
        }

        return $local[0] . '***@' . $domain;
    }
}
