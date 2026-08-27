<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DojoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un club de kyudo, référentiel global indépendant des taikai.
 */
#[ORM\Entity(repositoryClass: DojoRepository::class)]
#[ORM\Table(name: 'dojos')]
#[ORM\UniqueConstraint(name: 'by_shortname', columns: ['shortname'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['shortname'], message: 'dojo.shortname.already_used')]
class Dojo implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 32)]
    private ?string $shortname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'country_code', length: 255, nullable: true)]
    #[Assert\NotBlank]
    private ?string $countryCode = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShortname(): ?string
    {
        return $this->shortname;
    }

    /**
     * Normalisé à l'écriture, comme le `normalizes` de Rails : la validation
     * travaille ainsi sur la valeur définitive.
     */
    public function setShortname(?string $shortname): static
    {
        $this->shortname = null === $shortname ? null : mb_strtolower(trim($shortname));

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = null === $name ? null : trim($name);

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): static
    {
        $this->countryCode = null === $countryCode ? null : mb_strtoupper(trim($countryCode));

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->shortname;
    }
}
