# Troubleshooting Guide

This guide covers common issues you might encounter when using SyliusHeadlessOAuthBundle and their solutions.

## Table of Contents

- [Installation Issues](#installation-issues)
- [Configuration Issues](#configuration-issues)
- [Google OAuth Issues](#google-oauth-issues)
- [Apple Sign In Issues](#apple-sign-in-issues)
- [Facebook Login Issues](#facebook-login-issues)
- [OIDC Provider Issues](#oidc-provider-issues)
- [JWT Token Issues](#jwt-token-issues)
- [Database Issues](#database-issues)
- [API Response Errors](#api-response-errors)
- [Security Issues](#security-issues)
- [Debugging Tips](#debugging-tips)

---

## Installation Issues

### Bundle not loading

**Symptom:** Commands like `sylius:oauth:install` are not available.

**Solution:**
1. Ensure the bundle is registered in `config/bundles.php`:
   ```php
   Marac\SyliusHeadlessOAuthBundle\SyliusHeadlessOAuthBundle::class => ['all' => true],
   ```

2. Clear the cache:
   ```bash
   bin/console cache:clear
   ```

### Missing dependencies

**Symptom:** Class not found errors during installation.

**Solution:**
```bash
composer require marac/sylius-headless-oauth-bundle
```

Ensure these packages are installed:
- `lexik/jwt-authentication-bundle`
- `guzzlehttp/guzzle`

---

## Configuration Issues

### Configuration file not found

**Symptom:** `There is no extension able to load the configuration for "sylius_headless_oauth"`

**Solution:**
1. Run the install command:
   ```bash
   bin/console sylius:oauth:install
   ```

2. Or manually create `config/packages/sylius_headless_oauth.yaml`:
   ```yaml
   sylius_headless_oauth:
       providers:
           google:
               enabled: false
               client_id: '%env(GOOGLE_CLIENT_ID)%'
               client_secret: '%env(GOOGLE_CLIENT_SECRET)%'
   ```

### Environment variables not loaded

**Symptom:** Provider shows "Not configured" even though `.env` has values.

**Solution:**
1. Check that variables are in `.env.local` (not just `.env`):
   ```bash
   GOOGLE_CLIENT_ID=your-client-id
   GOOGLE_CLIENT_SECRET=your-client-secret
   ```

2. Clear the cache after changing environment variables:
   ```bash
   bin/console cache:clear
   ```

3. Verify values are loaded:
   ```bash
   bin/console debug:container --env-vars | grep GOOGLE
   ```

---

## Google OAuth Issues

### "Invalid client" error

**Symptom:** `{"error": "invalid_client"}` from Google.

**Causes & Solutions:**

1. **Wrong client ID or secret**
   - Verify credentials at [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
   - Ensure you're using the correct project

2. **Using wrong credential type**
   - For web applications, use "OAuth 2.0 Client IDs" (Web application type)
   - Do NOT use "Service Account" credentials

3. **Redirect URI mismatch**
   - Add your redirect URI to "Authorized redirect URIs" in Google Console
   - URIs must match exactly (including trailing slashes)

### "Access blocked: app has not completed verification"

**Symptom:** Google blocks the OAuth flow for unverified apps.

**Solution:**
1. For development: Add test users in Google Console under "OAuth consent screen"
2. For production: Submit app for verification

### "redirect_uri_mismatch" error

**Symptom:** `Error 400: redirect_uri_mismatch`

**Solution:**
1. Ensure the `redirectUri` in your API request matches exactly what's configured in Google Console
2. Add all variants to `allowed_redirect_uris` in your config:
   ```yaml
   sylius_headless_oauth:
       security:
           allowed_redirect_uris:
               - 'https://myapp.com/oauth/callback'
               - 'https://www.myapp.com/oauth/callback'
   ```

---

## Apple Sign In Issues

### "invalid_client" error

**Symptom:** Apple returns `invalid_client`.

**Causes & Solutions:**

1. **Private key issues**
   - Ensure the `.p8` key file is readable:
     ```bash
     chmod 600 /path/to/AuthKey_XXXXXXXXXX.p8
     ```
   - Verify the key path in your config:
     ```yaml
     apple:
         private_key_path: '%kernel.project_dir%/config/secrets/AuthKey.p8'
     ```

2. **Key ID mismatch**
   - The `key_id` must match the Key ID shown in Apple Developer portal

3. **Team ID incorrect**
   - Find your Team ID in [Apple Developer Account](https://developer.apple.com/account) (top right)

4. **Client ID (Service ID) issues**
   - For web, use the Service ID, not the App ID
   - Service ID must have "Sign in with Apple" capability enabled

### JWT signature verification failed

**Symptom:** `Apple JWT verification failed`

**Solution:**
1. Ensure `verify_apple_jwt: true` in production
2. For development/testing, you can temporarily disable:
   ```yaml
   sylius_headless_oauth:
       security:
           verify_apple_jwt: false  # Only for debugging!
   ```

3. Check system time is synchronized (JWT validation is time-sensitive)

### "invalid_grant" error

**Symptom:** Apple returns `invalid_grant` when exchanging code.

**Causes:**
- Authorization code already used (codes are single-use)
- Code has expired (codes expire after 5 minutes)
- Redirect URI mismatch

**Solution:**
- Request a new authorization code
- Ensure your frontend exchanges the code immediately

---

## Facebook Login Issues

### "Invalid OAuth access token"

**Symptom:** Facebook rejects the access token.

**Solution:**
1. Verify App ID and App Secret in [Facebook Developers](https://developers.facebook.com/apps/)
2. Ensure the app is in "Live" mode (not just Development)
3. Check that "Facebook Login" product is added to your app

### Missing email in response

**Symptom:** User authenticated but email is null.

**Causes:**
- User didn't grant email permission
- Facebook account has no verified email

**Solution:**
1. Request email permission in your OAuth scope:
   ```javascript
   FB.login(callback, {scope: 'email'});
   ```
2. Handle users without email gracefully in your application

### "App Not Set Up" error

**Symptom:** Users see "App Not Set Up: This app is still in development mode"

**Solution:**
1. Go to Facebook App Dashboard
2. Toggle "App Mode" from "Development" to "Live"
3. Complete any required verification steps

---

## OIDC Provider Issues

### Discovery endpoint not found

**Symptom:** `Failed to fetch OIDC discovery document`

**Solution:**
1. Verify the issuer URL is correct and accessible:
   ```bash
   curl https://your-issuer/.well-known/openid-configuration
   ```

2. Ensure the URL doesn't have a trailing slash if not expected:
   ```yaml
   # Correct
   issuer_url: 'https://keycloak.example.com/realms/myrealm'

   # Incorrect
   issuer_url: 'https://keycloak.example.com/realms/myrealm/'
   ```

### Token validation failed

**Symptom:** `Invalid ID token` or `JWT verification failed`

**Causes & Solutions:**

1. **Clock skew**
   - Ensure server time is synchronized with NTP
   - Some providers allow configuring clock tolerance

2. **Wrong audience**
   - The `client_id` must match the `aud` claim in the token

3. **Issuer mismatch**
   - The `issuer_url` must exactly match the `iss` claim

### Keycloak-specific issues

**"Client not found" error:**
- Ensure the client exists in the correct realm
- Client must have "Standard Flow" enabled

**"Invalid redirect URI" error:**
- Add your redirect URI to "Valid Redirect URIs" in Keycloak client settings
- Use wildcards carefully: `https://myapp.com/*`

---

## JWT Token Issues

### "Invalid JWT Token" on authenticated requests

**Symptom:** Requests with JWT token fail with 401.

**Solution:**
1. Check token hasn't expired:
   ```bash
   # Decode JWT payload (middle part)
   echo "YOUR_TOKEN" | cut -d'.' -f2 | base64 -d 2>/dev/null | jq .
   ```

2. Verify JWT configuration in `lexik_jwt_authentication.yaml`:
   ```yaml
   lexik_jwt_authentication:
       secret_key: '%kernel.project_dir%/config/jwt/private.pem'
       public_key: '%kernel.project_dir%/config/jwt/public.pem'
       pass_phrase: '%env(JWT_PASSPHRASE)%'
   ```

3. Regenerate JWT keys if corrupted:
   ```bash
   bin/console lexik:jwt:generate-keypair --overwrite
   ```

### Token not accepted by API

**Symptom:** Token works initially but fails on subsequent requests.

**Solution:**
1. Check `token_ttl` setting - default might be too short
2. Implement token refresh using the `/api/v2/auth/oauth/refresh` endpoint

---

## Database Issues

### Missing OAuth columns

**Symptom:** `Unknown column 'google_id' in 'field list'`

**Solution:**
1. Ensure Customer entity uses the trait:
   ```php
   use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;

   class Customer extends BaseCustomer implements OAuthIdentityInterface
   {
       use OAuthIdentityTrait;
   }
   ```

2. Generate and run migration:
   ```bash
   bin/console doctrine:migrations:diff
   bin/console doctrine:migrations:migrate
   ```

### Duplicate entry error

**Symptom:** `Duplicate entry 'xxx' for key 'google_id'`

**Cause:** Same OAuth ID trying to link to different customers.

**Solution:**
- This is expected behavior - each OAuth ID can only link to one customer
- If a user needs to change their linked account, unlink the old one first

---

## API Response Errors

### 400 Bad Request

**Common causes:**
- Missing required fields (`code`, `redirectUri`)
- Invalid redirect URI format

**Debug:**
```bash
curl -X POST https://your-site.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{"code": "xxx", "redirectUri": "https://your-frontend.com/callback"}'
```

### 401 Unauthorized

**"Redirect URI not allowed":**
- Add the URI to `allowed_redirect_uris` in config

**"Provider not supported":**
- Enable the provider in configuration
- Check provider name matches exactly (case-sensitive)

### 500 Internal Server Error

**Debug steps:**
1. Check Symfony logs:
   ```bash
   tail -f var/log/dev.log
   ```

2. Enable detailed errors in dev:
   ```yaml
   # config/packages/dev/framework.yaml
   framework:
       error_handler:
           throw_at: 0
   ```

---

## Security Issues

### CORS errors

**Symptom:** Browser blocks requests with CORS errors.

**Solution:**
Configure CORS in `nelmio_cors.yaml`:
```yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']
        allow_methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']
        allow_headers: ['Content-Type', 'Authorization']
        max_age: 3600
    paths:
        '^/api/':
            allow_origin: ['*']  # Restrict in production!
```

### State parameter validation

**Symptom:** Potential CSRF vulnerabilities.

**Solution:**
Always use the state parameter:

```javascript
// Frontend: Generate and store state before OAuth redirect
const state = crypto.randomUUID();
sessionStorage.setItem('oauth_state', state);

// Include state in OAuth URL
const authUrl = `https://accounts.google.com/o/oauth2/v2/auth?...&state=${state}`;

// After callback: Verify state matches
const returnedState = new URLSearchParams(window.location.search).get('state');
if (returnedState !== sessionStorage.getItem('oauth_state')) {
    throw new Error('State mismatch - possible CSRF attack');
}
```

---

## Debugging Tips

### Enable OAuth security logging

The bundle logs security events. Check logs at:
```bash
tail -f var/log/dev.log | grep oauth
```

### Check provider health

Run the health check command:
```bash
bin/console sylius:oauth:check-providers
```

### Test provider configuration

1. Check environment variables:
   ```bash
   bin/console debug:container --env-vars | grep -E "(GOOGLE|APPLE|FACEBOOK)"
   ```

2. Verify service configuration:
   ```bash
   bin/console debug:container GoogleProvider
   ```

### Enable verbose error messages

In development, set:
```yaml
# config/packages/dev/sylius_headless_oauth.yaml
sylius_headless_oauth:
    debug: true  # If supported
```

### Inspect raw OAuth responses

Temporarily log the raw provider response:
```php
// In your provider class (for debugging only)
$response = $this->httpClient->post($tokenUrl, [...]);
dump($response->getBody()->getContents());
```

### Common curl commands for testing

**Test Google token exchange:**
```bash
curl -X POST https://oauth2.googleapis.com/token \
  -d "code=YOUR_CODE" \
  -d "client_id=YOUR_CLIENT_ID" \
  -d "client_secret=YOUR_CLIENT_SECRET" \
  -d "redirect_uri=YOUR_REDIRECT_URI" \
  -d "grant_type=authorization_code"
```

**Test your API endpoint:**
```bash
curl -X POST https://your-site.com/api/v2/auth/oauth/google \
  -H "Content-Type: application/json" \
  -d '{"code": "AUTH_CODE", "redirectUri": "https://frontend.com/callback"}' \
  -v
```

---

## Still Having Issues?

1. **Check the logs** - Most issues are logged with helpful context
2. **Run health check** - `bin/console sylius:oauth:check-providers`
3. **Verify configuration** - `bin/console sylius:oauth:install` validates setup
4. **Test in isolation** - Try the provider's OAuth flow directly with curl
5. **Open an issue** - [GitHub Issues](https://github.com/marac/sylius-headless-oauth-bundle/issues)

When reporting issues, please include:
- Symfony and PHP versions
- Bundle version
- Relevant configuration (redact secrets!)
- Full error message and stack trace
- Steps to reproduce
