<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Security;

use Marac\SyliusHeadlessOAuthBundle\Exception\OAuthException;
use Marac\SyliusHeadlessOAuthBundle\Security\RedirectUriValidator;
use PHPUnit\Framework\TestCase;

final class RedirectUriValidatorTest extends TestCase
{
    public function testValidatePassesWhenNoAllowlistConfigured(): void
    {
        $validator = new RedirectUriValidator([]);

        // Should not throw when allowlist is empty
        $validator->validate('https://any-domain.com/callback');

        $this->assertTrue(true); // If we got here, validation passed
    }

    public function testValidatePassesForExactMatch(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com/oauth/callback',
            'https://staging.example.com/oauth/callback',
        ]);

        $validator->validate('https://example.com/oauth/callback');

        $this->assertTrue(true);
    }

    public function testValidatePassesForExactMatchWithTrailingSlash(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com/callback',
        ]);

        $validator->validate('https://example.com/callback/');

        $this->assertTrue(true);
    }

    public function testValidatePassesForPrefixMatch(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $validator->validate('https://example.com/oauth/callback');

        $this->assertTrue(true);
    }

    public function testValidatePassesForSecondAllowedUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
            'https://staging.example.com',
        ]);

        $validator->validate('https://staging.example.com/callback');

        $this->assertTrue(true);
    }

    public function testValidateThrowsForUnauthorizedUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Redirect URI "https://evil.com/callback" is not in the allowed list');

        $validator->validate('https://evil.com/callback');
    }

    public function testValidateThrowsForSubdomainAttack(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->expectException(OAuthException::class);

        // This should NOT match because it's a different subdomain
        $validator->validate('https://evil.example.com/callback');
    }

    public function testValidateThrowsForSuffixAttack(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->expectException(OAuthException::class);

        // This should NOT match because it's a different domain
        $validator->validate('https://notexample.com/callback');
    }

    public function testIsValidReturnsTrueForValidUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->assertTrue($validator->isValid('https://example.com/callback'));
    }

    public function testIsValidReturnsFalseForInvalidUri(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->assertFalse($validator->isValid('https://evil.com/callback'));
    }

    public function testGetAllowedUrisReturnsConfiguredUris(): void
    {
        $uris = [
            'https://example.com',
            'https://staging.example.com',
        ];

        $validator = new RedirectUriValidator($uris);

        $this->assertSame($uris, $validator->getAllowedUris());
    }

    public function testIsEnabledReturnsTrueWhenUrisConfigured(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com',
        ]);

        $this->assertTrue($validator->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenNoUrisConfigured(): void
    {
        $validator = new RedirectUriValidator([]);

        $this->assertFalse($validator->isEnabled());
    }

    public function testValidateHandlesNormalizedTrailingSlash(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com/',
        ]);

        // Both with and without trailing slash should work
        $validator->validate('https://example.com');
        $validator->validate('https://example.com/');
        $validator->validate('https://example.com/callback');

        $this->assertTrue(true);
    }

    public function testValidatePreventsPathTraversalInPrefix(): void
    {
        $validator = new RedirectUriValidator([
            'https://example.com/app',
        ]);

        $this->expectException(OAuthException::class);

        // This should NOT match because /app-evil is not /app/...
        $validator->validate('https://example.com/app-evil/callback');
    }
}
