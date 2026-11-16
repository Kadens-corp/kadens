import { Controller } from '@hotwired/stimulus';
import HoverPreview from 'hover-preview';

/*
 * Aperçu au survol d'une séance en lecture seule : carte du calendrier,
 * consultation d'un plan, page publique. Purement client, aucun AJAX
 * (offline-safe).
 *
 * Toute la mécanique (souris seulement, délai, placement qui ne recouvre jamais
 * la carte, fermeture au clic et au défilement) vit dans `assets/hover_preview.js`,
 * partagé avec l'éditeur de trame (`plangrid`).
 */
export default class extends Controller {
    static targets = ['panel'];

    connect() {
        this.preview = new HoverPreview();
    }

    disconnect() {
        this.preview.hide();
    }

    show(event) {
        const panel = this.hasPanelTarget ? this.panelTarget : this.element.querySelector('.kd-planpreview');
        this.preview.schedule(this.element, panel, event);
    }

    hide() {
        this.preview.hide();
    }
}
