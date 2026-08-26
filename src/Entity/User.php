<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'index_users_on_email_address', columns: ['email_address'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['emailAddress'], message: 'user.email_address.already_used')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'email_address', length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $emailAddress = null;

    #[ORM\Column(name: 'password_digest', length: 255, nullable: true)]
    private ?string $passwordDigest = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $lastname = null;

    #[ORM\Column(length: 255, options: ['default' => 'fr'])]
    #[Assert\Choice(choices: ['fr', 'en'])]
    private string $locale = 'fr';

    #[ORM\Column(options: ['default' => false])]
    private bool $admin = false;

    #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'confirmation_token', length: 255, nullable: true, unique: true)]
    private ?string $confirmationToken = null;

    #[ORM\Column(name: 'confirmation_sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmationSentAt = null;

    #[ORM\Column(name: 'unconfirmed_email', length: 255, nullable: true)]
    private ?string $unconfirmedEmail = null;

    /** @var Collection<int, Staff> */
    #[ORM\OneToMany(targetEntity: Staff::class, mappedBy: 'user')]
    private Collection $staffs;

    public function __construct()
    {
        $this->staffs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    /**
     * Reprend le `normalizes :email_address` du modèle Rails : trim + minuscules.
     */
    public function setEmailAddress(string $emailAddress): static
    {
        $this->emailAddress = mb_strtolower(trim($emailAddress));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        \assert(null !== $this->emailAddress && '' !== $this->emailAddress);

        return $this->emailAddress;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordDigest;
    }

    public function setPassword(string $passwordDigest): static
    {
        $this->passwordDigest = $passwordDigest;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    /**
     * Reprend le `normalizes :firstname, :lastname` du modèle Rails : trim + titlecase.
     */
    public function setFirstname(string $firstname): static
    {
        $this->firstname = mb_convert_case(trim($firstname), \MB_CASE_TITLE);

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = mb_convert_case(trim($lastname), \MB_CASE_TITLE);

        return $this;
    }

    public function getDisplayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return null !== $this->confirmedAt;
    }

    public function getConfirmationToken(): ?string
    {
        return $this->confirmationToken;
    }

    public function setConfirmationToken(?string $confirmationToken): static
    {
        $this->confirmationToken = $confirmationToken;

        return $this;
    }

    public function getConfirmationSentAt(): ?\DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }

    public function setConfirmationSentAt(?\DateTimeImmutable $confirmationSentAt): static
    {
        $this->confirmationSentAt = $confirmationSentAt;

        return $this;
    }

    public function getUnconfirmedEmail(): ?string
    {
        return $this->unconfirmedEmail;
    }

    public function setUnconfirmedEmail(?string $unconfirmedEmail): static
    {
        $this->unconfirmedEmail = $unconfirmedEmail;

        return $this;
    }

    /** @return Collection<int, Staff> */
    public function getStaffs(): Collection
    {
        return $this->staffs;
    }
}
