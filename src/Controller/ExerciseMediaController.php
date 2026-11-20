<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Exercise;
use App\Repository\ExerciseRepository;
use App\Service\ExerciseImageStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Les images d'un exercice pour l'app mobile (#38), hors pare-feu applicatif
 * (`PUBLIC_ACCESS` sur `^/media/exercise`) : la signature posée par
 * `ExerciseImageUrls` fait office de droit de lecture, le téléphone ne pose pas
 * son jeton sur une requête d'image. Le web garde `app_exercise_image`, sous
 * session et `ExerciseVoter::VIEW`.
 *
 * Le nom doit être cité par l'exercice **aujourd'hui** : une image retirée ne se
 * sert plus, même sous une adresse bien signée. Tout refus rend la même 404
 * qu'un exercice inconnu.
 */
final class ExerciseMediaController extends AbstractController
{
    #[Route('/media/exercise/{id}/{file}', name: 'app_exercise_media', methods: ['GET'], requirements: ['id' => Requirement::DIGITS, 'file' => '[a-f0-9]{32}\.jpg'])]
    public function __invoke(
        int $id,
        string $file,
        Request $request,
        ExerciseRepository $exercises,
        ExerciseImageStorage $storage,
        #[Autowire(service: 'uri_signer')]
        UriSigner $signer,
    ): Response {
        if (!$signer->checkRequest($request)) {
            throw $this->createNotFoundException('Image introuvable.');
        }

        $exercise = $exercises->find($id);
        if (!$exercise instanceof Exercise || !$exercise->hasImage($file)) {
            throw $this->createNotFoundException('Image introuvable.');
        }

        return $storage->response($file) ?? throw $this->createNotFoundException('Image introuvable.');
    }
}
