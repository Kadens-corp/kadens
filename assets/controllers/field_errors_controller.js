import { Controller } from '@hotwired/stimulus';

/*
 * Retire le message d'erreur d'un champ dès qu'il ne s'applique plus (#114).
 *
 * L'erreur vient du serveur et parle de la valeur ENVOYÉE : « Choisis une
 * activité. » restait affiché sous une activité choisie, jusqu'au prochain
 * envoi. Elle se masque donc quand deux conditions tiennent ensemble :
 *   - la valeur a changé depuis l'envoi (revenir à l'ancienne la réaffiche) ;
 *   - le navigateur la juge valide (`checkValidity()` : champ requis rempli,
 *     URL bien formée…), même sous `novalidate`, qui ne coupe que la bulle.
 *
 * Ce que le navigateur ne sait pas vérifier (unicité d'un pseudo, borne
 * métier) se masque au premier changement : le message parlait d'une valeur
 * qui n'est plus là, le serveur retranchera au prochain envoi.
 *
 * Posé par le thème (`form/kadens_theme.html.twig`) sur chaque `.kd-field` qui
 * porte une erreur, rien à brancher dans les templates.
 *
 * Cibles :
 *   - list : la liste des erreurs du champ (`.kd-errors`)
 */
export default class extends Controller {
    static targets = ['list'];

    connect() {
        this.submitted = this.snapshot();
    }

    check() {
        if (!this.hasListTarget) return;
        const changed = this.snapshot() !== this.submitted;
        const valid = this.controls().every((c) => c.checkValidity());
        this.listTarget.hidden = changed && valid;
    }

    controls() {
        return [...this.element.querySelectorAll('input, select, textarea')]
            .filter((c) => c.type !== 'hidden');
    }

    snapshot() {
        return JSON.stringify(this.controls().map((c) => {
            if (c.type === 'checkbox' || c.type === 'radio') return c.checked;
            if (c.multiple) return [...c.selectedOptions].map((o) => o.value);
            return c.value;
        }));
    }
}
