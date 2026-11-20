import { Controller } from '@hotwired/stimulus';

/*
 * Prévenir le doublon avant qu'un coach redonne une séance à son athlète (#82).
 *
 * Le serveur fournit, par id de séance du coach, la copie que l'athlète en a
 * déjà (`CoachController::existingCopies`) : combien, la plus récemment
 * modifiée et son adresse. Quand la séance choisie en a une, on le dit, on
 * propose de l'ouvrir, et le bouton devient « En créer une nouvelle » : créer
 * reste possible, c'est un choix, plus une surprise.
 *
 * Sans JavaScript, le formulaire garde son comportement : une copie de plus.
 */
export default class extends Controller {
    static targets = ['workout', 'notice', 'text', 'open', 'label'];
    static values = { copies: Object, name: String };

    connect() {
        this.defaultLabel = this.hasLabelTarget ? this.labelTarget.textContent : '';
        this.update();
    }

    update() {
        const copy = this.copiesValue[this.workoutTarget.value] || null;

        this.noticeTarget.hidden = copy === null;
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = copy ? 'En créer une nouvelle' : this.defaultLabel;
        }
        if (!copy) return;

        this.textTarget.textContent = this.describe(copy);
        this.openTarget.href = copy.url;
    }

    describe(copy) {
        const chosen = this.workoutTarget.selectedOptions[0]?.textContent.trim() || '';
        // Renommée chez lui, la copie ne se reconnaît plus au titre : le redire.
        const renamed = copy.title !== chosen ? ` (« ${copy.title} »)` : '';

        if (copy.count > 1) {
            return `${this.nameValue} a déjà ${copy.count} copies de cette séance. `
                + `La dernière${renamed}, modifiée le ${copy.modifiedAt} : l'ouvrir, ou en créer une nouvelle ?`;
        }

        return `${this.nameValue} a déjà une copie de cette séance${renamed}, modifiée le ${copy.modifiedAt} : `
            + 'l\'ouvrir, ou en créer une nouvelle ?';
    }
}
