<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Validator;

use InvalidArgumentException;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialValidator::class)]
final class CredentialValidatorTest extends TestCase
{
    private CredentialValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CredentialValidator();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCredentialProvider(): iterable
    {
        yield 'empty string' => ['', 'TEST_VAR'];
        yield 'placeholder env' => ['%env(TEST_VAR)%', 'TEST_VAR'];
    }

    #[Test]
    public function validCredentialDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate(
            'valid-client-id',
            'GOOGLE_CLIENT_ID',
            'Google',
            'client ID',
        );
    }

    #[Test]
    public function emptyCredentialThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Google OAuth is enabled but GOOGLE_CLIENT_ID is not configured');

        $this->validator->validate(
            '',
            'GOOGLE_CLIENT_ID',
            'Google',
            'client ID',
        );
    }

    #[Test]
    public function placeholderCredentialThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Apple OAuth is enabled but APPLE_CLIENT_ID is not configured');
        $this->expectExceptionMessage('sylius_headless_oauth.providers.apple.enabled: false');

        $this->validator->validate(
            '%env(APPLE_CLIENT_ID)%',
            'APPLE_CLIENT_ID',
            'Apple',
            'client ID',
        );
    }

    #[Test]
    public function validateManyWithValidCredentials(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validateMany([
            ['value' => 'client-id', 'env' => 'GOOGLE_CLIENT_ID', 'name' => 'client ID'],
            ['value' => 'client-secret', 'env' => 'GOOGLE_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'Google');
    }

    #[Test]
    public function validateManyThrowsOnFirstInvalidCredential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GOOGLE_CLIENT_SECRET');

        $this->validator->validateMany([
            ['value' => 'valid-client-id', 'env' => 'GOOGLE_CLIENT_ID', 'name' => 'client ID'],
            ['value' => '', 'env' => 'GOOGLE_CLIENT_SECRET', 'name' => 'client secret'],
        ], 'Google');
    }

    #[Test]
    #[DataProvider('invalidCredentialProvider')]
    public function invalidCredentialsThrowException(string $value, string $env): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->validator->validate($value, $env, 'Test', 'test credential');
    }

    #[Test]
    public function errorMessageIncludesProviderName(): void
    {
        try {
            $this->validator->validate('', 'SOME_VAR', 'MyProvider', 'my credential');
            self::fail('Expected exception was not thrown');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('MyProvider', $e->getMessage());
            self::assertStringContainsString('SOME_VAR', $e->getMessage());
            self::assertStringContainsString('myprovider', $e->getMessage()); // lowercase in config path
        }
    }
}
