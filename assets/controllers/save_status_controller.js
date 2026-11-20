import { Controller } from '@hotwired/stimulus';
import { SAVE_EVENT } from 'save-status';

/*
 * Indicateur d'enregistrement automatique (#86) : « Enregistrement… »,
 * « Enregistré », « Échec · Réessayer ». Il ne sait pas ce qui enregistre, il
 * écoute les événements que `trackSave()` (assets/save_status.js) émet sur lui.
 *
 * Deux places : <body>, pour toute la page (pastille fixe, qui s'efface après
 * l'enregistrement pour ne rien masquer durablement), et la modale d'édition
 * rapide, dont le voile cache la page (pastille dans l'en-tête, qui reste : c'est
 * elle qu'on regarde avant de fermer). Le plus proche ancêtre de la source gagne.
 *
 * Le rendu est dans `components/_save_status.html.twig` : un libellé par état,
 * le contrôleur ne pose que `data-state` sur la cible. Plusieurs enregistrements
 * peuvent se croiser (deux champs quittés vite) : on ne dit « Enregistré » qu'une
 * fois TOUS revenus, et un échec reste affiché tant qu'il n'a pas été rejoué.
 */
export default class extends Controller {
    static targets = ['output', 'message'];

    // Délai avant de revenir au repos après « Enregistré » ; 0 = ne pas s'effacer.
    static values = { hideAfter: { type: Number, default: 2500 } };

    static REFUSED_DELAY = 6000;

    initialize() {
        this.pending = 0;
        this.failures = new Map();
        this.timer = null;
    }

    connect() {
        this.onSave = this.onSave.bind(this);
        this.onBeforeUnload = this.onBeforeUnload.bind(this);
        this.element.addEventListener(SAVE_EVENT, this.onSave);
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        this.element.removeEventListener(SAVE_EVENT, this.onSave);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        clearTimeout(this.timer);
    }

    onSave(event) {
        // L'indicateur de la modale répond seul : celui de <body> n'a pas à le redire.
        event.stopPropagation();
        const { state, key, retry, message } = event.detail;

        switch (state) {
            case 'pending':
                // Une nouvelle tentative de la même source remplace son échec.
                this.failures.delete(key);
                this.pending += 1;
                this.show('pending');
                break;
            case 'saved':
                this.settle();
                if (this.pending === 0) this.show(this.failures.size > 0 ? 'error' : 'saved');
                break;
            case 'error':
                this.settle();
                this.failures.set(key, retry);
                if (this.pending === 0) this.show('error');
                break;
            case 'refused':
                this.settle();
                if (this.hasMessageTarget) this.messageTarget.textContent = message;
                this.show('refused');
                break;
            case 'reset':
                this.pending = 0;
                this.failures.clear();
                this.show('idle');
                break;
            default:
        }
    }

    settle() {
        this.pending = Math.max(0, this.pending - 1);
    }

    /** « Réessayer » : rejoue chaque enregistrement en échec, avec ce qu'il envoyait. */
    retry() {
        const attempts = [...this.failures.values()];
        this.failures.clear();
        attempts.forEach((attempt) => attempt());
    }

    show(state) {
        clearTimeout(this.timer);
        if (!this.hasOutputTarget) return;
        this.outputTarget.dataset.state = state;

        const delay = state === 'saved' ? this.hideAfterValue
            : state === 'refused' ? this.constructor.REFUSED_DELAY
                : 0;
        if (delay > 0) {
            this.timer = setTimeout(() => {
                this.outputTarget.dataset.state = this.failures.size > 0 ? 'error' : 'idle';
            }, delay);
        }
    }

    /** Quitter la page pendant un enregistrement, ou après un échec, perdrait la saisie. */
    onBeforeUnload(event) {
        if (this.pending === 0 && this.failures.size === 0) return;
        event.preventDefault();
        // Exigé par les anciens navigateurs pour afficher l'alerte ; le texte est ignoré.
        event.returnValue = '';
    }
}
