<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Entity;

interface OAuthIdentityInterface
{
    public function getGoogleId(): ?string;

    public function setGoogleId(?string $googleId): void;

    public function getAppleId(): ?string;

    public function setAppleId(?string $appleId): void;

    public function getFacebookId(): ?string;

    public function setFacebookId(?string $facebookId): void;
}
