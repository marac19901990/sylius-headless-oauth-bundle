<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Mock;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Creates a Guzzle HTTP client with a MockHandler for testing.
 *
 * The mock is preloaded with responses for Google, Facebook, and Apple OAuth flows.
 */
final class MockHttpClientFactory
{
    private static ?MockHandler $mockHandler = null;

    public static function getMockHandler(): MockHandler
    {
        if (self::$mockHandler === null) {
            self::$mockHandler = new MockHandler();
        }

        return self::$mockHandler;
    }

    public static function appendResponse(Response $response): void
    {
        self::getMockHandler()->append($response);
    }

    public static function reset(): void
    {
        self::getMockHandler()->reset();
    }

    /**
     * Get a mock Google token response.
     */
    public static function createGoogleTokenResponse(
        string $accessToken = 'google_access_token_123',
        ?string $refreshToken = 'google_refresh_token_456',
    ): Response {
        $body = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ];

        if ($refreshToken !== null) {
            $body['refresh_token'] = $refreshToken;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Get a mock Google userinfo response.
     */
    public static function createGoogleUserinfoResponse(
        string $id = 'google_user_123',
        string $email = 'test@example.com',
        string $givenName = 'Test',
        string $familyName = 'User',
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id,
            'email' => $email,
            'verified_email' => true,
            'given_name' => $givenName,
            'family_name' => $familyName,
            'picture' => 'https://example.com/photo.jpg',
        ]));
    }

    /**
     * Get a mock Facebook token response.
     */
    public static function createFacebookTokenResponse(
        string $accessToken = 'facebook_access_token_123',
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]));
    }

    /**
     * Get a mock Facebook userinfo response.
     */
    public static function createFacebookUserinfoResponse(
        string $id = 'facebook_user_123',
        string $email = 'test@example.com',
        string $firstName = 'Test',
        string $lastName = 'User',
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]));
    }

    /**
     * Get a mock Apple token response with id_token.
     */
    public static function createAppleTokenResponse(
        string $accessToken = 'apple_access_token_123',
        ?string $refreshToken = 'apple_refresh_token_456',
        string $idToken = '',
    ): Response {
        // Create a basic JWT id_token if not provided
        if ($idToken === '') {
            $header = base64_encode(json_encode(['alg' => 'RS256', 'kid' => 'test_key']));
            $payload = base64_encode(json_encode([
                'iss' => 'https://appleid.apple.com',
                'sub' => 'apple_user_123',
                'aud' => 'test_client_id',
                'email' => 'test@privaterelay.appleid.com',
                'email_verified' => 'true',
                'exp' => time() + 3600,
                'iat' => time(),
            ]));
            $signature = base64_encode('test_signature');
            $idToken = "$header.$payload.$signature";
        }

        $body = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ];

        if ($refreshToken !== null) {
            $body['refresh_token'] = $refreshToken;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Get a mock error response.
     */
    public static function createErrorResponse(
        int $statusCode = 400,
        string $error = 'invalid_grant',
        string $errorDescription = 'The authorization code has expired',
    ): Response {
        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode([
            'error' => $error,
            'error_description' => $errorDescription,
        ]));
    }

    public static function createClient(): Client
    {
        $handlerStack = HandlerStack::create(self::getMockHandler());

        return new Client(['handler' => $handlerStack]);
    }

    public function create(): Client
    {
        return self::createClient();
    }
}
