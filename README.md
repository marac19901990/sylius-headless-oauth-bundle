# SyliusHeadlessOAuthBundle

[![CI](https://github.com/marac19901990/sylius-headless-oauth-bundle/workflows/CI/badge.svg)](https://github.com/marac19901990/sylius-headless-oauth-bundle/actions)
[![codecov](https://codecov.io/gh/marac19901990/sylius-headless-oauth-bundle/branch/master/graph/badge.svg)](https://codecov.io/gh/marac19901990/sylius-headless-oauth-bundle)
[![Latest Stable Version](https://poser.pugx.org/marac19901990/sylius-headless-oauth-bundle/v/stable)](https://packagist.org/packages/marac19901990/sylius-headless-oauth-bundle)
[![Total Downloads](https://poser.pugx.org/marac19901990/sylius-headless-oauth-bundle/downloads)](https://packagist.org/packages/marac19901990/sylius-headless-oauth-bundle)
[![License](https://poser.pugx.org/marac19901990/sylius-headless-oauth-bundle/license)](https://packagist.org/packages/marac19901990/sylius-headless-oauth-bundle)

A Symfony bundle that provides headless OAuth authentication for Sylius e-commerce platform via API Platform. Supports Google, Apple, Facebook, GitHub, LinkedIn Sign-In, and any OpenID Connect provider (Keycloak, Auth0, Okta, Azure AD, etc.) out of the box.

## Quick Start (5 Minutes)

Get OAuth authentication running in your Sylius shop:

```bash
# 1. Install the bundle
composer require marac19901990/sylius-headless-oauth-bundle

# 2. Run the interactive installer
bin/console sylius:oauth:install

# 3. Create and run the migration (creates the sylius_oauth_identity table)
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate

# 4. Configure your OAuth provider in .env.local
echo "GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com" >> .env.local
echo "GOOGLE_CLIENT_SECRET=your-client-secret" >> .env.local

# 5. Test it!
curl -X POST https://your-shop.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{"code": "auth-code-from-google", "redirectUri": "https://your-frontend.com/callback"}'
```

**No Customer entity changes required!** The bundle uses a separate `sylius_oauth_identity` table to store OAuth connections.

**Response:**
```json
{
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refreshToken": "1//0eXXXXXXXXXXXX",
    "customerId": 123
}
```

That's it! Your frontend can now authenticate users via OAuth and receive JWT tokens for API access.

→ **[See full documentation below](#configuration)** | **[View architecture diagram](docs/oauth-flow.md)** | **[Compare with alternatives](docs/COMPARISON.md)**

---

## Features

- **Headless authentication** - Pure API-based OAuth flow, perfect for SPAs and mobile apps
- **Google Sign-In** - Full OAuth 2.0 implementation with userinfo endpoint
- **Apple Sign-In** - Complete implementation including JWT client secret generation
- **Facebook Sign-In** - Graph API integration for social login
- **GitHub Sign-In** - OAuth 2.0 for developer-focused applications
- **LinkedIn Sign-In** - Perfect for B2B e-commerce, wholesale portals, and professional services
- **Generic OpenID Connect** - Support for any OIDC-compliant provider (Keycloak, Auth0, Okta, Azure AD)
- **Automatic user management** - Finds existing users by provider ID or email, creates new users automatically
- **Provider linking** - Links OAuth providers to existing accounts found by email
- **Sylius integration** - Works with Sylius Customer and ShopUser entities
- **JWT tokens** - Returns JWT tokens via LexikJWTAuthenticationBundle
- **Admin UI integration** - View connected providers in customer grid and detail pages

## Requirements

- PHP 8.2 or higher
- Symfony 6.4 or 7.0
- Sylius 1.12+
- API Platform 3.0+
- LexikJWTAuthenticationBundle 2.0+

## Installation

```bash
composer require marac19901990/sylius-headless-oauth-bundle
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Marac\SyliusHeadlessOAuthBundle\SyliusHeadlessOAuthBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/sylius_headless_oauth.yaml`:

```yaml
sylius_headless_oauth:
    # Security settings (recommended for production)
    security:
        # List of allowed redirect URIs
        # If empty, all URIs are allowed (NOT recommended for production)
        allowed_redirect_uris:
            - 'https://myapp.com/oauth/callback'
            - 'https://staging.myapp.com/oauth/callback'

        # Verify Apple id_token JWT signatures against Apple's JWKS
        # Disable only for testing/development
        verify_apple_jwt: true

    providers:
        google:
            enabled: true
            client_id: '%env(GOOGLE_CLIENT_ID)%'
            client_secret: '%env(GOOGLE_CLIENT_SECRET)%'
        apple:
            enabled: true
            client_id: '%env(APPLE_CLIENT_ID)%'
            team_id: '%env(APPLE_TEAM_ID)%'
            key_id: '%env(APPLE_KEY_ID)%'
            private_key_path: '%env(APPLE_PRIVATE_KEY_PATH)%'
        facebook:
            enabled: true
            client_id: '%env(FACEBOOK_CLIENT_ID)%'
            client_secret: '%env(FACEBOOK_CLIENT_SECRET)%'
        linkedin:
            enabled: true
            client_id: '%env(LINKEDIN_CLIENT_ID)%'
            client_secret: '%env(LINKEDIN_CLIENT_SECRET)%'

        # Generic OIDC providers (Keycloak, Auth0, Okta, etc.)
        oidc:
            keycloak:  # Provider name used in API endpoint
                enabled: true
                issuer_url: '%env(KEYCLOAK_ISSUER_URL)%'
                client_id: '%env(KEYCLOAK_CLIENT_ID)%'
                client_secret: '%env(KEYCLOAK_CLIENT_SECRET)%'
                verify_jwt: true
                scopes: 'openid email profile'
```

Add environment variables to your `.env`:

```env
# Google OAuth
GOOGLE_CLIENT_ID=your-google-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret

# Apple Sign-In
APPLE_CLIENT_ID=com.your.app.id
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_PRIVATE_KEY_PATH=%kernel.project_dir%/config/secrets/apple_auth_key.p8

# Facebook Sign-In
FACEBOOK_CLIENT_ID=your-facebook-app-id
FACEBOOK_CLIENT_SECRET=your-facebook-app-secret

# LinkedIn Sign-In
LINKEDIN_CLIENT_ID=your-linkedin-client-id
LINKEDIN_CLIENT_SECRET=your-linkedin-client-secret

# Keycloak (or other OIDC provider)
KEYCLOAK_ISSUER_URL=https://keycloak.example.com/realms/your-realm
KEYCLOAK_CLIENT_ID=your-keycloak-client-id
KEYCLOAK_CLIENT_SECRET=your-keycloak-client-secret
```

## Database Setup

The bundle uses a separate `sylius_oauth_identity` table to store OAuth connections. **You don't need to modify your Customer entity!**

After installing the bundle, create and run the migration:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

This creates the `sylius_oauth_identity` table with the following structure:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `customer_id` | INT | Foreign key to sylius_customer |
| `provider` | VARCHAR(50) | Provider name (google, apple, etc.) |
| `identifier` | VARCHAR(255) | OAuth provider's user ID |
| `connected_at` | DATETIME | When the connection was made |

**Benefits of this approach:**
- No changes to your existing Customer entity
- Supports unlimited OAuth providers per customer
- Each provider connection has its own timestamp
- Easy to extend with custom providers

## API Endpoints

### GET /api/v2/auth/oauth/providers

Discover available OAuth providers. This allows frontend applications to dynamically render OAuth login buttons based on which providers are enabled.

**Response (200):**
```json
{
    "providers": [
        {"name": "google", "displayName": "Google"},
        {"name": "apple", "displayName": "Apple"},
        {"name": "keycloak", "displayName": "Keycloak"}
    ]
}
```

**Frontend Usage:**
```javascript
// Fetch available providers
const response = await fetch('/api/v2/auth/oauth/providers');
const { providers } = await response.json();

// Render only enabled providers
providers.forEach(provider => {
    renderOAuthButton(provider.name, provider.displayName);
});
```

### POST /api/v2/auth/oauth/{provider}

Exchange an OAuth authorization code for a JWT token.

**URL Parameters:**
- `provider` - The OAuth provider name (`google` or `apple`)

**Request Body:**
```json
{
    "code": "authorization_code_from_oauth_provider",
    "redirectUri": "https://your-app.com/oauth/callback"
}
```

**Success Response (200):**
```json
{
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refreshToken": "1//0eXXXXXXXXXXXX",
    "customerId": 123
}
```

**Error Responses:**
- `400` - Invalid request (missing code, invalid redirect URI, provider not supported)
- `400` - OAuth provider error (invalid code, expired code, etc.)

### Example: Google Sign-In

```bash
curl -X POST https://your-sylius-shop.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{
    "code": "4/0AX4XfWh...",
    "redirectUri": "https://your-app.com/oauth/callback"
  }'
```

### Example: Apple Sign-In

```bash
curl -X POST https://your-sylius-shop.com/api/v2/auth/oauth/apple \
  -H "Content-Type: application/json" \
  -d '{
    "code": "c1a2b3...",
    "redirectUri": "https://your-app.com/oauth/callback"
  }'
```

### POST /api/v2/auth/oauth/refresh/{provider}

Refresh an expired JWT token using the OAuth refresh token.

**URL Parameters:**
- `provider` - The OAuth provider name (`google` or `apple`)

**Request Body:**
```json
{
    "refreshToken": "1//0eXXXXXXXXXXXX"
}
```

**Success Response (200):**
```json
{
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
    "refreshToken": "1//0eNewRefreshToken",
    "customerId": 123
}
```

**Note:** Google typically reuses the same refresh token. Apple may rotate refresh tokens on each use.

### GET /api/v2/auth/oauth/connections (Authenticated)

List OAuth providers connected to the current user's account. Requires authentication.

**Response (200):**
```json
{
    "connections": [
        {"provider": "google", "displayName": "Google", "connectedAt": null},
        {"provider": "apple", "displayName": "Apple", "connectedAt": null}
    ]
}
```

**Error Response (401):**
```json
{
    "code": 401,
    "message": "Authentication required"
}
```

### DELETE /api/v2/auth/oauth/connections/{provider} (Authenticated)

Unlink an OAuth provider from the current user's account. Requires authentication.

**URL Parameters:**
- `provider` - The OAuth provider name to unlink (`google`, `apple`, `facebook`, `github`, etc.)

**Success Response (200):**
```json
{
    "message": "Provider disconnected successfully",
    "provider": "google"
}
```

**Error Responses:**
- `400` - Provider is not connected to this account
- `400` - Cannot unlink the last authentication method (user has no password and no other provider)
- `401` - Authentication required

## Frontend Integration

### Google Sign-In (Web)

1. Set up Google Sign-In in your frontend
2. Configure the same `redirect_uri` in Google Console and your request
3. After user authorizes, Google redirects with a `code` parameter
4. Send this code to `/api/v2/auth/oauth/google`

### Apple Sign-In (Web/iOS)

1. Configure Apple Sign-In in Apple Developer Portal
2. Set up Sign in with Apple JS or native SDK
3. After user authorizes, Apple provides an authorization code
4. Send this code to `/api/v2/auth/oauth/apple`

## Provider-Specific Notes

### Google

- Requires `client_id` and `client_secret` from Google Cloud Console
- Set up OAuth 2.0 credentials with appropriate redirect URIs
- Enable the Google+ API or People API for user info

### Apple

- Requires Apple Developer Program membership
- Create a Services ID and configure Sign in with Apple
- Generate a private key (.p8 file) for JWT signing
- **Important:** Apple only sends the user's name on the **first** authorization. After that, only email and Apple ID are provided. The bundle captures the name on first login.
- The bundle automatically generates the required JWT client secret using your private key
- **Email is required:** The bundle requires an email address from the OAuth provider. If a user selects Apple's "Hide My Email" option, Apple provides a private relay email (e.g., `xyz@privaterelay.appleid.com`) which works normally. If no email is returned (extremely rare), authentication fails with a clear error: `"Apple id_token missing required claim: email"`.
- **Caching:** Apple's JWKS (public keys for JWT verification) are cached for 24 hours to minimize network calls. Client secrets are generated fresh per request as the cryptographic overhead is negligible (~1-5ms).

### LinkedIn

- **Best for:** B2B e-commerce, wholesale portals, professional services
- Requires a LinkedIn Developer App at https://www.linkedin.com/developers/apps
- Request access to "Sign In with LinkedIn using OpenID Connect" product
- Uses LinkedIn's OpenID Connect implementation with the userinfo endpoint
- Supports refresh tokens for maintaining sessions

## How It Works

1. **User initiates OAuth** - Your frontend redirects to Google/Apple for authentication
2. **OAuth callback** - Provider redirects back with an authorization code
3. **Code exchange** - Your frontend sends the code to this bundle's endpoint
4. **Token exchange** - Bundle exchanges code for tokens with the OAuth provider
5. **User resolution** - Bundle finds or creates a Sylius user:
   - First, searches by provider ID in `sylius_oauth_identity` table
   - If not found, searches by email in the customer table
   - If found by email, creates an OAuth identity record linking the provider
   - If not found at all, creates a new Customer, ShopUser, and OAuth identity
6. **JWT generation** - Returns a JWT token for API authentication

## Extending

### Adding a New Provider

1. Create a class implementing `OAuthProviderInterface`:

```php
<?php

namespace App\Provider;

use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

class FacebookProvider implements OAuthProviderInterface
{
    public function supports(string $provider): bool
    {
        return 'facebook' === strtolower($provider);
    }

    public function getUserData(string $code, string $redirectUri): OAuthUserData
    {
        // Exchange code for tokens and fetch user data
        return new OAuthUserData(
            provider: 'facebook',
            providerId: $facebookUserId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
        );
    }
}
```

2. Register and tag the service:

```yaml
services:
    App\Provider\FacebookProvider:
        tags:
            - { name: 'sylius_headless_oauth.provider' }
```

The bundle automatically stores OAuth identities in the `sylius_oauth_identity` table, so you don't need to modify any entities.

## Events

The bundle dispatches Symfony events at key points in the OAuth flow, allowing you to hook into the process.

### Available Events

| Event | When Dispatched | Use Case |
|-------|-----------------|----------|
| `sylius.headless_oauth.pre_user_create` | Before creating a new user | Modify user data, add custom fields |
| `sylius.headless_oauth.post_authentication` | After successful auth | Cart merging, analytics, welcome emails |
| `sylius.headless_oauth.provider_linked` | When provider linked to existing account | Notifications, audit logging |

### Example: Cart Merging on Login

```php
<?php

namespace App\EventSubscriber;

use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPostAuthenticationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OAuthCartMergeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OAuthPostAuthenticationEvent::NAME => 'onPostAuthentication',
        ];
    }

    public function onPostAuthentication(OAuthPostAuthenticationEvent $event): void
    {
        // Merge anonymous cart with user's cart
        $shopUser = $event->shopUser;
        $isNewUser = $event->isNewUser;

        // ... your cart merging logic
    }
}
```

### Example: Welcome Email for New Users

```php
<?php

namespace App\EventSubscriber;

use Marac\SyliusHeadlessOAuthBundle\Event\OAuthPostAuthenticationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WelcomeEmailSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OAuthPostAuthenticationEvent::NAME => 'onPostAuthentication',
        ];
    }

    public function onPostAuthentication(OAuthPostAuthenticationEvent $event): void
    {
        if ($event->isNewUser) {
            // Send welcome email to new OAuth users
            $email = $event->userData->email;
            // ... send email
        }
    }
}
```

## Security

This bundle includes several security features to protect your OAuth implementation in production.

### JWT Signature Verification (Apple)

Apple id_tokens are verified against Apple's public keys (JWKS endpoint) before accepting them. This prevents attackers from forging tokens with arbitrary claims.

- **Enabled by default**: `verify_apple_jwt: true`
- Keys are cached for 24 hours
- Verification includes: signature, issuer, audience, expiration

### Redirect URI Allowlist

Prevent open redirect attacks by configuring allowed redirect URIs:

```yaml
sylius_headless_oauth:
    security:
        allowed_redirect_uris:
            - 'https://myapp.com/oauth/callback'
            - 'https://staging.myapp.com'  # Allows all paths under this origin
```

If no URIs are configured, validation is skipped (development mode).

### State Parameter (CSRF Protection)

The bundle supports the OAuth `state` parameter for CSRF protection:

```json
{
    "code": "authorization_code",
    "redirectUri": "https://myapp.com/callback",
    "state": "random_csrf_token_from_your_frontend"
}
```

The state is echoed back in the response for client verification:

```json
{
    "token": "eyJ...",
    "customerId": 123,
    "state": "random_csrf_token_from_your_frontend"
}
```

**Frontend responsibility**: Generate, store, and verify the state parameter.

### Security Logging

All OAuth events are logged for audit purposes:

- Successful authentications
- Authentication failures
- JWT verification failures
- Redirect URI rejections

Configure a dedicated Monolog channel for these logs:

```yaml
monolog:
    channels: ['oauth_security']
    handlers:
        oauth:
            type: stream
            path: '%kernel.logs_dir%/oauth_security.log'
            channels: ['oauth_security']
```

### Production Checklist

Before deploying to production:

1. **Configure allowed redirect URIs** - Don't leave the allowlist empty
2. **Keep JWT verification enabled** - `verify_apple_jwt: true`
3. **Use HTTPS** - All OAuth redirects must use HTTPS
4. **Implement state parameter** - Your frontend should generate and verify state
5. **Review security logs** - Monitor for suspicious activity
6. **Secure your credentials** - Use environment variables, not hardcoded values

## License

MIT License. See [LICENSE](LICENSE) for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to report bugs, submit pull requests, and more.
