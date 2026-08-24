<?php

namespace App\Entity;

use App\Repository\EquipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use ApiPlatform\Metadata\ApiResource;

#[ApiResource]
#[ORM\Entity(repositoryClass: EquipeRepository::class)]
class Equipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\ManyToOne(inversedBy: 'equipes')]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne(inversedBy: 'equipes')]
    private ?Championnat $championnat = null;

    /**
     * @var Collection<int, Rencontre>
     */
    #[ORM\OneToMany(targetEntity: Rencontre::class, mappedBy: 'equipe')]
    private Collection $rencontresDomicile;

    /**
     * @var Collection<int, Rencontre>
     */
    #[ORM\OneToMany(targetEntity: Rencontre::class, mappedBy: 'equipeAdverse')]
    private Collection $rencontresExterieur;

    public function __construct()
    {
        $this->rencontresDomicile = new ArrayCollection();
        $this->rencontresExterieur = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
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

    /**
     * @return Collection<int, Rencontre>
     */
    public function getRencontresDomicile(): Collection
    {
        return $this->rencontresDomicile;
    }

    public function addRencontreDomicile(Rencontre $rencontre): static
    {
        if (!$this->rencontresDomicile->contains($rencontre)) {
            $this->rencontresDomicile->add($rencontre);
            $rencontre->setEquipe($this);
        }

        return $this;
    }

    public function removeRencontreDomicile(Rencontre $rencontre): static
    {
        if ($this->rencontresDomicile->removeElement($rencontre)) {
            if ($rencontre->getEquipe() === $this) {
                $rencontre->setEquipe(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Rencontre>
     */
    public function getRencontresExterieur(): Collection
    {
        return $this->rencontresExterieur;
    }

    public function addRencontreExterieur(Rencontre $rencontre): static
    {
        if (!$this->rencontresExterieur->contains($rencontre)) {
            $this->rencontresExterieur->add($rencontre);
            $rencontre->setEquipeAdverse($this);
        }

        return $this;
    }

    public function removeRencontreExterieur(Rencontre $rencontre): static
    {
        if ($this->rencontresExterieur->removeElement($rencontre)) {
            if ($rencontre->getEquipeAdverse() === $this) {
                $rencontre->setEquipeAdverse(null);
            }
        }

        return $this;
    }
}