<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

/**
 * Interface for validating redirect URIs against an allowlist.
 *
 * Security: Prevents open redirect attacks by ensuring redirect URIs
 * are only allowed to configured, trusted domains.
 */
interface RedirectUriValidatorInterface
{
    /**
     * Validate that a redirect URI is in the allowlist.
     *
     * @throws OAuthException If the URI is not allowed
     */
    public function validate(string $redirectUri): void;

    /**
     * Check if a redirect URI is valid without throwing.
     */
    public function isValid(string $redirectUri): bool;

    /**
     * Get all configured allowed URIs.
     *
     * @return array<string>
     */
    public function getAllowedUris(): array;

    /**
     * Check if validation is enabled (has configured URIs).
     */
    public function isEnabled(): bool;
}
