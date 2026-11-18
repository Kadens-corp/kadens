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
