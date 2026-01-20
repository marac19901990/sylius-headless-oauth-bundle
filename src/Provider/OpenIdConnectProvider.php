<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Service\OidcDiscoveryServiceInterface;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;

use function count;
use function in_array;
use function is_array;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Generic OpenID Connect provider that works with any OIDC-compliant identity provider.
 *
 * Supports: Keycloak, Auth0, Okta, Azure AD, and any OIDC-compliant IdP.
 *
 * Features:
 * - Auto-discovery of endpoints via .well-known/openid-configuration
 * - JWT id_token verification
 * - Userinfo endpoint fallback
 * - Token refresh support
 */
final class OpenIdConnectProvider implements ConfigurableOAuthProviderInterface, RefreshableOAuthProviderInterface
{
    private const PROVIDER_NAME = 'oidc';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly OidcDiscoveryServiceInterface $discoveryService,
        private readonly CredentialValidator $credentialValidator,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $issuerUrl,
        private readonly bool $enabled = true,
        private readonly bool $verifyJwt = true,
        private readonly string $providerName = self::PROVIDER_NAME,
        private readonly string $scopes = 'openid email profile',
    ) {
        if ($this->enabled) {
            $this->validateCredentials();
        }
    }

    public function supports(string $provider): bool
    {
        return $this->enabled && strtolower($provider) === strtolower($this->providerName);
    }

    public function getName(): string
    {
        return $this->providerName;
    }

    public function getDisplayName(): string
    {
        return ucfirst($this->providerName);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCredentialStatus(): array
    {
        return [
            'client_id' => !empty($this->clientId) && !str_starts_with($this->clientId, '%env('),
            'client_secret' => !empty($this->clientSecret) && !str_starts_with($this->clientSecret, '%env('),
            'issuer_url' => !empty($this->issuerUrl) && !str_starts_with($this->issuerUrl, '%env('),
        ];
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $userData = $this->extractUserDataFromTokens($tokens);

        return $userData;
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData
    {
        $userInfo = $this->fetchUserInfo($accessToken);

        return $this->createUserDataFromUserInfo($userInfo);
    }

    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData
    {
        // Try to get user data from id_token first, fall back to userinfo endpoint
        if ($tokenData->idToken !== null) {
            try {
                return $this->extractUserDataFromIdToken($tokenData->idToken, $tokenData->refreshToken);
            } catch (OAuthException) {
                // Fall back to userinfo endpoint
            }
        }

        $userData = $this->getUserDataFromAccessToken($tokenData->accessToken);

        // Add refresh token if available
        if ($tokenData->refreshToken !== null) {
            return new OAuthUserData(
                provider: $userData->provider,
                providerId: $userData->providerId,
                email: $userData->email,
                firstName: $userData->firstName,
                lastName: $userData->lastName,
                refreshToken: $tokenData->refreshToken,
            );
        }

        return $userData;
    }

    public function refreshTokens(string $refreshToken): OAuthTokenData
    {
        $tokenEndpoint = $this->discoveryService->getTokenEndpoint($this->issuerUrl);

        try {
            $response = $this->httpClient->request('POST', $tokenEndpoint, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['access_token'])) {
                throw new OAuthException('OIDC refresh token response missing access_token');
            }

            return new OAuthTokenData(
                accessToken: $data['access_token'],
                refreshToken: $data['refresh_token'] ?? $refreshToken,
                expiresIn: $data['expires_in'] ?? null,
                tokenType: $data['token_type'] ?? null,
                idToken: $data['id_token'] ?? null,
            );
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to refresh OIDC tokens: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse OIDC refresh response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * Get the issuer URL for this provider.
     */
    public function getIssuerUrl(): string
    {
        return $this->issuerUrl;
    }

    /**
     * Get the configured scopes for this provider.
     */
    public function getScopes(): string
    {
        return $this->scopes;
    }

    private function validateCredentials(): void
    {
        if (empty($this->issuerUrl) || str_starts_with($this->issuerUrl, '%env(')) {
            throw new OAuthException(sprintf(
                'OIDC provider "%s" issuer URL is not configured. Set the issuer_url parameter.',
                $this->providerName,
            ));
        }

        $this->credentialValidator->validateMany([
            ['value' => $this->clientId, 'env' => 'OIDC_CLIENT_ID', 'name' => 'client ID'],
            ['value' => $this->clientSecret, 'env' => 'OIDC_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'OIDC (' . $this->providerName . ')');
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in?: int, refresh_token?: string, id_token?: string}
     */
    private function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $tokenEndpoint = $this->discoveryService->getTokenEndpoint($this->issuerUrl);

        try {
            $response = $this->httpClient->request('POST', $tokenEndpoint, [
                'form_params' => [
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['access_token'])) {
                throw new OAuthException('OIDC token response missing access_token');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to exchange OIDC authorization code: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse OIDC token response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * @param array{access_token: string, id_token?: string, refresh_token?: string} $tokens
     */
    private function extractUserDataFromTokens(array $tokens): OAuthUserData
    {
        // If we have an id_token, extract user data from it
        if (isset($tokens['id_token'])) {
            try {
                return $this->extractUserDataFromIdToken(
                    $tokens['id_token'],
                    $tokens['refresh_token'] ?? null,
                );
            } catch (OAuthException) {
                // Fall back to userinfo endpoint
            }
        }

        // Fall back to userinfo endpoint
        $userInfo = $this->fetchUserInfo($tokens['access_token']);
        $userData = $this->createUserDataFromUserInfo($userInfo);

        // Add refresh token if available
        if (isset($tokens['refresh_token'])) {
            return new OAuthUserData(
                provider: $userData->provider,
                providerId: $userData->providerId,
                email: $userData->email,
                firstName: $userData->firstName,
                lastName: $userData->lastName,
                refreshToken: $tokens['refresh_token'],
            );
        }

        return $userData;
    }

    private function extractUserDataFromIdToken(string $idToken, ?string $refreshToken): OAuthUserData
    {
        $claims = $this->decodeAndVerifyIdToken($idToken);

        // Extract standard OIDC claims
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if ($subject === null) {
            throw new OAuthException('OIDC id_token missing required claim: sub');
        }

        if ($email === null) {
            throw new OAuthException('OIDC id_token missing required claim: email');
        }

        // Name can come from various claims
        $firstName = $claims['given_name'] ?? $claims['first_name'] ?? null;
        $lastName = $claims['family_name'] ?? $claims['last_name'] ?? null;

        // Some providers put full name in 'name' claim
        if ($firstName === null && $lastName === null && isset($claims['name'])) {
            $nameParts = explode(' ', $claims['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? null;
        }

        return new OAuthUserData(
            provider: $this->providerName,
            providerId: $subject,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            refreshToken: $refreshToken,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndVerifyIdToken(string $idToken): array
    {
        if (!$this->verifyJwt) {
            // Decode without verification (for development/testing)
            $parts = explode('.', $idToken);

            if (count($parts) !== 3) {
                throw new OAuthException('Invalid id_token format');
            }

            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);

            if ($payload === null) {
                throw new OAuthException('Failed to decode id_token payload');
            }

            return $payload;
        }

        // Fetch JWKS and verify the token
        $jwksUri = $this->discoveryService->getJwksUri($this->issuerUrl);

        try {
            $response = $this->httpClient->request('GET', $jwksUri);
            $jwksData = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($jwksData['keys'])) {
                throw new OAuthException('Invalid JWKS response: missing keys');
            }

            /** @var array<string, Key> $keys */
            $keys = JWK::parseKeySet($jwksData);

            /** @var object $decoded */
            $decoded = JWT::decode($idToken, $keys);
            $claims = (array) $decoded;

            // Verify issuer
            $expectedIssuer = rtrim($this->issuerUrl, '/');
            $tokenIssuer = rtrim($claims['iss'] ?? '', '/');

            if ($tokenIssuer !== $expectedIssuer) {
                throw new OAuthException(sprintf(
                    'id_token issuer mismatch: expected "%s", got "%s"',
                    $expectedIssuer,
                    $tokenIssuer,
                ));
            }

            // Verify audience
            $audience = $claims['aud'] ?? null;

            if (is_array($audience)) {
                if (!in_array($this->clientId, $audience, true)) {
                    throw new OAuthException('id_token audience does not include client ID');
                }
            } elseif ($audience !== $this->clientId) {
                throw new OAuthException(sprintf(
                    'id_token audience mismatch: expected "%s", got "%s"',
                    $this->clientId,
                    $audience ?? 'null',
                ));
            }

            return $claims;
        } catch (GuzzleException $e) {
            throw new OAuthException('Failed to fetch JWKS: ' . $e->getMessage(), 0, $e);
        } catch (Exception $e) {
            if ($e instanceof OAuthException) {
                throw $e;
            }

            throw new OAuthException('Failed to verify id_token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserInfo(string $accessToken): array
    {
        $userinfoEndpoint = $this->discoveryService->getUserinfoEndpoint($this->issuerUrl);

        if ($userinfoEndpoint === null) {
            throw new OAuthException('OIDC provider does not expose a userinfo endpoint');
        }

        try {
            $response = $this->httpClient->request('GET', $userinfoEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['sub'])) {
                throw new OAuthException('OIDC userinfo response missing required field: sub');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to fetch OIDC user info: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse OIDC userinfo response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * @param array<string, mixed> $userInfo
     */
    private function createUserDataFromUserInfo(array $userInfo): OAuthUserData
    {
        $subject = $userInfo['sub'] ?? null;
        $email = $userInfo['email'] ?? null;

        if ($subject === null) {
            throw new OAuthException('OIDC userinfo missing required field: sub');
        }

        if ($email === null) {
            throw new OAuthException('OIDC userinfo missing required field: email');
        }

        $firstName = $userInfo['given_name'] ?? $userInfo['first_name'] ?? null;
        $lastName = $userInfo['family_name'] ?? $userInfo['last_name'] ?? null;

        // Some providers put full name in 'name' field
        if ($firstName === null && $lastName === null && isset($userInfo['name'])) {
            $nameParts = explode(' ', (string) $userInfo['name'], 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? null;
        }

        return new OAuthUserData(
            provider: $this->providerName,
            providerId: $subject,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
        );
    }
}
