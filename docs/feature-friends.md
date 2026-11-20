# Amis et flux de séances (#126)

Une notion sociale minimale : on ajoute des amis, et un flux montre les séances
de salle faites de ses amis et les siennes, avec la liste complète de leurs
exercices (exercices perso compris). Maquette : Claude Design « Social »,
tour 2 « Lire, copier, c'est tout » (2a à 2e).

## Ce qui a été tranché

- **Une amitié n'ouvre qu'une chose : le flux, en lecture, dans les deux sens.**
  Aucun voter ne consulte `Friendship`. Un ami n'ouvre ni la page d'une séance,
  ni une fiche d'exercice, ni un profil, ni un plan, ni un objectif. Tout ce
  qu'il lit est dans la carte du flux.
- **Le périmètre du flux est écrit une fois**, `ScheduledWorkoutRepository::
  feedQuery()` : statut `DONE`, au moins un exercice consigné (le cardio
  s'importe, il n'entre pas), partage de l'auteur ouvert (`User.shareWithFriends`)
  et séance non masquée (`ScheduledWorkout.hiddenFromFriends`). Ses propres
  séances passent toujours, masquées comprises (la carte le dit). La garde de la
  copie (`FriendFeed::canRead()`) réutilise cette requête : ce qu'on ne voit pas
  ne se copie pas.
- **Couper le partage retire aussi l'ancien.** Rien n'est recopié ni publié : le
  flux lit les réglages à chaque affichage.
- **Pas de réaction, pas de compteur.** La seule trace d'un ami chez l'auteur est
  « Copiée par Tom le 20/11 », sous la séance (`components/_copied_by`, au
  propriétaire seul : son coach ne lit pas le nom de ses amis) et sur sa carte.
- **Recherche exacte, un compte au plus.** Pseudo (insensible à la casse et aux
  accents, par sa collation) ou email exacts, jamais un préfixe : pas d'annuaire
  parcourable, la règle de `/coaching`. La demande se poste avec le texte cherché,
  jamais avec un identifiant (qui laisserait demander n'importe qui en comptant).
  Écart assumé à la maquette, qui listait « Camille_run » et « Camélia » pour « cam ».
- **Rien n'est gardé d'une amitié finie.** Refuser, annuler, retirer suppriment
  la ligne (`FriendshipStatus` n'a que `PENDING` et `ACCEPTED`). Les copies déjà
  faites restent chez leur propriétaire.
- **Demander à quelqu'un qui vous a déjà demandé accepte sa demande.**

## Copier une séance d'ami (`FriendSessionCopier`)

« Telle qu'il l'a faite » : la structure de la séance d'origine est clonée, le
réalisé de chaque exercice fait devient son prescrit par
`LineageSeed::applyLogged()` (la règle de la reprogrammation : séries chiffrées,
échauffement gardé, mode simple si tout est identique). Ensuite :

- un exercice de force **sauté ou jamais consigné** sort de la copie ; ce qui ne
  se logue pas (footing, mobilité) reste tel que prescrit ;
- un exercice fait **hors programme** s'ajoute au dernier bloc principal, par
  `LineageSeed::prescribeFromLog()` ; une séance libre devient un bloc principal ;
- les **exercices perso** de l'ami sont recopiés dans la bibliothèque du
  destinataire (`SharedWorkoutCopier::rebindExercises()`, homonyme réutilisé) ;
- la copie garde `Workout.copiedFromSession` (SET NULL), un repère, jamais un
  droit. Ni le bloc-notes privé ni le ressenti de l'ami ne suivent.

## Records battus (`SessionRecords`)

La couronne d'une carte est celle de l'écran de clôture du mobile
(`kadens-mobile/src/session/records.ts`), rejouée après coup : record de charge
(la charge, puis les répétitions à charge égale) ou au poids du corps (les
répétitions), une couronne par exercice, la première fois n'est pas un record.
La référence est ce que l'auteur a fait avant la séance, dans l'ordre (date,
identifiant). Une requête pour toute une page
(`LoggedSetRepository::loadsBySessionForOwners()`). Si l'une des deux règles
bouge, l'autre bouge avec.

## Aucun geste du flux ne recharge la page

On agit au milieu d'une liste qu'on fait défiler : une redirection ramènerait
en haut. Donc :

- **Copier, masquer, repartager** répondent en Turbo Stream
  (`friend/stream/card.stream.html.twig`) : la seule carte touchée est
  remplacée à sa place (`#seance-{id}`, racine de `friend/_feed_card`), ce qui
  ferme aussi la modale de copie, et le message part en toast dans `.kd-toasts`.
  La carte est reconstruite par `FriendFeed::entryFor()`, la même construction
  que `page()`.
- **« Séances plus anciennes »** charge la page suivante dans son turbo-frame
  (`friend/_feed_page`, `feed-page-N`), sous les cartes déjà lues. Les frames
  sont en `display: contents` (l'espacement de la liste reste celui d'une seule
  liste) et en `target="_top"` (seul le lien de pagination vise son frame).
  `?jour=` porte le dernier jour affiché, pour ne pas redire son séparateur.
- Pourquoi pas le refresh morphé du calendrier : il relit l'URL courante, donc
  la page 1, et effacerait les pages chargées plus bas.
- Sans JS, tout redirige comme avant (vers `#seance-{id}`, même filtre, même
  page), et la pagination ouvre une page entière. La page 1 reste
  auto-suffisante : rien n'est chargé sans un clic.

## Coût

Une page = `FriendFeed::PAGE_SIZE` (10) séances hydratées avec leur réalisé,
deux requêtes (identifiants triés et bornés, puis contenu), plus une pour les
records et une pour les copies. La pastille du menu est un COUNT sur l'index
(destinataire, statut), lu sur chaque page.

## Hors périmètre

- Le mobile n'a ni flux ni page Amis.
- Pas de notification : une demande se voit à la pastille de l'entrée « Amis »
  du menu de compte.
