<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Entity;

use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthIdentityTrait::class)]
final class OAuthIdentityTraitTest extends TestCase
{
    private OAuthIdentityInterface $entity;

    protected function setUp(): void
    {
        $this->entity = new class implements OAuthIdentityInterface {
            use OAuthIdentityTrait;
        };
    }

    #[Test]
    public function googleIdIsNullByDefault(): void
    {
        self::assertNull($this->entity->getGoogleId());
    }

    #[Test]
    public function canSetAndGetGoogleId(): void
    {
        $this->entity->setGoogleId('google-123');

        self::assertSame('google-123', $this->entity->getGoogleId());
    }

    #[Test]
    public function canSetGoogleIdToNull(): void
    {
        $this->entity->setGoogleId('google-123');
        $this->entity->setGoogleId(null);

        self::assertNull($this->entity->getGoogleId());
    }

    #[Test]
    public function appleIdIsNullByDefault(): void
    {
        self::assertNull($this->entity->getAppleId());
    }

    #[Test]
    public function canSetAndGetAppleId(): void
    {
        $this->entity->setAppleId('apple-456');

        self::assertSame('apple-456', $this->entity->getAppleId());
    }

    #[Test]
    public function canSetAppleIdToNull(): void
    {
        $this->entity->setAppleId('apple-456');
        $this->entity->setAppleId(null);

        self::assertNull($this->entity->getAppleId());
    }

    #[Test]
    public function canSetBothProviderIds(): void
    {
        $this->entity->setGoogleId('google-123');
        $this->entity->setAppleId('apple-456');

        self::assertSame('google-123', $this->entity->getGoogleId());
        self::assertSame('apple-456', $this->entity->getAppleId());
    }

    #[Test]
    public function providerIdsAreIndependent(): void
    {
        $this->entity->setGoogleId('google-123');
        $this->entity->setAppleId(null);

        self::assertSame('google-123', $this->entity->getGoogleId());
        self::assertNull($this->entity->getAppleId());
    }
}
