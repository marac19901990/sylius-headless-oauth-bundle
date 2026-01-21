# Manual Testing Guide: SyliusHeadlessOAuthBundle

A comprehensive guide for manually testing all bundle features by integrating into a real Sylius headless marketplace project.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Phase 1: Installation](#phase-1-installation)
- [Phase 2: API Endpoint Testing](#phase-2-api-endpoint-testing)
- [Phase 3: Security Testing](#phase-3-security-testing)
- [Phase 4: Admin UI Testing](#phase-4-admin-ui-testing)
- [Phase 5: Edge Cases](#phase-5-edge-cases)
- [Phase 6: Event Subscribers](#phase-6-event-subscribers)
- [Testing Checklist](#testing-checklist)
- [Testing Tools](#testing-tools)

---

## Prerequisites

### Your Sylius Project Requirements

| Requirement | Details |
|-------------|---------|
| Sylius | 1.12+ with API Platform enabled |
| JWT Authentication | LexikJWTAuthenticationBundle configured |
| PHP | 8.2+ |
| Client | Frontend/API client available for testing |

### OAuth Provider Credentials Needed

Before testing, obtain credentials from these providers:

| Provider | Where to Get | Required Credentials |
|----------|--------------|---------------------|
| **Google** | [Google Cloud Console](https://console.cloud.google.com/) | Client ID + Client Secret |
| **Apple** | [Apple Developer](https://developer.apple.com/) | Client ID + Team ID + Key ID + .p8 file |
| **Facebook** | [Meta Developer Portal](https://developers.facebook.com/) | App ID + App Secret |
| **GitHub** | [GitHub Developer Settings](https://github.com/settings/developers) | Client ID + Client Secret |
| **LinkedIn** | [LinkedIn Developer Portal](https://www.linkedin.com/developers/) | Client ID + Client Secret |
| **OIDC** | Provider-specific | Issuer URL + Client ID + Client Secret |

---

## Phase 1: Installation

### 1.1 Install Bundle

```bash
composer require marac19901990/sylius-headless-oauth-bundle
```

**Verify:**
- [ ] No composer conflicts
- [ ] Bundle auto-registered in `config/bundles.php` (or add manually):
  ```php
  Marac\SyliusHeadlessOAuthBundle\SyliusHeadlessOAuthBundle::class => ['all' => true],
  ```

### 1.2 Run Install Command

```bash
bin/console sylius:oauth:install
```

**Expected output:**
```
 Sylius Headless OAuth Bundle Installer
 ======================================

 [OK] Requirements check passed

 Configuration file created at config/packages/sylius_headless_oauth.yaml
 ...
```

**Verify:**
- [ ] Command completes without errors
- [ ] Config file created at `config/packages/sylius_headless_oauth.yaml`
- [ ] All requirements pass (PHP 8.2+, required extensions)

### 1.3 Update Customer Entity

Add the OAuth trait to your Customer entity at `src/Entity/Customer/Customer.php`:

```php
<?php

declare(strict_types=1);

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

**Verify:**
- [ ] No syntax errors
- [ ] Entity compiles correctly (`bin/console cache:clear`)

### 1.4 Run Database Migration

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

**Verify:**
- [ ] Migration creates 6 new columns on `sylius_customer` table:

| Column | Type | Attributes |
|--------|------|------------|
| `google_id` | VARCHAR(255) | nullable, unique |
| `apple_id` | VARCHAR(255) | nullable, unique |
| `facebook_id` | VARCHAR(255) | nullable, unique |
| `github_id` | VARCHAR(255) | nullable, unique |
| `linkedin_id` | VARCHAR(255) | nullable, unique |
| `oidc_id` | VARCHAR(255) | nullable, unique |

### 1.5 Configure Providers

Edit `.env.local`:

```env
# Google OAuth
GOOGLE_CLIENT_ID=your-google-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret

# Apple Sign-In
APPLE_CLIENT_ID=com.yourcompany.yourapp
APPLE_TEAM_ID=TEAM123456
APPLE_KEY_ID=KEY123456
APPLE_PRIVATE_KEY_PATH=%kernel.project_dir%/config/secrets/AuthKey_KEY123456.p8

# Facebook Login
FACEBOOK_APP_ID=your-facebook-app-id
FACEBOOK_APP_SECRET=your-facebook-app-secret

# GitHub OAuth
GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret

# LinkedIn Sign-In
LINKEDIN_CLIENT_ID=your-linkedin-client-id
LINKEDIN_CLIENT_SECRET=your-linkedin-client-secret

# Optional: Generic OIDC Provider
OIDC_ISSUER_URL=https://your-keycloak.com/realms/your-realm
OIDC_CLIENT_ID=your-oidc-client-id
OIDC_CLIENT_SECRET=your-oidc-client-secret
```

**Verify with health check:**
```bash
bin/console sylius:oauth:check-providers
```

**Expected output:**
```
 OAuth Provider Health Check
 ===========================

 ---------- --------- --------------------- -------
  Provider   Status    Credentials           Issues
 ---------- --------- --------------------- -------
  Google     Enabled   client_id: OK         None
                       client_secret: OK
  Apple      Enabled   client_id: OK         None
                       team_id: OK
                       key_id: OK
                       private_key_path: OK
  Facebook   Disabled  -                     -
  GitHub     Enabled   client_id: OK         None
                       client_secret: OK
  LinkedIn   Enabled   client_id: OK         None
                       client_secret: OK
 ---------- --------- --------------------- -------

 [OK] All enabled OAuth providers are properly configured.
```

---

## Phase 2: API Endpoint Testing

> **Tip:** Import the Postman collection from `docs/postman-collection.json` for pre-configured requests with auto-token capture.

### 2.1 Provider Discovery

**Request:**
```bash
curl -X GET https://your-shop.com/api/v2/auth/oauth/providers \
  -H "Accept: application/json"
```

**Expected Response (200 OK):**
```json
{
  "providers": [
    {"name": "google", "displayName": "Google"},
    {"name": "apple", "displayName": "Apple"},
    {"name": "github", "displayName": "GitHub"},
    {"name": "linkedin", "displayName": "LinkedIn"}
  ]
}
```

**Verify:**
- [ ] Returns 200 OK
- [ ] Lists all enabled providers (disabled providers not shown)
- [ ] Each provider has `name` (API identifier) and `displayName` (UI label)

### 2.2 OAuth Authentication Flow

For each provider, complete this flow:

#### Step 1: Frontend Redirect to Provider

Your frontend redirects the user to the OAuth provider. Example for Google:
```
https://accounts.google.com/o/oauth2/v2/auth?
  client_id=YOUR_CLIENT_ID&
  redirect_uri=https://your-frontend.com/callback&
  response_type=code&
  scope=openid%20email%20profile&
  state=random_csrf_token
```

#### Step 2: User Completes Consent

User authorizes the application in their browser.

#### Step 3: Capture Authorization Code

Provider redirects back to your frontend with:
```
https://your-frontend.com/callback?code=AUTHORIZATION_CODE&state=random_csrf_token
```

#### Step 4: Exchange Code for JWT

**Request:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/{provider} \
  -H "Content-Type: application/json" \
  -d '{
    "code": "AUTHORIZATION_CODE",
    "redirectUri": "https://your-frontend.com/callback"
  }'
```

**Expected Response (200 OK):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refreshToken": "1//0eXXXXXXXXXXXX",
  "customerId": 123
}
```

#### Test Results Per Provider

| Provider | JWT | Refresh Token | Customer ID | Notes |
|----------|-----|---------------|-------------|-------|
| **Google** | [ ] | [ ] Yes | [ ] | Full OAuth 2.0 |
| **Apple** | [ ] | [ ] Yes | [ ] | First login includes name |
| **Facebook** | [ ] | [ ] Limited | [ ] | May not return refresh token |
| **GitHub** | [ ] | [ ] No | [ ] | Tokens don't expire |
| **LinkedIn** | [ ] | [ ] Yes | [ ] | B2B use case |
| **OIDC** | [ ] | [ ] Depends | [ ] | Provider-specific |

**Verify for each successful authentication:**
- [ ] New user: Customer + ShopUser created in database
- [ ] Provider ID stored in correct column (e.g., `google_id` for Google)
- [ ] Customer email matches provider email
- [ ] JWT token is valid (decode at jwt.io to verify claims)

### 2.3 Existing User Linking

**Scenario:** Test that users with the same email are linked, not duplicated.

1. Create a customer manually with email `test@example.com`
2. OAuth login with a provider account that has `test@example.com`

**Verify:**
- [ ] No new customer created (same customer ID)
- [ ] Provider ID linked to existing customer
- [ ] Multiple providers can link to same customer

### 2.4 Token Refresh

**Request:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/refresh/{provider} \
  -H "Content-Type: application/json" \
  -d '{
    "refreshToken": "REFRESH_TOKEN_FROM_LOGIN"
  }'
```

**Expected Response (200 OK):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refreshToken": "1//0eNewRefreshToken",
  "customerId": 123
}
```

**Test providers with refresh support:**

| Provider | Supports Refresh | Expected Result |
|----------|-----------------|-----------------|
| **Google** | Yes | [ ] Returns new JWT + possibly rotated refresh token |
| **Apple** | Yes | [ ] Returns new JWT + possibly rotated refresh token |
| **LinkedIn** | Yes | [ ] Returns new JWT + refresh token |
| **GitHub** | No | [ ] Returns 400 error (expected) |
| **Facebook** | Limited | [ ] May return error |

**Verify:**
- [ ] New JWT is valid
- [ ] Same `customerId` returned
- [ ] Refresh token rotation handled correctly

### 2.5 List OAuth Connections (Authenticated)

**Request:**
```bash
curl -X GET https://your-shop.com/api/v2/auth/oauth/connections \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

**Expected Response (200 OK):**
```json
{
  "connections": [
    {"provider": "google", "displayName": "Google", "connectedAt": null},
    {"provider": "apple", "displayName": "Apple", "connectedAt": null}
  ]
}
```

**Verify:**
- [ ] Returns 200 with list of connected providers for current user
- [ ] Returns 401 without token
- [ ] Only shows providers linked to authenticated user

### 2.6 Unlink OAuth Connection (Authenticated)

**Request:**
```bash
curl -X DELETE https://your-shop.com/api/v2/auth/oauth/connections/{provider} \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Expected Response (200 OK):**
```json
{
  "message": "Provider disconnected successfully",
  "provider": "google"
}
```

**Verify:**
- [ ] Returns 200 + success message
- [ ] Provider ID removed from customer record (e.g., `google_id = NULL`)
- [ ] Provider no longer listed in connections
- [ ] Returns 400 if provider not connected
- [ ] Returns 400 if trying to unlink last auth method (no password set)

---

## Phase 3: Security Testing

### 3.1 Redirect URI Validation

**Configure allowed URIs in `config/packages/sylius_headless_oauth.yaml`:**
```yaml
sylius_headless_oauth:
    security:
        allowed_redirect_uris:
            - 'https://allowed-domain.com/callback'
            - 'https://app.yourcompany.com/oauth'
```

**Test with non-whitelisted URI:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{
    "code": "valid_code",
    "redirectUri": "https://evil-site.com/callback"
  }'
```

**Expected Response (400 Bad Request):**
```json
{
  "code": 400,
  "message": "Invalid redirect URI"
}
```

**Verify:**
- [ ] Non-whitelisted URIs return 400 error
- [ ] Whitelisted URIs work correctly
- [ ] Empty whitelist allows all (with dev mode warning in logs)

### 3.2 State Parameter (CSRF Protection)

**Request with state:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{
    "code": "valid_code",
    "redirectUri": "https://your-app.com/callback",
    "state": "my_csrf_token_12345"
  }'
```

**Expected Response:**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "customerId": 123,
  "state": "my_csrf_token_12345"
}
```

**Verify:**
- [ ] State is echoed back in response
- [ ] Frontend can verify state matches the original for CSRF protection

### 3.3 Invalid Provider

**Request:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/twitter \
  -H "Content-Type: application/json" \
  -d '{"code": "x", "redirectUri": "https://x.com"}'
```

**Expected Response (400 Bad Request):**
```json
{
  "code": 400,
  "message": "Provider 'twitter' is not supported"
}
```

**Verify:**
- [ ] Returns 400 error with clear message
- [ ] Does not expose internal details

### 3.4 Invalid/Expired Authorization Code

**Request:**
```bash
curl -X POST https://your-shop.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{"code": "invalid_or_expired_code", "redirectUri": "https://your-app.com/callback"}'
```

**Expected Response (400 Bad Request):**
```json
{
  "code": 400,
  "message": "Google authentication failed: The code passed is incorrect or expired."
}
```

**Verify:**
- [ ] Returns 400 error with provider-specific message
- [ ] Error logged to security channel (check logs)
- [ ] Does not expose sensitive details

### 3.5 Rate Limiting (if configured)

**Test rapid requests to check rate limiting:**
```bash
for i in {1..20}; do
  curl -s -o /dev/null -w "%{http_code}\n" \
    -X POST https://your-shop.com/api/v2/auth/oauth/google \
    -H "Content-Type: application/json" \
    -d '{"code": "test", "redirectUri": "https://app.com/cb"}'
done
```

**Verify:**
- [ ] Rate limiting activates if configured
- [ ] Returns 429 Too Many Requests when limit exceeded

---

## Phase 4: Admin UI Testing

### 4.1 Customer Grid

1. Navigate to: Sylius Admin → Customers
2. Look for OAuth provider column or indicators

**Verify:**
- [ ] Grid shows connected providers (icons/text)
- [ ] Shows "—" or empty for customers without OAuth
- [ ] Column is sortable/filterable (if implemented)

### 4.2 Customer Detail Page

1. Navigate to: Sylius Admin → Customers → [specific customer]
2. Look for OAuth section

**Verify:**
- [ ] Shows list of connected providers with display names
- [ ] Shows provider IDs (masked or full, per configuration)
- [ ] Admin can see which providers are linked

---

## Phase 5: Edge Cases

### 5.1 Apple First Login (Name Capture)

**Important:** Apple only sends user's name on the **first** authorization.

**Test:**
1. Complete fresh Apple Sign-In (first time for this user)
2. Check customer record in database

**Verify:**
- [ ] First name captured from Apple
- [ ] Last name captured from Apple
- [ ] Subsequent logins don't overwrite name with empty values

### 5.2 GitHub Private Email

GitHub users can configure their email to be private.

**Test:**
1. Login with GitHub account that has private email settings enabled
2. Check customer record

**Verify:**
- [ ] Bundle fetches email from `/user/emails` endpoint
- [ ] Primary verified email is used
- [ ] Customer created with correct email

### 5.3 Multiple OAuth Providers, Same Email

**Test:**
1. Login with Google (creates user with email `test@example.com`)
2. Login with GitHub (same email `test@example.com`)

**Verify:**
- [ ] Same customer record used (no duplicate)
- [ ] Customer has both `google_id` and `github_id` set
- [ ] Only one customer exists with that email

### 5.4 Provider Linking to Existing Password-Based Account

**Test:**
1. Create account with email `user@example.com` and password
2. OAuth login with same email via Google

**Verify:**
- [ ] Account linked (not duplicated)
- [ ] User can still login with password
- [ ] User can also login with OAuth

### 5.5 Concurrent Login Attempts

**Test:**
1. Attempt to login with same OAuth code twice simultaneously

**Verify:**
- [ ] First request succeeds
- [ ] Second request fails (code already used)
- [ ] No duplicate customers created

---

## Phase 6: Event Subscribers (Optional)

Test these if you've implemented custom event subscribers.

### 6.1 Pre User Create Event

**Event:** `sylius.headless_oauth.pre_user_create`

Create a test subscriber:
```php
<?php

namespace App\EventSubscriber;

use Marac\SyliusHeadlessOAuthBundle\Event\PreUserCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OAuthTestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            PreUserCreateEvent::class => 'onPreUserCreate',
        ];
    }

    public function onPreUserCreate(PreUserCreateEvent $event): void
    {
        // Log or modify user data before creation
        $userData = $event->getUserData();
        // $event->setUserData($modifiedData);
    }
}
```

**Verify:**
- [ ] Event fires before new user creation
- [ ] Can access user data from provider
- [ ] Can modify user data in subscriber

### 6.2 Post Authentication Event

**Event:** `sylius.headless_oauth.post_authentication`

**Verify:**
- [ ] Event fires after successful authentication
- [ ] Contains `shopUser` entity
- [ ] Contains `isNewUser` flag
- [ ] Contains `userData` from provider

### 6.3 Provider Linked Event

**Event:** `sylius.headless_oauth.provider_linked`

**Verify:**
- [ ] Event fires when provider linked to existing account
- [ ] Contains provider name
- [ ] Contains customer entity

---

## Testing Checklist

### Installation & Setup

| Item | Status | Notes |
|------|--------|-------|
| Bundle installation (composer) | ⬜ | |
| Install command (`sylius:oauth:install`) | ⬜ | |
| Customer entity updated | ⬜ | |
| Database migration executed | ⬜ | |
| Provider credentials configured | ⬜ | |
| Health check passes | ⬜ | |

### API Endpoints

| Endpoint | Status | Notes |
|----------|--------|-------|
| Provider Discovery | ⬜ | |
| Google Authentication | ⬜ | |
| Apple Authentication | ⬜ | |
| Facebook Authentication | ⬜ | |
| GitHub Authentication | ⬜ | |
| LinkedIn Authentication | ⬜ | |
| OIDC Authentication | ⬜ | |
| Token Refresh (Google) | ⬜ | |
| Token Refresh (Apple) | ⬜ | |
| Token Refresh (LinkedIn) | ⬜ | |
| List Connections | ⬜ | |
| Unlink Connection | ⬜ | |

### Security

| Item | Status | Notes |
|------|--------|-------|
| Redirect URI validation | ⬜ | |
| State/CSRF parameter | ⬜ | |
| Invalid provider error | ⬜ | |
| Invalid code error | ⬜ | |
| Rate limiting (if configured) | ⬜ | |

### Edge Cases

| Item | Status | Notes |
|------|--------|-------|
| Same email linking | ⬜ | |
| Apple name capture | ⬜ | |
| GitHub private email | ⬜ | |
| Multiple providers same user | ⬜ | |
| Concurrent login attempts | ⬜ | |

### Admin UI

| Item | Status | Notes |
|------|--------|-------|
| Customer grid | ⬜ | |
| Customer detail page | ⬜ | |

---

## Testing Tools

### Postman Collection

Import from `docs/postman-collection.json`:
- Pre-configured requests for all endpoints
- Variables for `baseUrl`, `jwtToken`, `provider`, `refreshToken`
- Auto-saves JWT tokens after authentication
- Test scripts for response validation

### OpenAPI Specification

Import from `docs/openapi.yaml` into:
- Swagger UI
- Insomnia
- Redoc
- Stoplight Studio

### Console Commands

```bash
# Check provider configuration health
bin/console sylius:oauth:check-providers

# Clear cache after configuration changes
bin/console cache:clear
```

### Database Inspection

```sql
-- Check OAuth columns on customer table
DESCRIBE sylius_customer;

-- Find customers with OAuth connections
SELECT id, email, google_id, apple_id, github_id, facebook_id, linkedin_id, oidc_id
FROM sylius_customer
WHERE google_id IS NOT NULL
   OR apple_id IS NOT NULL
   OR github_id IS NOT NULL
   OR facebook_id IS NOT NULL
   OR linkedin_id IS NOT NULL
   OR oidc_id IS NOT NULL;

-- Count customers by provider
SELECT
    COUNT(google_id) as google_users,
    COUNT(apple_id) as apple_users,
    COUNT(facebook_id) as facebook_users,
    COUNT(github_id) as github_users,
    COUNT(linkedin_id) as linkedin_users,
    COUNT(oidc_id) as oidc_users
FROM sylius_customer;
```

### Log Monitoring

```bash
# Watch OAuth security logs
tail -f var/log/dev.log | grep -i oauth

# Check for authentication errors
grep -i "authentication failed" var/log/dev.log
```

---

## Related Documentation

- [OAuth Flow Guide](oauth-flow.md) - Detailed sequence diagrams and code examples
- [Troubleshooting Guide](TROUBLESHOOTING.md) - Common issues and solutions
- [API Specification](openapi.yaml) - Full OpenAPI 3.1 specification
- [Comparison Guide](COMPARISON.md) - How this bundle compares to alternatives
