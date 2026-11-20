/*
 * Ce qu'un rafraîchissement par morphing (`<turbo-stream action="refresh"
 * method="morph">`) ne doit pas reprendre au serveur : l'état d'interface que le
 * lecteur a choisi, et que le HTML ne connaît pas.
 *
 * Le morphing aligne chaque attribut sur la page re-rendue. Or le serveur rend
 * tout `<details>` dans son état de départ (ouvert sous un `collapse`, que le
 * contrôleur referme au téléphone) et les cases à cocher d'affichage décochées :
 * déplacer une séance rouvrirait le résumé de la semaine et re-masquerait les
 * jours libres. On garde donc :
 *  - `open` de tout `<details>` ;
 *  - `checked` d'une case marquée `data-morph-keep`, une case d'affichage (pas
 *    un champ de formulaire, dont l'état serveur fait foi).
 *
 * Écoute sur `document` : l'événement remonte de l'élément morphé.
 */
document.addEventListener('turbo:before-morph-attribute', (event) => {
    const { target } = event;
    const { attributeName } = event.detail;

    if (attributeName === 'open' && target instanceof HTMLDetailsElement) {
        event.preventDefault();
    }

    if (attributeName === 'checked' && target instanceof HTMLInputElement && target.hasAttribute('data-morph-keep')) {
        event.preventDefault();
    }
});

/*
 * La barre de débogage Symfony (dev seulement) ne survit pas à un morph, et le
 * morph a deux usages ici : la séance ramenée à aujourd'hui (#109), les gestes
 * du calendrier (#120) et de la fiche coach (#125).
 *
 * Symfony injecte d'abord une barre provisoire, « Loading… », que son script
 * remplace par la vraie. Le morph aligne la page sur le HTML relu : la barre
 * chargée redevient la provisoire, et le script de la page relue ne s'exécute
 * pas (un morph n'exécute aucun script inséré). Elle restait donc en
 * « Loading » jusqu'au prochain chargement complet.
 *
 * On la rend permanente pour la durée de ce rendu seulement : même id des deux
 * côtés, `data-turbo-permanent`, et Turbo la laisse telle quelle. Seulement pour
 * un morph : une navigation ordinaire doit afficher la barre de la NOUVELLE
 * requête, pas garder celle de la page quittée. La requête du refresh, elle,
 * figure déjà dans le panneau AJAX de la barre gardée.
 */
const DEBUG_TOOLBAR_ID = 'kd-sf-toolbar';

document.addEventListener('turbo:before-render', (event) => {
    const { newBody, renderMethod } = event.detail;
    if (renderMethod !== 'morph' || !newBody) return;

    const current = document.querySelector('[id^="sfwdt"]')?.closest('.sf-toolbar');
    const incoming = newBody.querySelector('[id^="sfwdt"]')?.closest('.sf-toolbar');
    if (!current || !incoming) return;

    for (const toolbar of [current, incoming]) {
        toolbar.id = DEBUG_TOOLBAR_ID;
        toolbar.setAttribute('data-turbo-permanent', '');
    }
});

// La permanence ne vaut que pour le morph : retirée après le rendu, la barre
// suivra de nouveau les navigations ordinaires.
document.addEventListener('turbo:render', () => {
    document.getElementById(DEBUG_TOOLBAR_ID)?.removeAttribute('data-turbo-permanent');
});
