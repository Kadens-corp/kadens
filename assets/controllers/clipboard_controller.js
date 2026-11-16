import { Controller } from '@hotwired/stimulus';

/*
 * Copie une URL de partage dans le presse-papiers et le dit, visiblement,
 * partout : c'est le seul geste d'envoi d'un lien (#61, A-43). Plus de feuille
 * de partage du système : sur ordinateur elle ne montrait rien, et deux boutons
 * (« Partager », « Copier le lien ») laissaient deviner leur différence.
 *
 * Dégrade proprement : sans JS ou sans accès au presse-papiers, l'adresse reste
 * affichée à côté (champ en lecture seule, ou lien « Voir la page publique »),
 * et le retour dit de la copier à la main.
 */
export default class extends Controller {
    static values = { url: String };
    static targets = ['feedback'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.notify('Lien copié');
        } catch (error) {
            this.notify('Copie impossible, copie le lien à la main');
        }
    }

    /** Sélectionne tout le champ d'adresse au clic, pour une copie manuelle. */
    select(event) {
        event.currentTarget.select();
    }

    /*
     * Vidé puis réécrit au tour suivant : un second clic rejoue l'annonce
     * (`aria-live` ne parle que d'un changement), puis le message s'efface.
     */
    notify(message) {
        if (!this.hasFeedbackTarget) {
            return;
        }

        clearTimeout(this.timer);
        this.feedbackTarget.textContent = '';
        requestAnimationFrame(() => {
            this.feedbackTarget.textContent = message;
        });
        this.timer = setTimeout(() => {
            this.feedbackTarget.textContent = '';
        }, 5000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
