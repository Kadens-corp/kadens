<?php

namespace App\Security\Voter;

use App\Entity\Coaching;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès à la relation elle-même (pas au contenu : c'est le rôle des
 * voters Workout/PlanTemplate/ScheduledWorkout, via CoachingResolver).
 *
 * - VIEW : les deux parties.
 * - RESPOND : le seul destinataire de la demande. L'émetteur ne peut pas
 *   auto-accepter, sinon n'importe qui s'auto-nommerait coach.
 * - END : les deux parties (chacun peut mettre fin à la relation).
 * - CANCEL : le seul initiateur, pour retirer sa demande tant qu'elle attend
 *   (#79 : une faute dans l'email ne se corrigeait pas).
 * - ACKNOWLEDGE : le seul initiateur, pour fermer le bandeau qui lui annonce
 *   la réponse.
 *
 * @extends Voter<string, Coaching>
 */
final class CoachingVoter extends Voter
{
    public const VIEW = 'COACHING_VIEW';
    public const RESPOND = 'COACHING_RESPOND';
    public const END = 'COACHING_END';
    public const CANCEL = 'COACHING_CANCEL';
    public const ACKNOWLEDGE = 'COACHING_ACKNOWLEDGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::RESPOND, self::END, self::CANCEL, self::ACKNOWLEDGE], true)
            && $subject instanceof Coaching;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Coaching $subject */
        $isParty = $subject->getCoach() === $user || $subject->getAthlete() === $user;

        if (!$isParty) {
            return false;
        }

        return match ($attribute) {
            self::RESPOND => $subject->isRecipient($user),
            self::CANCEL, self::ACKNOWLEDGE => $subject->isRequester($user),
            default => true,
        };
    }
}
