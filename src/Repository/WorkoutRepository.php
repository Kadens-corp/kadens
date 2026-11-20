<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workout>
 */
class WorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workout::class);
    }

    /**
     * Séances de bibliothèque d'un utilisateur : ses séances réutilisables, hors
     * copies locales aux plans (planLocal = true, portées par une case de trame).
     * Sert à l'index, aux sélecteurs de séance et au compositeur de trame.
     *
     * @param array<string, string> $orderBy
     *
     * @return list<Workout>
     */
    public function findLibraryForOwner(User $owner, array $orderBy = ['title' => 'ASC']): array
    {
        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.owner = :owner')
            ->andWhere('w.planLocal = false')
            ->setParameter('owner', $owner);

        foreach ($orderBy as $field => $direction) {
            $qb->addOrderBy('w.'.$field, $direction);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les copies faites de ces séances d'amis (#126), la plus ancienne d'abord,
     * leur propriétaire chargé : « Copiée par Tom » sous la séance de l'auteur,
     * « Copiée dans tes séances » sur la carte de celui qui l'a prise.
     *
     * @param list<int> $sessionIds
     *
     * @return array<int, list<Workout>> indexé par identifiant de séance datée
     */
    public function findCopiesOfSessions(array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        /** @var list<Workout> $copies */
        $copies = $this->createQueryBuilder('w')
            ->addSelect('o')
            ->join('w.owner', 'o')
            ->andWhere('w.copiedFromSession IN (:sessions)')
            ->setParameter('sessions', $sessionIds)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();

        $bySession = [];
        foreach ($copies as $copy) {
            $sessionId = $copy->getCopiedFromSession()?->getId();
            if (null !== $sessionId) {
                $bySession[$sessionId][] = $copy;
            }
        }

        return $bySession;
    }

    /**
     * La copie de `$source` dans la bibliothèque de `$owner` (#58), la plus récente
     * s'il y en a plusieurs. Sert à reposer la même copie quand un coach repose sa
     * séance dans le plan de son athlète.
     */
    public function findLibraryCopy(Workout $source, User $owner): ?Workout
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.owner = :owner')
            ->andWhere('w.copiedFrom = :source')
            ->andWhere('w.planLocal = false')
            ->setParameter('owner', $owner)
            ->setParameter('source', $source)
            ->orderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Les ids des séances dont `$owner` a déjà une copie en bibliothèque : la
     * palette du coach les retire, la copie de l'athlète les remplace.
     *
     * @return list<int>
     */
    public function findCopiedSourceIds(User $owner): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('w')
            ->select('DISTINCT IDENTITY(w.copiedFrom)')
            ->andWhere('w.owner = :owner')
            ->andWhere('w.copiedFrom IS NOT NULL')
            ->andWhere('w.planLocal = false')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    /**
     * Comme findLibraryForOwner mais en chargeant le contenu (blocs -> exercices
     * prescrits -> exercice) en une requête. Sert la palette de l'éditeur de
     * trame, qui calcule pour chaque carte des repères dérivés (activités
     * distinctes, nombre d'exos) via WorkoutMetrics : sans ce fetch-join, ce
     * serait un N+1 sur toute la bibliothèque.
     *
     * @return list<Workout>
     */
    public function findLibraryForOwnerWithContent(User $owner): array
    {
        return $this->findLibraryForOwnersWithContent([$owner]);
    }

    /**
     * Variante multi-propriétaires : l'index des séances d'un coach liste aussi
     * celles de ses athlètes suivis (cf. CoachedLibrary). Réservée aux **listes de
     * consultation** — les sélecteurs qui posent une séance sur son propre
     * calendrier ou dans sa propre trame doivent rester sur la version à un seul
     * propriétaire, sinon on proposerait la séance d'un athlète à la pose.
     *
     * Les brouillons abandonnés n'y figurent pas (#115) : ni l'index, ni la
     * palette, ni le sélecteur du calendrier ne proposent une « Nouvelle séance »
     * vide laissée par un retour arrière. Elle reste en base jusqu'à la purge.
     *
     * @param list<User> $owners
     *
     * @return list<Workout>
     */
    public function findLibraryForOwnersWithContent(array $owners): array
    {
        if ([] === $owners) {
            return [];
        }

        $qb = $this->createQueryBuilder('w')
            ->addSelect('b', 'pe', 'ex')
            ->leftJoin('w.blocks', 'b')
            ->leftJoin('b.prescribedExercises', 'pe')
            ->leftJoin('pe.exercise', 'ex')
            ->andWhere('w.owner IN (:owners)')
            ->andWhere('w.planLocal = false')
            ->setParameter('owners', $owners)
            ->addOrderBy('w.title', 'ASC');

        return self::withoutAbandonedDrafts($qb, 'w')->getQuery()->getResult();
    }

    /**
     * Les brouillons abandonnés créés avant `$before`, toutes bibliothèques
     * confondues : ce que la purge supprime (`DraftPurger`, #115).
     *
     * @return list<Workout>
     */
    public function findAbandonedDrafts(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere(self::abandonedDraftCondition('w'))
            ->andWhere('w.createdAt < :before')
            ->setParameter('draftTitle', Workout::DRAFT_TITLE)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * **Brouillon abandonné** (#115) : une « Nouvelle séance » créée en un clic
     * puis quittée sans rien y faire, typiquement par un retour arrière. Il a
     * gardé le titre par défaut et n'a rien reçu : ni bloc, ni description, ni
     * note, ni partage, ni pose au calendrier ou dans un plan. Une seule de ces
     * traces suffit à le garder, parce qu'elle dit une intention.
     *
     * C'est la seule écriture de la règle : le masquage des listes et la purge
     * la lisent ici. Le paramètre `draftTitle` est à lier par l'appelant.
     */
    private static function abandonedDraftCondition(string $alias): string
    {
        return strtr(<<<'DQL'
            (a.title = :draftTitle
             AND a.planLocal = false
             AND a.shareToken IS NULL
             AND COALESCE(a.description, '') = ''
             AND COALESCE(a.notes, '') = ''
             AND a.blocks IS EMPTY
             AND a.planItems IS EMPTY
             AND a.scheduledWorkouts IS EMPTY)
            DQL, ['a.' => $alias.'.']);
    }

    private static function withoutAbandonedDrafts(QueryBuilder $qb, string $alias): QueryBuilder
    {
        return $qb
            ->andWhere('NOT '.self::abandonedDraftCondition($alias))
            ->setParameter('draftTitle', Workout::DRAFT_TITLE);
    }

    //    /**
    //     * @return Workout[] Returns an array of Workout objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('w.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Workout
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
