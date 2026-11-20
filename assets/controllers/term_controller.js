import { Controller } from '@hotwired/stimulus';

/*
 * Infobulle d'un sigle (#102, `components/_term.html.twig`).
 *
 * Le survol et le focus clavier sont tenus en CSS (`:hover`, `:focus-within`) :
 * ce contrôleur n'ajoute que le toucher, où il n'y a pas de survol et où Safari
 * ne donne pas le focus à un bouton touché. Un toucher ouvre, un second ferme,
 * un toucher ailleurs ou Échap referme. S'il ne charge pas, le clavier et la
 * souris gardent l'infobulle.
 */
export default class extends Controller {
    connect() {
        this.onOutside = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
    }

    toggle(event) {
        // Un sigle posé dans un lien ne doit pas l'ouvrir.
        event.preventDefault();
        if (this.element.classList.contains('is-open')) {
            this.close();
        } else {
            this.element.classList.add('is-open');
            document.addEventListener('click', this.onOutside);
        }
    }

    close() {
        this.element.classList.remove('is-open');
        document.removeEventListener('click', this.onOutside);
    }
}
