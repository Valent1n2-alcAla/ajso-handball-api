<?php

namespace App\Entity;

use App\Repository\RencontreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;

#[ApiResource]
#[ORM\Entity(repositoryClass: RencontreRepository::class)]
class Rencontre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rencontresDomicile')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Equipe $equipe = null;

    #[ORM\ManyToOne(inversedBy: 'rencontresExterieur')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Equipe $equipeAdverse = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $date = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTime $heure = null;

    #[ORM\Column(length: 150)]
    private ?string $lieu = null;

    #[ORM\Column]
    private ?bool $domicile = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreDom = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreExt = null;

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

    public function getEquipeAdverse(): ?Equipe
    {
        return $this->equipeAdverse;
    }

    public function setEquipeAdverse(?Equipe $equipeAdverse): static
    {
        $this->equipeAdverse = $equipeAdverse;

        return $this;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getHeure(): ?\DateTime
    {
        return $this->heure;
    }

    public function setHeure(\DateTime $heure): static
    {
        $this->heure = $heure;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(string $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function isDomicile(): ?bool
    {
        return $this->domicile;
    }

    public function setDomicile(bool $domicile): static
    {
        $this->domicile = $domicile;

        return $this;
    }

    public function getScoreDom(): ?int
    {
        return $this->scoreDom;
    }

    public function setScoreDom(?int $scoreDom): static
    {
        $this->scoreDom = $scoreDom;

        return $this;
    }

    public function getScoreExt(): ?int
    {
        return $this->scoreExt;
    }

    public function setScoreExt(?int $scoreExt): static
    {
        $this->scoreExt = $scoreExt;

        return $this;
    }
}