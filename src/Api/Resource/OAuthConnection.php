<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Api\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use ArrayObject;
use Marac\SyliusHeadlessOAuthBundle\Api\Action\ListOAuthConnectionsAction;
use Marac\SyliusHeadlessOAuthBundle\Api\Action\UnlinkOAuthConnectionAction;

/**
 * API Resource for managing OAuth connections for the current user.
 *
 * This endpoint allows authenticated users to:
 * - View their connected OAuth providers
 * - Disconnect OAuth providers from their account
 *
 * List connections:
 *   GET /api/v2/auth/oauth/connections
 *   Response:
 *   {
 *     "connections": [
 *       {"provider": "google", "displayName": "Google", "connectedAt": null}
 *     ]
 *   }
 *
 * Unlink provider:
 *   DELETE /api/v2/auth/oauth/connections/google
 *   Response:
 *   {
 *     "message": "Provider disconnected successfully",
 *     "provider": "google"
 *   }
 */
#[ApiResource(
    shortName: 'OAuthConnection',
    operations: [
        new Get(
            uriTemplate: '/auth/oauth/connections',
            controller: ListOAuthConnectionsAction::class,
            read: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            openapi: new Model\Operation(
                summary: 'List connected OAuth providers',
                description: 'Returns a list of OAuth providers connected to the current user\'s account.',
                tags: ['OAuth'],
                responses: [
                    '200' => new Model\Response(
                        description: 'List of connected OAuth providers',
                        content: new ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'connections' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'provider' => [
                                                        'type' => 'string',
                                                        'description' => 'Provider identifier',
                                                        'example' => 'google',
                                                    ],
                                                    'displayName' => [
                                                        'type' => 'string',
                                                        'description' => 'Human-readable provider name',
                                                        'example' => 'Google',
                                                    ],
                                                    'connectedAt' => [
                                                        'type' => 'string',
                                                        'nullable' => true,
                                                        'description' => 'Connection timestamp (if available)',
                                                        'example' => null,
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'example' => [
                                    'connections' => [
                                        ['provider' => 'google', 'displayName' => 'Google', 'connectedAt' => null],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '401' => new Model\Response(
                        description: 'Authentication required',
                    ),
                ],
            ),
        ),
        new Delete(
            uriTemplate: '/auth/oauth/connections/{provider}',
            controller: UnlinkOAuthConnectionAction::class,
            read: false,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            openapi: new Model\Operation(
                summary: 'Unlink an OAuth provider',
                description: 'Disconnects an OAuth provider from the current user\'s account.',
                tags: ['OAuth'],
                parameters: [
                    new Model\Parameter(
                        name: 'provider',
                        in: 'path',
                        required: true,
                        schema: ['type' => 'string'],
                        description: 'Provider name to unlink (google, apple, facebook, github, etc.)',
                        example: 'google',
                    ),
                ],
                responses: [
                    '200' => new Model\Response(
                        description: 'Provider disconnected successfully',
                        content: new ArrayObject([
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'Provider disconnected successfully',
                                        ],
                                        'provider' => [
                                            'type' => 'string',
                                            'example' => 'google',
                                        ],
                                    ],
                                ],
                            ],
                        ]),
                    ),
                    '400' => new Model\Response(
                        description: 'Provider not connected or cannot unlink last auth method',
                    ),
                    '401' => new Model\Response(
                        description: 'Authentication required',
                    ),
                ],
            ),
        ),
    ],
)]
final class OAuthConnection
{
    // This class serves as an API Platform resource definition
    // The actual logic is handled by the controller actions
}
