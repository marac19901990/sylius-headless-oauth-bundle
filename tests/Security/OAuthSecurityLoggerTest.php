<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Security;

use Marac\SyliusHeadlessOAuthBundle\Security\OAuthSecurityLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OAuthSecurityLoggerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private OAuthSecurityLogger $securityLogger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->securityLogger = new OAuthSecurityLogger($this->logger);
    }

    public function testLogAuthSuccessLogsWithMaskedEmail(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'OAuth authentication successful',
                $this->callback(function (array $context): bool {
                    return $context['provider'] === 'google'
                        && $context['email'] === 'u***@example.com'
                        && $context['customer_id'] === 123
                        && $context['is_new_user'] === false
                        && $context['event_type'] === 'oauth_auth_success';
                }),
            );

        $this->securityLogger->logAuthSuccess('google', 'user@example.com', 123);
    }

    public function testLogAuthSuccessWithNewUser(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'OAuth authentication successful',
                $this->callback(fn (array $context): bool => $context['is_new_user'] === true),
            );

        $this->securityLogger->logAuthSuccess('google', 'user@example.com', 123, true);
    }

    public function testLogAuthFailureLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'OAuth authentication failed',
                $this->callback(function (array $context): bool {
                    return $context['provider'] === 'apple'
                        && $context['reason'] === 'Invalid code'
                        && $context['event_type'] === 'oauth_auth_failure';
                }),
            );

        $this->securityLogger->logAuthFailure('apple', 'Invalid code');
    }

    public function testLogAuthFailureWithContext(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'OAuth authentication failed',
                $this->callback(function (array $context): bool {
                    return $context['extra_key'] === 'extra_value';
                }),
            );

        $this->securityLogger->logAuthFailure('google', 'Error', ['extra_key' => 'extra_value']);
    }

    public function testLogRefreshSuccessLogsInfo(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'OAuth token refresh successful',
                $this->callback(function (array $context): bool {
                    return $context['provider'] === 'google'
                        && $context['customer_id'] === 456
                        && $context['event_type'] === 'oauth_refresh_success';
                }),
            );

        $this->securityLogger->logRefreshSuccess('google', 456);
    }

    public function testLogRefreshFailureLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'OAuth token refresh failed',
                $this->callback(function (array $context): bool {
                    return $context['provider'] === 'apple'
                        && $context['reason'] === 'Token expired'
                        && $context['event_type'] === 'oauth_refresh_failure';
                }),
            );

        $this->securityLogger->logRefreshFailure('apple', 'Token expired');
    }

    public function testLogSuspiciousActivityLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Suspicious OAuth activity detected',
                $this->callback(function (array $context): bool {
                    return $context['type'] === 'forged_jwt'
                        && $context['event_type'] === 'oauth_suspicious_activity'
                        && $context['ip'] === '192.168.1.1';
                }),
            );

        $this->securityLogger->logSuspiciousActivity('forged_jwt', ['ip' => '192.168.1.1']);
    }

    public function testLogJwtVerificationFailureLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'JWT verification failed',
                $this->callback(function (array $context): bool {
                    return $context['provider'] === 'apple'
                        && $context['reason'] === 'Invalid signature'
                        && $context['event_type'] === 'oauth_jwt_verification_failure';
                }),
            );

        $this->securityLogger->logJwtVerificationFailure('apple', 'Invalid signature');
    }

    public function testLogRedirectUriRejectedLogsWarning(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Redirect URI rejected',
                $this->callback(function (array $context): bool {
                    return $context['redirect_host'] === 'evil.com'
                        && $context['provider'] === 'google'
                        && $context['event_type'] === 'oauth_redirect_uri_rejected';
                }),
            );

        $this->securityLogger->logRedirectUriRejected('https://evil.com/callback', 'google');
    }

    public function testEmailMaskingWithSingleCharacterLocal(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'OAuth authentication successful',
                $this->callback(fn (array $context): bool => $context['email'] === '***@example.com'),
            );

        $this->securityLogger->logAuthSuccess('google', 'a@example.com', 1);
    }

    public function testEmailMaskingWithInvalidEmail(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'OAuth authentication successful',
                $this->callback(fn (array $context): bool => $context['email'] === '***'),
            );

        $this->securityLogger->logAuthSuccess('google', 'not-an-email', 1);
    }

    public function testWorksWithNullLogger(): void
    {
        $logger = new OAuthSecurityLogger(new \Psr\Log\NullLogger());

        // This should not throw - NullLogger is used
        $logger->logAuthSuccess('google', 'test@example.com', 1);

        $this->assertTrue(true);
    }
}
