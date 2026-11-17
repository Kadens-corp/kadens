<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\IntervalsConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La synchro Intervals.icu de **tous** les comptes connectés, sans clic : ce que
 * déclenche le planificateur de tâches Infomaniak via `/cron/intervals`.
 *
 * Elle passe par `IntervalsImporter::sync()`, exactement le chemin du bouton :
 * même fenêtre glissante, même lot de `BATCH` activités, jamais de séance libre.
 * Un lot coupé reprend au passage suivant, comme un second clic.
 *
 * **Budget de temps.** Le mutualisé n'a ni worker ni cron shell : tout tient dans
 * une requête HTTP, sous le `max_execution_time` de PHP. Le budget n'est vérifié
 * qu'**entre deux comptes** (un compte en cours va au bout de son lot) et les
 * comptes passent du plus anciennement synchronisé au plus récent : ceux qu'un
 * appel n'a pas atteints passent en tête au suivant, personne n'est oublié.
 *
 * **Un compte en panne n'arrête pas les autres.** Clé révoquée ou Intervals qui
 * ne répond pas : on le compte en échec, on le journalise et on passe au suivant.
 * Ce qui a été importé avant la panne est gardé (cf. `IntervalsImporter::sync`).
 */
final readonly class IntervalsAutoSync
{
    public function __construct(
        private IntervalsConnectionRepository $connections,
        private IntervalsImporter $importer,
        private LoggerInterface $logger,
        #[Autowire('%env(int:CRON_TIME_LIMIT)%')]
        private int $timeLimitSeconds,
    ) {
    }

    /**
     * @return array{synced: int, failed: int, notReached: int, imported: int, attached: int, remaining: int}
     */
    public function run(): array
    {
        $start = microtime(true);
        $result = ['synced' => 0, 'failed' => 0, 'notReached' => 0, 'imported' => 0, 'attached' => 0, 'remaining' => 0];
        $queue = $this->connections->findForAutoSync();

        foreach ($queue as $index => $connection) {
            if (microtime(true) - $start >= $this->timeLimitSeconds) {
                $result['notReached'] = \count($queue) - $index;

                break;
            }

            $owner = $connection->getOwner();

            try {
                $report = $this->importer->sync($owner);
            } catch (IntervalsAuthException|IntervalsUnavailableException $e) {
                ++$result['failed'];
                $this->logger->warning('Synchro Intervals.icu automatique en échec pour l\'utilisateur {user} : {reason}', [
                    'user' => $owner->getId(),
                    'reason' => $e instanceof IntervalsAuthException ? 'clé refusée' : 'Intervals.icu injoignable',
                ]);

                continue;
            }

            ++$result['synced'];
            $result['imported'] += $report->imported;
            $result['attached'] += $report->attached;
            $result['remaining'] += $report->remaining;
        }

        return $result;
    }
}
