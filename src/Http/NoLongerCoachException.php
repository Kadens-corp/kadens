<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Refus d'accès à la fiche d'un athlète que l'on a entraîné (#81) : la relation
 * est terminée. Sans elle, l'ancien lien tombait sur le 403 générique, qui parle
 * de contenu « qui ne t'appartient pas » à quelqu'un qui le programmait la veille.
 *
 * C'est une `HttpException`, et non l'`AccessDeniedException` du composant
 * Security : le pare-feu la laisse passer telle quelle jusqu'au rendu d'erreur,
 * dont le template 403 reconnaît la classe (`exception.class`) pour changer de
 * texte. Le template ne fait aucune requête : c'est le contrôleur qui a
 * tranché, l'exception ne porte que sa conclusion.
 */
final class NoLongerCoachException extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('Tu ne suis plus cet athlète.');
    }
}
