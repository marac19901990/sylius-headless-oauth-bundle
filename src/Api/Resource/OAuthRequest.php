<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Marac\SyliusHeadlessOAuthBundle\Api\Response\OAuthResponse;
use Marac\SyliusHeadlessOAuthBundle\Processor\OAuthProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'OAuth',
    operations: [
        new Post(
            uriTemplate: '/auth/oauth/{provider}',
            output: OAuthResponse::class,
            processor: OAuthProcessor::class,
            openapiContext: [
                'summary' => 'Authenticate via OAuth provider',
                'description' => 'Exchange an OAuth authorization code for a JWT token. Supports Google and Apple Sign-In.',
                'tags' => ['OAuth'],
            ],
        ),
    ],
)]
final class OAuthRequest
{
    #[Assert\NotBlank(message: 'The authorization code is required.')]
    public string $code;

    #[Assert\NotBlank(message: 'The redirect URI is required.')]
    #[Assert\Url(message: 'The redirect URI must be a valid URL.')]
    public string $redirectUri;
}
