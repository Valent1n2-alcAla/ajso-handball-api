<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\ParticipationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['participation:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['joueur.equipe' => 'exact', 'joueur' => 'exact', 'rencontre' => 'exact'])]
#[ORM\UniqueConstraint(name: 'participation_unique', columns: ['rencontre_id', 'joueur_id'])]
#[ORM\Entity(repositoryClass: ParticipationRepository::class)]
class Participation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['participation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['participation:read'])]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['participation:read'])]
    private ?Joueur $joueur = null;

    /** Numéro porté sur ce match */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    #[Groups(['participation:read'])]
    private ?int $numero = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['participation:read'])]
    private int $buts = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRencontre(): ?Rencontre
    {
        return $this->rencontre;
    }

    public function setRencontre(?Rencontre $rencontre): static
    {
        $this->rencontre = $rencontre;

        return $this;
    }

    public function getJoueur(): ?Joueur
    {
        return $this->joueur;
    }

    public function setJoueur(?Joueur $joueur): static
    {
        $this->joueur = $joueur;

        return $this;
    }

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(?int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getButs(): int
    {
        return $this->buts;
    }

    public function setButs(int $buts): static
    {
        $this->buts = $buts;

        return $this;
    }
}
