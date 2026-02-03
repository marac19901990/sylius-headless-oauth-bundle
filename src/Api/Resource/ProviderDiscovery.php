<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use ArrayObject;
use Marac\SyliusHeadlessOAuthBundle\Api\Action\ProviderDiscoveryAction;

/**
 * API Resource for discovering available OAuth providers.
 *
 * This endpoint allows frontend applications to dynamically determine which
 * OAuth providers are enabled, so they can render appropriate login buttons.
 *
 * Example response:
 * {
 *   "providers": [
 *     {"name": "google", "displayName": "Google"},
 *     {"name": "apple", "displayName": "Apple"},
 *     {"name": "keycloak", "displayName": "Keycloak"}
 *   ]
 * }
 *
 * Frontend usage:
 *   const response = await fetch('/api/v2/auth/oauth/providers');
 *   const { providers } = await response.json();
 *   providers.forEach(p => renderOAuthButton(p.name, p.displayName));
 */
#[ApiResource(
    shortName: 'OAuthProviders',
    operations: [
        new Get(
            uriTemplate: '/auth/oauth/providers',
            controller: ProviderDiscoveryAction::class,
            read: false,
            openapi: new Model\Operation(
                summary: 'Get available OAuth providers',
                description: 'Returns a list of enabled OAuth providers for rendering login buttons.',
                tags: ['OAuth'],
                responses: [
                    '200' => new Model\Response(
                        description: 'List of enabled OAuth providers',
                        content: new ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'providers' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'name' => [
                                                        'type' => 'string',
                                                        'description' => 'Provider identifier for API calls',
                                                        'example' => 'google',
                                                    ],
                                                    'displayName' => [
                                                        'type' => 'string',
                                                        'description' => 'Human-readable provider name',
                                                        'example' => 'Google',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'example' => [
                                    'providers' => [
                                        ['name' => 'google', 'displayName' => 'Google'],
                                        ['name' => 'apple', 'displayName' => 'Apple'],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                ],
            ),
        ),
    ],
)]
final class ProviderDiscovery
{
    // This class serves as an API Platform resource definition
    // The actual response is handled by ProviderDiscoveryAction
}
