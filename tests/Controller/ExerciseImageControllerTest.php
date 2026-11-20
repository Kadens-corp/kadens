<?php

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Block;
use App\Entity\Exercise;
use App\Entity\PrescribedExercise;
use App\Entity\User;
use App\Entity\Workout;
use App\Enum\ActivityType;
use App\Enum\BlockRole;
use App\Enum\PrescriptionType;
use App\Service\ExerciseImageStorage;
use App\Tests\BuildsJpeg;
use App\Tests\PurgesDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les images d'un exercice (#38).
 *
 * Ce que ces tests protègent : le format commun (seul un JPEG carré de
 * `SIZE` px, donc passé par la mise en forme du navigateur, entre), les droits
 * de l'exercice (EDIT pour poser, VIEW pour voir), l'ordre de lecture, et le
 * disque qui suit la base (rien d'orphelin après un retrait ou une suppression,
 * rien de rangé après un envoi refusé).
 */
final class ExerciseImageControllerTest extends WebTestCase
{
    use BuildsJpeg;
    use PurgesDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ExerciseImageStorage $storage;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->storage = static::getContainer()->get(ExerciseImageStorage::class);

        $this->purgeDatabase($this->em);
    }

    public function testAnAdminIllustratesAGlobalExerciseSeenByEveryone(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createUser('member@example.com');
        $squat = $this->createExercise(null, 'Squat');
        $this->client->loginUser($admin);

        $this->upload($squat, [self::frame(), self::frame()]);

        self::assertResponseRedirects(sprintf('/exercise/%d/edit#images', $squat->getId()));
        $images = $this->reload($squat)->getImages();
        self::assertCount(2, $images);
        foreach ($images as $file) {
            self::assertFileExists($this->storage->path($file));
            self::assertStringNotContainsString(self::EXIF_MARKER, (string) file_get_contents($this->storage->path($file)));
        }

        // La globale se lit par tous : l'image aussi, et la fiche la joue.
        $this->client->loginUser($member);
        $this->client->request('GET', sprintf('/exercise/%d/image/%s', $squat->getId(), $images[0]));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        $crawler = $this->client->request('GET', '/exercise/'.$squat->getId());
        self::assertCount(1, $crawler->filter('.kd-exframes--sheet[data-controller="exercise-frames"]'));
        self::assertCount(2, $crawler->filter('.kd-exframes--sheet .kd-exframes__frame'));
        self::assertCount(1, $crawler->filter('.kd-exframes--sheet .kd-exframes__frame.is-active'));

        // Mais seul l'admin la retouche.
        $this->upload($squat, [self::frame()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testASingleImageShowsWithoutPlayer(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);

        $this->upload($row, [self::frame()]);

        $crawler = $this->client->request('GET', '/exercise/'.$row->getId());
        self::assertCount(1, $crawler->filter('.kd-exframes--sheet .kd-exframes__frame'));
        self::assertCount(0, $crawler->filter('.kd-exframes--sheet[data-controller="exercise-frames"]'));
    }

    public function testAPersonalExerciseImageStaysPrivate(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame()]);
        [$file] = $this->reload($row)->getImages();

        $this->client->loginUser($stranger);
        $this->client->request('GET', sprintf('/exercise/%d/image/%s', $row->getId(), $file));
        self::assertResponseStatusCodeSame(403);

        $this->upload($row, [self::frame()]);
        self::assertResponseStatusCodeSame(403);
    }

    /** L'id de l'adresse ne sert pas l'image d'un autre exercice. */
    public function testAnImageIsOnlyServedUnderItsOwnExercise(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $other = $this->createExercise($owner, 'Pompes');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame()]);
        [$file] = $this->reload($row)->getImages();

        $this->client->request('GET', sprintf('/exercise/%d/image/%s', $other->getId(), $file));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Pas carré, pas à la taille, pas un JPEG : l'image n'est pas passée par la
     * mise en forme. Et un envoi est tout ou rien : la bonne image d'un lot
     * refusé ne reste pas sur le disque.
     */
    public function testAnImageThatWasNotPreparedIsRefused(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $before = $this->storedFiles();

        foreach ([self::jpeg(1080, 720), self::jpeg(800, 800), self::png(1080, 1080), '<?php echo "bonjour";'] as $bytes) {
            $this->upload($row, [$bytes]);
            self::assertResponseRedirects(sprintf('/exercise/%d/edit#images', $row->getId()));
        }

        $this->upload($row, [self::frame(), self::jpeg(1080, 720)]);

        self::assertSame([], $this->reload($row)->getImages());
        self::assertSame($before, $this->storedFiles());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-toasts', 'Image non préparée');
    }

    public function testTheLimitHolds(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);

        $this->upload($row, array_fill(0, Exercise::MAX_IMAGES - 1, self::frame()));
        self::assertCount(Exercise::MAX_IMAGES - 1, $this->reload($row)->getImages());

        $this->upload($row, [self::frame(), self::frame()]);
        self::assertCount(Exercise::MAX_IMAGES - 1, $this->reload($row)->getImages());

        $this->upload($row, [self::frame()]);
        self::assertCount(Exercise::MAX_IMAGES, $this->reload($row)->getImages());

        // Au maximum, l'éditeur ne propose plus d'ajout.
        $crawler = $this->client->request('GET', sprintf('/exercise/%d/edit', $row->getId()));
        self::assertCount(0, $crawler->filter('.kd-imgstrip__add'));
    }

    public function testOrderAndRemovalFollowTheEditorAndTheDisk(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame(), self::frame()]);
        [$start, $end] = $this->reload($row)->getImages();

        $this->post($row, sprintf('/image/%s/move', $end), ['direction' => 'up']);
        self::assertSame([$end, $start], $this->reload($row)->getImages());

        // En tête, monter encore ne fait rien.
        $this->post($row, sprintf('/image/%s/move', $end), ['direction' => 'up']);
        self::assertSame([$end, $start], $this->reload($row)->getImages());

        $this->post($row, sprintf('/image/%s/delete', $end));
        self::assertSame([$start], $this->reload($row)->getImages());
        self::assertFileDoesNotExist($this->storage->path($end));
        self::assertFileExists($this->storage->path($start));

        // Un nom que l'exercice ne cite pas : 404, rien ne bouge.
        $this->post($row, sprintf('/image/%s/delete', $end));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingTheExerciseClearsItsImages(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame(), self::frame()]);
        $paths = array_map($this->storage->path(...), $this->reload($row)->getImages());

        $exercise = $this->reload($row);
        $this->em->remove($exercise);
        $this->em->flush();

        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    public function testEveryGestureNeedsACsrfToken(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);

        $this->client->request('POST', sprintf('/exercise/%d/images', $row->getId()), ['_token' => 'faux'], ['images' => [$this->uploaded(self::frame())]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->reload($row)->getImages());
    }

    /**
     * La planche : salle, mobilité et « autre » seulement, et seulement ce que
     * le lecteur peut éditer. Un admin y voit la globale, un membre ses perso.
     */
    public function testTheBoardListsWhatTheReaderCanIllustrate(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $member = $this->createUser('member@example.com');
        $this->createExercise(null, 'Squat');
        $this->createExercise(null, 'Étirement psoas', ActivityType::MOBILITY);
        $this->createExercise(null, 'Corde à sauter', ActivityType::OTHER);
        $this->createExercise(null, 'Fractionné 400 m', ActivityType::RUNNING);
        $this->createExercise(null, 'Sortie longue vélo', ActivityType::CYCLING);
        $this->createExercise($member, 'Tirage maison');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/exercise/images');
        self::assertResponseIsSuccessful();
        $names = $crawler->filter('.kd-imgboard__name')->each(static fn ($node): string => $node->text());
        sort($names);
        self::assertSame(['Corde à sauter', 'Squat', 'Étirement psoas'], $names);
        self::assertCount(3, $crawler->filter('.kd-imgstrip__add'));
        self::assertCount(1, $crawler->filter('dialog[data-exercise-images-target="dialog"]'));

        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/exercise/images');
        self::assertSame(['Tirage maison'], $crawler->filter('.kd-imgboard__name')->each(static fn ($node): string => $node->text()));
    }

    public function testTheLibraryAnnouncesTheBoardOnlyToWhoCanUseIt(): void
    {
        $member = $this->createUser('member@example.com');
        $this->createExercise(null, 'Squat');
        $this->client->loginUser($member);

        $this->client->request('GET', '/exercise');
        self::assertSelectorNotExists('a[href="/exercise/images"]');

        $this->createExercise($this->refetch($member), 'Fractionné maison', ActivityType::RUNNING);
        $this->client->request('GET', '/exercise');
        self::assertSelectorNotExists('a[href="/exercise/images"]');

        $this->createExercise($this->refetch($member), 'Tirage maison');
        $this->client->request('GET', '/exercise');
        self::assertSelectorExists('a[href="/exercise/images"]');
    }

    public function testOnlyGymMobilityAndOtherTakeImages(): void
    {
        $owner = $this->createUser('owner@example.com');
        $run = $this->createExercise($owner, 'Fractionné');
        $this->client->loginUser($owner);
        // Jeton lu tant que l'exercice est de salle : passé en course, son
        // éditeur n'a plus de section Images où le prendre.
        $token = $this->token($run);
        $exercise = $this->reload($run);
        $exercise->setActivity(ActivityType::RUNNING);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/exercise/%d/edit', $run->getId()));
        self::assertCount(0, $crawler->filter('#images'));

        $this->client->request('POST', sprintf('/exercise/%d/images', $run->getId()), ['_token' => $token], ['images' => [$this->uploaded(self::frame())]]);

        self::assertSame([], $this->reload($run)->getImages());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.kd-toasts', 'salle, de mobilité');
    }

    /**
     * Images posées, puis l'exercice change d'activité : elles restent
     * lisibles et retirables, on ne peut plus en ajouter.
     */
    public function testImagesLeftOnAnotherActivityCanStillBeRemoved(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame()]);
        [$file] = $this->reload($row)->getImages();

        $exercise = $this->reload($row);
        $exercise->setActivity(ActivityType::RUNNING);
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/exercise/%d/edit', $row->getId()));
        self::assertCount(1, $crawler->filter('#images .kd-imgstrip__thumb'));
        self::assertCount(0, $crawler->filter('#images .kd-imgstrip__add'));

        $this->post($row, sprintf('/image/%s/delete', $file));
        self::assertSame([], $this->reload($row)->getImages());
    }

    /**
     * Avec Turbo, un geste ne renvoie que la bande de l'exercice touché ; sans,
     * il revient à l'écran d'origine, à la hauteur de l'exercice.
     */
    public function testGesturesAnswerWithTheStripOrGoBackWhereTheyCameFrom(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);

        $token = $this->token($row);
        $this->client->request('POST', sprintf('/exercise/%d/images', $row->getId()), ['_token' => $token, 'from' => 'board'], ['images' => [$this->uploaded(self::frame())]], [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html',
        ]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('target="exercise-images-%d"', $row->getId()), $body);
        self::assertStringContainsString('kd-imgstrip__thumb', $body);
        // Le retour sans JS des gestes de la bande garde son écran d'origine.
        self::assertStringContainsString('name="from" value="board"', $body);

        [$file] = $this->reload($row)->getImages();
        $this->client->request('POST', sprintf('/exercise/%d/image/%s/delete', $row->getId(), $file), ['_token' => $token, 'from' => 'board']);
        self::assertResponseRedirects(sprintf('/exercise/images#exercise-row-%d', $row->getId()));
    }

    /**
     * L'œil : sur la lecture d'une séance et au compositeur, pour un exercice
     * illustré seulement, avec ses adresses ; la visionneuse est posée une fois
     * par la page.
     */
    public function testTheEyeOpensTheMovementWhereTheSessionShowsIt(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $plain = $this->createExercise($owner, 'Pompes');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame(), self::frame()]);
        $illustrated = $this->reload($row);
        $workout = $this->createWorkout($this->refetch($owner), [$illustrated, $this->em->find(Exercise::class, $plain->getId()) ?? throw new \LogicException()]);
        [$start, $end] = $this->reload($row)->getImages();

        foreach (['/workout/%d', '/workout/%d/edit'] as $pattern) {
            $crawler = $this->client->request('GET', sprintf($pattern, $workout->getId()));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('dialog.kd-modal--exviewer'), $pattern);

            $eyes = $crawler->filter('.kd-exeye');
            self::assertCount(1, $eyes, $pattern);
            self::assertSame('Tirage', $eyes->attr('data-exercise-viewer-name-param'));
            self::assertSame([
                sprintf('/exercise/%d/image/%s', $row->getId(), $start),
                sprintf('/exercise/%d/image/%s', $row->getId(), $end),
            ], json_decode((string) $eyes->attr('data-exercise-viewer-images-param'), true));
        }
    }

    /**
     * Le téléphone : des adresses absolues, signées, sans date, qui se servent
     * sans session ni jeton, et meurent avec l'image qu'elles désignent.
     */
    public function testThePhoneGetsSignedImageUrlsThatNeedNoSession(): void
    {
        $owner = $this->createUser('owner@example.com');
        $row = $this->createExercise($owner, 'Tirage');
        $this->client->loginUser($owner);
        $this->upload($row, [self::frame(), self::frame()]);
        [$start] = $this->reload($row)->getImages();

        $secret = ApiToken::generateSecret();
        $this->em->persist(new ApiToken($this->refetch($owner), 'Pixel de test', $secret));
        $this->em->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/bootstrap', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $urls = array_column($payload['exercises'], 'images', 'name');
        self::assertSame([], $urls['Pompes'] ?? []);
        self::assertCount(2, $urls['Tirage']);
        [$url] = $urls['Tirage'];
        self::assertStringStartsWith(sprintf('http://localhost/media/exercise/%d/%s?', $row->getId(), $start), $url);
        self::assertStringContainsString('_hash=', $url);
        self::assertStringNotContainsString('_expiration', $url);

        // Sans cookie ni jeton : la signature suffit.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        // Une signature trafiquée, ou sans signature : la même 404.
        $this->client->request('GET', substr($url, 0, -3).'abc');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/media/exercise/%d/%s', $row->getId(), $start));
        self::assertResponseStatusCodeSame(404);

        // L'image retirée, son adresse ne sert plus rien.
        $exercise = $this->reload($row);
        $exercise->removeImage($start);
        $this->em->flush();
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<Exercise> $exercises
     */
    private function createWorkout(User $owner, array $exercises): Workout
    {
        $workout = (new Workout())
            ->setOwner($owner)
            ->setTitle('Dos')
            ->setSlug('dos-'.bin2hex(random_bytes(6)));
        $this->em->persist($workout);

        $block = (new Block())->setRole(BlockRole::MAIN)->setRounds(1)->setPosition(0);
        $workout->addBlock($block);
        $this->em->persist($block);

        foreach ($exercises as $position => $exercise) {
            $block->addPrescribedExercise(
                (new PrescribedExercise())
                    ->setExercise($exercise)
                    ->setPosition($position)
                    ->setPrescriptionType(PrescriptionType::SETS_REPS)
                    ->setSets(3)->setReps(8),
            );
        }
        $this->em->flush();

        return $workout;
    }

    private static function frame(): string
    {
        return self::jpeg(ExerciseImageStorage::SIZE, ExerciseImageStorage::SIZE);
    }

    /** Un en-tête PNG que `getimagesize()` lit : signature et IHDR suffisent. */
    private static function png(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    /**
     * @param list<string> $images
     */
    private function upload(Exercise $exercise, array $images): void
    {
        $this->client->request(
            'POST',
            sprintf('/exercise/%d/images', $exercise->getId()),
            ['_token' => $this->token($exercise)],
            ['images' => array_map($this->uploaded(...), $images)],
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Exercise $exercise, string $suffix, array $fields = []): void
    {
        $this->client->request('POST', sprintf('/exercise/%d%s', $exercise->getId(), $suffix), $fields + ['_token' => $this->token($exercise)]);
    }

    private function uploaded(string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kd-upload');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'frame.jpg', null, null, true);
    }

    /** Le jeton, lu sur l'éditeur comme le ferait le navigateur. */
    private function token(Exercise $exercise): string
    {
        $crawler = $this->client->request('GET', sprintf('/exercise/%d/edit', $exercise->getId()));
        $strip = sprintf('#exercise-images-%d', $exercise->getId());
        $add = $crawler->filter($strip.' [data-exercise-images-token-param]');
        if ($add->count() > 0) {
            return (string) $add->first()->attr('data-exercise-images-token-param');
        }
        $field = $crawler->filter($strip.' input[name="_token"]');

        return $field->count() > 0 ? (string) $field->first()->attr('value') : 'absent';
    }

    /** Après une requête, le noyau a redémarré : l'entité d'avant est détachée. */
    private function refetch(User $user): User
    {
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');

        return $this->em->find(User::class, $user->getId()) ?? throw new \LogicException('Utilisateur introuvable.');
    }

    private function reload(Exercise $exercise): Exercise
    {
        $this->em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em->clear();

        return $this->em->find(Exercise::class, $exercise->getId()) ?? throw new \LogicException('Exercice introuvable.');
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $directory = \dirname($this->storage->path(str_repeat('0', 32).'.jpg'));
        $entries = is_dir($directory) ? scandir($directory) : false;
        $files = false === $entries ? [] : array_values(array_diff($entries, ['.', '..']));
        sort($files);

        return $files;
    }

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createExercise(?User $owner, string $name, ActivityType $activity = ActivityType::GYM): Exercise
    {
        $exercise = (new Exercise())
            ->setOwner($owner)
            ->setName($name)
            ->setActivity($activity);

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }
}
