<?php

namespace App\Tests\Controller;

use App\Http\NoLongerCoachException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Twig\Environment;

/**
 * Les pages d'erreur ne se rendent qu'en prod : `TwigErrorRenderer` court-circuite
 * ses templates dès que `kernel.debug` est vrai, ce qui est le cas en test. Une
 * requête HTTP ne les exercerait donc jamais — on rend les templates directement,
 * ce qui suffit à attraper ce qu'on veut attraper : une variable inexistante
 * (`strict_variables` est activé en test), un filtre absent, une route morte.
 */
final class ErrorPageTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function errorTemplates(): iterable
    {
        yield '404' => ['error404.html.twig', 404, 'Page introuvable'];
        yield '403' => ['error403.html.twig', 403, 'Accès refusé'];
        yield '500' => ['error500.html.twig', 500, 'Erreur serveur'];
        yield 'générique 503' => ['error.html.twig', 503, 'Service indisponible'];
        yield 'générique 429' => ['error.html.twig', 429, 'Requête refusée'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorTemplates')]
    public function testErrorTemplateRenders(string $template, int $statusCode, string $expected): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // `app.request` est consulté par le squelette (adresse demandée, lien de
        // réessai) : sans requête empilée, on ne testerait pas le vrai chemin.
        $container->get(RequestStack::class)->push(Request::create('/chemin/inexistant'));

        $exception = FlattenException::createFromThrowable(new HttpException($statusCode));

        $html = $container->get(Environment::class)->render(
            '@Twig/Exception/'.$template,
            [
                'exception' => $exception,
                'status_code' => $exception->getStatusCode(),
                'status_text' => $exception->getStatusText(),
            ],
        );

        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString((string) $statusCode, $html);
        self::assertStringContainsString('kd-error', $html);
    }

    public function testNotFoundPageEchoesRequestedUri(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(RequestStack::class)->push(Request::create('/workout/999999'));

        $exception = FlattenException::createFromThrowable(new HttpException(404));
        $html = $container->get(Environment::class)->render('@Twig/Exception/error404.html.twig', [
            'exception' => $exception,
            'status_code' => 404,
            'status_text' => 'Not Found',
        ]);

        self::assertStringContainsString('/workout/999999', $html);
    }

    /**
     * #81 : l'ancien coach qui rouvre la fiche d'un athlète lit pourquoi elle
     * est fermée, pas « ce contenu ne t'appartient pas ». Le template reconnaît
     * l'exception à sa classe : renommer la classe sans le template casse ici.
     */
    public function testForbiddenPageExplainsAnEndedCoaching(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(RequestStack::class)->push(Request::create('/coach/athlete/12'));
        $twig = $container->get(Environment::class);

        $render = static fn (\Throwable $error): string => $twig->render('@Twig/Exception/error403.html.twig', [
            'exception' => FlattenException::createFromThrowable($error),
            'status_code' => 403,
            'status_text' => 'Forbidden',
        ]);

        $ended = $render(new NoLongerCoachException());
        self::assertStringContainsString('Tu ne suis plus cet athlète', $ended);
        self::assertStringContainsString('Anciens athlètes', $ended);
        self::assertStringNotContainsString('ne t&#039;appartient pas', $ended);

        $generic = $render(new HttpException(403));
        self::assertStringContainsString('Accès refusé', $generic);
        self::assertStringNotContainsString('Tu ne suis plus cet athlète', $generic);
    }

    /**
     * Le rouge est réservé au vrai échec serveur : une 404 ne doit pas se peindre
     * comme une panne (cf. CLAUDE.md §5, règle 2).
     */
    public function testOnlyServerFaultsAreRed(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(RequestStack::class)->push(Request::create('/'));
        $twig = $container->get(Environment::class);

        $render = static fn (string $template, int $code): string => $twig->render(
            '@Twig/Exception/'.$template,
            [
                'exception' => FlattenException::createFromThrowable(new HttpException($code)),
                'status_code' => $code,
                'status_text' => 'Test',
            ],
        );

        self::assertStringContainsString('kd-error--fault', $render('error500.html.twig', 500));
        self::assertStringNotContainsString('kd-error--fault', $render('error404.html.twig', 404));
        self::assertStringNotContainsString('kd-error--fault', $render('error403.html.twig', 403));
    }
}
