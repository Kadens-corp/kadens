<?php

namespace App\Controller;

use App\Entity\Coaching;
use App\Entity\User;
use App\Enum\CoachingStatus;
use App\Repository\CoachingRepository;
use App\Repository\UserRepository;
use App\Security\Voter\CoachingVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page unique de la relation coach ↔ athlète, ouverte à **tout** utilisateur :
 * les deux sens (mes coachs / mes athlètes) et les demandes des deux sens y
 * cohabitent. Seule la fiche de travail d'un athlète vit ailleurs (/coach,
 * ROLE_COACH).
 *
 * On ne gère ici que le lien : demander, annuler, accepter, refuser, terminer. Aucun
 * contenu n'est créé ni consulté depuis ces routes.
 */
#[Route('/coaching')]
final class CoachingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CoachingRepository $coachingRepository,
    ) {
    }

    #[Route('', name: 'app_coaching_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Une demande envoyée se range dans la section de la relation qu'elle
        // propose (#79) : une invitation d'athlète sous « Mes athlètes », une
        // demande à un coach sous « Mes coachs ». Rangée à part en bas de page,
        // le coach la croyait perdue pendant que sa section disait « aucun athlète ».
        $sentAsCoach = [];
        $sentAsAthlete = [];
        foreach ($this->coachingRepository->findPendingSentBy($user) as $relation) {
            if ($relation->getCoach() === $user) {
                $sentAsCoach[] = $relation;
            } else {
                $sentAsAthlete[] = $relation;
            }
        }

        return $this->render('coaching/index.html.twig', [
            'coaches' => $this->coachingRepository->findAcceptedCoaches($user),
            'athletes' => $this->coachingRepository->findAcceptedAthletes($user),
            // Trace des relations terminées côté coach (#81) : un nom et des
            // dates, sans lien vers un contenu qui ne lui est plus ouvert.
            'formerAthletes' => $this->coachingRepository->findEndedAthletes($user),
            'received' => $this->coachingRepository->findPendingReceivedBy($user),
            'sentAsCoach' => $sentAsCoach,
            'sentAsAthlete' => $sentAsAthlete,
        ]);
    }

    /**
     * Demande de mise en relation par email exact (pas de recherche floue : on ne
     * veut pas d'annuaire parcourable). L'athlète cible forcément un ROLE_COACH ;
     * un coach, lui, peut démarcher n'importe quel utilisateur.
     *
     * Le pseudo n'est pas accepté (#79), et c'est voulu : il s'affiche partout,
     * se devine et se change à volonté, quand l'email reste l'identifiant du
     * compte. L'accepter ferait de ce formulaire un annuaire à tâtons, puisque
     * la réponse (et la carte « En attente ») dit qu'un compte existe. Le champ
     * est donc un texte libre, pour que ce soit le site, et non la bulle du
     * navigateur, qui explique pourquoi un pseudo ne suffit pas.
     *
     * Une relation refusée ou terminée est **réouverte en place** (retour à
     * PENDING) plutôt que dupliquée : l'UniqueConstraint l'interdirait de toute façon.
     */
    #[Route('/request', name: 'app_coaching_request', methods: ['POST'])]
    public function request(Request $request, UserRepository $userRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('coaching_request', $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $redirect = $this->redirectToRoute('app_coaching_index');

        $email = trim($payload->getString('email'));

        if ('' !== $email && !str_contains($email, '@')) {
            $this->addFlash('error', sprintf('« %s » n\'est pas une adresse email. On invite avec l\'email du compte Kadens, pas avec le pseudo.', $email));

            return $redirect;
        }

        $target = '' === $email ? null : $userRepository->findOneBy(['email' => $email]);

        if (null === $target) {
            $this->addFlash('error', sprintf('Aucun utilisateur Kadens avec l\'email « %s ».', $email));

            return $redirect;
        }

        if ($target === $user) {
            $this->addFlash('error', 'Tu ne peux pas te coacher toi-même.');

            return $redirect;
        }

        // Qui est coach, qui est athlète ? Déterminé par le rôle de la cible et
        // celui de l'émetteur, jamais par un champ de formulaire.
        $asCoach = $user->isCoach() && !$this->wantsCoach($payload->getString('role'));

        if (!$asCoach && !$target->isCoach()) {
            $this->addFlash('error', sprintf('%s n\'est pas coach sur Kadens.', $email));

            return $redirect;
        }

        $coach = $asCoach ? $user : $target;
        $athlete = $asCoach ? $target : $user;

        $existing = $this->coachingRepository->findAnyBetween($user, $target);

        if (null !== $existing) {
            // Relation déjà vivante (ou demande en cours) : rien à faire.
            if (CoachingStatus::ACCEPTED === $existing->getStatus()) {
                $this->addFlash('error', sprintf('Tu es déjà en relation avec %s.', $email));

                return $redirect;
            }

            if (CoachingStatus::PENDING === $existing->getStatus()) {
                $this->addFlash('error', sprintf('Une demande est déjà en attente avec %s.', $email));

                return $redirect;
            }

            // Refusée ou terminée : on réouvre la ligne existante, dans le sens
            // demandé cette fois-ci.
            $existing->setCoach($coach);
            $existing->setAthlete($athlete);
            $existing->setStatus(CoachingStatus::PENDING);
            $existing->setRequestedBy($user);
            $existing->setRespondedAt(null);
            $existing->setResponseSeenAt(null);
            $existing->setEndedAt(null);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Demande renvoyée à %s.', $email));

            return $redirect;
        }

        $coaching = (new Coaching())
            ->setCoach($coach)
            ->setAthlete($athlete)
            ->setStatus(CoachingStatus::PENDING)
            ->setRequestedBy($user);

        $this->entityManager->persist($coaching);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Demande envoyée à %s.', $email));

        return $redirect;
    }

    /**
     * Acceptation ou refus, réservé au destinataire (CoachingVoter::RESPOND :
     * l'émetteur ne peut pas répondre à sa propre demande).
     */
    #[Route('/{id}/respond', name: 'app_coaching_respond', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function respond(Request $request, Coaching $coaching): Response
    {
        $this->denyAccessUnlessGranted(CoachingVoter::RESPOND, $coaching);

        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('coaching_respond'.$coaching->getId(), $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        if (CoachingStatus::PENDING !== $coaching->getStatus()) {
            $this->addFlash('error', 'Cette demande a déjà été traitée.');

            return $this->redirectToRoute('app_coaching_index');
        }

        $accepted = 'accept' === $payload->getString('decision');
        $coaching->setStatus($accepted ? CoachingStatus::ACCEPTED : CoachingStatus::DECLINED);
        $coaching->setRespondedAt(new \DateTimeImmutable());
        // L'initiateur l'apprend sur son accueil (#79), jusqu'à ce qu'il ferme le bandeau.
        $coaching->setResponseSeenAt(null);
        $this->entityManager->flush();

        /** @var User $user */
        $user = $this->getUser();
        $other = $coaching->otherParty($user)?->getDisplayName() ?? 'cet utilisateur';

        $this->addFlash('success', $accepted
            ? sprintf('Relation acceptée avec %s.', $other)
            : sprintf('Demande de %s refusée.', $other));

        return $this->redirectToRoute('app_coaching_index');
    }

    /**
     * Fin de relation, à l'initiative de l'une ou l'autre partie. Le contenu déjà
     * créé n'est pas touché : il appartient à l'athlète, qui le garde. Seuls les
     * droits du coach tombent (les voters ne voient plus de relation ACCEPTED).
     *
     * Les notes privées du coach sur l'athlète (#65), elles, sont effacées : ce
     * sont des données sur une personne qu'il ne suit plus, souvent de santé.
     * La ligne survit (ENDED) et une nouvelle demande la rouvre : sans cet
     * effacement, un coach retrouverait des années plus tard ce qu'il notait.
     *
     * La date de fin est gardée (#81) : le coach retrouve la personne dans ses
     * « Anciens athlètes », et son ancienne fiche lui dit pourquoi elle est fermée.
     */
    #[Route('/{id}/end', name: 'app_coaching_end', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function end(Request $request, Coaching $coaching): Response
    {
        $this->denyAccessUnlessGranted(CoachingVoter::END, $coaching);

        if (!$this->isCsrfTokenValid('coaching_end'.$coaching->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $coaching->setStatus(CoachingStatus::ENDED);
        $coaching->setEndedAt(new \DateTimeImmutable());
        $coaching->setCoachNotes(null);
        $this->entityManager->flush();

        /** @var User $user */
        $user = $this->getUser();
        $other = $coaching->otherParty($user)?->getDisplayName() ?? 'cet utilisateur';

        $this->addFlash('success', sprintf('Relation terminée avec %s. Le contenu déjà créé reste chez son propriétaire.', $other));

        return $this->redirectToRoute('app_coaching_index');
    }

    /**
     * Retrait d'une demande encore sans réponse, par son seul initiateur (#79).
     * La ligne est supprimée : une demande annulée n'a jamais eu lieu, et c'est
     * le seul moyen de corriger une faute dans l'email. Le destinataire perd sa
     * demande reçue (bandeau d'accueil, carte « À traiter ») au chargement suivant.
     */
    #[Route('/{id}/cancel', name: 'app_coaching_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(Request $request, Coaching $coaching): Response
    {
        $this->denyAccessUnlessGranted(CoachingVoter::CANCEL, $coaching);

        if (!$this->isCsrfTokenValid('coaching_cancel'.$coaching->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // L'autre a pu répondre entre l'affichage et le clic.
        if (CoachingStatus::PENDING !== $coaching->getStatus()) {
            $this->addFlash('error', 'Cette demande a déjà reçu une réponse.');

            return $this->redirectToRoute('app_coaching_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $other = $coaching->otherParty($user)?->getDisplayName() ?? 'cet utilisateur';

        $this->entityManager->remove($coaching);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Demande à %s annulée.', $other));

        return $this->redirectToRoute('app_coaching_index');
    }

    /**
     * Ferme le bandeau d'accueil qui annonce la réponse à une demande (#79),
     * pour son seul initiateur.
     *
     * `then=athlete` (bouton « Programmer » d'un coach accepté) ouvre ensuite la
     * fiche de l'athlète. La destination est une route fixe, jamais une URL
     * reçue : rien à rediriger ailleurs.
     */
    #[Route('/{id}/acknowledge', name: 'app_coaching_acknowledge', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acknowledge(Request $request, Coaching $coaching): Response
    {
        $this->denyAccessUnlessGranted(CoachingVoter::ACKNOWLEDGE, $coaching);

        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid('coaching_acknowledge'.$coaching->getId(), $payload->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $coaching->setResponseSeenAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        /** @var User $user */
        $user = $this->getUser();
        $athlete = $coaching->getAthlete();

        if ('athlete' === $payload->getString('then')
            && $coaching->isActive()
            && $coaching->getCoach() === $user
            && $user->isCoach()
            && null !== $athlete
        ) {
            return $this->redirectToRoute('app_coach_athlete', ['id' => $athlete->getId()]);
        }

        return $this->redirectToRoute('app_home');
    }

    /**
     * Un coach qui demande explicitement à être coaché (champ `role=athlete` du
     * formulaire) inverse les rôles : il devient l'athlète de la relation.
     */
    private function wantsCoach(string $role): bool
    {
        return 'athlete' === $role;
    }
}
