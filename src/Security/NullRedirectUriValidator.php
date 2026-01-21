<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Security;

/**
 * Null Object implementation of RedirectUriValidatorInterface.
 *
 * Used when redirect URI validation is disabled or not configured.
 * All URIs are considered valid (no-op validation).
 */
final class NullRedirectUriValidator implements RedirectUriValidatorInterface
{
    public function validate(string $redirectUri): void
    {
    }

    public function isValid(string $redirectUri): bool
    {
        return true;
    }

    public function getAllowedUris(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
