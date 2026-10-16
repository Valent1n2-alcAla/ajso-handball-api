<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\EquipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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
    normalizationContext: ['groups' => ['equipe:read']],
    order: ['ordre' => 'ASC', 'nom' => 'ASC'],
)]
#[ApiFilter(BooleanFilter::class, properties: ['estAjso'])]
#[ApiFilter(OrderFilter::class, properties: ['nom', 'ordre'])]
#[ORM\Entity(repositoryClass: EquipeRepository::class)]
class Equipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['equipe:read', 'rencontre:read', 'creneau:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['equipe:read', 'rencontre:read', 'creneau:read'])]
    private ?string $nom = null;

    /** true = équipe du club AJSO, false = adversaire */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['equipe:read', 'rencontre:read'])]
    private bool $estAjso = false;

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    #[Groups(['equipe:read'])]
    private ?string $entraineur = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['equipe:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['equipe:read'])]
    private ?string $photo = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['equipe:read'])]
    private int $ordre = 0;

    /** Libellé exact de l'équipe sur ffhandball.fr (ex. « AJS OUISTREHAM HANDBALL 2 ») */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    #[Groups(['equipe:read'])]
    private ?string $nomFfhb = null;

    #[ORM\ManyToOne(inversedBy: 'equipes')]
    #[Groups(['equipe:read', 'rencontre:read', 'creneau:read'])]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne(inversedBy: 'equipes')]
    #[Groups(['equipe:read', 'rencontre:read'])]
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

    public function isEstAjso(): bool
    {
        return $this->estAjso;
    }

    public function setEstAjso(bool $estAjso): static
    {
        $this->estAjso = $estAjso;

        return $this;
    }

    public function getEntraineur(): ?string
    {
        return $this->entraineur;
    }

    public function setEntraineur(?string $entraineur): static
    {
        $this->entraineur = $entraineur;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getNomFfhb(): ?string
    {
        return $this->nomFfhb;
    }

    public function setNomFfhb(?string $nomFfhb): static
    {
        $this->nomFfhb = $nomFfhb;

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
