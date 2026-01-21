# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

#### Separate OAuthIdentity Entity
- New `OAuthIdentity` entity with its own `sylius_oauth_identity` table
- `OAuthIdentityRepositoryInterface` with methods for finding identities by provider/customer
- Automatic Doctrine mapping via XML configuration
- `connected_at` timestamp for each OAuth connection
- Support for unlimited providers per customer (no more field-per-provider limitation)

#### Generic OpenID Connect Provider
- Generic OIDC provider supporting any OIDC-compliant identity provider (Keycloak, Auth0, Okta, Azure AD, etc.)
- Auto-discovery of endpoints via `.well-known/openid-configuration`
- JWT id_token verification against provider's JWKS endpoint
- Userinfo endpoint fallback for providers that don't include claims in id_token
- Token refresh support
- Configuration via YAML with support for multiple named OIDC providers
- `OidcDiscoveryService` with caching (1-hour TTL) for OIDC configuration

#### Admin UI Integration
- Twig extension (`oauth_has_providers`, `oauth_connected_providers`, etc.) for displaying OAuth provider info in templates
- Customer show page OAuth block showing connected providers with icons and IDs
- Customer grid column displaying OAuth provider badges (Google, Apple, Facebook, OIDC icons)
- CSS styles for OAuth badges and provider icons
- Translation support (English) for admin UI labels

### Changed
- **BREAKING:** OAuth identities now stored in separate `sylius_oauth_identity` table instead of Customer entity columns
- Users no longer need to modify their Customer entity - the bundle handles everything automatically
- `UserResolver` now uses `OAuthIdentityRepositoryInterface` instead of `ProviderFieldMapper`
- `ListOAuthConnectionsAction` now returns actual `connectedAt` timestamps
- `UnlinkOAuthConnectionAction` now removes `OAuthIdentity` records instead of nullifying fields
- `OAuthExtension` (Twig) now uses repository to fetch connected providers
- Install command no longer shows entity modification instructions

### Removed
- `OAuthIdentityTrait` - no longer needed as identities are stored in separate table
- `OAuthIdentityInterface` (old version with getGoogleId/setGoogleId methods)
- `ProviderFieldMapper` and `ProviderFieldMapperInterface` - replaced by repository pattern

## [1.0.0] - 2026-01-20

### Added

#### OAuth Providers
- **Google Sign-In** - Full OAuth 2.0 implementation with token exchange and user info retrieval
- **Apple Sign-In** - Complete implementation with JWT client secret generation (ES256) and id_token verification against Apple's JWKS endpoint
- **Facebook Sign-In** - Full OAuth implementation with Graph API v19.0 integration
- Extensible provider architecture via `OAuthProviderInterface` for custom provider implementations
- Token refresh support for all providers via `RefreshableOAuthProviderInterface`
- Provider configuration inspection via `ConfigurableOAuthProviderInterface`

#### API Endpoints
- `POST /api/v2/auth/oauth/{provider}` - Authentication endpoint accepting authorization code and redirect URI
- `POST /api/v2/auth/oauth/{provider}/refresh` - Token refresh endpoint for obtaining new JWT tokens
- State parameter support for CSRF protection
- Integration with LexikJWTAuthenticationBundle for JWT token generation

#### User Management
- Automatic user resolution: search by provider ID, fallback to email, or create new user
- Automatic linking of OAuth providers to existing Sylius customers
- Auto-creation of ShopUser with verified email and random password for OAuth users
- `OAuthIdentityInterface` and `OAuthIdentityTrait` for adding OAuth provider IDs to Customer entity

#### Security Features
- JWT signature verification for Apple id_tokens against Apple's JWKS endpoint
- JWKS caching with 24-hour TTL for performance
- Redirect URI validation with configurable whitelist to prevent open redirect attacks
- Comprehensive security logging via dedicated `oauth_security` Monolog channel
- Email masking in logs for privacy
- Credential validation on provider initialization

#### Events
- `OAuthPreUserCreateEvent` - Hook before creating new users for data modification or validation
- `OAuthPostAuthenticationEvent` - Hook after successful authentication for cart merging, welcome emails, etc.
- `OAuthProviderLinkedEvent` - Hook when OAuth provider is linked to existing account

#### Console Commands
- `sylius:oauth:check-providers` - Health check command displaying provider configuration status

#### Configuration
- YAML-based configuration with environment variable support
- Per-provider enable/disable toggles
- Configurable security settings (redirect URI whitelist, Apple JWT verification)
- Support for Google, Apple, and Facebook credentials via environment variables

#### Developer Experience
- PHPStan level 8 static analysis
- PHP-CS-Fixer code style enforcement
- Comprehensive test suite with unit and functional tests
- Mock HTTP client factory for testing OAuth flows
- Test JWT keys for Apple JWT verification testing

### Security
- All OAuth endpoints validate redirect URIs against configured whitelist
- Apple JWT verification enabled by default to prevent token tampering
- Failed authentication attempts are logged with context for security auditing
- Provider credentials are validated to prevent accidental use of placeholder values

[Unreleased]: https://github.com/marac19901990/sylius-headless-oauth-bundle/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/marac19901990/sylius-headless-oauth-bundle/releases/tag/v1.0.0
