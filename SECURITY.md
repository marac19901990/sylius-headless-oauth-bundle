# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take security vulnerabilities seriously. If you discover a security issue, please report it responsibly.

### How to Report

**Please do NOT report security vulnerabilities through public GitHub issues.**

Instead, please report them via email to:

**marac19901990@gmail.com**

Include the following information in your report:

- Type of vulnerability (e.g., authentication bypass, injection, etc.)
- Full path to the affected source file(s)
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact assessment (what an attacker could achieve)

### What to Expect

- **Acknowledgment**: We will acknowledge receipt of your report within 48 hours.
- **Assessment**: We will investigate and assess the vulnerability within 7 days.
- **Updates**: We will keep you informed of our progress.
- **Resolution**: We aim to release a fix within 30 days for critical issues.
- **Credit**: We will credit you in the release notes (unless you prefer to remain anonymous).

### Security Best Practices

When using this bundle in production:

1. **Configure redirect URI allowlist** - Never leave `allowed_redirect_uris` empty in production
2. **Keep JWT verification enabled** - Do not disable `verify_apple_jwt`
3. **Use HTTPS everywhere** - All OAuth flows require HTTPS
4. **Implement state parameter** - Protect against CSRF attacks
5. **Secure your credentials** - Use environment variables, never commit secrets
6. **Monitor security logs** - Review the `oauth_security` Monolog channel
7. **Keep dependencies updated** - Run `composer audit` regularly

### Known Security Considerations

- **Apple private keys**: Store `.p8` files outside the web root with restricted permissions
- **Refresh tokens**: These are long-lived credentials; treat them with the same care as passwords
- **Email privacy**: Apple's "Hide My Email" provides relay addresses; these are still valid emails

## Security Features

This bundle includes several built-in security features:

- JWT signature verification for Apple id_tokens
- Redirect URI allowlist validation
- OAuth state parameter support for CSRF protection
- Comprehensive security logging
- Credential validation on provider initialization

See the [Security section](README.md#security) in the README for configuration details.
