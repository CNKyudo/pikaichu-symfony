<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StaffRoleCode;
use App\Enum\TaikaiState;
use App\Repository\StaffRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un membre du staff d'un taikai (organisateur, juge, enregistreur…).
 */
#[ORM\Entity(repositoryClass: StaffRepository::class)]
#[ORM\Table(name: 'staffs')]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\Loggable(logEntryClass: LogEntry::class)]
class Staff implements \Stringable
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taikai::class, inversedBy: 'staffs')]
    #[ORM\JoinColumn(name: 'taikai_id', nullable: false, onDelete: 'CASCADE')]
    private ?Taikai $taikai = null;

    #[ORM\ManyToOne(targetEntity: StaffRole::class)]
    #[ORM\JoinColumn(name: 'role_id', nullable: false)]
    #[Assert\NotNull]
    private ?StaffRole $role = null;

    #[ORM\ManyToOne(targetEntity: ParticipatingDojo::class, inversedBy: 'staffs')]
    #[ORM\JoinColumn(name: 'participating_dojo_id', nullable: true, onDelete: 'CASCADE')]
    private ?ParticipatingDojo $participatingDojo = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'staffs')]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    #[Gedmo\Versioned]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\NotBlank]
    #[Gedmo\Versioned]
    private ?string $lastname = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaikai(): ?Taikai
    {
        return $this->taikai;
    }

    public function setTaikai(Taikai $taikai): static
    {
        $this->taikai = $taikai;

        return $this;
    }

    public function getRole(): ?StaffRole
    {
        return $this->role;
    }

    public function setRole(StaffRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getParticipatingDojo(): ?ParticipatingDojo
    {
        return $this->participatingDojo;
    }

    public function setParticipatingDojo(?ParticipatingDojo $participatingDojo): static
    {
        $this->participatingDojo = $participatingDojo;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Rattacher un compte recopie son identité, comme le `before_validation` du modèle Rails.
     */
    public function setUser(?User $user): static
    {
        $this->user = $user;
        if (null !== $user) {
            $this->firstname = $user->getFirstname();
            $this->lastname = $user->getLastname();
        }

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

    public function getDisplayName(): string
    {
        return trim($this->firstname.' '.$this->lastname);
    }

    /**
     * Certains rôles exigent un compte utilisateur ou un club hôte.
     * Reprend les validations conditionnelles du modèle Rails.
     */
    #[Assert\Callback]
    public function validateRoleRequirements(ExecutionContextInterface $context): void
    {
        $code = $this->role?->getCode();
        if (null === $code) {
            return;
        }

        if ($code->requiresUser() && null === $this->user) {
            $context->buildViolation('staff.user.required_for_role')
                ->atPath('user')
                ->addViolation();
        }

        if ($code->requiresParticipatingDojo() && null === $this->participatingDojo) {
            $context->buildViolation('staff.participating_dojo.required_for_role')
                ->atPath('participatingDojo')
                ->addViolation();
        }
    }

    /**
     * Le taikai doit conserver au moins un administrateur : on ne peut pas
     * rétrograder le dernier `taikai_admin` restant. Comme côté Rails, la règle
     * ne s'applique qu'aux staffs déjà enregistrés — un nouveau membre peut
     * prendre n'importe quel rôle sans y toucher.
     */
    #[Assert\Callback]
    public function validateAtLeastOneAdminRemains(ExecutionContextInterface $context): void
    {
        if (null === $this->id || null === $this->taikai || StaffRoleCode::TaikaiAdmin === $this->role?->getCode()) {
            return;
        }

        foreach ($this->taikai->getStaffs() as $other) {
            if ($other !== $this && StaffRoleCode::TaikaiAdmin === $other->getRole()?->getCode()) {
                return;
            }
        }

        $context->buildViolation('staff.at_least_one_admin')
            ->atPath('role')
            ->addViolation();
    }

    /**
     * Un taikai à l'étape « Terminé » est figé, comme le fait `ValidateChangeBasedOnState`
     * (`no_change_if_taikai_is_done`) côté Rails. Contrairement à Rails on ne
     * distingue pas les modifications qui ne changent en fait rien : resoumettre
     * un formulaire identique une fois le taikai clos reste refusé.
     */
    #[Assert\Callback]
    public function validateTaikaiIsNotDone(ExecutionContextInterface $context): void
    {
        if (null !== $this->id && ($this->taikai?->isState(TaikaiState::Done) ?? false)) {
            $context->buildViolation('taikai.no_change_if_taikai_is_done')->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->getDisplayName();
    }
}
