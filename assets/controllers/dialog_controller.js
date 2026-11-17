import { Controller } from '@hotwired/stimulus';

/*
 * Modale réutilisable, adossée à l'élément natif <dialog>. Purement client :
 * aucun AJAX, le contenu (formulaires) est déjà dans la page — cohérent avec la
 * discipline « pages auto-suffisantes / cachables offline ». Sans JS, le
 * <dialog> reste dans le flux et ses formulaires restent postables.
 *
 * Usage :
 *   <div data-controller="dialog">
 *     <button data-action="dialog#open">Ouvrir</button>
 *     <dialog data-dialog-target="dialog" data-action="click->dialog#backdrop">
 *       ... <button data-action="dialog#close">Fermer</button> ...
 *     </dialog>
 *   </div>
 *
 * Ouverture automatique : `data-dialog-open-once-value="<clé>"` ouvre la modale
 * au chargement, **une fois par session de navigateur** (la clé est notée en
 * `sessionStorage`). C'est ce qui sépare « fermer » de « refuser » : la croix
 * ou Échap remettent à plus tard sans que la modale ressurgisse à chaque clic
 * de navigation ; un refus définitif, lui, est une action serveur.
 *
 * Garde « modifications non enregistrées » (#90), optionnelle : un formulaire
 * `data-dialog-target="guarded"` et un encart caché `data-dialog-target="unsaved"`.
 * L'état du formulaire est relevé à l'ouverture ; s'il a changé, fermer (croix,
 * fond, Échap) ne ferme pas mais montre l'encart, qui propose d'enregistrer ou
 * d'abandonner. Un autre formulaire de la modale qui ferait perdre la saisie
 * (en rechargeant la vue) se garde par `submit->dialog#guardSubmit`. Écoutes à
 * poser sur le <dialog> : `cancel->dialog#cancel close->dialog#closed`.
 * Jamais de `window.confirm` : la question vit dans la modale.
 */
export default class extends Controller {
    static targets = ['dialog', 'guarded', 'unsaved'];
    static values = { openOnce: String };

    /*
     * Turbo met la page en cache **avant** de naviguer, et `showModal()` laisse un
     * attribut `open` dans le DOM. Un instantané pris modale ouverte la restaure
     * donc au retour arrière — mais hors top-layer : elle s'affiche dans le flux,
     * sans fond, sans piège au clavier, et l'échappement ne la ferme pas. On la
     * ferme sur `turbo:before-cache`, juste avant la prise d'instantané, plutôt
     * que de nettoyer l'état restauré après coup.
     *
     * L'écoute est sur `document` (l'événement n'est pas émis sur l'élément), donc
     * elle se démonte explicitement.
     */
    connect() {
        // Avant l'instantané, on ferme sans rien demander : la navigation a
        // déjà eu lieu, il n'y a plus personne à qui poser la question.
        this.closeBeforeCache = () => this.discardAndClose();
        document.addEventListener('turbo:before-cache', this.closeBeforeCache);

        if (this.openOnceValue) this.openOnceThisSession(this.openOnceValue);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.closeBeforeCache);
    }

    open() {
        this.dialogTarget.showModal();
        this.hideWarning();
        if (this.hasGuardedTarget) this.initial = this.serialize();
    }

    /*
     * Stockage illisible (navigation privée, données bloquées) : on n'ouvre pas.
     * Sans mémoire, la modale reviendrait à chaque page ; mieux vaut ne pas
     * l'imposer, la même chose s'atteint depuis la page qui la propose en dur.
     * Turbo connecte aussi le contrôleur sur un aperçu tiré du cache : la clé
     * déjà posée l'y empêche de s'ouvrir une seconde fois.
     */
    openOnceThisSession(key) {
        try {
            if (sessionStorage.getItem(key)) return;
            sessionStorage.setItem(key, '1');
        } catch {
            return;
        }

        if (!this.dialogTarget.open) this.open();
    }

    /*
     * Variante « souris seulement », posée sur un lien : ouvre la modale à la
     * place de la navigation quand le pointeur est fin, et laisse simplement le
     * lien suivre son cours au doigt.
     *
     * C'est de l'amélioration progressive, pas une bifurcation : le HTML de base
     * est un vrai lien (clavier, clic du milieu, sans JS), et la modale n'est
     * qu'un raccourci desktop. Sur téléphone, une modale d'édition dans une case
     * de calendrier est intenable — on va sur la page.
     */
    openFine(event) {
        if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;

        event.preventDefault();
        this.open();
    }

    close() {
        if (!this.hasDialogTarget) return;
        if (this.holdIfDirty(() => this.dialogTarget.close())) return;
        this.dialogTarget.close();
    }

    // Ferme si on clique sur le fond (hors de la carte). La carte stoppe la
    // propagation via sa propre zone ; ici on ne ferme que si la cible est le
    // <dialog> lui-même (le backdrop occupe toute sa surface).
    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    // Échap : le navigateur émet `cancel` avant de fermer, on peut l'annuler.
    cancel(event) {
        if (this.holdIfDirty(() => this.dialogTarget.close())) event.preventDefault();
    }

    /*
     * Filet de sécurité. Chrome ne laisse pas annuler deux `cancel` d'affilée
     * sans geste de l'utilisateur entre les deux : un second Échap ferme quand
     * même. Si la modale s'est fermée sale, on la rouvre avec l'avertissement.
     */
    closed() {
        if (this.forcing || !this.isDirty() || !this.dialogTarget.isConnected) return;
        this.dialogTarget.showModal();
        this.warn(() => this.dialogTarget.close());
    }

    // Un autre formulaire de la modale (déplacer) rechargerait la vue et
    // emporterait la saisie : même question, puis on renvoie ce formulaire.
    guardSubmit(event) {
        if (event.defaultPrevented) return;
        const form = event.target;
        const submitter = event.submitter;
        if (this.holdIfDirty(() => form.requestSubmit(submitter ?? undefined))) event.preventDefault();
    }

    // « Ne pas enregistrer » : la saisie revient à l'état servi, puis le geste
    // qui avait été retenu (fermer, déplacer) reprend son cours.
    discard() {
        const pending = this.pending;
        this.resetGuarded();
        this.hideWarning();
        this.forcing = true;
        try {
            if (pending) pending();
        } finally {
            this.forcing = false;
        }
    }

    discardAndClose() {
        if (!this.hasDialogTarget) return;
        this.resetGuarded();
        this.hideWarning();
        this.forcing = true;
        try {
            if (this.dialogTarget.open) this.dialogTarget.close();
        } finally {
            this.forcing = false;
        }
    }

    /* ---- garde ---- */

    // Vrai si le geste est retenu : la saisie a changé, l'encart s'affiche.
    holdIfDirty(pending) {
        if (this.forcing || !this.isDirty()) return false;
        this.warn(pending);

        return true;
    }

    isDirty() {
        return this.hasGuardedTarget && this.initial !== undefined && this.serialize() !== this.initial;
    }

    serialize() {
        return new URLSearchParams(new FormData(this.guardedTarget)).toString();
    }

    resetGuarded() {
        if (!this.hasGuardedTarget) return;
        this.guardedTarget.reset();
        this.initial = this.serialize();
    }

    warn(pending) {
        this.pending = pending;
        if (!this.hasUnsavedTarget) return;
        this.unsavedTarget.hidden = false;
        // Le focus part sur la première réponse : la question est lue, et
        // l'encart défile en vue s'il est hors de l'écran.
        this.unsavedTarget.querySelector('button')?.focus();
    }

    hideWarning() {
        this.pending = null;
        if (this.hasUnsavedTarget) this.unsavedTarget.hidden = true;
    }
}
