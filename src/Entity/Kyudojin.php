<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\KyudojinRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un archer licencié, référentiel global alimenté par l'import Kyudo Gestion.
 */
#[ORM\Entity(repositoryClass: KyudojinRepository::class)]
#[ORM\Table(name: 'kyudojins')]
#[ORM\UniqueConstraint(name: 'by_license_id', columns: ['license_id'])]
#[ORM\Index(name: 'by_firstname_lastname', columns: ['firstname', 'lastname'])]
#[ORM\Index(name: 'by_lastname_firstname', columns: ['lastname', 'firstname'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['licenseId'], message: 'kyudojin.license_id.already_used')]
class Kyudojin implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'license_id', length: 255, nullable: true, unique: true)]
    #[Assert\NotBlank]
    private ?string $licenseId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastname = null;

    #[ORM\Column(name: 'federation_club', length: 255, nullable: true)]
    private ?string $federationClub = null;

    #[ORM\Column(name: 'federation_country_code', length: 255, nullable: true)]
    private ?string $federationCountryCode = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLicenseId(): ?string
    {
        return $this->licenseId;
    }

    public function setLicenseId(?string $licenseId): static
    {
        $this->licenseId = $licenseId;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getFederationClub(): ?string
    {
        return $this->federationClub;
    }

    public function setFederationClub(?string $federationClub): static
    {
        $this->federationClub = $federationClub;

        return $this;
    }

    public function getFederationCountryCode(): ?string
    {
        return $this->federationCountryCode;
    }

    public function setFederationCountryCode(?string $federationCountryCode): static
    {
        $this->federationCountryCode = $federationCountryCode;

        return $this;
    }

    public function getDisplayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    public function __toString(): string
    {
        return $this->getDisplayName();
    }
}
