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

final class FacebookProvider implements ConfigurableOAuthProviderInterface, RefreshableOAuthProviderInterface
{
    private const TOKEN_URL = 'https://graph.facebook.com/v19.0/oauth/access_token';
    private const USERINFO_URL = 'https://graph.facebook.com/me';
    private const PROVIDER_NAME = 'facebook';

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
        return 'Facebook';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCredentialStatus(): array
    {
        return [
            'client_id' => !empty($this->clientId) && $this->clientId !== '%env(FACEBOOK_CLIENT_ID)%',
            'client_secret' => !empty($this->clientSecret) && $this->clientSecret !== '%env(FACEBOOK_CLIENT_SECRET)%',
        ];
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $this->validateCredentials();

        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $userInfo['id'],
            email: $userInfo['email'],
            firstName: $userInfo['first_name'] ?? null,
            lastName: $userInfo['last_name'] ?? null,
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
            providerId: $userInfo['id'],
            email: $userInfo['email'],
            firstName: $userInfo['first_name'] ?? null,
            lastName: $userInfo['last_name'] ?? null,
        );
    }

    public function getUserDataFromTokenData(OAuthTokenData $tokenData): OAuthUserData
    {
        return $this->getUserDataFromAccessToken($tokenData->accessToken);
    }

    public function refreshTokens(string $refreshToken): OAuthTokenData
    {
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

            if (!isset($data['access_token'])) {
                throw new OAuthException('Facebook refresh token response missing access_token');
            }

            return new OAuthTokenData(
                accessToken: $data['access_token'],
                refreshToken: $data['refresh_token'] ?? $refreshToken,
                expiresIn: $data['expires_in'] ?? null,
                tokenType: $data['token_type'] ?? null,
            );
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to refresh Facebook tokens: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse Facebook refresh response: ' . $e->getMessage(), 400, $e);
        }
    }

    private function validateCredentials(): void
    {
        $this->credentialValidator->validateMany([
            ['value' => $this->clientId, 'env' => 'FACEBOOK_CLIENT_ID', 'name' => 'client ID'],
            ['value' => $this->clientSecret, 'env' => 'FACEBOOK_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'Facebook');
    }

    /**
     * @return array{access_token: string, token_type?: string, expires_in?: int, refresh_token?: string}
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

            if (!isset($data['access_token'])) {
                throw new OAuthException('Facebook token response missing access_token');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to exchange Facebook authorization code: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse Facebook token response: ' . $e->getMessage(), 400, $e);
        }
    }

    /**
     * @return array{id: string, email: string, first_name?: string, last_name?: string}
     */
    private function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::USERINFO_URL, [
                'query' => [
                    'fields' => 'id,email,first_name,last_name',
                    'access_token' => $accessToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['id'], $data['email'])) {
                throw new OAuthException('Facebook user info response missing required fields (id, email)');
            }

            return $data;
        } catch (GuzzleException $e) {
            $statusCode = $e instanceof RequestException ? ($e->getResponse()?->getStatusCode() ?? 400) : 400;

            throw new OAuthException('Failed to fetch Facebook user info: ' . $e->getMessage(), $statusCode, $e);
        } catch (JsonException $e) {
            throw new OAuthException('Failed to parse Facebook user info response: ' . $e->getMessage(), 400, $e);
        }
    }
}
