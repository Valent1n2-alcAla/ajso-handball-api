<?php

namespace App\Entity;

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
use App\Repository\PermanenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Planning des bénévoles des matchs à domicile : un poste (arbitrage, table, buvette…) par ligne.
 * `benevole` vide = poste à pourvoir. Nom public : prénom + initiale conseillés (« Julie M. »).
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
    normalizationContext: ['groups' => ['permanence:read', 'rencontre:read']],
    denormalizationContext: ['groups' => ['permanence:write']],
    order: ['rencontre.date' => 'ASC', 'rencontre.heure' => 'ASC', 'role' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['rencontre' => 'exact', 'rencontre.equipe' => 'exact', 'role' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['rencontre.date'])]
#[ORM\Entity(repositoryClass: PermanenceRepository::class)]
class Permanence
{
    public const ROLES = ['arbitrage', 'table', 'buvette', 'accueil', 'autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['permanence:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['permanence:read', 'permanence:write'])]
    private ?Rencontre $rencontre = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::ROLES)]
    #[Groups(['permanence:read', 'permanence:write'])]
    private ?string $role = null;

    /** Nom affiché du bénévole ; null = à pourvoir */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    #[Groups(['permanence:read', 'permanence:write'])]
    private ?string $benevole = null;

    /** Nombre de personnes nécessaires */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Range(min: 1, max: 20)]
    #[Groups(['permanence:read', 'permanence:write'])]
    private int $besoin = 1;

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

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getBenevole(): ?string
    {
        return $this->benevole;
    }

    public function setBenevole(?string $benevole): static
    {
        $benevole = $benevole !== null ? trim($benevole) : null;
        $this->benevole = $benevole === '' ? null : $benevole;

        return $this;
    }

    public function getBesoin(): int
    {
        return $this->besoin;
    }

    public function setBesoin(int $besoin): static
    {
        $this->besoin = $besoin;

        return $this;
    }
}
