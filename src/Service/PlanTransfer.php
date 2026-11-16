<?php

namespace App\Service;

use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;

/**
 * « Ce plan est pour mon athlète » (#58) : le coach compose un plan depuis son
 * propre éditeur, puis le donne. Le plan **change de propriétaire** (ce n'est pas
 * une copie) ; le coach en reste co-éditeur par la relation acceptée, comme pour
 * tout contenu de l'athlète.
 *
 * Ce qui passe avec lui, pour que l'athlète ne voie jamais rien qui ne soit pas à
 * lui : chaque copie locale de case change de propriétaire, sa lignée se re-racine
 * dans la bibliothèque de l'athlète (`SharedWorkoutCopier::libraryCopyFor`) et la
 * règle d'exercices s'applique (un exercice d'un AUTRE athlète du coach lui serait
 * fermé).
 *
 * Ce qui ne passe pas : le bloc-notes privé du plan et de ses cases (l'athlète le
 * lirait, c'est le brouillon du coach), les liens vers des objectifs, qui sont
 * ceux du coach, et le partage par lien (#61) : publier est un choix du
 * propriétaire, et l'athlète n'a pas fait celui du coach. Le plan arrive privé.
 *
 * Les gardes (propriétaire, athlète accepté, plan jamais posé au calendrier)
 * sont au contrôleur. Ne flushe pas.
 */
final class PlanTransfer
{
    public function __construct(
        private readonly WorkoutCloner $cloner,
        private readonly SharedWorkoutCopier $workoutCopier,
        private readonly ShareLink $shareLink,
    ) {
    }

    public function transfer(PlanTemplate $plan, User $athlete): void
    {
        $plan->setOwner($athlete)->setNotes(null);
        $this->shareLink->close($plan);

        foreach ($plan->getGoals()->toArray() as $goal) {
            $plan->removeGoal($goal);
        }

        foreach ($plan->getPlanItems() as $item) {
            $cell = $item->getWorkout();
            if (null !== $cell) {
                $item->setWorkout($this->cellFor($cell, $athlete));
            }
        }
    }

    private function cellFor(Workout $cell, User $athlete): Workout
    {
        // Donnée ancienne : une case qui pointe encore une séance de bibliothèque.
        // On ne la déplace pas (elle resterait à manquer chez le coach), on la
        // remplace par une copie locale, comme le ferait une pose aujourd'hui.
        if (!$cell->isPlanLocal()) {
            $copy = $this->cloner->cloneWorkout($cell, $athlete, $cell->getTitle(), true)
                ->setOrigin($this->workoutCopier->libraryCopyFor($cell, $athlete));
            $this->workoutCopier->rebindExercises($copy, $athlete);

            return $copy;
        }

        $cell->setOwner($athlete)->setNotes(null);

        $root = $cell->getOrigin();
        if (null !== $root && $root->getOwner() !== $athlete) {
            $cell->setOrigin($this->workoutCopier->libraryCopyFor($root, $athlete));
        }

        $this->workoutCopier->rebindExercises($cell, $athlete);

        return $cell;
    }
}
