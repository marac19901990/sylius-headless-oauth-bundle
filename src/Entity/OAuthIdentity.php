<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Entity;

use DateTimeInterface;
use Sylius\Component\Core\Model\CustomerInterface;

/**
 * Entity that stores OAuth identity information linking providers to customers.
 *
 * Each OAuth identity represents a connection between a customer and an OAuth provider.
 * A customer can have multiple OAuth identities (one per provider).
 */
class OAuthIdentity implements OAuthIdentityInterface
{
    private ?int $id = null;

    private string $provider = '';

    private string $identifier = '';

    private ?DateTimeInterface $connectedAt = null;

    private CustomerInterface $customer;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getConnectedAt(): ?DateTimeInterface
    {
        return $this->connectedAt;
    }

    public function setConnectedAt(?DateTimeInterface $connectedAt): void
    {
        $this->connectedAt = $connectedAt;
    }

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(CustomerInterface $customer): void
    {
        $this->customer = $customer;
    }
}
