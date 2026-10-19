<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\ConvocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Convocation des joueurs à un match. Lecture publique des seules convocations publiées
 * (voir App\Doctrine\ConvocationsPublieesExtension) ; l'admin voit et modifie tout.
 * Les joueurs sont publiés avec leur nom affiché seulement (« Léa M. » pour les jeunes).
 */
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['convocation:read', 'rencontre:read']],
    denormalizationContext: ['groups' => ['convocation:write']],
    order: ['rendezVous' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['rencontre' => 'exact', 'rencontre.equipe' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['rendezVous', 'rencontre.date'])]
#[ApiFilter(BooleanFilter::class, properties: ['publiee'])]
#[ORM\Entity(repositoryClass: ConvocationRepository::class)]
class Convocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['convocation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['convocation:read', 'convocation:write'])]
    private ?Rencontre $rencontre = null;

    /** Heure locale du club, sans fuseau (AAAA-MM-JJTHH:MM) */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\\TH:i'])]
    #[Groups(['convocation:read', 'convocation:write'])]
    private ?\DateTime $rendezVous = null;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    #[Groups(['convocation:read', 'convocation:write'])]
    private ?string $lieuRendezVous = null;

    /** @var Collection<int, Joueur> */
    #[ORM\ManyToMany(targetEntity: Joueur::class)]
    #[ORM\JoinTable(name: 'convocation_joueur')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[Assert\Count(max: 30)]
    #[Groups(['convocation:read', 'convocation:write'])]
    private Collection $joueurs;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    #[Groups(['convocation:read', 'convocation:write'])]
    private ?string $message = null;

    /** false = brouillon, visible seulement dans l'admin */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['convocation:read', 'convocation:write'])]
    private bool $publiee = false;

    public function __construct()
    {
        $this->joueurs = new ArrayCollection();
    }

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

    public function getRendezVous(): ?\DateTime
    {
        return $this->rendezVous;
    }

    public function setRendezVous(?\DateTime $rendezVous): static
    {
        $this->rendezVous = $rendezVous;

        return $this;
    }

    public function getLieuRendezVous(): ?string
    {
        return $this->lieuRendezVous;
    }

    public function setLieuRendezVous(?string $lieuRendezVous): static
    {
        $this->lieuRendezVous = $lieuRendezVous;

        return $this;
    }

    /** @return Collection<int, Joueur> */
    public function getJoueurs(): Collection
    {
        return $this->joueurs;
    }

    public function addJoueur(Joueur $joueur): static
    {
        if (!$this->joueurs->contains($joueur)) {
            $this->joueurs->add($joueur);
        }

        return $this;
    }

    public function removeJoueur(Joueur $joueur): static
    {
        $this->joueurs->removeElement($joueur);

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function isPubliee(): bool
    {
        return $this->publiee;
    }

    public function setPubliee(bool $publiee): static
    {
        $this->publiee = $publiee;

        return $this;
    }
}
