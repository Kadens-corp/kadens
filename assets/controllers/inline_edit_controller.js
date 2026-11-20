import { Controller } from '@hotwired/stimulus';
import { ensureSaved, trackSave } from 'save-status';

/*
 * Édition en ligne « semi-invisible » d'un champ texte.
 *
 * Le contenu affiché (data-inline-edit-target="display") ressemble à du texte
 * jusqu'au clic : on le remplace alors par un <input>/<textarea> prérempli. On
 * enregistre au blur ou à Entrée (Échap annule). La persistance passe par un
 * `fetch` POST qui renvoie la valeur nettoyée par le serveur (texte brut), qu'on
 * réaffiche. Amélioration progressive : sans JS, un formulaire complet (replié)
 * reste le repli.
 *
 * Générique : titre/description du plan (endpoint meta) et note de case (endpoint
 * item/note) l'utilisent avec des URLs différentes.
 *
 * Rien ne se voit à l'enregistrement : l'indicateur `save-status` le dit (#86).
 * En cas d'échec, le texte affiché revient à la dernière valeur enregistrée (on
 * n'affiche pas comme acquis ce qui ne l'est pas), et « Réessayer » renvoie la
 * valeur saisie. Un refus du serveur (titre vide) n'est pas rejouable : il se lit
 * dans la pastille.
 */
export default class extends Controller {
    static targets = ['display'];

    static values = {
        url: String,
        field: String,
        token: String,
        type: { type: String, default: 'input' }, // 'input' | 'textarea'
        placeholder: String,
        // Ouvre le champ dès l'affichage : on arrive sur un brouillon fraîchement
        // créé, dont le titre par défaut est à remplacer tout de suite.
        autofocus: Boolean,
    };

    connect() {
        if (this.autofocusValue) this.start();
    }

    start() {
        if (this.editing) return;
        this.editing = true;

        this.original = this.displayTarget.dataset.value ?? '';
        const control = document.createElement(this.typeValue === 'textarea' ? 'textarea' : 'input');
        if (this.typeValue !== 'textarea') control.type = 'text';
        control.value = this.original;
        control.className = 'kd-inlineedit__input';
        if (this.hasPlaceholderValue) control.placeholder = this.placeholderValue;

        this.displayTarget.hidden = true;
        this.displayTarget.after(control);
        this.control = control;
        control.focus();
        control.select();

        this.onBlur = () => this.save();
        this.onKey = (e) => this.handleKey(e);
        control.addEventListener('blur', this.onBlur);
        control.addEventListener('keydown', this.onKey);
    }

    handleKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        } else if (event.key === 'Enter' && (this.typeValue !== 'textarea' || event.metaKey || event.ctrlKey)) {
            // Entrée valide (mono-ligne) ; en textarea, Entrée insère un saut,
            // Ctrl/Cmd+Entrée valide.
            event.preventDefault();
            this.control.blur();
        }
    }

    async save() {
        if (!this.editing) return;
        const value = this.control.value.trim();

        if (value === this.original) {
            this.finish(this.original);
            return;
        }

        // On rend la main tout de suite, avec la valeur saisie : le champ n'attend
        // plus le serveur pour se refermer. `latest` dit quelle saisie est la plus
        // récente, pour qu'une réponse en retard n'écrase jamais la suivante.
        const saved = this.original;
        this.finish(value);
        trackSave(this.element, async () => {
            this.latest = value;
            try {
                const kept = await this.post(value);
                if (!this.editing && this.latest === value) this.finish(kept);
            } catch (error) {
                // Pas acquis : on réaffiche la dernière valeur enregistrée.
                if (!this.editing && this.latest === value) this.finish(saved);
                throw error;
            }
        });
    }

    /** Poste la valeur ; rend celle que le serveur a retenue (nettoyée). */
    async post(value) {
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('field', this.fieldValue);
        body.append('value', value);
        const response = await fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' });

        return (await ensureSaved(response)).text();
    }

    cancel() {
        this.finish(this.original);
    }

    finish(value) {
        this.editing = false;
        if (this.control) {
            this.control.removeEventListener('blur', this.onBlur);
            this.control.remove();
            this.control = null;
        }

        const el = this.displayTarget;
        el.dataset.value = value;
        if (value === '' && this.hasPlaceholderValue) {
            el.textContent = this.placeholderValue;
            el.classList.add('kd-inlineedit--empty');
        } else {
            el.textContent = value;
            el.classList.remove('kd-inlineedit--empty');
        }
        el.hidden = false;
    }
}
