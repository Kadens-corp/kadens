/*
 * L'aperçu au survol d'une séance (#69). Partagé par `preview_controller`
 * (calendrier, consultation d'un plan, page publique) et `plangrid_controller`
 * (éditeur de trame), qui avaient chacun leur copie du même positionnement — et
 * donc les mêmes défauts.
 *
 * Le panneau `.kd-planpreview` est un `popover="manual"` promu en top-layer pour
 * échapper à l'overflow de la grille. Il n'a donc aucun light-dismiss : tout ce
 * qui doit le refermer est écrit ici.
 *
 * ## Quatre règles
 *
 * 1. **Souris seulement.** Au doigt, un tap émet un `mouseenter` synthétique sans
 *    `mouseleave` : l'aperçu s'ouvrait et restait collé. Sur tactile, le détail
 *    passe par la page de la séance.
 *
 * 2. **Jamais par-dessus la carte.** Le panneau est un descendant DOM de la
 *    carte : posé sur elle, il recevait le clic destiné au titre, et la souris
 *    posée dessus comptait comme « encore dans la carte », donc pas de
 *    `mouseleave`. C'est ce qui arrivait en fenêtre étroite, où l'ancien code le
 *    recalait à 8 px du bord, en plein sur la case. On essaie à droite, à gauche,
 *    dessous, dessus ; si rien ne tient sans recouvrir la carte, pas d'aperçu.
 *    En plus, le CSS lui retire les événements de pointeur (`pointer-events:
 *    none`) : même mal placé, il ne pourrait plus ni capter un clic ni retenir
 *    la souris.
 *
 * 3. **Un petit délai avant l'ouverture.** Une souris qui traverse l'agenda ne
 *    doit pas faire clignoter un panneau de 340 px sur les jours voisins.
 *
 * 4. **Tout geste le referme** : quitter la carte, cliquer n'importe où (ouvrir
 *    la modale de la carte compris), faire défiler. Et il ne s'ouvre pas tant
 *    qu'une modale est affichée, ni pendant un glisser (bouton enfoncé).
 */

const DELAY_MS = 250;
const GAP = 8;
const MARGIN = 8;

export function canHover() {
    return matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function modalOpen() {
    try {
        return document.querySelector(':modal') !== null;
    } catch (error) {
        // Navigateur sans `:modal` : on retombe sur les <dialog> ouverts.
        return document.querySelector('dialog[open]') !== null;
    }
}

function clamp(value, min, max) {
    return Math.max(min, Math.min(value, max));
}

/**
 * Coin haut-gauche du panneau, ou null si aucune place ne le laisse entier sans
 * recouvrir la carte.
 */
export function placePanel(rect, pw, ph, vw, vh) {
    const top = clamp(rect.top, MARGIN, vh - ph - MARGIN);
    const left = clamp(rect.left, MARGIN, vw - pw - MARGIN);
    const fitsH = ph <= vh - 2 * MARGIN;
    const fitsW = pw <= vw - 2 * MARGIN;

    const right = rect.right + GAP;
    if (fitsH && right + pw <= vw - MARGIN) return { left: right, top };

    const before = rect.left - GAP - pw;
    if (fitsH && before >= MARGIN) return { left: before, top };

    const below = rect.bottom + GAP;
    if (fitsW && below + ph <= vh - MARGIN) return { left, top: below };

    const above = rect.top - GAP - ph;
    if (fitsW && above >= MARGIN) return { left, top: above };

    return null;
}

export default class HoverPreview {
    constructor() {
        this.timer = null;
        this.panel = null;
        this.dismiss = () => this.hide();
    }

    /** À brancher sur `mouseenter` : ouvre l'aperçu après le délai. */
    schedule(anchor, panel, event = null) {
        this.hide();
        if (!canHover()) return;
        // Bouton enfoncé : on est en plein glisser-déposer, pas en train de lire.
        if (event && event.buttons) return;
        if (!panel || typeof panel.showPopover !== 'function') return;

        this.timer = setTimeout(() => {
            this.timer = null;
            this.show(anchor, panel);
        }, DELAY_MS);
    }

    show(anchor, panel) {
        if (!anchor.isConnected || !panel.isConnected) return;
        if (modalOpen()) return;

        try {
            panel.showPopover();
        } catch (error) {
            return;
        }

        // Mesuré une fois ouvert : fermé, le panneau est en `display: none`.
        const spot = placePanel(
            anchor.getBoundingClientRect(),
            panel.offsetWidth,
            panel.offsetHeight,
            window.innerWidth,
            window.innerHeight,
        );
        if (!spot) {
            this.close(panel);
            return;
        }

        panel.style.left = `${spot.left}px`;
        panel.style.top = `${spot.top}px`;
        this.panel = panel;

        // Le panneau est en `position: fixed` : au défilement il resterait sur
        // place pendant que la carte s'en va.
        window.addEventListener('scroll', this.dismiss, { capture: true, passive: true });
        document.addEventListener('pointerdown', this.dismiss, { capture: true });
    }

    /** À brancher sur `mouseleave`, et au `disconnect` du contrôleur porteur. */
    hide() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        if (!this.panel) return;

        window.removeEventListener('scroll', this.dismiss, { capture: true });
        document.removeEventListener('pointerdown', this.dismiss, { capture: true });
        this.close(this.panel);
        this.panel = null;
    }

    close(panel) {
        try {
            panel.hidePopover();
        } catch (error) {
            // Déjà retiré du DOM (re-render de grille) : rien à faire.
        }
    }
}
