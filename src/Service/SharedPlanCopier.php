<?php

namespace App\Service;

use App\Entity\PlanItem;
use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copie un plan dans la bibliothèque d'un AUTRE utilisateur (#58) : le coach qui
 * donne un de ses plans à son athlète, le visiteur qui copie un plan partagé.
 *
 * Même trame, mêmes notes de case, chaque case avec sa propre copie locale
 * (`planLocal`), comme `PlanTemplateController::duplicate`. Deux choses en plus,
 * parce que le plan change de propriétaire :
 *
 * - **la lignée se re-racine chez le destinataire.** La racine d'une case
 *   (`origin`) est une séance de bibliothèque de l'auteur ; elle reçoit sa copie
 *   dans la bibliothèque du destinataire (`SharedWorkoutCopier::libraryCopyFor`,
 *   une seule par racine), et les cases la pointent. Sans ça, les requêtes de
 *   lignée, scopées sur le propriétaire de la racine, ne trouveraient jamais son
 *   réalisé : ni « d'après le dernier réalisé », ni ressenti en palette ;
 * - **la règle d'exercices** de `SharedWorkoutCopier` s'applique à chaque case.
 *
 * Ni le bloc-notes privé ni les objectifs ne suivent : ils sont à l'auteur.
 * Persiste, ne flushe pas.
 */
final class SharedPlanCopier
{
    public function __construct(
        private readonly WorkoutCloner $cloner,
        private readonly SharedWorkoutCopier $workoutCopier,
        private readonly SlugGenerator $slugGenerator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function copyFor(PlanTemplate $source, User $recipient): PlanTemplate
    {
        $title = (string) $source->getTitle();

        $copy = (new PlanTemplate())
            ->setOwner($recipient)
            ->setTitle($title)
            ->setDescription($source->getDescription())
            ->setDurationWeeks((int) $source->getDurationWeeks())
            ->setSlug($this->slugGenerator->generate($title, PlanTemplate::class));

        foreach ($source->getPlanItems() as $item) {
            $cell = $item->getWorkout();
            if (null === $cell) {
                continue;
            }

            $copy->addPlanItem(
                (new PlanItem())
                    ->setWorkout($this->cellFor($cell, $recipient))
                    ->setWeekNumber((int) $item->getWeekNumber())
                    ->setDayOfWeek((int) $item->getDayOfWeek())
                    ->setNotes($item->getNotes())
            );
        }

        $this->entityManager->persist($copy);

        return $copy;
    }

    /**
     * La copie locale d'une case chez le destinataire, racinée dans SA
     * bibliothèque. Une case sans racine (séance d'origine supprimée) le reste.
     */
    private function cellFor(Workout $cell, User $recipient): Workout
    {
        // Une case pointe normalement une copie locale ; une donnée ancienne peut
        // encore pointer une séance de bibliothèque, qui est alors sa propre racine.
        $root = $cell->isPlanLocal() ? $cell->getOrigin() : $cell;

        $copy = $this->cloner->cloneWorkout($cell, $recipient, $cell->getTitle(), true)
            ->setOrigin(null === $root ? null : $this->workoutCopier->libraryCopyFor($root, $recipient));
        $this->workoutCopier->rebindExercises($copy, $recipient);

        return $copy;
    }
}
