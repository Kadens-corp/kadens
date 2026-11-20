# Images d'exercice (#38)

Un exercice de salle, de mobilité ou « autre » peut porter une ou plusieurs
images qui montrent le mouvement. Typiquement deux : le début, puis la fin. La
fiche de l'exercice les joue en fondu enchaîné, comme une petite animation.

## 1. Ce qui a été décidé

- **Des images fixes superposées en fondu, pas de GIF.** Chaque image arrive
  par-dessus la précédente, qui reste opaque dessous le temps du fondu : les
  deux positions se superposent, on ne voit jamais le fond
  (`exercise_frames_controller.js`, classes `is-active` / `is-prev` /
  `is-entering`). Sur la fiche : pause et une pastille par étape ; rien ne
  démarre seul sous `prefers-reduced-motion`. En petit, sans commande, au bout
  de chaque bande d'images.
- **Un format commun, imposé.** Carré de 1080 px, JPEG à 0,82, fond papier sous
  une image transparente, logo Kadens en bas à droite : le PNG transparent tel
  quel, sans pastille. Tout se fait dans le navigateur avant l'envoi
  (`exercise_images_controller.js`), avec l'aperçu final dans une modale. Le
  cadrage se choisit sur l'axe long : haut, centre ou bas (gauche, centre ou
  droite en largeur), rien pour une photo déjà carrée.
- **Salle, mobilité et « autre » seulement** (`ActivityType::takesImages`,
  `Exercise::acceptsImages`). Une sortie n'a pas de mouvement à montrer. Si un
  exercice illustré change d'activité, ses images restent lisibles et
  retirables, on ne peut plus en ajouter.
- **Une planche pour illustrer vite** (`/exercise/images`, bouton « Images » de
  la bibliothèque) : tous les exercices qu'on peut illustrer, une ligne chacun,
  leurs images en vignettes, un « + » par ligne. Une image à la fois : « + »,
  fichier, aperçu, Entrée. Filtres « Sans image », activité, tri « Les moins
  illustrés ». La même bande d'images vit dans l'éditeur de l'exercice.
- **Rien ne recharge la page.** Chaque geste répond par un Turbo Stream qui ne
  remplace que la bande de l'exercice touché (`#exercise-images-{id}`) ; une
  erreur part en toast. Sans JS, retour à l'écran d'origine (`from`), à la
  hauteur de l'exercice.
- **Un œil partout où la séance montre l'exercice.** Compositeur
  (`workout/_cexo_row`, hors du bouton de la ligne), lecture d'une séance
  (`components/_workout_exrow`) et réalisé (`components/_log_exrow`) :
  `components/_exercise_eye` ouvre la visionneuse commune
  (`components/_exercise_viewer`, posée une fois par `base.html.twig`, contrôleur
  `exercise-viewer` sur `<body>`), qui joue le même fondu que la fiche. Les
  adresses partent dans l'attribut du bouton : la page reste auto-suffisante. Pas
  dans la palette du compositeur, dont chaque carte est un bouton.
- **Le téléphone reçoit des URL signées.** Le bootstrap porte `images` sur chaque
  exercice (`ExerciseImageUrls`) : absolues, signées, **sans expiration**, servies
  sans session par `app_exercise_media` (`/media/exercise/{id}/{file}`,
  `PUBLIC_ACCESS`). Contrat : `docs/api-mobile.md` §6.5.
- **Les droits sont ceux de l'exercice.** `EDIT` pour ajouter, ranger, retirer :
  un `ROLE_ADMIN` sur la globale, le propriétaire sur un perso. `VIEW` pour voir.
- **Une liste JSON sur `Exercise`** (`images`), pas une entité : l'ordre est la
  seule chose qu'une image porte. Changer la liste touche `updatedAt`.

## 2. Mise en route

- **Admin** : bibliothèque → « Images » (la planche), ou fiche d'un exercice →
  « Éditer » → section « Images ». Sans image, la fiche propose aussi « Ajouter
  des images du mouvement ».
- **Serveur** : rien à installer. Les images s'écrivent sous
  `var/uploads/exercise-images/`, créé au premier envoi. **À sauvegarder**, comme
  les photos de récit (`var/uploads/` entier).
- **Base** : migration `Version20261120140000` (`exercise.images`, nullable).

## 3. Invariants à ne pas casser

- **Le serveur ne redessine rien, il vérifie.** Pas de GD au mutualisé :
  `ExerciseImageStorage::store()` refuse tout ce qui n'est pas un JPEG de
  exactement `SIZE` × `SIZE`. C'est ce qui garantit le 1:1 et la taille commune.
  `SIZE` est écrit deux fois (PHP et JS), les deux bougent ensemble. Le logo, lui,
  ne se vérifie pas sans décoder les pixels.
- **Un envoi est tout ou rien.** Une image refusée dans un lot fait repartir du
  disque celles déjà rangées ; la liste ne bouge pas.
- **Seul un nom cité par l'exercice se sert** (`app_exercise_image`), sous
  `ExerciseVoter::VIEW`, cache privé de 30 jours (le nom est aléatoire et ne
  sert qu'une fois). Jamais dans `public/`.
- **Les fichiers suivent l'ORM.** `ExerciseImageCleanupListener` efface, au
  `postFlush`, les images retirées de la liste et toutes celles d'un exercice
  supprimé.
- **Deux exercices ne partagent jamais un fichier.** `SharedWorkoutCopier`, qui
  recopie l'exercice perso d'un autre chez le destinataire, recopie aussi ses
  images sous de nouveaux noms (`ExerciseImageStorage::duplicate`).
- **Six images au plus** (`Exercise::MAX_IMAGES`).
- **La planche ne liste que ce qu'on peut éditer** : éligible (`acceptsImages`)
  et `ExerciseVoter::EDIT`. Le bouton « Images » de la bibliothèque ne s'affiche
  que s'il y a au moins un tel exercice.

## 4. Limites connues

- **Sans JS, pas d'ajout** : le « + » ne fait rien, et une image brute serait
  refusée par le serveur. Les images déjà posées s'affichent (la première seule)
  et se rangent ou se retirent.
- **Vignettes en pleine taille** : la planche affiche les carrés de 1080 px en
  72 px (chargement différé, cache navigateur de 30 jours). Lourd si l'on
  parcourt toute la globale illustrée ; une miniature dédiée viendra si besoin.
- **Logo sans fond** : sur une photo très sombre, les parties noires du logo se
  voient mal.
- **HEIC** : seul Safari sait le décoder. Ailleurs, la préparation échoue et
  l'envoi est bloqué avec un message.
- **Pas de page publique** : l'œil ne s'affiche qu'à qui peut lire l'exercice
  (`ExerciseVoter::VIEW`). Sur un lien de partage ouvert sans compte, rien.
- **Une adresse mobile ne se révoque qu'avec l'image.** Sans date, elle reste
  valable tant que l'exercice cite le fichier, même pour un compte qui n'a plus
  accès à l'exercice (fin d'un coaching). Le nom de fichier est aléatoire, la
  fuite suppose déjà l'adresse.
- **Hors ligne** : le service worker n'intercepte pas les images, elles ne sont
  que dans le cache HTTP du navigateur.
- **Suppression d'un compte** : faite par la base (CASCADE), elle laisse les
  images de ses exercices perso sur le disque, inertes.
- **Le logo est brûlé dans l'image.** Changer de logo ne met pas à jour les
  images déjà envoyées : il faudrait les renvoyer.
