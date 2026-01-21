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
 * GitHub OAuth 2.0 Provider.
 *
 * Implements OAuth 2.0 authentication flow for GitHub.
 *
 * Setup instructions:
 * 1. Go to https://github.com/settings/developers
 * 2. Create a new OAuth App
 * 3. Set the callback URL to your application's redirect URI
 * 4. Copy the Client ID and Client Secret
 *
 * Note: GitHub does not issue refresh tokens by default.
 * Access tokens do not expire unless revoked.
 *
 * @see https://docs.github.com/en/developers/apps/building-oauth-apps/authorizing-oauth-apps
 */
final class GitHubProvider implements ConfigurableOAuthProviderInterface, RefreshableOAuthProviderInterface
{
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const USER_URL = 'https://api.github.com/user';
    private const USER_EMAILS_URL = 'https://api.github.com/user/emails';
    private const PROVIDER_NAME = 'github';

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
        return 'GitHub';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCredentialStatus(): array
    {
        return [
            'client_id' => !empty($this->clientId) && $this->clientId !== '%env(GITHUB_CLIENT_ID)%',
            'client_secret' => !empty($this->clientSecret) && $this->clientSecret !== '%env(GITHUB_CLIENT_SECRET)%',
        ];
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $this->validateCredentials();

        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        // GitHub may not include email in user info if it's private
        // We need to fetch emails separately
        $email = $userInfo['email'];
        if ($email === null) {
            $email = $this->fetchPrimaryEmail($tokens['access_token']);
        }

        if ($email === null) {
            throw new OAuthException('GitHub account does not have a verified email address');
        }

        // GitHub uses numeric ID, convert to string
        $providerId = (string) $userInfo['id'];

        // Parse name into first/last if available
        $firstName = null;
        $lastName = null;
        if (!empty($userInfo['name'])) {
            $nameParts = explode(' ', $userInfo['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? null;
        }

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $providerId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            refreshToken: null, // GitHub doesn't provide refresh tokens
        );
    }

    public function supportsRefresh(): bool
    {
        // GitHub access tokens don't expire by default, but we support
        // the interface for consistency. In practice, refresh won't work
        // unless the user has device flow enabled.
        return false;
    }

    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData
    {
        $userInfo = $this->fetchUserInfo($accessToken);

        $email = $userInfo['email'];
        if ($email === null) {
            $email = $this->fetchPrimaryEmail($accessToken);
        }

        if ($email === null) {
            throw new OAuthException('GitHub account does not have a verified email address');
        }

        $providerId = (string) $userInfo['id'];

        $firstName = null;
        $lastName = null;
        if (!empty($userInfo['name'])) {
            $nameParts = explode(' ', $userInfo['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? null;
        }

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $providerId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData
    {
        return $this->getUserDataFromAccessToken($tokenData->accessToken);
    }

    public function refreshTokens(string $refreshToken): OAuthTokenData
    {
        // GitHub doesn't support refresh tokens in the standard OAuth flow
        // This method exists for interface compliance but should not be called
        throw new OAuthException('GitHub does not support token refresh. Access tokens do not expire.');
    }

    private function validateCredentials(): void
    {
        $this->credentialValidator->validateMany([
            ['value' => $this->clientId, 'env' => 'GITHUB_CLIENT_ID', 'name' => 'client ID'],
            ['value' => $this->clientSecret, 'env' => 'GITHUB_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'GitHub');
    }

    /**
     * @return array{access_token: string, token_type: string, scope?: string}
     */
    private function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            // GitHub returns error in response body, not via HTTP status
            if (isset($data['error'])) {
                $errorMessage = $data['error_description'] ?? $data['error'];

                throw new OAuthException('GitHub authentication failed: ' . $errorMessage);
            }

            if (!isset($data['access_token'])) {
                throw new OAuthException('GitHub token response missing access_token');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to exchange GitHub authorization code: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse GitHub token response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * @return array{id: int, login: string, name: ?string, email: ?string, avatar_url: ?string}
     */
    private function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::USER_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['id'])) {
                throw new OAuthException('GitHub user info response missing required field (id)');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to fetch GitHub user info: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse GitHub user info response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * Fetch the primary verified email from GitHub.
     *
     * If the user has made their email private, we need to call the emails endpoint.
     */
    private function fetchPrimaryEmail(string $accessToken): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::USER_EMAILS_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]);

            $emails = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            // Find the primary verified email
            foreach ($emails as $emailInfo) {
                if ($emailInfo['primary'] && $emailInfo['verified']) {
                    return $emailInfo['email'];
                }
            }

            // Fall back to any verified email
            foreach ($emails as $emailInfo) {
                if ($emailInfo['verified']) {
                    return $emailInfo['email'];
                }
            }

            return null;
        } catch (GuzzleException $e) {
            // If we can't fetch emails, return null and let caller handle it
            return null;
        } catch (JsonException) {
            return null;
        }
    }
}
