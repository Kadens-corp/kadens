<?php

namespace App\Entity;

use App\Repository\WorkoutRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
class Workout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'workouts')]
    private ?User $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Bloc-notes libre du PROPRIÉTAIRE. Contrairement à `description`, qui décrit
     * la séance pour quiconque la lit (coach, partage public), ce champ n'est
     * visible que de `owner` : brouillon, déroulé en vrac, rappels personnels.
     *
     * Portée à ne pas casser : jamais dans `PlanFlattener`, jamais dans les vues
     * de consultation, d'export ou de partage public — l'exposer quelque part le
     * rendrait lisible par le coach, qui est co-éditeur de tout le reste. La garde
     * vit dans les contrôleurs (`updateMeta`) et dans les éditeurs (Twig).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Identifiant lisible, dérivé du titre : nom du fichier d'export. N'apparaît
     * plus dans aucune adresse publique (#61), c'est `shareToken` qui les porte.
     */
    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    /**
     * Partage par lien (#61). Null = privée, c'est le défaut. Posé par
     * `ShareLink::open()` à la demande du propriétaire : il sert alors à lui seul
     * d'autorisation de lecture sous `/s/{shareToken}`, d'où l'aléa (128 bits) et
     * aucune dérivation du titre. Arrêter le partage le remet à null : l'adresse
     * meurt pour de bon, un nouveau partage en tire une autre. Jamais posé sur une
     * copie `planLocal`, qui ne se lit que par le lien de son plan.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $shareToken = null;

    #[ORM\Column(nullable: true)]
    private ?int $estimatedDurationMinutes = null;

    /**
     * Séance « locale à un plan » : copie privée créée en posant une séance dans
     * une trame (fork à la pose, cf. notion de progression). Ces copies portent la
     * progression propre à leur case et ne doivent PAS apparaître dans la
     * bibliothèque de séances (index, sélecteurs). Une séance de bibliothèque
     * vaut false.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $planLocal = false;

    /**
     * Séance de bibliothèque dont cette copie descend : c'est ce qui dit que
     * « Upper A » semaine 10 est bien « Upper A », même renommée. Toujours la
     * RACINE de la lignée, jamais une copie intermédiaire (`WorkoutCloner` la
     * reconduit de copie en copie) : on ne remonte donc jamais de chaîne.
     * Null pour une séance de bibliothèque. SET NULL : supprimer la séance
     * d'origine laisse ses copies en place, elles en oublient juste la parenté.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Workout $origin = null;

    /**
     * Séance d'un AUTRE utilisateur dont celle-ci est la copie (#58) : le coach qui
     * a donné la sienne à son athlète, l'auteur d'une séance partagée. Posée par
     * `SharedWorkoutCopier`, jamais par une duplication chez soi.
     *
     * À ne pas confondre avec `origin`, qui relie une copie locale de plan à sa
     * racine CHEZ LE MÊME propriétaire. Ici, rien ne se propage : c'est un repère
     * pour retrouver « la copie de cette séance chez l'athlète » quand le coach la
     * repose (une seule copie par séance, pas une par pose). SET NULL : supprimer
     * l'original ne touche pas la copie, qui appartient à quelqu'un d'autre.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Workout $copiedFrom = null;

    /**
     * Qui, autre que le propriétaire, a mis ce contenu chez lui (#80) : le coach
     * qui l'a créé, copié, posé ou donné. Null = le propriétaire lui-même, c'est
     * le cas courant. Posé une fois pour toutes par `ContentAuthorListener` à la
     * création (et par `PlanTransfer` au don d'un plan), jamais réécrit : c'est
     * un fait d'origine, que l'athlète retouche ensuite le contenu ou non.
     *
     * Ne donne aucun droit : les voters passent par `Coaching`, pas par ce champ.
     * Il ne sert qu'à dire à l'athlète « ceci vient de ton coach ». SET NULL :
     * supprimer le compte du coach laisse le contenu, sans badge.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Block>
     */
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: Block::class, mappedBy: 'workout', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $blocks;

    /**
     * @var Collection<int, PlanItem>
     */
    #[ORM\OneToMany(targetEntity: PlanItem::class, mappedBy: 'workout')]
    private Collection $planItems;

    /**
     * @var Collection<int, ScheduledWorkout>
     */
    #[ORM\OneToMany(targetEntity: ScheduledWorkout::class, mappedBy: 'workout')]
    private Collection $scheduledWorkouts;

    public function __construct()
    {
        $this->blocks = new ArrayCollection();
        $this->planItems = new ArrayCollection();
        $this->scheduledWorkouts = new ArrayCollection();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): static
    {
        $this->shareToken = $shareToken;

        return $this;
    }

    public function isShared(): bool
    {
        return null !== $this->shareToken;
    }

    public function getEstimatedDurationMinutes(): ?int
    {
        return $this->estimatedDurationMinutes;
    }

    public function setEstimatedDurationMinutes(?int $estimatedDurationMinutes): static
    {
        $this->estimatedDurationMinutes = $estimatedDurationMinutes;

        return $this;
    }

    public function isPlanLocal(): bool
    {
        return $this->planLocal;
    }

    public function setPlanLocal(bool $planLocal): static
    {
        $this->planLocal = $planLocal;

        return $this;
    }

    public function getOrigin(): ?Workout
    {
        return $this->origin;
    }

    public function setOrigin(?Workout $origin): static
    {
        $this->origin = $origin;

        return $this;
    }

    public function getCopiedFrom(): ?Workout
    {
        return $this->copiedFrom;
    }

    public function setCopiedFrom(?Workout $copiedFrom): static
    {
        $this->copiedFrom = $copiedFrom;

        return $this;
    }


    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }

    public function setAddedBy(?User $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
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
     * @return Collection<int, Block>
     */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(Block $block): static
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
            $block->setWorkout($this);
        }

        return $this;
    }

    public function removeBlock(Block $block): static
    {
        if ($this->blocks->removeElement($block)) {
            // set the owning side to null (unless already changed)
            if ($block->getWorkout() === $this) {
                $block->setWorkout(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, PlanItem>
     */
    public function getPlanItems(): Collection
    {
        return $this->planItems;
    }

    public function addPlanItem(PlanItem $planItem): static
    {
        if (!$this->planItems->contains($planItem)) {
            $this->planItems->add($planItem);
            $planItem->setWorkout($this);
        }

        return $this;
    }

    public function removePlanItem(PlanItem $planItem): static
    {
        if ($this->planItems->removeElement($planItem)) {
            // set the owning side to null (unless already changed)
            if ($planItem->getWorkout() === $this) {
                $planItem->setWorkout(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ScheduledWorkout>
     */
    public function getScheduledWorkouts(): Collection
    {
        return $this->scheduledWorkouts;
    }

    public function addScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if (!$this->scheduledWorkouts->contains($scheduledWorkout)) {
            $this->scheduledWorkouts->add($scheduledWorkout);
            $scheduledWorkout->setWorkout($this);
        }

        return $this;
    }

    public function removeScheduledWorkout(ScheduledWorkout $scheduledWorkout): static
    {
        if ($this->scheduledWorkouts->removeElement($scheduledWorkout)) {
            // set the owning side to null (unless already changed)
            if ($scheduledWorkout->getWorkout() === $this) {
                $scheduledWorkout->setWorkout(null);
            }
        }

        return $this;
    }
}
