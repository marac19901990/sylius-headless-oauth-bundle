# SyliusHeadlessOAuthBundle

A Symfony bundle that provides headless OAuth authentication for Sylius e-commerce platform via API Platform. Supports Google and Apple Sign-In out of the box.

## Features

- **Headless authentication** - Pure API-based OAuth flow, perfect for SPAs and mobile apps
- **Google Sign-In** - Full OAuth 2.0 implementation with userinfo endpoint
- **Apple Sign-In** - Complete implementation including JWT client secret generation
- **Automatic user management** - Finds existing users by provider ID or email, creates new users automatically
- **Provider linking** - Links OAuth providers to existing accounts found by email
- **Sylius integration** - Works with Sylius Customer and ShopUser entities
- **JWT tokens** - Returns JWT tokens via LexikJWTAuthenticationBundle

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
```

## Customer Entity Setup

Your Customer entity must implement `OAuthIdentityInterface`. The easiest way is to use the provided trait:

```php
<?php

namespace App\Entity\Customer;

use Doctrine\ORM\Mapping as ORM;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;
use Sylius\Component\Core\Model\Customer as BaseCustomer;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_customer')]
class Customer extends BaseCustomer implements OAuthIdentityInterface
{
    use OAuthIdentityTrait;
}
```

The trait automatically adds:
- `googleId` column (varchar 255, nullable, unique)
- `appleId` column (varchar 255, nullable, unique)
- Getters and setters for both fields

After adding the trait, create and run a migration:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

## API Endpoint

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
    "refreshToken": null,
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

## How It Works

1. **User initiates OAuth** - Your frontend redirects to Google/Apple for authentication
2. **OAuth callback** - Provider redirects back with an authorization code
3. **Code exchange** - Your frontend sends the code to this bundle's endpoint
4. **Token exchange** - Bundle exchanges code for tokens with the OAuth provider
5. **User resolution** - Bundle finds or creates a Sylius user:
   - First, searches by provider ID (googleId/appleId)
   - If not found, searches by email
   - If found by email, links the provider ID to the existing customer
   - If not found at all, creates a new Customer and ShopUser
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

3. Add the provider ID field to your Customer entity and update `UserResolver` accordingly.

## License

MIT License. See [LICENSE](LICENSE) for details.

## Contributing

Contributions are welcome! Please submit pull requests with tests.
