<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthRefreshProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'OAuthRefresh',
    operations: [
        new Post(
            uriTemplate: '/auth/oauth/{provider}/refresh',
            output: OAuthResponse::class,
            processor: OAuthRefreshProcessor::class,
            openapi: new Model\Operation(
                summary: 'Refresh OAuth tokens',
                description: 'Use a refresh token to obtain a new JWT and optionally a new refresh token. Note: Apple rotates refresh tokens on each use.',
                tags: ['OAuth'],
            ),
        ),
    ],
)]
final class OAuthRefreshRequest
{
    #[Assert\NotBlank(message: 'The refresh token is required.')]
    public string $refreshToken;
}
