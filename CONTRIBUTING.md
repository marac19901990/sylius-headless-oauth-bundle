# Contributing to Sylius Headless OAuth Bundle

Thank you for your interest in contributing to this project! This document provides guidelines and instructions for contributing.

## Code of Conduct

Please be respectful and constructive in all interactions. We welcome contributors of all experience levels.

## How to Report Bugs

1. **Search existing issues** - Check if the bug has already been reported in [GitHub Issues](https://github.com/marac19901990/sylius-headless-oauth-bundle/issues)
2. **Create a new issue** - If not found, open a new issue with:
   - Clear, descriptive title
   - Steps to reproduce the bug
   - Expected behavior vs actual behavior
   - PHP version, Symfony version, and Sylius version
   - Relevant configuration (redact sensitive credentials)
   - Error messages and stack traces

## How to Request Features

1. Open a [GitHub Issue](https://github.com/marac19901990/sylius-headless-oauth-bundle/issues) with the "feature request" label
2. Describe the use case and why it would benefit users
3. If possible, outline a proposed implementation approach

## How to Submit Pull Requests

### Getting Started

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/sylius-headless-oauth-bundle.git
   cd sylius-headless-oauth-bundle
   ```
3. Install dependencies:
   ```bash
   composer install
   ```
4. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature-name
   ```

### Development Workflow

1. Make your changes
2. Ensure code style compliance:
   ```bash
   composer fix    # Auto-fix code style issues
   composer lint   # Check for remaining issues
   ```
3. Run static analysis:
   ```bash
   composer analyse
   ```
4. Run tests:
   ```bash
   composer test
   ```
5. Or run all checks at once:
   ```bash
   composer check
   ```

### Submitting Your PR

1. Push your branch to your fork
2. Open a Pull Request against the `master` branch
3. Fill out the PR template with:
   - Description of changes
   - Related issue number (if applicable)
   - Testing performed
4. Wait for review and address any feedback

## Code Style Requirements

This project uses **PHP-CS-Fixer** to enforce consistent code style.

### Commands

```bash
# Check code style (dry-run)
composer lint

# Auto-fix code style issues
composer fix
```

### Style Guidelines

- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`
- Use type declarations for parameters, return types, and properties
- Keep methods focused and reasonably sized
- Use meaningful variable and method names

## Testing Requirements

All contributions must include appropriate tests.

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/Unit/Provider/GoogleProviderTest.php

# Run with coverage (requires Xdebug or PCOV)
vendor/bin/phpunit --coverage-html coverage/
```

### Test Guidelines

- Write unit tests for new functionality
- Write functional tests for API endpoints
- Aim for meaningful coverage, not just high percentages
- Use descriptive test method names that explain the scenario
- Mock external HTTP calls using `MockHttpClientFactory`

## Static Analysis

This project uses **PHPStan** at level 8 (strictest).

```bash
composer analyse
```

All code must pass static analysis without errors.

## Commit Message Format

Use clear, descriptive commit messages following this format:

```
<type>: <short description>

[optional body with more details]

[optional footer with breaking changes or issue references]
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, no logic change)
- `refactor`: Code refactoring (no feature or fix)
- `test`: Adding or updating tests
- `chore`: Maintenance tasks (dependencies, CI, etc.)

### Examples

```
feat: Add Microsoft/Azure AD OAuth provider

Implements OAuth 2.0 flow for Microsoft accounts with support for
both personal and organizational accounts.

Closes #42
```

```
fix: Handle Apple refresh token rotation correctly

Apple rotates refresh tokens on each use. The previous implementation
was not storing the new refresh token, causing subsequent refresh
attempts to fail.
```

```
docs: Add troubleshooting guide for common Apple Sign-In issues
```

## Development Setup

### Prerequisites

- PHP 8.2 or higher
- Composer 2.x

### Environment Variables for Testing

For running functional tests that require OAuth credentials, copy the example environment file:

```bash
cp .env.test .env.test.local
```

Edit `.env.test.local` with test credentials (or use mock values for unit tests).

## Questions?

If you have questions about contributing, feel free to:
- Open a [GitHub Discussion](https://github.com/marac19901990/sylius-headless-oauth-bundle/discussions)
- Comment on a related issue

Thank you for contributing!
