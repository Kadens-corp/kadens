import { Controller } from '@hotwired/stimulus';

/*
 * Un message flash en toast (#125, `components/_flash_toasts.html.twig`) : il se
 * ferme d'un clic, et s'efface seul si `hideAfter` est posé. Le succès le pose,
 * pas l'erreur : un refus qui disparaît avant d'être lu n'a rien dit.
 */
export default class extends Controller {
    // Délai avant effacement, en ms ; 0 = rester jusqu'à la fermeture.
    static values = { hideAfter: { type: Number, default: 0 } };

    connect() {
        if (this.hideAfterValue > 0) {
            this.timer = setTimeout(() => this.close(), this.hideAfterValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    close() {
        this.element.remove();
    }
}
