import { Controller } from '@hotwired/stimulus';

/*
 * Joue les images d'un exercice en fondu enchaîné (#38) : chaque image apparaît
 * PAR-DESSUS la précédente, qui reste pleinement visible dessous le temps du
 * fondu. Les deux se superposent, le corps semble passer d'une position à
 * l'autre. Un fondu croisé simple (l'une s'efface pendant que l'autre arrive)
 * laisserait voir le fond à mi-chemin, l'image « clignoterait ».
 *
 * Trois états, portés en classes (le CSS fait le reste) :
 *   - is-active   : l'image du dessus, qui arrive en fondu ;
 *   - is-prev     : celle d'avant, opaque, juste dessous ;
 *   - is-entering : l'instant où l'image du dessus repart de transparent, sans
 *                   transition, avant que is-active lance le fondu.
 *
 * Le serveur rend la première image active ; sans JS, c'est elle qu'on voit.
 * Avec `prefers-reduced-motion`, rien ne démarre seul et il n'y a pas de fondu :
 * les commandes de la fiche permettent d'avancer à la main. La boucle s'arrête
 * quand l'onglet est caché.
 */
export default class extends Controller {
    static targets = ['frame', 'dot', 'toggle'];
    static values = { interval: { type: Number, default: 1400 } };

    connect() {
        this.index = 0;
        this.timer = null;
        if (this.frameTargets.length < 2) {
            return;
        }
        // Les commandes ne s'affichent qu'avec le JS qui les fait marcher.
        this.element.classList.add('is-live');

        this.onVisibility = () => {
            if (document.hidden) {
                this.stop();
            } else if (this.wanted) {
                this.start();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.wanted = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (this.wanted) {
            this.start();
        }
        this.renderControls();
    }

    disconnect() {
        this.stop();
        if (this.onVisibility) {
            document.removeEventListener('visibilitychange', this.onVisibility);
        }
    }

    toggle() {
        this.wanted = !this.wanted;
        this.wanted ? this.start() : this.stop();
        this.renderControls();
    }

    show(event) {
        this.wanted = false;
        this.stop();
        this.go(Number(event.params.index) || 0);
        this.renderControls();
    }

    start() {
        this.stop();
        this.timer = window.setInterval(() => this.go((this.index + 1) % this.frameTargets.length), this.intervalValue);
    }

    stop() {
        if (this.timer !== null) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    go(next) {
        if (next === this.index) {
            return;
        }
        const frames = this.frameTargets;
        const previous = this.index;
        this.index = next;

        frames.forEach((frame, i) => {
            if (i !== next && i !== previous) {
                frame.classList.remove('is-active', 'is-prev', 'is-entering');
            }
        });
        frames[previous].classList.remove('is-active', 'is-entering');
        frames[previous].classList.add('is-prev');

        const incoming = frames[next];
        incoming.classList.remove('is-prev', 'is-active');
        incoming.classList.add('is-entering');
        void incoming.offsetWidth; // fige l'opacité 0 avant de lancer le fondu
        incoming.classList.remove('is-entering');
        incoming.classList.add('is-active');

        this.renderControls();
    }

    renderControls() {
        this.dotTargets.forEach((dot, index) => dot.setAttribute('aria-current', index === this.index ? 'true' : 'false'));
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-pressed', this.wanted ? 'true' : 'false');
            this.toggleTarget.querySelector('[data-when="playing"]').hidden = !this.wanted;
            this.toggleTarget.querySelector('[data-when="paused"]').hidden = this.wanted;
        }
    }
}
