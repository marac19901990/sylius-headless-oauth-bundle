<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Entity;

use DateTimeInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Resource\Model\ResourceInterface;

/**
 * Interface for OAuth identity entities that link OAuth providers to customers.
 */
interface OAuthIdentityInterface extends ResourceInterface
{
    public function getProvider(): string;

    public function setProvider(string $provider): void;

    public function getIdentifier(): string;

    public function setIdentifier(string $identifier): void;

    public function getConnectedAt(): ?DateTimeInterface;

    public function setConnectedAt(?DateTimeInterface $connectedAt): void;

    public function getCustomer(): CustomerInterface;

    public function setCustomer(CustomerInterface $customer): void;
}
