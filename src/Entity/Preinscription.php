<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\PreinscriptionRepository;
use App\State\PreinscriptionProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Préinscription envoyée depuis le site (envoi public, lecture et suivi par l'admin, comme les messages de contact).
 */
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new Post(denormalizationContext: ['groups' => ['preinscription:write']], processor: PreinscriptionProcessor::class),
        new Patch(security: "is_granted('ROLE_ADMIN')", denormalizationContext: ['groups' => ['preinscription:admin']]),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['preinscription:read']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(BooleanFilter::class, properties: ['traitee'])]
#[ORM\Entity(repositoryClass: PreinscriptionRepository::class)]
class Preinscription
{
    public const SEXES = ['F', 'M'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['preinscription:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $prenom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $nom = null;

    /** AAAA-MM-JJ */
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    #[Assert\LessThan('today', message: 'La date de naissance doit être passée.')]
    #[Assert\GreaterThan('-100 years', message: 'Date de naissance invalide.')]
    #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?\DateTime $dateNaissance = null;

    /** F, M ou null */
    #[ORM\Column(length: 1, nullable: true)]
    #[Assert\Choice(choices: self::SEXES)]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $sexe = null;

    /** Adresse du joueur ou de son responsable légal */
    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Assert\Email]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    #[Assert\Regex('/^[0-9 +().-]{6,30}$/', message: 'Numéro de téléphone invalide.')]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $telephone = null;

    /** Catégorie ou équipe souhaitée (texte libre, ex. « -13 filles », « Loisirs ») */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $categorieSouhaitee = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private bool $dejaLicencie = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    #[Groups(['preinscription:read', 'preinscription:write'])]
    private ?string $message = null;

    #[ORM\Column]
    #[Groups(['preinscription:read'])]
    private \DateTimeImmutable $createdAt;

    /** Prise en charge par le club */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['preinscription:read', 'preinscription:admin'])]
    private bool $traitee = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDateNaissance(): ?\DateTime
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTime $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;

        return $this;
    }

    public function getSexe(): ?string
    {
        return $this->sexe;
    }

    public function setSexe(?string $sexe): static
    {
        $this->sexe = $sexe === '' ? null : $sexe;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getCategorieSouhaitee(): ?string
    {
        return $this->categorieSouhaitee;
    }

    public function setCategorieSouhaitee(?string $categorieSouhaitee): static
    {
        $this->categorieSouhaitee = $categorieSouhaitee;

        return $this;
    }

    public function isDejaLicencie(): bool
    {
        return $this->dejaLicencie;
    }

    public function setDejaLicencie(bool $dejaLicencie): static
    {
        $this->dejaLicencie = $dejaLicencie;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isTraitee(): bool
    {
        return $this->traitee;
    }

    public function setTraitee(bool $traitee): static
    {
        $this->traitee = $traitee;

        return $this;
    }
}
