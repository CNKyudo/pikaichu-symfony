<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StaffRoleCode;
use App\Repository\StaffRoleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rôle du staff. Les libellés sont traduits en base (colonnes JSON), ce qui reprend
 * le comportement de Mobility `translates :label, :description` côté Rails.
 */
#[ORM\Entity(repositoryClass: StaffRoleRepository::class)]
#[ORM\Table(name: 'staff_roles')]
#[ORM\UniqueConstraint(name: 'by_staff_roles_code', columns: ['code'])]
#[ORM\HasLifecycleCallbacks]
class StaffRole implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true, unique: true, enumType: StaffRoleCode::class)]
    private ?StaffRoleCode $code = null;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $label = [];

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $description = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?StaffRoleCode
    {
        return $this->code;
    }

    public function setCode(StaffRoleCode $code): static
    {
        $this->code = $code;

        return $this;
    }

    /** @return array<string, string> */
    public function getLabel(): array
    {
        return $this->label;
    }

    /** @param array<string, string> $label */
    public function setLabel(array $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @return array<string, string> */
    public function getDescription(): array
    {
        return $this->description;
    }

    /** @param array<string, string> $description */
    public function setDescription(array $description): static
    {
        $this->description = $description;

        return $this;
    }

    /** Libellé dans la locale demandée, avec repli sur le français puis sur le code. */
    public function translatedLabel(string $locale): string
    {
        return $this->label[$locale] ?? $this->label['fr'] ?? (string) $this->code?->value;
    }

    public function translatedDescription(string $locale): string
    {
        return $this->description[$locale] ?? $this->description['fr'] ?? '';
    }

    public function is(StaffRoleCode $code): bool
    {
        return $this->code === $code;
    }

    public function __toString(): string
    {
        return $this->translatedLabel('fr');
    }
}
