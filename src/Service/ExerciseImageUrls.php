<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Exercise;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les adresses des images d'un exercice pour l'app mobile (#38) : absolues et
 * **signées**, servies par `app_exercise_media` hors pare-feu applicatif. Le
 * téléphone ne pose pas son jeton sur une requête d'image (`expo-image`), même
 * raison que pour l'image d'un message programmé (`CustomMessageInbox`).
 *
 * **Sans date d'expiration**, et c'est l'écart avec les messages : le téléphone
 * garde la bibliothèque et ne la reçoit à nouveau que quand un exercice change
 * (delta `?since`). Une URL qui expirerait mourrait chez lui sans qu'aucun pull
 * ne la remplace. Ce qui la révoque, c'est l'exercice : la route ne sert que les
 * noms qu'il cite **aujourd'hui**. Retirer une image tue son adresse, et la
 * liste change, donc `updatedAt` aussi, donc le prochain delta la renvoie.
 *
 * Le nom de fichier est déjà aléatoire (128 bits) ; la signature empêche en plus
 * de servir une image sous l'id d'un autre exercice.
 */
final class ExerciseImageUrls
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'uri_signer')]
        private readonly UriSigner $signer,
    ) {
    }

    /**
     * @return list<string>
     */
    public function signed(Exercise $exercise): array
    {
        $id = $exercise->getId();
        if (null === $id) {
            return [];
        }

        return array_map(
            fn (string $file): string => $this->signer->sign($this->urls->generate('app_exercise_media', ['id' => $id, 'file' => $file], UrlGeneratorInterface::ABSOLUTE_URL)),
            $exercise->getImages(),
        );
    }
}
