<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La page d'aide (#72) : les textes des « ? » des écrans clés, regroupés, et
 * le mini-guide coach. Page statique, sans donnée : les textes vivent dans
 * `help/_topics.html.twig`, partagés avec le composant `_help`.
 */
final class HelpController extends AbstractController
{
    #[Route('/aide', name: 'app_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help/index.html.twig');
    }
}
