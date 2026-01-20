<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;

use function count;
use function in_array;
use function parse_url;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Validates redirect URIs against an allowlist.
 *
 * Security: Prevents open redirect attacks by ensuring redirect URIs
 * are only allowed to configured, trusted domains.
 */
final class RedirectUriValidator
{
    /**
     * @param array<string> $allowedUris List of allowed redirect URIs
     *                                   Can be exact URIs or URI prefixes (e.g., "https://example.com")
     */
    public function __construct(
        private readonly array $allowedUris,
    ) {
    }

    /**
     * Validate that a redirect URI is in the allowlist.
     *
     * @throws OAuthException If the URI is not allowed
     */
    public function validate(string $redirectUri): void
    {
        // If no allowlist is configured, skip validation (dev mode)
        if (count($this->allowedUris) === 0) {
            return;
        }

        // Check for exact match first
        if (in_array($redirectUri, $this->allowedUris, true)) {
            return;
        }

        // Check for prefix match (allows e.g. "https://example.com" to match "https://example.com/callback")
        foreach ($this->allowedUris as $allowedUri) {
            $normalizedAllowed = rtrim($allowedUri, '/');

            // Exact match with normalized trailing slash
            if ($redirectUri === $normalizedAllowed || $redirectUri === $normalizedAllowed . '/') {
                return;
            }

            // Prefix match - ensure we match the full origin
            if (str_starts_with($redirectUri, $normalizedAllowed . '/')) {
                return;
            }
        }

        throw new OAuthException(
            sprintf('Redirect URI "%s" is not in the allowed list', $redirectUri),
            400,
        );
    }

    /**
     * Check if a redirect URI is valid without throwing.
     */
    public function isValid(string $redirectUri): bool
    {
        try {
            $this->validate($redirectUri);

            return true;
        } catch (OAuthException) {
            return false;
        }
    }

    /**
     * Get all configured allowed URIs.
     *
     * @return array<string>
     */
    public function getAllowedUris(): array
    {
        return $this->allowedUris;
    }

    /**
     * Check if validation is enabled (has configured URIs).
     */
    public function isEnabled(): bool
    {
        return count($this->allowedUris) > 0;
    }
}
