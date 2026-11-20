<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Le stockage des images d'un exercice (#38). Mêmes partis pris que
 * `GoalPhotoStorage` (hors de `public/`, nom aléatoire revérifié, aucune
 * extension d'image côté serveur), avec une exigence de plus : **le format est
 * imposé**.
 *
 * Les images d'exercice doivent se ressembler d'un bout à l'autre du site :
 * carré, même taille, même compression, logo Kadens en bas à droite. Tout ce
 * travail se fait dans le navigateur (`exercise_image_controller.js`), qui
 * produit un JPEG de `SIZE` × `SIZE`. Le serveur ne sait pas redessiner (pas de
 * GD au mutualisé), il sait en revanche **vérifier** : un fichier qui n'a pas
 * exactement ce format n'est pas passé par la préparation, il est refusé. C'est
 * ce qui garantit le 1:1 et la taille commune ; le logo, lui, n'est pas
 * vérifiable sans décoder les pixels.
 */
final class ExerciseImageStorage
{
    /** Côté du carré, en pixels. Doit rester égal à `SIZE` de `exercise_image_controller.js`. */
    public const SIZE = 1080;

    /** Un carré de 1080 px en JPEG à 0,82 pèse quelques centaines de Ko : 3 Mo laisse de la marge. */
    public const MAX_BYTES = 3 * 1024 * 1024;

    private const FILE_PATTERN = '/^[a-f0-9]{32}\.jpg$/';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    /**
     * Range une image préparée et renvoie son nom de fichier.
     *
     * @throws \InvalidArgumentException message lisible par l'utilisateur
     */
    public function store(UploadedFile $upload): string
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException(\in_array($upload->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'Image trop lourde pour le serveur.' : 'L\'envoi de l\'image a échoué.');
        }

        if ($upload->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image trop lourde (3 Mo au plus).');
        }

        $info = @getimagesize($upload->getPathname());
        if (false === $info || \IMAGETYPE_JPEG !== $info[2] || self::SIZE !== $info[0] || self::SIZE !== $info[1]) {
            throw new \InvalidArgumentException(sprintf('Image non préparée : elle doit passer par la mise en forme du navigateur (carré de %d px, JavaScript nécessaire).', self::SIZE));
        }

        $file = bin2hex(random_bytes(16)).'.jpg';
        $this->ensureDirectory();
        $upload->move($this->directory, $file);

        // Le canvas ne recopie aucune métadonnée ; ce nettoyage ne sert que pour
        // un fichier fabriqué à la main au bon format.
        $path = $this->path($file);
        $bytes = (string) file_get_contents($path);
        $stripped = GoalPhotoStorage::stripJpegMetadata($bytes);
        if ($stripped !== $bytes) {
            file_put_contents($path, $stripped);
        }

        return $file;
    }

    /**
     * Copie une image sous un nouveau nom, pour un exercice recopié chez un autre
     * compte (`SharedWorkoutCopier`) : deux exercices ne partagent jamais un
     * fichier, sinon supprimer l'un effacerait l'image de l'autre. Null quand
     * l'original manque.
     */
    public function duplicate(string $file): ?string
    {
        if (!$this->exists($file)) {
            return null;
        }

        $copy = bin2hex(random_bytes(16)).'.jpg';
        $this->ensureDirectory();

        return copy($this->path($file), $this->path($copy)) ? $copy : null;
    }

    /** Chemin absolu ; le nom est revérifié, rien d'autre qu'un nom de `store()` ne sort d'ici. */
    public function path(string $file): string
    {
        if (1 !== preg_match(self::FILE_PATTERN, $file)) {
            throw new \InvalidArgumentException('Nom d\'image invalide.');
        }

        return $this->directory.'/'.$file;
    }

    public function exists(string $file): bool
    {
        return 1 === preg_match(self::FILE_PATTERN, $file) && is_file($this->path($file));
    }

    /**
     * Null quand le fichier manque. Le nom est aléatoire et ne sert qu'une fois :
     * longue durée de cache. Privé, l'appelant le dit déjà en vérifiant le droit
     * de lecture.
     */
    public function response(string $file): ?BinaryFileResponse
    {
        if (!$this->exists($file)) {
            return null;
        }

        $response = new BinaryFileResponse($this->path($file));
        $response->setMaxAge(30 * 24 * 3600);
        $response->setPrivate();
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Idempotent : un fichier déjà parti n'est pas une erreur. */
    public function delete(string $file): void
    {
        if ($this->exists($file)) {
            @unlink($this->path($file));
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Dossier d\'images impossible à créer : %s', $this->directory));
        }
    }
}
