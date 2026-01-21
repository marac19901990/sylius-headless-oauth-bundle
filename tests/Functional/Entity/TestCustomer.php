<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Component\Review\Model\ReviewInterface;
use Sylius\Component\User\Model\UserInterface;

/**
 * Minimal test customer entity for functional testing.
 *
 * OAuth identities are now stored in a separate sylius_oauth_identity table,
 * so this entity no longer needs any OAuth-related fields.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_customer')]
class TestCustomer implements CustomerInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailCanonical = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 1)]
    private string $gender = CustomerInterface::UNKNOWN_GENDER;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $birthday = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column]
    private bool $subscribedToNewsletter = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\OneToOne(targetEntity: TestShopUser::class, mappedBy: 'customer', cascade: ['persist', 'remove'])]
    private ?TestShopUser $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email ?? '';
    }

    public function getEmailCanonical(): ?string
    {
        return $this->emailCanonical;
    }

    public function setEmailCanonical(?string $emailCanonical): void
    {
        $this->emailCanonical = $emailCanonical;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getBirthday(): ?DateTimeInterface
    {
        return $this->birthday;
    }

    public function setBirthday(?DateTimeInterface $birthday): void
    {
        $this->birthday = $birthday;
    }

    public function getGender(): string
    {
        return $this->gender;
    }

    public function setGender(string $gender): void
    {
        $this->gender = $gender;
    }

    public function isMale(): bool
    {
        return $this->gender === CustomerInterface::MALE_GENDER;
    }

    public function isFemale(): bool
    {
        return $this->gender === CustomerInterface::FEMALE_GENDER;
    }

    public function getGroup(): ?CustomerGroupInterface
    {
        return null;
    }

    public function setGroup(?CustomerGroupInterface $group): void
    {
        // Not needed for tests
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }

    public function isSubscribedToNewsletter(): bool
    {
        return $this->subscribedToNewsletter;
    }

    public function setSubscribedToNewsletter(bool $subscribedToNewsletter): void
    {
        $this->subscribedToNewsletter = $subscribedToNewsletter;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    // CustomerInterface (Core) methods

    public function getOrders(): Collection
    {
        return new ArrayCollection();
    }

    public function getDefaultAddress(): ?AddressInterface
    {
        return null;
    }

    public function setDefaultAddress(?AddressInterface $defaultAddress): void
    {
        // Not needed for tests
    }

    public function addAddress(AddressInterface $address): void
    {
        // Not needed for tests
    }

    public function removeAddress(AddressInterface $address): void
    {
        // Not needed for tests
    }

    public function hasAddress(AddressInterface $address): bool
    {
        return false;
    }

    public function getAddresses(): Collection
    {
        return new ArrayCollection();
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(?UserInterface $user): void
    {
        if ($user instanceof TestShopUser) {
            $this->user = $user;
        }
    }

    // ProductReviewerInterface methods

    /**
     * @return Collection<array-key, ReviewInterface>
     */
    public function getReviews(): Collection
    {
        return new ArrayCollection();
    }

    public function addReview(ReviewInterface $review): void
    {
        // Not needed for tests
    }

    public function removeReview(ReviewInterface $review): void
    {
        // Not needed for tests
    }
}
