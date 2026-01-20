<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trait to add OAuth identity fields to your Customer entity.
 *
 * Usage:
 *   use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityInterface;
 *   use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentityTrait;
 *
 *   class Customer extends BaseCustomer implements OAuthIdentityInterface
 *   {
 *       use OAuthIdentityTrait;
 *   }
 *
 * After adding the trait, run:
 *   bin/console doctrine:migrations:diff
 *   bin/console doctrine:migrations:migrate
 */
trait OAuthIdentityTrait
{
    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $googleId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $appleId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $facebookId = null;

    /**
     * Generic OIDC provider ID.
     * Used for custom OIDC providers like Keycloak, Auth0, Okta, etc.
     * If you need multiple OIDC providers, add additional fields to your Customer entity.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $oidcId = null;

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): void
    {
        $this->googleId = $googleId;
    }

    public function getAppleId(): ?string
    {
        return $this->appleId;
    }

    public function setAppleId(?string $appleId): void
    {
        $this->appleId = $appleId;
    }

    public function getFacebookId(): ?string
    {
        return $this->facebookId;
    }

    public function setFacebookId(?string $facebookId): void
    {
        $this->facebookId = $facebookId;
    }

    public function getOidcId(): ?string
    {
        return $this->oidcId;
    }

    public function setOidcId(?string $oidcId): void
    {
        $this->oidcId = $oidcId;
    }
}
