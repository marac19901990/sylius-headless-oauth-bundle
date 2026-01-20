<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Exception;

/**
 * Thrown when an unknown OAuth provider is requested.
 */
final class ProviderNotSupportedException extends OAuthException
{
    public function __construct(string $provider)
    {
        parent::__construct(
            message: sprintf('OAuth provider "%s" is not supported. Available providers: google, apple', $provider),
            statusCode: 400,
        );
    }
}
