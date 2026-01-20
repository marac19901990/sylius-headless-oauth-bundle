<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Base exception for all OAuth-related errors.
 */
class OAuthException extends HttpException
{
    public function __construct(
        string $message = 'An OAuth error occurred',
        int $statusCode = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $message, $previous);
    }
}
