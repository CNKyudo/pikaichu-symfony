<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ScoreboardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un affichage public de résultats, consultable sans authentification via sa clé
 * d'API. Sert à projeter le classement en salle.
 */
#[ORM\Entity(repositoryClass: ScoreboardRepository::class)]
#[ORM\Table(name: 'scoreboards')]
#[ORM\UniqueConstraint(name: 'index_scoreboards_on_api_key', columns: ['api_key'])]
#[ORM\HasLifecycleCallbacks]
class Scoreboard
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ParticipatingDojo::class)]
    #[ORM\JoinColumn(name: 'participating_dojo_id', nullable: true, onDelete: 'CASCADE')]
    private ?ParticipatingDojo $participatingDojo = null;

    #[ORM\Column(name: 'api_key', length: 255, nullable: true, unique: true)]
    private ?string $apiKey = null;

    /** Délai de rafraîchissement de l'affichage, en secondes. */
    #[ORM\Column(options: ['default' => 15])]
    private int $delay = 15;

    /** Nombre de participants affichés, null pour tous. */
    #[ORM\Column(name: 'nb_participants', nullable: true)]
    private ?int $nbParticipants = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }

    public function setDelay(int $delay): static
    {
        $this->delay = $delay;

        return $this;
    }

    public function getNbParticipants(): ?int
    {
        return $this->nbParticipants;
    }

    public function setNbParticipants(?int $nbParticipants): static
    {
        $this->nbParticipants = $nbParticipants;

        return $this;
    }
}
