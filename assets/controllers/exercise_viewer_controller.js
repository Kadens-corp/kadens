import { Controller } from '@hotwired/stimulus';

/*
 * La visionneuse des images d'un exercice (#38), posée une fois sur <body>.
 * Un œil (`components/_exercise_eye`) l'ouvre avec trois paramètres : le nom,
 * l'adresse de la fiche et la liste des images.
 *
 * Le lecteur est cloné depuis le gabarit de la modale, rempli, puis inséré :
 * `exercise-frames` se connecte à l'insertion et joue le fondu enchaîné, comme
 * sur la fiche. Fermer vide le corps, ce qui arrête sa boucle (disconnect).
 *
 * Sur <body>, l'action marche aussi sur une ligne remplacée par un Turbo Stream
 * (compositeur) : Stimulus lit les actions au clic, pas au chargement.
 */
export default class extends Controller {
    static targets = ['dialog', 'name', 'body', 'link', 'template'];

    connect() {
        // Pas d'instantané Turbo pris modale ouverte (cf. dialog_controller.js).
        this.beforeCache = () => this.close();
        document.addEventListener('turbo:before-cache', this.beforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
    }

    open({ params }) {
        if (!this.hasDialogTarget) {
            return;
        }
        const images = Array.isArray(params.images) ? params.images : [];
        if (images.length === 0) {
            return;
        }

        this.nameTarget.textContent = params.name ?? '';
        this.linkTarget.href = params.url ?? '#';
        this.linkTarget.hidden = !params.url;
        this.bodyTarget.replaceChildren(this.player(images, params.name ?? ''));
        this.dialogTarget.showModal();
    }

    close() {
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    closed() {
        this.bodyTarget.replaceChildren();
    }

    player(images, name) {
        const figure = this.templateTarget.content.firstElementChild.cloneNode(true);
        const stage = figure.querySelector('[data-slot="stage"]');
        const dots = figure.querySelector('[data-slot="dots"]');
        const animated = images.length > 1;

        images.forEach((src, index) => {
            const image = document.createElement('img');
            image.src = src;
            image.width = 1080;
            image.height = 1080;
            image.decoding = 'async';
            image.alt = animated ? `Mouvement de ${name}, étape ${index + 1} sur ${images.length}` : `Mouvement de ${name}`;
            image.className = 'kd-exframes__frame' + (index === 0 ? ' is-active' : '');
            if (animated) {
                image.dataset.exerciseFramesTarget = 'frame';
            }
            stage.append(image);

            if (animated) {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'kd-exframes__dot';
                dot.setAttribute('aria-current', index === 0 ? 'true' : 'false');
                dot.setAttribute('aria-label', `Étape ${index + 1}`);
                dot.dataset.exerciseFramesTarget = 'dot';
                dot.dataset.action = 'exercise-frames#show';
                dot.dataset.exerciseFramesIndexParam = String(index);
                dots.append(dot);
            }
        });

        if (!animated) {
            figure.removeAttribute('data-controller');
            figure.querySelector('[data-slot="controls"]').remove();
        }

        return figure;
    }
}
