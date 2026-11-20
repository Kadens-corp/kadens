/*
 * Suivi des enregistrements automatiques (#86).
 *
 * Le compositeur, l'édition rapide d'une case et l'édition en ligne (titre,
 * description, notes) enregistrent sans bouton. Sans retour, on ne sait pas si
 * c'est pris en compte : l'audit a vu un athlète recharger la page pour vérifier.
 * Ce module porte la seule mécanique de ce retour, partagée par les trois.
 *
 * `trackSave(source, run)` exécute `run` (qui fait le fetch et applique la réponse,
 * et lève une erreur si l'enregistrement n'a pas eu lieu) et le raconte à
 * l'indicateur le plus proche : « Enregistrement… », puis « Enregistré » ou
 * « Échec · Réessayer ». L'indicateur est un contrôleur `save-status` ANCÊTRE de
 * la source : celui de la modale d'édition rapide pour ce qui s'y passe (la page
 * est sous le voile du <dialog>), celui de <body> sinon.
 *
 * L'indicateur est résolu AVANT d'enregistrer, et les événements partent de lui,
 * pas de la source : un stream re-rend souvent le formulaire qui vient d'être
 * soumis, et un événement émis par un élément détaché ne remonte nulle part.
 *
 * Réessayer rejoue `run` tel quel : c'est à l'appelant d'y capturer ce qu'il
 * envoie (valeur, FormData) au premier essai. Une nouvelle tentative de la même
 * source (`key`, par défaut l'élément) efface son échec précédent : réessayer
 * plus tard ne doit jamais écraser une saisie plus récente qui, elle, a réussi.
 */

const EVENT = 'kd:save';

/** Enregistrement refusé par le serveur (4xx) : rejouer ne changerait rien. */
export class SaveRefused extends Error {
    constructor(message) {
        super(message || 'Non enregistré');
        this.name = 'SaveRefused';
    }
}

/** Lève si la réponse n'est pas un succès. Un 4xx est un refus, le reste un échec. */
export async function ensureSaved(response) {
    if (response.ok) return response;
    if (response.status >= 400 && response.status < 500) {
        const text = (await response.text().catch(() => '')).trim();
        // Seul un message court et en texte brut se montre (ex. « Le titre ne peut
        // pas être vide. ») ; une page d'erreur HTML n'a rien à faire dans la pastille.
        throw new SaveRefused(text.length > 0 && text.length <= 120 && !text.startsWith('<') ? text : null);
    }
    throw new Error(`HTTP ${response.status}`);
}

function scopeOf(source) {
    return (source instanceof Element && source.closest('[data-controller~="save-status"]')) || document.body;
}

/**
 * @param {Element} source  ce qui enregistre (formulaire, champ en ligne)
 * @param {() => Promise<unknown>} run  enregistre ; lève si rien n'a été enregistré
 * @param {unknown} key  identité de la source, pour qu'une tentative remplace son échec
 * @returns {Promise<boolean>} vrai si enregistré ; ne rejette jamais
 */
export function trackSave(source, run, key = source) {
    const scope = scopeOf(source);
    const emit = (detail) => scope.dispatchEvent(new CustomEvent(EVENT, { detail: { key, ...detail } }));

    const attempt = async () => {
        emit({ state: 'pending' });
        try {
            await run();
            emit({ state: 'saved' });
            return true;
        } catch (error) {
            if (error instanceof SaveRefused) {
                emit({ state: 'refused', message: error.message });
            } else {
                console.error('Save failed:', error);
                emit({ state: 'error', retry: attempt });
            }
            return false;
        }
    };

    return attempt();
}

/** Remet un indicateur au repos (ex. la modale qu'on rouvre sur une autre case). */
export function resetSaveStatus(scope) {
    scope.dispatchEvent(new CustomEvent(EVENT, { detail: { state: 'reset' } }));
}

export const SAVE_EVENT = EVENT;
