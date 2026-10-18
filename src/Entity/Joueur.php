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
use App\Repository\JoueurRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        // Lecture admin : nom de famille complet
        new GetCollection(uriTemplate: '/admin/joueurs', security: "is_granted('ROLE_ADMIN')", normalizationContext: ['groups' => ['joueur:read', 'joueur:admin']]),
        new Get(uriTemplate: '/admin/joueurs/{id}', security: "is_granted('ROLE_ADMIN')", normalizationContext: ['groups' => ['joueur:read', 'joueur:admin']]),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['joueur:read']],
    order: ['numero' => 'ASC', 'prenom' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['equipe' => 'exact'])]
#[ORM\Entity(repositoryClass: JoueurRepository::class)]
class Joueur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?string $prenom = null;

    /** Jamais publié tel quel : voir getNomAffiche() */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['joueur:admin'])]
    private ?string $nom = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?int $numero = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Choice(choices: ['gardien', 'ailier-gauche', 'arriere-gauche', 'demi-centre', 'pivot', 'arriere-droit', 'ailier-droit', 'polyvalent'])]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?string $poste = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['joueur:read', 'participation:read'])]
    private ?string $photo = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['joueur:read', 'participation:read'])]
    private bool $capitaine = false;

    /** Créé automatiquement depuis une feuille de match (peut être retiré s'il n'a plus aucun match) */
    #[ORM\Column(options: ['default' => false])]
    private bool $creeParFeuille = false;

    public function isCreeParFeuille(): bool
    {
        return $this->creeParFeuille;
    }

    public function setCreeParFeuille(bool $creeParFeuille): static
    {
        $this->creeParFeuille = $creeParFeuille;

        return $this;
    }

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

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;

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

    public function getPoste(): ?string
    {
        return $this->poste;
    }

    public function setPoste(?string $poste): static
    {
        $this->poste = $poste;

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

    public function isCapitaine(): bool
    {
        return $this->capitaine;
    }

    public function setCapitaine(bool $capitaine): static
    {
        $this->capitaine = $capitaine;

        return $this;
    }

    /**
     * Nom publié : complet en catégorie adultes, « Prénom N. » pour les jeunes (protection des mineurs).
     */
    #[Groups(['joueur:read', 'participation:read'])]
    public function getNomAffiche(): string
    {
        $adulte = ($this->equipe?->getCategorie()?->getTranche()?->getAgeMin() ?? 0) >= 18;
        $nom = trim((string) $this->nom);

        return trim($this->prenom.' '.($adulte ? $nom : ($nom !== '' ? mb_strtoupper(mb_substr($nom, 0, 1)).'.' : '')));
    }
}
