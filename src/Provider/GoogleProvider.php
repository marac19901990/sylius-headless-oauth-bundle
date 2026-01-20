<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

final class GoogleProvider implements OAuthProviderInterface
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const PROVIDER_NAME = 'google';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $enabled = true,
    ) {
        if ($this->enabled) {
            $this->validateCredentials();
        }
    }

    private function validateCredentials(): void
    {
        if (empty($this->clientId) || $this->clientId === '%env(GOOGLE_CLIENT_ID)%') {
            throw new \InvalidArgumentException(
                'Google OAuth is enabled but GOOGLE_CLIENT_ID is not configured. ' .
                'Set the environment variable or disable Google: sylius_headless_oauth.providers.google.enabled: false'
            );
        }

        if (empty($this->clientSecret) || $this->clientSecret === '%env(GOOGLE_CLIENT_SECRET)%') {
            throw new \InvalidArgumentException(
                'Google OAuth is enabled but GOOGLE_CLIENT_SECRET is not configured. ' .
                'Set the environment variable or disable Google: sylius_headless_oauth.providers.google.enabled: false'
            );
        }
    }

    public function supports(string $provider): bool
    {
        return $this->enabled && self::PROVIDER_NAME === strtolower($provider);
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        $tokens = $this->exchangeCodeForTokens($code, $redirectUri);
        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        return new OAuthUserData(
            provider: self::PROVIDER_NAME,
            providerId: $userInfo['id'],
            email: $userInfo['email'],
            firstName: $userInfo['given_name'] ?? null,
            lastName: $userInfo['family_name'] ?? null,
        );
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token?: string, id_token?: string}
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
                throw new OAuthException('Google token response missing access_token');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new OAuthException('Failed to exchange Google authorization code: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new OAuthException('Failed to parse Google token response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{id: string, email: string, verified_email?: bool, given_name?: string, family_name?: string, picture?: string}
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

            if (!isset($data['id'], $data['email'])) {
                throw new OAuthException('Google user info response missing required fields (id, email)');
            }

            return $data;
        } catch (GuzzleException $e) {
            throw new OAuthException('Failed to fetch Google user info: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new OAuthException('Failed to parse Google user info response: ' . $e->getMessage(), 0, $e);
        }
    }
}
