<?php

namespace App\Repository;

use App\Entity\PlanTemplate;
use App\Entity\User;
use App\Entity\Workout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanTemplate>
 */
class PlanTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanTemplate::class);
    }

    /**
     * Charge un plan avec tout son contenu (cases -> séance -> blocs -> exercices
     * prescrits -> exercice) en une requête. Sert la vue de progression prévue,
     * qui parcourt chaque case du plan : sans ce fetch-join, ce serait un N+1 sur
     * toutes les semaines. L'entité renvoyée est la même instance managée (identity
     * map) avec ses collections déjà hydratées.
     */
    public function findWithContent(int $id): ?PlanTemplate
    {
        return $this->createQueryBuilder('t')
            ->addSelect('i', 'w', 'b', 'pe', 'ex')
            ->leftJoin('t.planItems', 'i')
            ->leftJoin('i.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'ex')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Plans d'un ou plusieurs propriétaires, contenu fetch-joint. L'index des
     * plans dérive de chaque trame ses activités distinctes (il traverse cases ->
     * séance -> blocs -> exercices) : sans ce join, ce serait un N+1 par plan.
     *
     * Plusieurs propriétaires parce qu'un coach y voit aussi les plans de ses
     * athlètes suivis (cf. CoachedLibrary). Les sélecteurs qui posent un plan sur
     * son propre calendrier restent scopés sur l'utilisateur courant.
     *
     * @param list<User> $owners
     *
     * @return list<PlanTemplate>
     */
    public function findForOwnersWithContent(array $owners): array
    {
        if ([] === $owners) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('i', 'w', 'b', 'pe', 'ex')
            ->leftJoin('t.planItems', 'i')
            ->leftJoin('i.workout', 'w')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'ex')
            ->andWhere('t.owner IN (:owners)')
            ->setParameter('owners', $owners)
            ->addOrderBy('t.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le plan le plus récent de `$owner` qui porte au moins une séance : la
     * deuxième étape du démarrage de l'accueil (#70). Un plan créé mais vide
     * n'assemble rien, il ne coche pas l'étape. Le plan rendu sert aussi de
     * cible à la troisième étape (« placer le plan dans l'agenda »).
     */
    public function findLatestFilledForOwner(User $owner): ?PlanTemplate
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\PlanItem i WHERE i.planTemplate = t)')
            ->setParameter('owner', $owner)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Plans où une séance de bibliothèque a été posée (#113) : ceux dont une case
     * est une copie de sa lignée (`origin`). Le compositeur les nomme pour dire
     * que ces cases gardent leur version, puisque la pose forke.
     *
     * Portée = le propriétaire de la séance, comme `findDoneInLineage` : ses
     * copies `planLocal` sont à lui, et le plan d'un autre n'a rien à faire dans
     * ce rappel. Trié par titre, sans doublon (un plan peut poser la séance
     * dans plusieurs cases).
     *
     * @return list<PlanTemplate>
     */
    public function findUsingLibraryWorkout(Workout $root): array
    {
        $owner = $root->getOwner();
        if (null === $owner || $root->isPlanLocal()) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\PlanItem i JOIN i.workout w WHERE i.planTemplate = t AND w.origin = :root)')
            ->setParameter('owner', $owner)
            ->setParameter('root', $root)
            ->orderBy('t.title', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return PlanTemplate[] Returns an array of PlanTemplate objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?PlanTemplate
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
