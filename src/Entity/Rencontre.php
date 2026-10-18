<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\RencontreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
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
    normalizationContext: ['groups' => ['rencontre:read']],
    order: ['date' => 'ASC', 'heure' => 'ASC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['equipe' => 'exact', 'equipeAdverse' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
#[ApiFilter(BooleanFilter::class, properties: ['domicile'])]
#[ApiFilter(OrderFilter::class, properties: ['date', 'heure'])]
#[ORM\Entity(repositoryClass: RencontreRepository::class)]
class Rencontre
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['rencontre:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rencontresDomicile')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['rencontre:read'])]
    private ?Equipe $equipe = null;

    #[ORM\ManyToOne(inversedBy: 'rencontresExterieur')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['rencontre:read'])]
    private ?Equipe $equipeAdverse = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    #[Groups(['rencontre:read'])]
    private ?\DateTime $date = null;

    /** Null tant que l'horaire n'est pas connu */
    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'H:i'])]
    #[Groups(['rencontre:read'])]
    private ?\DateTime $heure = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['rencontre:read'])]
    private ?string $lieu = null;

    #[ORM\Column]
    #[Groups(['rencontre:read'])]
    private ?bool $domicile = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['rencontre:read'])]
    private ?int $scoreDom = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Groups(['rencontre:read'])]
    private ?int $scoreExt = null;

    /** Compétition du match (renseignée par l'import ; sinon championnat principal de l'équipe) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[Groups(['rencontre:read'])]
    private ?Championnat $championnat = null;

    /** Code de la feuille de match FDME (PDF publié après le match) */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fdmCode = null;

    /** Feuille de match lue : compositions et statistiques officielles enregistrées */
    #[ORM\Column(options: ['default' => false])]
    private bool $feuilleLue = false;

    /** Identifiant de la rencontre sur ffhandball.fr (renseigné par l'import) */
    #[ORM\Column(length: 30, unique: true, nullable: true)]
    #[Groups(['rencontre:read'])]
    private ?string $ffhbId = null;

    /** false = seul le week-end est connu : `date` porte alors le samedi du week-end */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['rencontre:read'])]
    private bool $dateConfirmee = true;

    /** Journée / phase de championnat (ex. « J3 », « Coupe 1er tour ») */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    #[Groups(['rencontre:read'])]
    private ?string $journee = null;

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

    public function setHeure(?\DateTime $heure): static
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

    public function isDateConfirmee(): bool
    {
        return $this->dateConfirmee;
    }

    public function setDateConfirmee(bool $dateConfirmee): static
    {
        $this->dateConfirmee = $dateConfirmee;

        return $this;
    }

    public function getJournee(): ?string
    {
        return $this->journee;
    }

    public function setJournee(?string $journee): static
    {
        $this->journee = $journee;

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

    public function getFdmCode(): ?string
    {
        return $this->fdmCode;
    }

    public function setFdmCode(?string $fdmCode): static
    {
        $this->fdmCode = $fdmCode;

        return $this;
    }

    public function isFeuilleLue(): bool
    {
        return $this->feuilleLue;
    }

    public function setFeuilleLue(bool $feuilleLue): static
    {
        $this->feuilleLue = $feuilleLue;

        return $this;
    }

    public function getFfhbId(): ?string
    {
        return $this->ffhbId;
    }

    public function setFfhbId(?string $ffhbId): static
    {
        $this->ffhbId = $ffhbId;

        return $this;
    }
}
