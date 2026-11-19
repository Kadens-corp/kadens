import { Controller } from '@hotwired/stimulus';

/*
 * Modales de sélection du calendrier (remplacent les anciens dropdowns) :
 *  - « poser une séance » : ouverte par le « + » d'un jour (data-caladd-date-param),
 *    la date choisie alimente un champ caché ; chaque carte est un bouton submit
 *    (clic = pose immédiate, endpoint lean `app_scheduled_workout_place`, la vue
 *    se morphe sans rechargement puis la modale se ferme : `placed`), et
 *    « Nouvelle séance pour ce jour » crée un brouillon posé à cette date (#92) ;
 *  - « placer un plan dans l'agenda » : ouverte par le bouton en tête, carte
 *    cliquée = sélection (alimente le <select> caché du PlanInstantiationType et
 *    émet `change`, que relit l'aperçu `plan-preview`), puis date + « Placer
 *    dans l'agenda ».
 *
 * Trois valeurs posées par le serveur, les deux premières depuis la query (#68) :
 *  - `preselect` : id d'un plan venu de « Placer dans mon agenda » (page du
 *    plan) : la modale s'ouvre au chargement, ce plan déjà choisi ;
 *  - `focus` : date (Y-m-d) d'un jour à amener à l'écran, posée après une pose
 *    de plan pour montrer la première séance ;
 *  - `today` : date (Y-m-d) du jour côté serveur. Un jour antérieur ouvre la
 *    modale avec le choix du statut (faite, manquée, encore à faire, #109).
 *
 * Recherche / filtre d'activité / tri sont 100 % client (offline-safe). Les outils
 * (recherche, tri, filtres) vivent HORS du <form> pour qu'un Entrée n'y déclenche
 * pas une pose accidentelle ; seules les cartes/les champs sont dans le <form>.
 */
export default class extends Controller {
    static targets = [
        'workoutDialog', 'dateInput', 'dateLabel', 'pastStatus',
        'workoutList', 'workoutCard', 'workoutEmpty',
        'planDialog', 'planList', 'planCard', 'planEmpty',
        'planSelectWrap', 'planSubmit', 'planDate',
    ];

    static values = { preselect: Number, focus: String, today: String };

    connect() {
        if (this.focusValue) {
            const day = this.element.querySelector(`[data-day="${this.focusValue}"]`);
            day?.scrollIntoView({ block: 'center' });
            day?.classList.add('kd-calfocus');
        }

        if (this.preselectValue) {
            const card = this.planCardTargets.find((c) => c.dataset.planId === String(this.preselectValue));
            if (card) {
                this.openPlan();
                this.armPlan(card);
                card.scrollIntoView({ block: 'nearest' });
            }
        }
    }

    // ----------------------------------------------------------------- Séance
    openWorkout(event) {
        const date = event.params.date;
        // Deux champs portent la date : la pose d'une séance existante et
        // « Nouvelle séance pour ce jour » (#92).
        this.dateInputTargets.forEach((input) => { input.value = date; });
        if (this.hasDateLabelTarget) {
            this.dateLabelTarget.textContent = this.humanDate(date);
        }
        // Jour passé (#109) : le statut se choisit à la pose. `today` vient du
        // serveur, qui tranche de toute façon : un statut posté pour aujourd'hui
        // ou le futur y est ignoré. Dates ISO, la comparaison de chaînes suffit.
        if (this.hasPastStatusTarget) {
            const past = Boolean(this.todayValue) && date < this.todayValue;
            this.pastStatusTarget.hidden = !past;
            this.pastStatusTarget.disabled = !past;
            if (past) {
                const done = this.pastStatusTarget.querySelector('input[value="done"]');
                if (done) done.checked = true;
            }
        }
        this.resetWorkoutFilters();
        this.workoutDialogTarget.showModal();
    }

    /**
     * Séance posée : le serveur répond par un refresh morphé de la vue, plus
     * par une redirection. La modale, ouverte côté client, n'a pas d'état
     * serveur à reprendre : on la ferme ici, avant que le morphing ne passe.
     * En échec (jeton, séance introuvable), Turbo suit la redirection.
     */
    placed(event) {
        if (event.detail.success) this.workoutDialogTarget.close();
    }

    filterWorkouts(event) {
        this.workoutQuery = (event.target.value || '').trim().toLowerCase();
        this.applyWorkoutFilter();
    }

    pickWorkoutActivity(event) {
        const btn = event.currentTarget;
        this.workoutActivity = btn.dataset.activity;
        btn.parentElement.querySelectorAll('[data-activity]').forEach((b) => {
            b.classList.toggle('kd-libfilter--on', b === btn);
        });
        this.applyWorkoutFilter();
    }

    sortWorkouts(event) {
        this.sortCards(this.workoutListTarget, this.workoutCardTargets, event.target.value);
    }

    applyWorkoutFilter() {
        const q = this.workoutQuery || '';
        const act = this.workoutActivity || 'all';
        let visible = 0;
        this.workoutCardTargets.forEach((card) => {
            const matchText = !q || (card.dataset.filterText || '').includes(q);
            const matchAct = act === 'all' || (card.dataset.activity || '').split(' ').includes(act);
            const show = matchText && matchAct;
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (this.hasWorkoutEmptyTarget) {
            this.workoutEmptyTarget.hidden = visible > 0;
        }
    }

    resetWorkoutFilters() {
        this.workoutQuery = '';
        this.workoutActivity = 'all';
        const dlg = this.workoutDialogTarget;
        const search = dlg.querySelector('input[type="search"]');
        if (search) search.value = '';
        dlg.querySelectorAll('[data-activity]').forEach((b) => {
            b.classList.toggle('kd-libfilter--on', b.dataset.activity === 'all');
        });
        const sort = dlg.querySelector('.kd-picker__sort');
        if (sort) sort.selectedIndex = 0;
        this.applyWorkoutFilter();
        this.sortCards(this.workoutListTarget, this.workoutCardTargets, 'title');
    }

    // -------------------------------------------------------------------- Plan
    openPlan() {
        if (this.hasPlanDateTarget && !this.planDateTarget.value) {
            // Date locale (toISOString passerait en UTC, la veille après minuit).
            const d = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            this.planDateTarget.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            this.planDateTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (!this.planDialogTarget.open) this.planDialogTarget.showModal();
    }

    /** Plan placé : même logique que `placed`, pour la modale des plans. */
    planPlaced(event) {
        if (event.detail.success) this.planDialogTarget.close();
    }

    filterPlans(event) {
        const q = (event.target.value || '').trim().toLowerCase();
        let visible = 0;
        this.planCardTargets.forEach((card) => {
            const show = !q || (card.dataset.filterText || '').includes(q);
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (this.hasPlanEmptyTarget) {
            this.planEmptyTarget.hidden = visible > 0;
        }
    }

    sortPlans(event) {
        this.sortCards(this.planListTarget, this.planCardTargets, event.target.value);
    }

    selectPlan(event) {
        this.armPlan(event.currentTarget);
    }

    armPlan(card) {
        const select = this.planSelectWrapTarget.querySelector('select');
        if (select) {
            select.value = card.dataset.planId;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        this.planCardTargets.forEach((c) => {
            c.classList.toggle('kd-palettecard--armed', c === card);
        });
        if (this.hasPlanSubmitTarget) this.planSubmitTarget.disabled = false;
    }

    // ------------------------------------------------------------------ Commun
    close(event) {
        event.target.closest('dialog')?.close();
    }

    backdrop(event) {
        if (event.target.tagName === 'DIALOG') event.target.close();
    }

    /** Réordonne les cartes dans leur conteneur (title=alpha, autres=numérique). */
    sortCards(container, cards, key) {
        if (!container) return;
        const attr = { title: 'sortTitle', duration: 'sortDuration', weeks: 'sortWeeks' }[key] || 'sortTitle';
        const numeric = key === 'duration' || key === 'weeks';
        [...cards]
            .sort((a, b) => {
                const va = a.dataset[attr] || '';
                const vb = b.dataset[attr] || '';
                return numeric ? Number(va) - Number(vb) : va.localeCompare(vb);
            })
            .forEach((c) => container.appendChild(c));
    }

    humanDate(iso) {
        const d = new Date(`${iso}T00:00:00`);
        return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
}
