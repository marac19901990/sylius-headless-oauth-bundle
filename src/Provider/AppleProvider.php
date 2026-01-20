<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGeneratorInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

/**
 * Apple Sign-In OAuth provider.
 *
 * Important: Apple only sends the user's name on the FIRST authorization.
 * After that, you only get email and sub (Apple ID). The UserResolver
 * must capture the name on first login or it's lost forever.
 */
final class AppleProvider implements ConfigurableOAuthProviderInterface, RefreshableOAuthProviderInterface
{
    private const TOKEN_URL = 'https://appleid.apple.com/auth/token';
    private const PROVIDER_NAME = 'apple';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly AppleClientSecretGeneratorInterface $clientSecretGenerator,
        private readonly string $clientId,
        private readonly bool $enabled = true,
    ) {
        if ($this->enabled) {
            $this->validateCredentials();
        }
    }

    private function validateCredentials(): void
    {
        if (empty($this->clientId) || $this->clientId === '%env(APPLE_CLIENT_ID)%') {
            throw new \InvalidArgumentException(
                'Apple OAuth is enabled but APPLE_CLIENT_ID is not configured. ' .
                'Set the environment variable or disable Apple: sylius_headless_oauth.providers.apple.enabled: false'
            );
        }
    }

    public function supports(string $provider): bool
    {
        return $this->enabled && self::PROVIDER_NAME === strtolower($provider);
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCredentialStatus(): array
    {
        return [
            'client_id' => !empty($this->clientId) && $this->clientId !== '%env(APPLE_CLIENT_ID)%',
        ];
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $idTokenData = $this->decodeIdToken($tokens['id_token']);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $idTokenData['sub'],
            email: $idTokenData['email'],
            firstName: $idTokenData['firstName'] ?? null,
            lastName: $idTokenData['lastName'] ?? null,
            refreshToken: $tokens['refresh_token'] ?? null,
        );
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function getUserDataFromAccessToken(string $accessToken): OAuthUserData
    {
        // Apple doesn't have a userinfo endpoint like Google.
        // For Apple, we use the id_token from the refresh response.
        // This method signature exists for interface compatibility.
        // The actual implementation uses decodeIdTokenForUserData with the id_token.
        throw new OAuthException(
            'Apple does not support fetching user data from access token. ' .
            'Use the id_token from the refresh response instead.'
        );
    }

    /**
     * Get user data from an id_token (used during refresh flow).
     */
    public function getUserDataFromIdToken(string $idToken): OAuthUserData
    {
        $data = $this->decodeIdToken($idToken);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $data['sub'],
            email: $data['email'],
            firstName: $data['firstName'] ?? null,
            lastName: $data['lastName'] ?? null,
        );
    }

    public function refreshTokens(string $refreshToken): OAuthTokenData
    {
        $clientSecret = $this->clientSecretGenerator->generate();

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['access_token'])) {
                throw new OAuthException('Apple refresh token response missing access_token');
            }

            // Apple rotates refresh tokens on each use
            return new OAuthTokenData(
                accessToken: $data['access_token'],
                refreshToken: $data['refresh_token'] ?? null,
                expiresIn: $data['expires_in'] ?? null,
                tokenType: $data['token_type'] ?? null,
                idToken: $data['id_token'] ?? null,
            );
        } catch (GuzzleException $e) {
            throw new OAuthException('Failed to refresh Apple tokens: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new OAuthException('Failed to parse Apple refresh response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token?: string, id_token: string}
     */
    private function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        $clientSecret = $this->clientSecretGenerator->generate();

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'form_params' => [
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['access_token'], $data['id_token'])) {
                throw new OAuthException('Apple token response missing required fields');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new OAuthException('Failed to exchange Apple authorization code: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new OAuthException('Failed to parse Apple token response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decode the id_token JWT to extract user data.
     *
     * Note: In production, you should verify the JWT signature using Apple's public keys.
     * For simplicity, we're just decoding the payload here.
     *
     * @return array{sub: string, email: string, email_verified?: bool, firstName?: string, lastName?: string}
     */
    private function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new OAuthException('Invalid Apple id_token format');
        }

        $payload = $this->base64UrlDecode($parts[1]);
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['sub'], $data['email'])) {
            throw new OAuthException('Apple id_token missing required claims (sub, email)');
        }

        return $data;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new OAuthException('Failed to decode Apple id_token payload');
        }

        return $decoded;
    }
}
