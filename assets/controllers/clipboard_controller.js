import { Controller } from '@hotwired/stimulus';

/*
 * Copie une URL de partage dans le presse-papiers et affiche un retour bref.
 * Dégrade proprement : sans JS, le lien « Ouvrir la page publique » reste
 * cliquable à côté du bouton.
 *
 * `share` ouvre la feuille de partage du système (Android, iOS, Safari, Edge) :
 * c'est le geste « envoyer à quelqu'un », vers une messagerie, sans passer par
 * le presse-papiers. Là où elle n'existe pas (Firefox de bureau), il retombe sur
 * la copie : le bouton fait toujours quelque chose d'utile.
 */
export default class extends Controller {
    static values = { url: String, title: String };
    static targets = ['feedback'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.notify('Lien copié');
        } catch (error) {
            this.notify('Copie impossible, copie le lien manuellement');
        }
    }

    async share() {
        const data = { title: this.titleValue, url: this.urlValue };

        if (!navigator.share || (navigator.canShare && !navigator.canShare(data))) {
            await this.copy();
            return;
        }

        try {
            await navigator.share(data);
        } catch (error) {
            // Fermer la feuille sans choisir est un renoncement, pas une panne.
            if (error.name !== 'AbortError') {
                await this.copy();
            }
        }
    }

    notify(message) {
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.textContent = message;
        }
    }
}
