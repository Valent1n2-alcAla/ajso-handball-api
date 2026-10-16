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
use App\Repository\ClassementRepository;
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
    normalizationContext: ['groups' => ['classement:read']],
    order: ['championnat' => 'ASC', 'position' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['championnat' => 'exact'])]
#[ORM\Entity(repositoryClass: ClassementRepository::class)]
class Classement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['classement:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['classement:read'])]
    private ?Championnat $championnat = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['classement:read'])]
    private ?int $position = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['classement:read'])]
    private ?string $equipe = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['classement:read'])]
    private bool $estAjso = false;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['classement:read'])]
    private int $points = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $joues = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $gagnes = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $nuls = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $perdus = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $butsPour = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['classement:read'])]
    private int $butsContre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChampionnat(): ?Championnat
    {
        return $this->championnat;
    }

    public function setChampionnat(?Championnat $championnat): static
    {
        $this->championnat = $championnat;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getEquipe(): ?string
    {
        return $this->equipe;
    }

    public function setEquipe(?string $equipe): static
    {
        $this->equipe = $equipe;

        return $this;
    }

    public function isEstAjso(): bool
    {
        return $this->estAjso;
    }

    public function setEstAjso(bool $estAjso): static
    {
        $this->estAjso = $estAjso;

        return $this;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

        return $this;
    }

    public function getJoues(): int
    {
        return $this->joues;
    }

    public function setJoues(int $joues): static
    {
        $this->joues = $joues;

        return $this;
    }

    public function getGagnes(): int
    {
        return $this->gagnes;
    }

    public function setGagnes(int $gagnes): static
    {
        $this->gagnes = $gagnes;

        return $this;
    }

    public function getNuls(): int
    {
        return $this->nuls;
    }

    public function setNuls(int $nuls): static
    {
        $this->nuls = $nuls;

        return $this;
    }

    public function getPerdus(): int
    {
        return $this->perdus;
    }

    public function setPerdus(int $perdus): static
    {
        $this->perdus = $perdus;

        return $this;
    }

    public function getButsPour(): int
    {
        return $this->butsPour;
    }

    public function setButsPour(int $butsPour): static
    {
        $this->butsPour = $butsPour;

        return $this;
    }

    public function getButsContre(): int
    {
        return $this->butsContre;
    }

    public function setButsContre(int $butsContre): static
    {
        $this->butsContre = $butsContre;

        return $this;
    }
}
