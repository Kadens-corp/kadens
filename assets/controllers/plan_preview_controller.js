import { Controller } from '@hotwired/stimulus';

/*
 * Aperçu d'une pose de plan dans l'agenda (#68) : avant de valider, dire où
 * tombent les séances (première et dernière date, nombre, séances dans le passé).
 *
 * La règle d'ancrage vit dans `PlanScheduler` : le serveur fournit, plan par
 * plan, le décalage en jours de chaque case depuis le lundi de la semaine 1
 * (`placementPreviews`). Ici on ne fait qu'ajouter ces décalages au lundi de la
 * semaine de la date choisie. Un plan déjà placé ne se redate pas : la date
 * choisie n'est alors pas utilisée, et l'aperçu le dit au lieu d'inventer.
 *
 * Écoute `change` et `input` sur le formulaire (posés par le template) : le
 * <select> caché de la modale calendrier est piloté par `caladd`, qui émet un
 * `change` à chaque carte choisie.
 *
 * Les dates se calculent en UTC : une date « 2026-03-30 » n'a pas d'heure, et
 * l'heure locale ferait glisser un jour au passage à l'heure d'été.
 */
export default class extends Controller {
    static targets = ['plan', 'date', 'output'];
    static values = { plans: Object };

    connect() {
        this.update();
    }

    update() {
        if (!this.hasOutputTarget) return;

        const text = this.describe();
        this.outputTarget.textContent = text;
        this.outputTarget.hidden = text === '';
    }

    describe() {
        const whose = this.outputTarget.dataset.whose || 'ton';
        const planId = this.hasPlanTarget ? this.planTarget.value : '';
        const plan = planId ? this.plansValue[planId] : null;
        if (!plan) return '';

        if (plan.placed) {
            return `Ce plan est déjà dans ${whose} agenda : la date de départ n'est pas utilisée, `
                + 'seules les séances ajoutées au plan depuis seront placées, à leur place.';
        }

        const offsets = plan.offsets || [];
        if (offsets.length === 0) {
            return 'Ce plan n\'a encore aucune séance : rien ne sera placé.';
        }

        const start = this.hasDateTarget ? this.parse(this.dateTarget.value) : null;
        if (!start) return 'Choisis une date de départ pour voir où tombent les séances.';

        // Lundi de la semaine ISO de la date de départ (getUTCDay : dimanche = 0).
        const monday = this.addDays(start, -((start.getUTCDay() + 6) % 7));
        const first = this.addDays(monday, offsets[0]);
        const last = this.addDays(monday, offsets[offsets.length - 1]);

        const count = offsets.length;
        const sameYear = first.getUTCFullYear() === last.getUTCFullYear();
        let text = `${count} séance${count > 1 ? 's' : ''}`;
        text += count > 1
            ? `, du ${this.human(first, !sameYear)} au ${this.human(last, true)}.`
            : `, le ${this.human(first, true)}.`;
        text += ` Semaine 1 : celle du lundi ${this.human(monday, false, false)}.`;

        const today = this.parse(this.todayIso());
        const past = offsets.filter((o) => this.addDays(monday, o) < today).length;
        if (past > 0) {
            text += past === count
                ? ' Attention : toutes tombent avant aujourd\'hui.'
                : ` Attention : ${past} tombe${past > 1 ? 'nt' : ''} avant aujourd'hui.`;
        }

        return text;
    }

    parse(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))) : null;
    }

    addDays(date, days) {
        return new Date(date.getTime() + days * 86400000);
    }

    todayIso() {
        const d = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    /** « mercredi 1er avril 2026 » : le 1er à la française, l'année au besoin. */
    human(date, withYear, withWeekday = true) {
        const opts = { timeZone: 'UTC' };
        const weekday = date.toLocaleDateString('fr-FR', { ...opts, weekday: 'long' });
        const month = date.toLocaleDateString('fr-FR', { ...opts, month: 'long' });
        const day = date.getUTCDate();
        const parts = [withWeekday ? weekday : null, day === 1 ? '1er' : String(day), month];
        if (withYear) parts.push(String(date.getUTCFullYear()));

        return parts.filter(Boolean).join(' ');
    }
}
