import { Controller } from '@hotwired/stimulus';

/*
 * Menu du statut d'une séance datée au calendrier (#89).
 *
 * Le menu est un `popover` natif ouvert par `popovertarget` : l'ouverture, la
 * fermeture au clic extérieur et à Échap, le top-layer (qui le sort de
 * l'`overflow: hidden` de la pastille) ne doivent rien à ce contrôleur. Il ne
 * fait que le **poser contre son bouton** : dessous, aligné à gauche, remonté
 * au-dessus s'il n'y a pas la place, toujours gardé dans la fenêtre. Sans lui,
 * le navigateur centre le popover à l'écran : moins joli, tout aussi utilisable.
 *
 * Le menu étant positionné en `fixed` sur des coordonnées d'écran, un défilement
 * le décollerait de sa pastille : il se referme plutôt que de flotter ailleurs.
 */
const GAP = 4;
const MARGIN = 8;

export default class extends Controller {
    static targets = ['trigger', 'menu'];

    connect() {
        this.closeOnScroll = () => this.close();
        this.closeBeforeCache = () => this.close();
        // Un instantané Turbo pris menu ouvert le restaurerait hors top-layer.
        document.addEventListener('turbo:before-cache', this.closeBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.closeBeforeCache);
        window.removeEventListener('scroll', this.closeOnScroll, true);
    }

    place(event) {
        if (event.newState !== 'open') {
            window.removeEventListener('scroll', this.closeOnScroll, true);
            return;
        }

        const rect = this.triggerTarget.getBoundingClientRect();
        const menu = this.menuTarget;
        const { width, height } = menu.getBoundingClientRect();
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;

        let top = rect.bottom + GAP;
        if (top + height > vh - MARGIN && rect.top - GAP - height >= MARGIN) {
            top = rect.top - GAP - height;
        }
        const left = Math.max(MARGIN, Math.min(rect.left, vw - width - MARGIN));

        // Le centrage par défaut du popover (inset: 0 + margin: auto) n'est levé
        // qu'ici : c'est lui qui sert de repli sans JS, le CSS n'y touche pas.
        Object.assign(menu.style, {
            margin: '0',
            right: 'auto',
            bottom: 'auto',
            top: `${Math.max(MARGIN, top)}px`,
            left: `${left}px`,
        });

        // Le focus va au statut courant : au clavier, on part de ce qui est.
        menu.querySelector('[aria-pressed="true"]')?.focus();

        window.addEventListener('scroll', this.closeOnScroll, { capture: true, passive: true });
    }

    close() {
        if (this.hasMenuTarget && this.menuTarget.matches(':popover-open')) {
            this.menuTarget.hidePopover();
        }
    }
}
