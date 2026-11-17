<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IntervalsAutoSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Points d'entrée du planificateur de tâches Infomaniak, qui ne sait qu'appeler
 * une URL (pas de cron shell sur le mutualisé).
 *
 * Route publique (`^/cron` en PUBLIC_ACCESS), gardée par un secret partagé passé
 * en `?token=`. Garder l'URL configurée privée ; changer `APP_CRON_SECRET` si
 * elle fuite. Secret vide = route fermée (403), c'est le défaut.
 */
final class CronController extends AbstractController
{
    #[Route('/cron/intervals', name: 'app_cron_intervals', methods: ['GET'])]
    public function intervals(
        IntervalsAutoSync $autoSync,
        #[Autowire('%env(APP_CRON_SECRET)%')] string $secret,
        #[MapQueryParameter] string $token = '',
    ): JsonResponse {
        if ('' === $secret || !hash_equals($secret, $token)) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['status' => 'ok', ...$autoSync->run()]);
    }
}
