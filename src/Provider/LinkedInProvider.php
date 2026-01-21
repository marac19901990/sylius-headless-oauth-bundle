<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidatorInterface;

use const JSON_THROW_ON_ERROR;

/**
 * LinkedIn OAuth 2.0 Provider.
 *
 * Implements OAuth 2.0 authentication flow for LinkedIn using the v2 API.
 * LinkedIn is essential for B2B e-commerce, wholesale portals, and professional services.
 *
 * Setup instructions:
 * 1. Go to https://www.linkedin.com/developers/apps
 * 2. Create a new app or select an existing one
 * 3. Under "Auth" tab, add your redirect URLs
 * 4. Under "Products" tab, request access to "Sign In with LinkedIn using OpenID Connect"
 * 5. Copy the Client ID and Client Secret from the "Auth" tab
 *
 * Required scopes: openid, profile, email
 *
 * @see https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2
 */
final class LinkedInProvider implements ConfigurableOAuthProviderInterface, RefreshableOAuthProviderInterface
{
    private const TOKEN_URL = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const USERINFO_URL = 'https://api.linkedin.com/v2/userinfo';
    private const PROVIDER_NAME = 'linkedin';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly CredentialValidatorInterface $credentialValidator,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $enabled = true,
    ) {
    }

    public function supports(string $provider): bool
    {
        return $this->enabled && strtolower($provider) === self::PROVIDER_NAME;
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'LinkedIn';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCredentialStatus(): array
    {
        return [
            'client_id' => !empty($this->clientId) && $this->clientId !== '%env(LINKEDIN_CLIENT_ID)%',
            'client_secret' => !empty($this->clientSecret) && $this->clientSecret !== '%env(LINKEDIN_CLIENT_SECRET)%',
        ];
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $this->validateCredentials();

        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $userInfo['sub'],
            email: $userInfo['email'],
            firstName: $userInfo['given_name'] ?? null,
            lastName: $userInfo['family_name'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
        );
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData
    {
        $userInfo = $this->fetchUserInfo($accessToken);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $userInfo['sub'],
            email: $userInfo['email'],
            firstName: $userInfo['given_name'] ?? null,
            lastName: $userInfo['family_name'] ?? null,
        );
    }

    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData
    {
        return $this->getUserDataFromAccessToken($tokenData->accessToken);
    }

    public function refreshTokens(string $refreshToken): OAuthTokenData
    {
        $this->validateCredentials();

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['error'])) {
                $errorMessage = $data['error_description'] ?? $data['error'];

                throw new OAuthException('LinkedIn token refresh failed: ' . $errorMessage);
            }

            if (!isset($data['access_token'])) {
                throw new OAuthException('LinkedIn refresh token response missing access_token');
            }

            return new OAuthTokenData(
                accessToken: $data['access_token'],
                refreshToken: $data['refresh_token'] ?? $refreshToken,
                expiresIn: $data['expires_in'] ?? null,
                tokenType: $data['token_type'] ?? null,
            );
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to refresh LinkedIn tokens: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse LinkedIn refresh response: ' . $e->getMessage(), 400, $e);
        }
    }

    private function validateCredentials(): void
    {
        $this->credentialValidator->validateMany([
            ['value' => $this->clientId, 'env' => 'LINKEDIN_CLIENT_ID', 'name' => 'client ID'],
            ['value' => $this->clientSecret, 'env' => 'LINKEDIN_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'LinkedIn');
    }

    /**
     * @return array{access_token: string, token_type?: string, expires_in?: int, refresh_token?: string, scope?: string}
     */
    private function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['error'])) {
                $errorMessage = $data['error_description'] ?? $data['error'];

                throw new OAuthException('LinkedIn authentication failed: ' . $errorMessage);
            }

            if (!isset($data['access_token'])) {
                throw new OAuthException('LinkedIn token response missing access_token');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to exchange LinkedIn authorization code: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse LinkedIn token response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * Fetch user info from LinkedIn's OpenID Connect userinfo endpoint.
     *
     * @return array{sub: string, email: string, given_name?: string, family_name?: string, name?: string, picture?: string, email_verified?: bool}
     */
    private function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::USERINFO_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['sub'])) {
                throw new OAuthException('LinkedIn user info response missing required field (sub)');
            }

            if (!isset($data['email'])) {
                throw new OAuthException('LinkedIn user info response missing required field (email). Ensure your app has the "email" scope.');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to fetch LinkedIn user info: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse LinkedIn user info response: ' . $e->getMessage(), 400, $e);
        }
    }
}
