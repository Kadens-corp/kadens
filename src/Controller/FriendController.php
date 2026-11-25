<?php

namespace App\Controller;

use App\Entity\Friendship;
use App\Entity\ScheduledWorkout;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\ScheduledWorkoutRepository;
use App\Repository\UserRepository;
use App\Service\FriendFeed;
use App\Service\FriendSessionCopier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Amis et flux de séances (#126).
 *
 * Une amitié ne donne qu'une chose, dans les deux sens : lire les séances de
 * salle faites de l'autre dans son flux, et pouvoir les copier chez soi. Aucun
 * voter ne la consulte, aucune page de contenu ne s'ouvre par elle — le flux se
 * suffit à lui-même (`FriendFeed`).
 *
 * Les gestes de la page Amis redirigent (POST → redirect → GET), comme
 * `/coaching` : la page se recharge entière, les toasts disent ce qui s'est passé.
 * Ceux du flux, non : on les fait au milieu d'une liste qu'on fait défiler, et
 * une redirection ramènerait en haut. Copier, masquer, repartager répondent en
 * Turbo Stream (la carte touchée seule, `streamCard()`), et « Séances plus
 * anciennes » charge la page suivante dans son turbo-frame (`feed()`). Sans JS,
 * les mêmes routes redirigent vers la carte, comme avant.
 * Le droit d'agir se vérifie avant le jeton CSRF, comme les voters de
 * `/coaching` : hors droits, la réponse est la 404 d'une ressource inexistante.
 */
#[Route('/friends')]
final class FriendController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    /**
     * La page Amis : demandes (reçues et envoyées), amis, recherche, partage.
     *
     * La recherche est un GET (`?q=`) : elle ne change rien, et la page reste
     * lisible sans JS. Elle trouve **au plus un compte**, par pseudo ou email
     * EXACTS (pseudo insensible à la casse et aux accents, par sa collation) :
     * pas d'annuaire parcourable, la règle de `/coaching`. Taper « cam » ne liste
     * pas les Camille.
     */
    #[Route('', name: 'app_friend_index', methods: ['GET'])]
    public function index(Request $request, UserRepository $users, ScheduledWorkoutRepository $sessions): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $query = trim($request->query->getString('q'));
        $found = '' === $query ? null : $this->lookup($users, $query, $user);

        $accepted = $this->friendships->findAcceptedFor($user);
        $friends = array_values(array_filter(array_map(static fn (Friendship $f): ?User => $f->otherParty($user), $accepted)));

        return $this->render('friend/index.html.twig', [
            'received' => $this->friendships->findReceivedPending($user),
            'sent' => $this->friendships->findSentPending($user),
            'friendships' => $accepted,
            'latest' => $sessions->latestSharedByOwner($friends),
            'hiddenCount' => $sessions->countHiddenFromFriends($user),
            'query' => $query,
            'found' => $found,
            'foundRelation' => null !== $found ? $this->friendships->findBetween($user, $found) : null,
        ]);
    }

    /**
     * Demande d'amitié. Le compte visé se retrouve **par la même recherche
     * exacte** que la page, à partir du texte saisi, jamais par un identifiant :
     * un `id` posté laisserait demander n'importe qui en comptant, et la réponse
     * dirait son nom.
     *
     * Une demande déjà reçue de cette personne s'accepte : deux personnes qui se
     * cherchent en même temps sont amies, pas en attente l'une de l'autre.
     */
    #[Route('/request', name: 'app_friend_request', methods: ['POST'])]
    public function request(Request $request, UserRepository $users): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();
        $this->guard('friend_request', $payload->getString('_token'));

        $query = trim($payload->getString('q'));
        $target = '' === $query ? null : $this->lookup($users, $query, $user);
        $back = $this->redirectToRoute('app_friend_index', ['q' => $query]);

        if (null === $target) {
            $this->addFlash('error', sprintf('Aucun compte Kadens avec le pseudo ou l\'email « %s ».', $query));

            return $back;
        }

        $name = $target->getDisplayName();
        $existing = $this->friendships->findBetween($user, $target);

        if (null !== $existing) {
            if ($existing->isAccepted()) {
                $this->addFlash('error', sprintf('%s est déjà ton ami.', $name));
            } elseif ($existing->isAddressee($user)) {
                $existing->accept();
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('%s t\'avait déjà fait une demande : vous êtes amis.', $name));
            } else {
                $this->addFlash('error', sprintf('Ta demande à %s attend déjà sa réponse.', $name));
            }

            return $back;
        }

        $this->entityManager->persist((new Friendship())->setRequester($user)->setAddressee($target));
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Demande envoyée à %s.', $name));

        return $back;
    }

    /** Accepter ou refuser, par le seul destinataire. Refuser efface la demande. */
    #[Route('/{id}/respond', name: 'app_friend_respond', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function respond(Request $request, Friendship $friendship): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();

        if (!$friendship->isPending() || !$friendship->isAddressee($user)) {
            throw $this->createNotFoundException();
        }
        $this->guard('friend_respond'.$friendship->getId(), $payload->getString('_token'));

        $name = $friendship->otherParty($user)?->getDisplayName() ?? 'cette personne';

        if ('accept' === $payload->getString('decision')) {
            $friendship->accept();
            $this->addFlash('success', sprintf('Toi et %s êtes amis. Vos séances de salle faites arrivent dans vos flux.', $name));
        } else {
            $this->entityManager->remove($friendship);
            $this->addFlash('success', sprintf('Demande de %s refusée.', $name));
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_friend_index');
    }

    /**
     * Annuler sa demande (son seul émetteur, tant qu'elle attend) ou retirer un
     * ami (l'un ou l'autre). Dans les deux cas la ligne disparaît : rien n'est
     * gardé d'une amitié finie. Les copies déjà faites restent chez leur
     * propriétaire, elles lui appartiennent.
     */
    #[Route('/{id}/remove', name: 'app_friend_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(Request $request, Friendship $friendship): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$friendship->involves($user) || ($friendship->isPending() && !$friendship->isRequester($user))) {
            throw $this->createNotFoundException();
        }
        $this->guard('friend_remove'.$friendship->getId(), $request->getPayload()->getString('_token'));

        $name = $friendship->otherParty($user)?->getDisplayName() ?? 'cette personne';
        $this->addFlash('success', $friendship->isPending()
            ? sprintf('Demande à %s annulée.', $name)
            : sprintf('%s ne fait plus partie de tes amis. Vous ne voyez plus vos séances.', $name));

        $this->entityManager->remove($friendship);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_friend_index');
    }

    /** Ouvrir ou couper le partage de ses séances avec tous ses amis. */
    #[Route('/sharing', name: 'app_friend_sharing', methods: ['POST'])]
    public function sharing(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();
        $this->guard('friend_sharing', $payload->getString('_token'));

        $share = '1' === $payload->getString('share');
        $user->setShareWithFriends($share);
        $this->entityManager->flush();

        $this->addFlash('success', $share
            ? 'Tes séances de salle faites sont de nouveau visibles par tes amis.'
            : 'Partage coupé : tes amis ne voient plus tes séances, ni les nouvelles ni les anciennes.');

        return $this->redirectToRoute('app_friend_index', ['_fragment' => 'partage']);
    }

    /**
     * Le flux : ses séances et celles de ses amis, filtrables par personne
     * (`?ami=<id>` ou `?ami=moi`), par pages de `FriendFeed::PAGE_SIZE`.
     *
     * Demandée par le turbo-frame de sa page (`Turbo-Frame: feed-page-N`, cf.
     * `_feed_page`), la page N ne rend que ce frame : ses cartes s'ajoutent sous
     * la page précédente. `?jour=` y dit le dernier jour déjà affiché, pour ne
     * pas répéter son séparateur. Ouverte entière, elle l'ignore.
     */
    #[Route('/feed', name: 'app_friend_feed', methods: ['GET'])]
    public function feed(Request $request, FriendFeed $feed): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $only = $feed->authorFor($user, $request->query->getString('ami'));
        $page = max(1, $request->query->getInt('page', 1));
        $result = $feed->page($user, $only, $page);
        $context = [
            'entries' => $result['entries'],
            'hasMore' => $result['hasMore'],
            'plates' => $result['plates'],
            'page' => $page,
            'filter' => null === $only ? null : ($only === $user ? 'moi' : (string) $only->getId()),
            'previewCount' => FriendFeed::PREVIEW_EXERCISES,
        ];

        if ('feed-page-'.$page === $request->headers->get('Turbo-Frame')) {
            return $this->render('friend/_feed_page.html.twig', $context + [
                'continuesDay' => $request->query->getString('jour') ?: null,
            ]);
        }

        return $this->render('friend/feed.html.twig', $context + [
            'friends' => $this->friendships->friendsOf($user),
        ]);
    }

    /**
     * « Masquer pour mes amis » / « Partager à nouveau » sur une de SES séances.
     * Propriétaire seul : c'est sa séance, et une séance d'un autre n'a pas à
     * savoir qu'on a tenté.
     */
    #[Route('/feed/{id}/visibility', name: 'app_friend_session_visibility', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function visibility(Request $request, ScheduledWorkout $session, FriendFeed $feed): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();

        if ($session->getOwner()?->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
        $this->guard('friend_visibility'.$session->getId(), $payload->getString('_token'));

        $hidden = '1' === $payload->getString('hidden');
        $session->setHiddenFromFriends($hidden);
        $this->entityManager->flush();

        $this->addFlash('success', $hidden
            ? sprintf('« %s » est masquée pour tes amis.', $session->getDisplayTitle())
            : sprintf('« %s » est de nouveau visible par tes amis.', $session->getDisplayTitle()));

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return $this->streamCard($request, $feed, $user, $session);
        }

        return $this->backToFeed($payload->getString('ami'), $payload->getInt('page', 1), $session);
    }

    /**
     * « Copier dans mes séances » : la séance d'un ami devient une séance de sa
     * bibliothèque, sous le nom choisi (`FriendSessionCopier`). La garde est le
     * flux lui-même (`FriendFeed::canRead()`) : une séance qu'on ne voit pas — pas
     * amis, partage coupé, masquée, pas faite — rend la même 404 qu'une séance
     * inventée.
     */
    #[Route('/feed/{id}/copy', name: 'app_friend_session_copy', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copy(Request $request, ScheduledWorkout $session, FriendFeed $feed, FriendSessionCopier $copier): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();

        if (!$feed->canRead($user, $session)) {
            throw $this->createNotFoundException();
        }
        $this->guard('friend_copy'.$session->getId(), $payload->getString('_token'));

        $title = mb_substr(trim($payload->getString('title')), 0, 255);
        if ('' === $title) {
            $title = FriendFeed::copyTitle($session);
        }

        $copy = $copier->copy($session, $user, $title);
        $this->entityManager->flush();

        $this->addFlash('success', 0 === $copy->copiedExercises
            ? sprintf('« %s » est dans tes séances.', $title)
            : sprintf('« %s » est dans tes séances, avec %d exercice%s perso ajouté%2$s à ta bibliothèque.', $title, $copy->copiedExercises, $copy->copiedExercises > 1 ? 's' : ''));

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return $this->streamCard($request, $feed, $user, $session);
        }

        return $this->backToFeed($payload->getString('ami'), $payload->getInt('page', 1), $session);
    }

    /**
     * Le compte désigné par `$query`, pseudo ou email exacts, hors soi-même.
     * Un texte avec `@` ne cherche que l'email (un pseudo ne peut pas en
     * contenir, cf. `User::$username`).
     */
    private function lookup(UserRepository $users, string $query, User $me): ?User
    {
        $found = str_contains($query, '@')
            ? $users->findOneBy(['email' => $query])
            : $users->findOneBy(['username' => $query]);

        return null === $found || $found->getId() === $me->getId() ? null : $found;
    }

    private function guard(string $id, string $token): void
    {
        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /**
     * La carte touchée, re-rendue à sa place (`friend/stream/card.stream.html.twig`),
     * avec le message en toast. Le filtre et la page postés ne servent qu'aux
     * formulaires de la carte, pour leur repli sans JS.
     */
    private function streamCard(Request $request, FriendFeed $feed, User $user, ScheduledWorkout $session): Response
    {
        $payload = $request->getPayload();
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return $this->render('friend/stream/card.stream.html.twig', [
            'entry' => $feed->entryFor($user, $session),
            'filter' => $payload->getString('ami') ?: null,
            'page' => max(1, $payload->getInt('page', 1)),
            'previewCount' => FriendFeed::PREVIEW_EXERCISES,
        ]);
    }

    /** Retour au flux, sur le même filtre et la même page, à la carte touchée. */
    private function backToFeed(string $filter, int $page, ScheduledWorkout $session): RedirectResponse
    {
        return $this->redirectToRoute('app_friend_feed', array_filter([
            'ami' => '' !== $filter ? $filter : null,
            'page' => $page > 1 ? $page : null,
            '_fragment' => 'seance-'.$session->getId(),
        ]));
    }
}
