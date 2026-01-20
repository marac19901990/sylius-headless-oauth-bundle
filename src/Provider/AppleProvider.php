<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Apple\AppleClientSecretGeneratorInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthTokenData;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Marac\SyliusHeadlessOAuthBundle\Security\AppleJwksVerifier;
use Marac\SyliusHeadlessOAuthBundle\Security\OAuthSecurityLogger;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;

use function count;
use function strlen;

use const JSON_THROW_ON_ERROR;

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
        private readonly CredentialValidator $credentialValidator,
        private readonly string $clientId,
        private readonly bool $enabled = true,
        private readonly ?AppleJwksVerifier $jwksVerifier = null,
        private readonly bool $verifyJwt = true,
        private readonly ?OAuthSecurityLogger $securityLogger = null,
    ) {
        if ($this->enabled) {
            $this->validateCredentials();
        }
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
        return 'Apple';
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
            'Use the id_token from the refresh response instead.',
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

    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData
    {
        // Apple uses id_token to extract user data (no userinfo endpoint)
        if ($tokenData->idToken === null) {
            throw new OAuthException(
                'Apple refresh response did not include id_token. Cannot identify user.',
            );
        }

        return $this->getUserDataFromIdToken($tokenData->idToken);
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
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to refresh Apple tokens: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse Apple refresh response: ' . $e->getMessage(), 400, $e);
        }
    }

    private function validateCredentials(): void
    {
        $this->credentialValidator->validate(
            $this->clientId,
            'APPLE_CLIENT_ID',
            'Apple',
            'client ID',
        );
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
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to exchange Apple authorization code: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse Apple token response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * Decode and verify the id_token JWT.
     *
     * When verification is enabled (default), validates the JWT signature
     * against Apple's JWKS and checks standard claims (iss, aud, exp).
     *
     * @return array{sub: string, email: string, email_verified?: bool, firstName?: string, lastName?: string}
     */
    private function decodeIdToken(string $idToken): array
    {
        // If verification is enabled and we have a verifier, use it
        if ($this->verifyJwt && $this->jwksVerifier !== null) {
            try {
                return $this->jwksVerifier->verify($idToken);
            } catch (OAuthException $e) {
                // Log the verification failure
                $this->securityLogger?->logJwtVerificationFailure(
                    self::PROVIDER_NAME,
                    $e->getMessage(),
                );

                throw $e;
            }
        }

        // Fallback: decode without verification (for testing or when verifier unavailable)
        // In production with verify_apple_jwt: true, this path should not be reached
        return $this->decodeIdTokenWithoutVerification($idToken);
    }

    /**
     * Decode the id_token JWT without signature verification.
     *
     * WARNING: This should only be used for testing purposes.
     * In production, always use the JWKS verifier.
     *
     * @return array{sub: string, email: string, email_verified?: bool, firstName?: string, lastName?: string}
     */
    private function decodeIdTokenWithoutVerification(string $idToken): array
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
