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
use App\Repository\CreneauRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/creneaux'),
        new Get(uriTemplate: '/creneaux/{id}'),
        new Post(uriTemplate: '/creneaux', security: "is_granted('ROLE_ADMIN')"),
        new Put(uriTemplate: '/creneaux/{id}', security: "is_granted('ROLE_ADMIN')"),
        new Patch(uriTemplate: '/creneaux/{id}', security: "is_granted('ROLE_ADMIN')"),
        new Delete(uriTemplate: '/creneaux/{id}', security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['creneau:read']],
    order: ['jour' => 'ASC', 'heureDebut' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['equipe' => 'exact', 'gymnase' => 'exact'])]
#[ORM\Entity(repositoryClass: CreneauRepository::class)]
class Creneau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['creneau:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['creneau:read'])]
    private ?Equipe $equipe = null;

    /** Un gymnase encore utilisé par des créneaux ne peut pas être supprimé */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    #[Groups(['creneau:read'])]
    private ?Gymnase $gymnase = null;

    /** 1 = lundi … 7 = dimanche */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['creneau:read'])]
    private ?int $jour = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    #[Assert\NotNull]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i'])]
    #[Groups(['creneau:read'])]
    private ?\DateTime $heureDebut = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'heureDebut')]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i'])]
    #[Groups(['creneau:read'])]
    private ?\DateTime $heureFin = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEquipe(): ?Equipe
    {
        return $this->equipe;
    }

    public function setEquipe(?Equipe $equipe): static
    {
        $this->equipe = $equipe;

        return $this;
    }

    public function getGymnase(): ?Gymnase
    {
        return $this->gymnase;
    }

    public function setGymnase(?Gymnase $gymnase): static
    {
        $this->gymnase = $gymnase;

        return $this;
    }

    public function getJour(): ?int
    {
        return $this->jour;
    }

    public function setJour(?int $jour): static
    {
        $this->jour = $jour;

        return $this;
    }

    public function getHeureDebut(): ?\DateTime
    {
        return $this->heureDebut;
    }

    public function setHeureDebut(?\DateTime $heureDebut): static
    {
        $this->heureDebut = $heureDebut;

        return $this;
    }

    public function getHeureFin(): ?\DateTime
    {
        return $this->heureFin;
    }

    public function setHeureFin(?\DateTime $heureFin): static
    {
        $this->heureFin = $heureFin;

        return $this;
    }
}
