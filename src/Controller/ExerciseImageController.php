<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exercise;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Repository\ExerciseRepository;
use App\Security\Voter\ExerciseVoter;
use App\Service\ExerciseImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Les images d'un exercice (#38) : une ou plusieurs, jouées en fondu enchaîné
 * sur sa fiche pour montrer le mouvement (typiquement le début puis la fin).
 *
 * Les droits sont ceux de l'exercice : `EDIT` pour ajouter, ranger, retirer
 * (un `ROLE_ADMIN` sur la globale, le propriétaire sur un perso), `VIEW` pour
 * voir. L'ajout est réservé à la salle, la mobilité et « autre »
 * (`Exercise::acceptsImages`). La mise en forme (carré, taille, compression,
 * logo) se fait dans le navigateur ; `ExerciseImageStorage` refuse ce qui n'y est
 * pas passé.
 *
 * Deux écrans posent les gestes : la planche (`/exercise/images`, tous les
 * exercices éligibles en liste) et la section « Images » de l'éditeur. Avec
 * Turbo, chaque geste répond par un Turbo Stream qui ne remplace que la bande
 * d'images de l'exercice touché (`#exercise-images-{id}`) : la planche ne se
 * recharge pas et ne remonte pas. Sans JS, il revient à l'écran d'origine
 * (`from`), à la hauteur de l'exercice.
 */
#[Route('/exercise')]
final class ExerciseImageController extends AbstractController
{
    private const FILE = '[a-f0-9]{32}\.jpg';

    /**
     * La planche : les exercices qu'on peut illustrer (droit `EDIT` et activité
     * éligible), leurs images en vignettes, un ajout par ligne. Filtrée côté
     * client, comme la bibliothèque.
     */
    #[Route('/images', name: 'app_exercise_images', methods: ['GET'])]
    public function board(ExerciseRepository $exercises): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $rows = array_values(array_filter(
            $exercises->findLibraryForUser($user),
            fn (Exercise $exercise): bool => $exercise->acceptsImages() && $this->isGranted(ExerciseVoter::EDIT, $exercise),
        ));

        $counts = [];
        $illustrated = 0;
        foreach ($rows as $exercise) {
            $activity = $exercise->getActivity()->value ?? '';
            $counts[$activity] = ($counts[$activity] ?? 0) + 1;
            if ([] !== $exercise->getImages()) {
                ++$illustrated;
            }
        }

        $activityFacets = [];
        foreach (ActivityType::cases() as $case) {
            if (isset($counts[$case->value])) {
                $activityFacets[] = ['value' => $case->value, 'label' => $case->getLabel(), 'count' => $counts[$case->value]];
            }
        }

        return $this->render('exercise/images.html.twig', [
            'exercises' => $rows,
            'activityFacets' => $activityFacets,
            'illustrated' => $illustrated,
        ]);
    }

    /**
     * Ajoute en fin de liste. La planche envoie une image à la fois ; plusieurs
     * restent acceptées, dans l'ordre reçu. Tout ou rien : si une image d'un
     * envoi est refusée, celles déjà rangées repartent du disque et la liste ne
     * bouge pas.
     */
    #[Route('/{id}/images', name: 'app_exercise_image_add', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function add(Request $request, Exercise $exercise, ExerciseImageStorage $storage, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::EDIT, $exercise);
        $this->assertToken($request, $exercise);

        if (!$exercise->acceptsImages()) {
            return $this->back($request, $exercise, 'Les images ne concernent que les exercices de salle, de mobilité et « autre ».');
        }

        $uploads = array_values(array_filter($request->files->all('images'), static fn (mixed $file): bool => $file instanceof UploadedFile));
        if ([] === $uploads) {
            return $this->back($request, $exercise, 'Choisis une image.');
        }

        $room = Exercise::MAX_IMAGES - \count($exercise->getImages());
        if (\count($uploads) > $room) {
            return $this->back($request, $exercise, sprintf('%d images au plus par exercice.', Exercise::MAX_IMAGES));
        }

        $stored = [];
        try {
            foreach ($uploads as $upload) {
                $stored[] = $storage->store($upload);
            }
        } catch (\InvalidArgumentException $exception) {
            foreach ($stored as $file) {
                $storage->delete($file);
            }

            return $this->back($request, $exercise, $exception->getMessage());
        }

        $exercise->setImages([...$exercise->getImages(), ...$stored]);
        $entityManager->flush();

        return $this->back($request, $exercise);
    }

    #[Route('/{id}/image/{file}/move', name: 'app_exercise_image_move', methods: ['POST'], requirements: ['id' => Requirement::DIGITS, 'file' => self::FILE])]
    public function move(Request $request, Exercise $exercise, string $file, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::EDIT, $exercise);
        $this->assertToken($request, $exercise);
        $this->assertCited($exercise, $file);

        $exercise->moveImage($file, 'up' === $request->getPayload()->getString('direction') ? -1 : 1);
        $entityManager->flush();

        return $this->back($request, $exercise);
    }

    /** Le fichier part du disque après le flush (`ExerciseImageCleanupListener`). */
    #[Route('/{id}/image/{file}/delete', name: 'app_exercise_image_delete', methods: ['POST'], requirements: ['id' => Requirement::DIGITS, 'file' => self::FILE])]
    public function delete(Request $request, Exercise $exercise, string $file, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::EDIT, $exercise);
        $this->assertToken($request, $exercise);
        $this->assertCited($exercise, $file);

        $exercise->removeImage($file);
        $entityManager->flush();

        return $this->back($request, $exercise);
    }

    /**
     * Sert une image à qui peut lire l'exercice. Seul un nom cité par cet
     * exercice se sert : l'id de l'adresse ne peut pas ouvrir l'image d'un autre.
     */
    #[Route('/{id}/image/{file}', name: 'app_exercise_image', methods: ['GET'], requirements: ['id' => Requirement::DIGITS, 'file' => self::FILE])]
    public function show(Exercise $exercise, string $file, ExerciseImageStorage $storage): Response
    {
        $this->denyAccessUnlessGranted(ExerciseVoter::VIEW, $exercise);
        $this->assertCited($exercise, $file);

        return $storage->response($file) ?? throw $this->createNotFoundException();
    }

    private function assertToken(Request $request, Exercise $exercise): void
    {
        if (!$this->isCsrfTokenValid('exercise-images'.$exercise->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function assertCited(Exercise $exercise, string $file): void
    {
        if (!$exercise->hasImage($file)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * La réponse commune des gestes. Un succès ne dit rien de plus : la bande
     * d'images qui change suffit. Une erreur part en toast.
     */
    private function back(Request $request, Exercise $exercise, ?string $error = null): Response
    {
        if (null !== $error) {
            $this->addFlash('error', $error);
        }

        $from = 'board' === $request->getPayload()->getString('from') ? 'board' : 'edit';

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('exercise/stream/image_strip.stream.html.twig', ['exercise' => $exercise, 'from' => $from]);
        }

        if ('board' === $from) {
            return $this->redirect($this->generateUrl('app_exercise_images').'#exercise-row-'.$exercise->getId());
        }

        return $this->redirect($this->generateUrl('app_exercise_edit', ['id' => $exercise->getId()]).'#images');
    }
}
