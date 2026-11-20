<?php

namespace App\Entity;

use App\Enum\ActivityType;
use App\Enum\TargetArea;
use App\Repository\ExerciseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_exercise_ref_key', columns: ['ref_key'])]
#[ORM\HasLifecycleCallbacks]
class Exercise
{
    /** Au-delà, ce n'est plus un mouvement qu'on montre, c'est une galerie (#38). */
    public const MAX_IMAGES = 6;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'exercises')]
    private ?User $owner = null;

    /**
     * L'identité **stable** d'une entrée de la bibliothèque globale, séparée de
     * son libellé. C'est ce qui rend `app:import-exercises` rejouable : la
     * commande apparie là-dessus, donc renommer un exercice dans
     * `data/exercises.json` le met à jour au lieu d'en créer un second avec un
     * nouvel `id`. Or `LoggedExercise.exercise`, `PrescribedExercise.exercise`
     * et le cache mobile indexent tous sur cet `id` — un doublon coûterait
     * l'historique.
     *
     * **Réservée à la globale.** Un exercice perso n'en porte pas : la contrainte
     * est unique sur toute la table, et deux utilisateurs important le même
     * fichier la violeraient. MariaDB laisse passer plusieurs `NULL` sous un
     * index unique, c'est exactement ce qu'on lui demande ici.
     *
     * **Immuable une fois posée.** La changer reviendrait à réintroduire le
     * problème qu'elle résout.
     */
    #[ORM\Column(length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $refKey = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /**
     * Le nom anglais, facultatif. Ce n'est **pas** une traduction au sens
     * Symfony (l'app n'a aucun catalogue, toute l'UI est en français en dur) :
     * c'est une donnée métier, parce que les mouvements de salle se nomment
     * couramment en anglais et qu'on veut pouvoir les chercher des deux côtés.
     *
     * Absent, l'affichage en anglais retombe sur `name` — jamais de trou.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $nameEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: ActivityType::class)]
    #[Assert\NotNull(message: 'Choisis une activité.')]
    private ?ActivityType $activity = null;

    /**
     * @var TargetArea[]|null
     */
    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true, enumType: TargetArea::class)]
    private ?array $targetAreas = [];

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(message: 'Le lien média doit être une URL valide.')]
    #[Assert\Length(max: 255)]
    private ?string $mediaUrl = null;

    /**
     * Les images qui montrent le mouvement (#38), dans l'ordre où elles se
     * jouent : typiquement deux, le début puis la fin. Ce sont des **noms de
     * fichiers** rangés par `ExerciseImageStorage`, jamais des URL : elles se
     * servent par contrôleur, sous le droit de lecture de l'exercice.
     *
     * Une liste JSON plutôt qu'une entité : l'ordre est la seule chose qu'une
     * image porte, et une image ne sert qu'à un exercice. Corollaire voulu :
     * changer la liste touche une colonne d'`Exercise`, donc son `updatedAt`.
     * Les fichiers suivent l'ORM (`ExerciseImageCleanupListener`).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $images = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, PrescribedExercise>
     */
    #[ORM\OneToMany(targetEntity: PrescribedExercise::class, mappedBy: 'exercise')]
    private Collection $prescribedExercises;

    public function __construct()
    {
        $this->prescribedExercises = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getRefKey(): ?string
    {
        return $this->refKey;
    }

    public function setRefKey(?string $refKey): static
    {
        $this->refKey = $refKey;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getNameEn(): ?string
    {
        return $this->nameEn;
    }

    public function setNameEn(?string $nameEn): static
    {
        $this->nameEn = $nameEn;

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

    public function getActivity(): ?ActivityType
    {
        return $this->activity;
    }

    public function setActivity(ActivityType $activity): static
    {
        $this->activity = $activity;

        return $this;
    }


    /**
     * @return TargetArea[]|null
     */
    public function getTargetAreas(): ?array
    {
        return $this->targetAreas;
    }

    /**
     * @param TargetArea[]|null $targetAreas
     */
    public function setTargetAreas(?array $targetAreas): static
    {
        $this->targetAreas = $targetAreas;

        return $this;
    }

    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    public function setMediaUrl(?string $mediaUrl): static
    {
        $this->mediaUrl = $mediaUrl;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getImages(): array
    {
        return $this->images ?? [];
    }

    /**
     * Vide, la colonne repasse à `null` : un exercice sans image reste lu comme
     * avant le #38.
     *
     * @param list<string> $images
     */
    public function setImages(array $images): static
    {
        $images = array_values(array_unique($images));
        $this->images = [] === $images ? null : $images;

        return $this;
    }

    /**
     * Peut-on lui ajouter des images (#38) ? Seulement salle, mobilité et autre
     * (`ActivityType::takesImages`). Celles déjà posées restent lisibles et
     * retirables si l'activité change ensuite.
     */
    public function acceptsImages(): bool
    {
        return true === $this->activity?->takesImages();
    }

    public function hasImage(string $file): bool
    {
        return \in_array($file, $this->getImages(), true);
    }

    public function removeImage(string $file): static
    {
        return $this->setImages(array_values(array_filter($this->getImages(), static fn (string $image): bool => $image !== $file)));
    }

    /**
     * Échange une image avec sa voisine (`-1` : plus tôt, `+1` : plus tard).
     * Sans effet en bout de liste.
     */
    public function moveImage(string $file, int $direction): static
    {
        $images = $this->getImages();
        $from = array_search($file, $images, true);
        if (false === $from) {
            return $this;
        }

        $to = $from + ($direction < 0 ? -1 : 1);
        if ($to < 0 || $to >= \count($images)) {
            return $this;
        }

        [$images[$from], $images[$to]] = [$images[$to], $images[$from]];

        return $this->setImages(array_values($images));
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, PrescribedExercise>
     */
    public function getPrescribedExercises(): Collection
    {
        return $this->prescribedExercises;
    }

    public function addPrescribedExercise(PrescribedExercise $prescribedExercise): static
    {
        if (!$this->prescribedExercises->contains($prescribedExercise)) {
            $this->prescribedExercises->add($prescribedExercise);
            $prescribedExercise->setExercise($this);
        }

        return $this;
    }

    public function removePrescribedExercise(PrescribedExercise $prescribedExercise): static
    {
        if ($this->prescribedExercises->removeElement($prescribedExercise)) {
            // set the owning side to null (unless already changed)
            if ($prescribedExercise->getExercise() === $this) {
                $prescribedExercise->setExercise(null);
            }
        }

        return $this;
    }
}
