# Roadmap — Modes d'affichage (mur + poste de jeu)

Ajout de deux contextes de déploiement à l'installation existante, sans toucher
aux trois qui fonctionnent déjà.

**Contexte réel.** Événement à Miami, public international, jeu en anglais. Deux
écrans **tactiles de 42 pouces** montés sur pied, côte à côte, chacun piloté par
son propre PC. Un clavier physique existe mais reste rangé dans un coin.

## Ce qui existe et ne doit pas bouger

| Contexte | Comment on y arrive | État |
|---|---|---|
| Mobile / lien partagé | URL nue | inchangé |
| Desktop navigateur | URL nue | inchangé |
| Kiosque iPad | toggle dans l'écran Admin | inchangé |

## Ce qu'on ajoute

| Contexte | URL | Écran |
|---|---|---|
| Mur Hall of Fame | `?mode=hof` | 42" **vertical**, tactile mais jamais touché |
| Poste de jeu | `?mode=play` | 42" **paysage**, tactile, joué debout |

---

## Décisions d'architecture

### Le mode vit dans l'URL, résolu côté serveur

Pas dans un drapeau de session. `kioskMode` est aujourd'hui une variable de
session activée depuis l'écran Admin (`app.js` l.82, l.2256). Ça convient à un
iPad qu'on prépare à la main. Ça ne convient pas à un PC branché sur un mur : au
premier rechargement — mise à jour Windows, coupure, veille, plantage de l'onglet
— l'écran revient en mode par défaut, menus compris, et personne n'est devant
pour le remettre. Un paramètre d'URL survit à tout ça.

Résolution **côté serveur** et non en JavaScript, pour deux raisons. Le nav est
rendu par `layout.php` (l.271-278) : le masquer après coup en JS laisse un flash
de menus au chargement. Et il resterait dans le DOM, atteignable au clavier ou
par un curieux — alors que la demande est que les joueurs n'aillent pas sur le
Hall of Fame.

À poser clairement : **c'est un garde-fou, pas une barrière de sécurité.** Les
routes de l'API restent ouvertes et les données du classement sont publiques de
toute façon. Le but est qu'un joueur ne s'égare pas, pas qu'un adversaire motivé
en soit empêché. Ne construis pas d'authentification pour ça.

**Accès à l'administration depuis les postes** : charger l'URL sans paramètre.
Pas de porte dérobée dans les modes d'affichage.

### Le tactile est déjà supporté — n'y touche pas

Le glisser-déposer fonctionne déjà au doigt : `startTouchDrag()`, le clone qui
suit le doigt sur `touchmove`, et `elementFromPoint()` pour trouver le slot au
lâcher (`app.js` l.733-770). C'est ce qui fait tourner le kiosque iPad
aujourd'hui. **Aucun travail n'est nécessaire pour que le jeu soit jouable au
doigt sur les 42 pouces.** La seule obligation est de ne pas casser ce chemin.

Deux conséquences en revanche, traitées plus bas :

**Windows n'affichera pas son clavier tactile.** Il ne le propose que s'il ne
détecte aucun clavier physique. Le tien est branché, simplement rangé. Windows en
conclut que l'utilisateur peut taper et n'affiche rien. Le clavier à l'écran de
l'itération 5 n'est donc pas un confort : c'est la seule façon de saisir un nom.

**`hasNativeShare()` va se déclencher à tort.** La fonction (l.1161) renvoie vrai
dès que `'ontouchstart' in window`, ce qui sera le cas sur un écran tactile
Windows. La fin de partie proposerait le partage natif, qui ouvre la barre de
partage de l'OS **par-dessus** le kiosque, sans moyen simple d'en sortir. Le mode
`play` ne doit afficher aucun bouton de partage.

### Contraintes de déploiement

Lancer les deux postes avec le mode kiosque du navigateur, pas l'API Fullscreen :

```
chrome --kiosk --app="https://<hôte>/?mode=hof"
chrome --kiosk --app="https://<hôte>/?mode=play"
```

`enterFullscreen()` exige un geste utilisateur. Après un rechargement nocturne,
plus personne n'est là pour le fournir. `--kiosk` revient plein écran tout seul,
et empêche d'atteindre la barre d'adresse.

**Dimensions tactiles.** Sur un 42 pouces en 1080p, un pixel CSS vaut environ
0,5 mm. Vise **72 px** pour toute cible tactile principale, soit ~35 mm : on joue
debout, à bout de bras, parfois à deux devant l'écran. Les 44 px habituels du
mobile sont trop petits ici.

---

# Itération 1 — Plomberie des modes

**Objectif.** Le mode est résolu, transmis, et le nav disparaît. Aucun changement
de comportement de jeu.

**Fichiers.** `public/index.php`, `app/Views/layout.php`,
`public/assets/js/app.js`, `public/assets/css/app.css`.

**Détail.**

Dans `index.php`, juste avant de servir le shell SPA (l.294), lis `$_GET['mode']`
et valide-le contre une liste blanche stricte : `''`, `'hof'`, `'play'`. Toute
autre valeur retombe silencieusement sur `''`. N'ajoute pas `kiosk` : le kiosque
iPad garde son toggle, on n'y touche pas.

Passe la valeur à `layout.php`, qui :

- pose `data-mode="hof|play"` sur le `<body>`, rien en mode par défaut ;
- **omet entièrement** `<nav class="header-nav">` et le bouton hamburger pour
  `hof` et `play` — pas de `display:none`, pas de rendu suivi d'un masquage ;
- pour `hof`, omet aussi les liens Privacy et GitHub du footer, mais garde le
  bloc « Supported by » et le logo PMPG : sur un mur, c'est là qu'il sert ;
- pour `play`, garde le footer tel quel — un joueur doit pouvoir voir Privacy.

Le titre `<h1 class="logo">` reste affiché dans les deux modes.

Côté JS, expose le mode une fois au démarrage :
`var displayMode = document.body.dataset.mode || '';` En lecture seule.

**Tests.**
- PHPUnit : `?mode=hof` et `?mode=play` rendent un shell sans `header-nav` ;
  l'URL nue le contient ; `?mode=nimportequoi` se comporte comme l'URL nue.
- e2e de **non-régression**, prioritaires : l'URL nue affiche les quatre boutons
  de nav ; le hamburger fonctionne sous 768 px ; le toggle kiosque de l'admin
  fait exactement ce qu'il faisait.

**Terminé quand.** Les deux nouvelles URL servent une page sans menus, les trois
contextes existants sont identiques, les trois suites sont vertes.

---

# Itération 2 — API du board et fenêtre de temps

**Objectif.** Une source de données que le mur peut interroger pendant huit heures
sans jamais échouer pour une raison d'authentification.

**Fichiers.** `public/index.php`, nouveau `app/Controllers/BoardController.php`,
`app/Models/LeaderboardModel.php`, `public/assets/js/app.js` (champ admin),
`README.md`.

**Détail.**

**Route GET `/board/data`**, déclarée à côté des autres routes GET publiques
(`/bg`, `/share`, l.105-128), donc **avant** `session_start()`. JSON, sans
session, sans jeton CSRF.

C'est le cœur de l'itération. Toute l'API applicative passe en POST avec un jeton
CSRF adossé à la session PHP, dont la durée de vie par défaut est de 24 minutes.
Une page qui interroge le serveur toute une soirée verrait sa session expirer et
ses appels tomber en 403, en silence, vers minuit. Une route GET publique
supprime le problème à la racine. Les données sont déjà publiques : c'est le même
classement que dans le Hall of Fame. **N'introduis pas de rafraîchissement de
jeton pour contourner ça.**

Réponse : `window_hours`, `total_count`, `server_time`, `entries[]`, `recent[]`.

- `entries` : le haut du classement dans la fenêtre, paramètre `limit`, **borné
  serveur à 50** quoi que demande le client.
- `recent` : les entrées les plus récentes de la fenêtre, triées par `created_at`
  décroissant, plafonnées à 10. Alimente le bandeau des arrivants hors du top.
- `rank` calculé **serveur**. Ne laisse pas le client le déduire de sa position
  dans le tableau : il se tromperait dès qu'il y a des ex æquo.

En-tête `Cache-Control: no-store`. N'expose rien que le Hall of Fame ne montre
déjà.

**Modèle.** Ajoute `getBoardEntries(int $limit, ?int $windowHours)`,
`getRecentEntries(int $limit, ?int $windowHours)` et
`getCountSince(?int $windowHours)`. **N'élargis pas les signatures** de
`getPaginatedEntries()` ni de `getTopEntries()` : elles servent le Hall of Fame
classique, qui reste all-time, et leurs tests existants doivent rester valides
sans modification.

Filtre `created_at >= NOW() - INTERVAL :hours HOUR`, ignoré si `windowHours` vaut
`null` ou `0`. Garde exactement le même tri que le classement existant —
`game_score DESC, time_seconds ASC, created_at ASC` — sinon le mur et le Hall of
Fame se contrediraient.

**Réglage.** Clé `board_window_hours` dans `SettingsModel`, défaut **24**, `0`
valant « depuis toujours ». Validation serveur entre 0 et 8760. Pour l'instant, un
simple champ entier dans l'écran Admin suffit : **l'itération 6 le déplacera**
dans une section dédiée, ne construis pas l'interface complète maintenant.

Le réglage ne s'applique **qu'au board**. Le Hall of Fame du mobile et du kiosque
reste all-time.

**Tests.** Bornes de la fenêtre, pas seulement le cas évident. `windowHours = 0`
renvoie tout. `limit` au-delà de 50 ramené à 50. La route répond sans session ni
CSRF. Les tests existants de `getPaginatedEntries()` passent sans avoir été
touchés.

**Terminé quand.** `curl` sur `/board/data` renvoie du JSON valide dans un shell
sans cookie, et la fenêtre est réglable.

---

# Itération 3 — Le mur (`?mode=hof`)

**Objectif.** Un écran vertical qui vit tout seul toute la soirée.

**Fichiers.** `public/assets/js/app.js`, `public/assets/css/app.css`.

**Détail.**

Quand `displayMode === 'hof'`, le SPA ne rend que ce tableau. **Aucune
interactivité** : pas de pagination, pas de clic, aucun gestionnaire tactile.
L'écran est tactile mais personne ne le touchera ; un appui accidentel ne doit
rien déclencher.

**Composition, de haut en bas :** titre, podium des trois premiers, liste des
rangs 4 et suivants, zone de bandeau, logo PMPG. Le nombre de lignes s'ajuste à
la hauteur réelle du viewport — ne fige aucun nombre, un 42 pouces vertical peut
être en 1080×1920 comme en 2160×3840. Recalcule au `resize`.

**Rafraîchissement.** `/board/data` toutes les **5 secondes**. Pas de WebSocket ni
de SSE : hébergement mutualisé, et 5 secondes suffisent à l'effet.

**Détection des arrivants.** Conserve l'ensemble des `id` connus ; tout `id`
absent est un nouvel arrivant. À la **toute première** réponse, remplis
l'ensemble sans rien fêter — sinon un rechargement déclencherait une avalanche de
confettis pour des scores d'il y a deux heures.

**Célébration, les deux cas sont fêtés :**

- arrivant dans le haut affiché → sa ligne passe en surbrillance quelques
  secondes, confettis ;
- arrivant hors du haut affiché → bandeau « **{nom}** vient d'entrer — rang
  {rang} », confettis.

Réutilise `boundConfetti`, déjà présent et déjà utilisé par le Hall of Fame.

**File d'attente des bandeaux.** Plusieurs joueurs peuvent finir entre deux
interrogations. Empile et affiche l'un après l'autre, environ 4 secondes chacun.
N'en perds aucun, n'en superpose aucun.

**Résilience — la partie qui compte vraiment.** Si une requête échoue, **garde
les dernières données valides à l'écran** et retente avec un recul progressif
plafonné à 30 secondes. Ne vide jamais l'écran, n'affiche jamais d'erreur en
pleine page : un mur figé sur des données d'il y a deux minutes vaut infiniment
mieux qu'un mur blanc devant cinquante personnes. Après trois échecs consécutifs,
un point discret en coin signale que la donnée est périmée.

**Rémanence d'écran.** Un moniteur affichant le même bloc lumineux pendant huit
heures marque. Évite les aplats clairs permanents et fais dériver très lentement
le conteneur de quelques pixels sur un cycle de plusieurs minutes. Peu coûteux
maintenant, impossible à rattraper après.

Respecte `prefers-reduced-motion` pour la dérive et les confettis.

**Tests.**
- e2e : `?mode=hof` affiche podium et liste, sans nav ni pagination.
- Vitest sur la logique de diff, **isolée de l'affichage** : premier chargement
  ne fête rien ; `id` inédit dans le haut → surbrillance ; `id` inédit hors du
  haut → bandeau ; trois arrivants d'un coup → trois bandeaux successifs.
- Une réponse en échec laisse les données précédentes affichées.

**Terminé quand.** Le mur tourne trente minutes sans intervention et survit à une
coupure réseau simulée.

---

# Itération 4 — Le poste de jeu et sa fin de partie

**Objectif.** Une boucle de jeu qui s'enchaîne toute seule, et une fin de partie
qui récompense le joueur sans le retenir.

**Fichiers.** `public/assets/js/app.js`, `public/assets/css/app.css`.

**Détail.**

Quand `displayMode === 'play'` :

**Le Hall of Fame devient inatteignable.** À la fin d'une partie, `app.js` appelle
aujourd'hui `showScreen('leaderboard')` (l.1095). En mode `play`, ce chemin
disparaît.

**Aucun bouton de partage.** `hasNativeShare()` renverra vrai sur un écran
tactile Windows, et `navigator.share` ouvre la barre de partage de l'OS
par-dessus le kiosque, dont un joueur ne saura pas sortir. Ne te contente pas de
masquer le bouton : n'entre pas du tout dans le chemin de partage en mode `play`.

**Ce que voit le joueur**, dans cet ordre :

1. Une salve de confettis. La fête publique est sur le mur, mais l'écran du
   joueur a le droit de réagir.
2. Une accroche personnelle courte, « Nice one, {prénom} ».
3. **Son score, en très grand**, animé d'un léger jaillissement à l'apparition.
4. Trois statistiques sur une ligne : adresses correctes, temps, indices utilisés.
   Elles donnent de quoi se comparer **sans révéler de rang**.
5. Un bandeau « **Your name is going up on the wall →** », la flèche pointant
   vers l'écran voisin. C'est la pièce maîtresse : la récompense n'est pas
   supprimée, elle est redirigée vers le mur.
6. Un bouton **Play again** dimensionné pour le tactile, dont une barre de
   progression se vide sur les 8 secondes du retour automatique.

Aucun rang, aucun classement, aucun top 3 : c'est ce qui ferait stagner la file.
Le score part en base normalement, c'est le mur qui le fête publiquement.

Ce comportement est **strictement réservé à `play`**. Le mobile, le desktop et le
kiosque iPad gardent leur fin de partie actuelle, Hall of Fame, confettis et
partage compris. Un test doit le prouver.

**Ce qui ne change pas :** la minuterie d'inactivité reste active. Pas
d'économiseur d'écran en mode `play` : un poste de jeu est surveillé, et un
économiseur en pleine soirée ferait croire à une panne.

**Tests.**
- e2e : en `?mode=play`, la fin de partie n'affiche jamais le Hall of Fame,
  n'expose aucun bouton de partage, et revient à l'accueil.
- e2e de non-régression : en URL nue, la fin de partie affiche toujours le Hall
  of Fame avec la ligne du joueur en surbrillance, et le partage reste proposé
  là où il l'était.

**Terminé quand.** Trois parties s'enchaînent sans intervention, et le parcours
mobile est inchangé.

---

# Itération 5 — Clavier tactile

**Objectif.** Saisir un nom au doigt, sur un poste dont le clavier physique est
rangé.

**Fichiers.** `public/assets/js/app.js`, `public/assets/css/app.css`.

**Détail.**

Uniquement en mode `play`. Le mobile et l'iPad ont leur clavier système : le leur
imposer serait une régression.

**Ce clavier n'est pas un confort, c'est la seule voie de saisie.** Windows
n'affiche son clavier tactile que s'il ne détecte aucun clavier physique. Celui
du poste est branché, simplement rangé — Windows n'affichera donc rien, et sans
ce composant le champ reste vide quoi que tapote le joueur.

Champ concerné : `welcomeNameInput` (l.482), `maxlength=50`, seul champ de saisie
de tout le parcours joueur.

**Disposition QWERTY.** L'événement est à Miami, le public international, le jeu
en anglais : QWERTY est la disposition que tout le monde reconnaît, et la
familiarité l'emporte sur toute autre logique dès qu'on ne tape que quelques
lettres.

Ajoute une **rangée de caractères accentués** couvrant le public attendu — au
minimum `á é í ó ú ñ ü ç ø å`. Un forum standards réunit des noms scandinaves,
irlandais, allemands et hispanophones ; sans cette rangée, ils ressortiront tous
écorchés sur le mur.

Touches nécessaires : lettres, apostrophe, trait d'union, espace, retour arrière,
effacer tout, et une touche de validation qui lance la partie.

**Le nom passe par un contrôle de profanité serveur** (`game/check-name`) avant le
démarrage, et le message de refus est inséré juste sous le champ. Le clavier ne
doit ni le recouvrir ni le pousser hors de l'écran : le joueur doit lire pourquoi
son nom est refusé, sans quoi il recommencera à l'identique.

Reflète chaque frappe dans le champ réel plutôt que dans un état parallèle : la
validation, le `maxlength` et le focus existants continuent alors de fonctionner
sans être réécrits.

**Cibles de 72 px minimum**, soit environ 35 mm sur un 42 pouces en 1080p. On
joue debout, à bout de bras.

**Tests.**
- e2e : en `?mode=play`, composer un nom uniquement par appuis permet de lancer
  la partie.
- e2e : le clavier est absent en URL nue et en mode kiosque.
- Un nom refusé affiche son message, et ce message reste visible clavier ouvert.

**Terminé quand.** Une partie complète est jouable sans jamais toucher un clavier
physique.

---

# Itération 6 — Panneau admin « Display modes »

**Objectif.** Qu'un organisateur comprenne en dix secondes comment allumer chaque
écran.

**Fichiers.** `public/assets/js/app.js`, `public/assets/css/app.css`.

**Détail.**

L'écran Admin contient aujourd'hui une section « Kiosk Mode » isolée. Regroupe
les trois façons d'afficher le jeu dans une seule section **Display modes**, avec
une phrase d'introduction qui pose la distinction : le kiosque s'active sur
l'appareil courant, le mur et le poste de jeu s'activent par leur URL, sur leur
propre machine.

**Le panneau n'est pas un interrupteur pour le mur, c'en est le mode d'emploi.**
Ne cède pas à la tentation d'ajouter un bouton qui basculerait le mur depuis
l'admin : il s'éteindrait au premier redémarrage du PC, ce qui est précisément le
problème que l'URL résout.

Trois blocs :

1. **Kiosk mode — this device.** Le toggle existant, déplacé tel quel, renommé
   pour dire clairement qu'il n'agit que sur l'appareil courant.
2. **Dedicated screens.** Deux lignes, une par mode, chacune avec le nom du
   contexte, l'URL absolue construite depuis l'origine courante, un bouton
   **Copy** et un QR code. Réutilise la bibliothèque QR déjà embarquée pour le
   partage en kiosque, n'en ajoute pas une seconde. Sous les deux lignes, la
   commande `chrome --kiosk --app="…"` prête à copier, et une note expliquant
   pourquoi elle est préférable au plein écran déclenché depuis la page.
3. **Wall window.** Le champ `board_window_hours` de l'itération 2, **déplacé
   ici**, avec la mention qu'il n'affecte que `?mode=hof`. Ne le laisse pas en
   double à son ancien emplacement.

**Tests.** e2e : la section affiche les deux URL construites depuis l'origine
courante, et le champ de fenêtre enregistre toujours sa valeur après le
déplacement.

**Terminé quand.** Un organisateur qui n'a pas lu la documentation peut monter
les deux écrans depuis ce seul panneau.

---

# Itération 7 — Documentation et passe finale

**Fichiers.** `README.md`, `DESIGN.md`.

**Détail.**

Ajoute au README une section **Display modes** : le tableau des cinq contextes,
les deux URL, les commandes `chrome --kiosk`, le réglage `board_window_hours`, et
la manière d'atteindre l'administration depuis un poste — charger l'URL sans
paramètre.

Ajoute à `DESIGN.md` les décisions et leurs raisons : le mode est dans l'URL
parce qu'un affichage non surveillé doit survivre à un rechargement ; le masquage
du nav est un garde-fou et non une barrière de sécurité ; le clavier à l'écran
existe parce que Windows supprime le sien en présence d'un clavier physique ; le
partage natif est désactivé en mode `play` parce qu'il ouvre une interface de
l'OS par-dessus le kiosque. Ces quatre raisons sont non évidentes : sans elles,
la prochaine personne défera le travail en croyant simplifier.

Passe finale : les trois suites, plus la CI sur PHP 8.1 et 8.4.

**Terminé quand.** Quelqu'un qui n'a pas suivi le projet peut monter les deux
écrans en lisant le seul README.

---

## Hors périmètre

- Authentifier ou verrouiller les modes d'affichage : garde-fou assumé.
- WebSocket, SSE, ou toute poussée temps réel.
- Appliquer la fenêtre de temps au Hall of Fame classique.
- Une notion d'« événement » ou de session de jeu en base.
- Toucher au mode kiosque iPad existant, ou au glisser-déposer tactile qui
  fonctionne déjà.
