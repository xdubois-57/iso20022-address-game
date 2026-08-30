# Roadmap — Rebrand PMPG

Rebrand complet du jeu ISO 20022 Address Game pour le présenter comme soutenu par
le **Payments Market Practice Group (PMPG)**.

Accord écrit obtenu, wording validé : **« Supported by »**. Le jeu reste l'œuvre de
Xavier Dubois et Niel Buchan ; le PMPG le soutient. Aucun texte ne doit laisser
entendre que le PMPG est l'auteur ou l'éditeur du jeu.

---

## Instructions d'exécution

**À l'attention de Claude Code.**

1. Traite les itérations **dans l'ordre, une par une**. Une itération = un commit
   (ou une PR) autonome, qui laisse le jeu dans un état livrable.
2. **Ne pose pas de question** entre les itérations. Enchaîne. Deux exceptions, et
   deux seulement :
   - un **changement de design** est nécessaire par rapport à ce qui est écrit ici ;
   - une **ambiguïté fonctionnelle** rend deux comportements également défendables.
   Dans ces deux cas, arrête-toi et pose la question.
3. **Rien ne part sur `main` tant que les tests ne sont pas intégralement verts.**
   Les trois suites, à chaque itération :
   ```bash
   composer test      # PHPUnit
   npm test           # Vitest
   npm run e2e        # Playwright / Chromium
   ```
   Une suite rouge bloque le push. Pas de `--skip`, pas de test commenté, pas de
   `test.skip()`.
4. Ne touche jamais à `config/`, `storage/`, `uploads/`. Ce sont des répertoires
   d'exécution, gitignorés ou porteurs de secrets.
5. Tout nouveau fichier source (PHP, JS, CSS) reçoit l'en-tête GPL v3 utilisé
   partout dans le repo.
6. Mets à jour `README.md` et `DESIGN.md` **dans l'itération qui change le
   comportement**, pas à la fin. Ces deux fichiers sont la documentation de
   référence du projet et ils sont actuellement exacts — garde-les exacts.

---

## Contraintes techniques constatées

Relevées lors de la revue du repo. À respecter, elles ne sont pas négociables.

- **La CSP autorise déjà les images locales** : `img-src 'self' data:`
  (`public/index.php`, `sendSecurityHeaders()`). Un PNG servi depuis
  `public/assets/images/` passe sans modifier la politique. **Ne charge pas le
  logo depuis un CDN** — cela demanderait d'élargir la CSP, ce qui est un recul
  de sécurité pour un gain nul.
- **Le SPA construit son HTML par concaténation de chaînes** dans
  `public/assets/js/app.js`. Pas de framework de templating. Suis le style en
  place. Le balisage du logo est statique : pas d'interpolation de données, donc
  pas de passage par `escapeHtml()` — mais si tu injectes quoi que ce soit de
  dynamique à côté, `escapeHtml()` reste obligatoire.
- **Deux sources de vérité pour le thème, à garder synchronisées** :
  `App\Models\ThemeModel::defaults()` (PHP, `app/Models/ThemeModel.php` l.45-49)
  et `themeDefaults` (JS, `public/assets/js/app.js` l.1749). Une divergence
  entre les deux donne un bouton « Reset to Defaults » qui restaure des couleurs
  différentes de celles d'une installation neuve. Un test doit garantir l'égalité.
- **Les fallbacks CSS statiques** (`public/assets/css/app.css`, bloc `:root`) sont
  une troisième copie, utilisée quand l'injection par `layout.php` n'a pas lieu.
  Elle doit suivre aussi.
- **Le cache-busting existe pour `layout.php`** via `assetUrl()` (mtime en query
  string). Les chaînes construites dans `app.js` n'en bénéficient pas. Pour un
  asset qui ne changera plus, un nom de fichier versionné ou un `?v=` figé suffit ;
  ne réinvente pas de mécanisme.
- **Le logo PMPG est une marque déposée.** Le projet est sous GPL v3. La GPL
  couvre le code, **pas les marques** : un fork ne reçoit aucun droit d'usage du
  logo. Ce point est traité en itération 2 et n'est pas facultatif.

---

## Palette PMPG

Couleurs échantillonnées directement dans le logo fourni.

| Variable | Avant | Après | Rôle |
|---|---|---|---|
| `color_primary` | `#00364a` | `#3D345F` | Violet PMPG — boutons, chips, accents |
| `color_primary_hover` | `#00a3d7` | `#2C2646` | Violet assombri — états hover |
| `color_primary_light` | `#caf0fe` | `#DCEAF3` | Bleu très clair — slots remplis, highlights |
| `color_bg` | `#94e3fe` | `#8ABED9` | Bleu du sunburst — fond de page et d'image |
| `color_text` | `#00364a` | `#3D345F` | Violet PMPG — texte et titres |

Contrastes vérifiés : `#3D345F` sur `#8ABED9` ≈ 5,7:1 et sur blanc ≈ 12:1 — au-dessus
du seuil WCAG AA dans les deux cas. Blanc sur `#3D345F` ≈ 12:1. Ne dérive pas de
ces valeurs sans revérifier les contrastes.

---

## Assets

Trois fichiers fournis à côté de cette roadmap, à committer dans
`public/assets/images/` :

| Fichier | Dimensions | Usage |
|---|---|---|
| `pmpg-logo.png` | 1095×282, fond transparent | Carte d'accueil, footer, share card |
| `pmpg-mark.png` | 512×512, fond transparent | Sunburst seul — apple-touch-icon |

Le fichier d'origine avait un fond crème `#F8F7F5` qui se serait vu sur la carte
blanche : les deux assets sont détourés, canal alpha propre.

---

# Itération 1 — Assets et logo sur la carte d'accueil

**Objectif.** Le joueur voit le soutien du PMPG dès l'écran d'accueil.

**Fichiers.** `public/assets/images/` (ajout), `public/assets/js/app.js`,
`public/assets/css/app.css`.

**Détail.**

Committer `pmpg-logo.png` et `pmpg-mark.png` dans `public/assets/images/`.

Ajouter en pied de la carte blanche, sous le bouton, séparé par un filet
horizontal :

- un label `Supported by` — petit, en majuscules, gris discret, au-dessus du logo ;
- le logo PMPG, largeur ~172px, centré, `alt="Payments Market Practice Group"`.

L'alt text n'est pas décoratif : le logo porte l'information du soutien, il doit
être annoncé aux lecteurs d'écran.

Le bloc va dans **les deux** rendus de `.welcome-card` :

- `renderWelcomeCard()` (~l.468) — écran d'accueil normal ;
- `renderEventCodeGate()` (~l.417) — écran de saisie du code d'événement.

Le second compte autant que le premier : quand un code d'événement est actif,
c'est le premier écran que voit le joueur. Extrais le fragment dans une petite
fonction partagée plutôt que de le dupliquer.

Le logo ne doit pas être cliquable. Un lien sortant depuis un kiosque en Guided
Access piège l'utilisateur dans un navigateur dont il ne peut pas revenir.

**Tests.**
- `tests/e2e/specs/boot.spec.js` : le logo est visible sur l'accueil, et son
  `alt` vaut `Payments Market Practice Group`.
- Un test e2e couvrant l'écran de code d'événement, pour verrouiller le fait que
  le logo y figure aussi.

**Terminé quand.** Le logo apparaît sur les deux cartes, les trois suites passent,
et le rendu mobile (≤768px) ne déborde pas.

---

# Itération 2 — Textes légaux et mention de marque

**Objectif.** Supprimer la contradiction entre l'affichage et la documentation, et
protéger la marque PMPG dans un projet GPL.

**Fichiers.** `README.md`, `public/assets/js/app.js` (écran Privacy, ~l.2065 et
~l.2069), `DESIGN.md`.

**Détail.**

Le repo affirme aujourd'hui, à deux endroits, que le jeu *« is not affiliated with
or endorsed by any organisation »*. Cette phrase devient fausse à la seconde où le
logo arrive sur la home. C'est le point le plus sensible de tout le rebrand : une
banque qui lit la page Privacy y trouverait le démenti de ce que la page d'accueil
affiche.

Remplacer, dans le README (§ *Legal notice*) et dans l'écran Privacy, par une
formulation qui tient les deux bouts :

> This game was created as an educational tool by **Xavier Dubois** and **Niel
> Buchan**, and is supported by the **Payments Market Practice Group (PMPG)**. It
> is developed and maintained by its authors; the PMPG endorses it but does not
> operate it.

**Ne pas toucher** au paragraphe des responsables de traitement (~l.2069) : les
data controllers restent Xavier Dubois et Niel Buchan. Le PMPG ne traite aucune
donnée personnelle et ne doit surtout pas être désigné comme tel — ce serait une
déclaration RGPD inexacte, avec des conséquences réelles.

Ajouter au README, dans *Third-party assets*, une sous-section *PMPG logo* :

> The PMPG name and logo are trademarks of the Payments Market Practice Group,
> used with permission. They are **not** covered by the GPL v3 licence granted
> over this project's source code: a fork receives the code, not the right to use
> the mark. Remove the logo assets and the "Supported by" wording before
> redistributing a modified version.

**Tests.** Pas de test automatisé pertinent — c'est de la documentation. Vérifier
manuellement que l'écran Privacy rend le nouveau texte sans casser le balisage.

**Terminé quand.** Plus aucune occurrence de « not affiliated with or endorsed by
any organisation » dans le repo, et la mention de marque est en place.

---

# Itération 3 — Palette par défaut PMPG

**Objectif.** Les couleurs par défaut du jeu deviennent celles du PMPG, et le
bouton « Reset to Defaults » restaure ces couleurs-là.

**Fichiers.** `app/Models/ThemeModel.php`, `public/assets/js/app.js`,
`public/assets/css/app.css`, `DESIGN.md`, `README.md`.

**Détail.**

Appliquer la palette du tableau ci-dessus aux **trois** copies : `ThemeModel::defaults()`,
`themeDefaults` dans `app.js`, et le bloc `:root` de `app.css`.

**Le bouton de reset doit devenir une vraie remise à zéro serveur.** C'est le point
le plus important de cette itération : c'est le seul chemin de migration pour les
installations déjà déployées.

Comportement actuel de `resetThemeBtn` (`app.js` ~l.1817) : il remplit les champs
du formulaire avec `themeDefaults` et affiche « Click "Save Colors" to persist ».
Il ne persiste rien. Deux défauts pour notre usage :

1. Un admin qui clique Reset, voit les pastilles changer et quitte la page croit
   avoir migré alors que rien n'est enregistré.
2. Le second clic sur « Save Colors » écrit les cinq hex comme lignes explicites
   en base. L'installation repasse en couleurs PMPG mais devient **épinglée** :
   elle ne suivra plus jamais un futur changement de défauts.

Le comportement correct est de **supprimer les lignes de thème**, pas de les
réécrire. `ThemeModel::get()` part de `DEFAULTS` et n'écrase que les clés
présentes en base ; sans lignes, l'installation suit les défauts, exactement
comme une installation neuve. `SettingsModel::delete()` existe déjà.

À construire :

- **Nouvelle route `admin/reset-theme`** dans `public/index.php` (à côté de
  `admin/get-theme` et `admin/save-theme`, l.304-305), servie par une méthode
  `AdminController::resetTheme()`. Elle supprime les cinq clés de `ThemeModel::KEYS`
  via `SettingsModel::delete()` et renvoie le thème résultant.
  Mêmes garanties que les routes admin voisines : session admin requise,
  jeton CSRF vérifié. Ne l'expose pas sans authentification.
- **Une méthode `ThemeModel::reset()`** qui porte la suppression, pour que la
  logique reste dans le modèle et soit testable sans passer par HTTP.
- Câbler `resetThemeBtn` sur cette route : un seul clic, qui persiste. Passer par
  la modale de confirmation maison utilisée ailleurs dans l'app — c'est une
  action destructive sur une personnalisation — puis rafraîchir les champs avec
  le thème renvoyé et afficher « Reset to PMPG colours. Reload to apply. »
- Renommer le libellé en **« Reset to PMPG colours »**, et non « Reset to
  Defaults » : l'admin doit savoir vers quoi il repart avant de cliquer.

Ne conserve pas l'ancien comportement « remplit sans enregistrer » en parallèle.
Deux boutons de reset aux sémantiques différentes seraient un piège.

Mettre à jour la palette documentée dans `DESIGN.md` § 2.2 et dans le § *Customize
Theme* du README, en y précisant ce que fait le bouton — supprimer la
personnalisation, pas écrire les couleurs PMPG.

**Note sur les installations existantes.** Celles qui n'ont jamais enregistré de
thème n'ont aucune ligne en base et basculent seules sur la palette PMPG à la
mise à jour. Celles qui ont sauvegardé au moins une fois gardent leurs couleurs
jusqu'à un clic sur le bouton. Ne force pas de migration automatique : écraser le
thème choisi par un admin sans qu'il l'ait demandé n'est pas acceptable.

**Tests.**
- `tests/ThemeModelTest.php` : les défauts valent bien la palette PMPG.
- `ThemeModel::reset()` : après avoir enregistré un thème personnalisé, le reset
  supprime les lignes et `get()` renvoie de nouveau les défauts PMPG. C'est le
  scénario exact d'une instance déjà déployée — il doit être couvert.
- La route `admin/reset-theme` refuse une requête sans session admin, et une
  requête sans jeton CSRF valide.
- **Un test qui compare `ThemeModel::defaults()` et `themeDefaults` du JS** et
  échoue s'ils divergent. Il n'existe pas aujourd'hui et c'est précisément le
  genre de désynchronisation qui passe inaperçue. Lis la constante JS depuis le
  fichier source, ne la recopie pas dans le test.

**Terminé quand.** Une installation neuve démarre en couleurs PMPG ; sur une
installation portant un thème teal enregistré, un clic sur « Reset to PMPG
colours » suivi d'un rechargement affiche la palette PMPG ; et le test de
synchronisation PHP/JS existe et passe.

---

# Itération 4 — En-tête et pied de page

**Objectif.** Le co-branding tient sur toutes les pages, pas seulement l'accueil.

**Fichiers.** `app/Views/layout.php`, `public/assets/css/app.css`.

**Détail.**

Le titre `<h1 class="logo">ISO 20022 Address Game</h1>` reste du texte et reste le
titre. Le logo PMPG ne le remplace pas — le jeu garde son nom.

Ajouter le logo PMPG dans le pied de page (`<footer class="game-footer">`, l.168),
précédé de `Supported by`, sur sa propre ligne au-dessus de la ligne de version.
Utiliser `assetUrl()` comme le reste du layout.

Hauteur discrète (~24px). Le footer est déjà chargé : ne l'alourdis pas
visuellement, le logo de l'accueil porte déjà le message.

**Tests.** e2e : le logo du footer est présent sur l'accueil et sur au moins un
autre écran (Hall of Fame), pour prouver qu'il est bien dans le layout et pas
dans une vue.

**Terminé quand.** Le logo apparaît en pied sur toutes les pages, sans casser le
rendu mobile où le footer passe déjà sur plusieurs lignes.

---

# Itération 5 — Apple touch icon

**Objectif.** L'icône d'écran d'accueil iPad porte l'identité PMPG.

**Fichiers.** `app/Controllers/AppIconController.php`, `README.md`.

**Détail.**

L'icône actuelle est générée à la volée : fond arrondi aux couleurs du thème +
emoji 🎮 (`emoji-controller.png`) + texte. Deux chemins de rendu, Imagick avec
repli GD — **les deux doivent être traités**, sinon les installations sans Imagick
gardent l'ancienne icône.

Remplacer l'emoji 🎮 par `pmpg-mark.png` (le sunburst), composité au même endroit
et à la même taille. Garder le fond arrondi thémé et le texte : c'est ce qui rend
l'icône lisible à 180px.

Ne supprime pas `emoji-controller.png` du repo dans cette itération — si le rendu
du sunburst déçoit à petite taille, le retour arrière doit être immédiat.

Le sunburst est clair sur ses pétales basses ; sur un fond `#8ABED9` il risque de
se fondre. Vérifie le rendu réel avant de valider, et si le contraste est
insuffisant, pose le sunburst sur une pastille blanche.

**Tests.** Étendre les tests existants du contrôleur d'icône : la route répond un
PNG valide de 180×180 sur les deux chemins de rendu. Si Imagick n'est pas
disponible dans l'environnement de test, ne teste que GD plutôt que de skipper.

**Terminé quand.** `/app-icon` renvoie l'icône au sunburst, sur les deux chemins,
et le rendu à 180px est lisible.

---

# Itération 6 — Share cards et OpenGraph

**Objectif.** Une image partagée sur LinkedIn porte le soutien PMPG. C'est le point
de contact le plus visible en dehors du jeu.

**Fichiers.** `app/Controllers/ShareController.php`, `app/Views/layout.php`,
`app/Views/share.php`, `DESIGN.md`.

**Détail.**

Composer `pmpg-logo.png` dans la share card 1200×630 générée par
`buildShareImageImagick()` : en bas, centré, largeur ~260px, précédé de
`Supported by` dans la même fonte que le reste.

Attention à la zone de texte centrale déjà réservée pour éviter les ballons
(`$balloonPalette`, l.141-149) : le logo doit avoir sa propre zone d'exclusion,
sinon un ballon lui passera dessus. Étends la logique existante, ne la contourne
pas.

Adapter la palette des ballons aux couleurs PMPG — les teintes actuelles sont
accordées au thème teal.

Mettre à jour les meta OpenGraph et Twitter de `layout.php` (l.130-145) :
`og:site_name` et les descriptions mentionnent le soutien PMPG. Garder les titres
courts, LinkedIn tronque.

**Tests.** `tests/ShareControllerTest.php` : l'image générée fait toujours
1200×630 et reste un PNG valide. Un test d'image ne vérifie pas l'esthétique —
contrôle visuellement le rendu une fois.

**Terminé quand.** La share card intègre le logo sans collision avec les ballons
ni le texte, et les meta sont à jour.

---

# Itération 7 — Documentation et passe finale

**Objectif.** La documentation décrit le produit tel qu'il est, et tout est vert.

**Fichiers.** `README.md`, `DESIGN.md`.

**Détail.**

Relire les deux fichiers de bout en bout à la recherche de ce que les six
itérations ont rendu faux : palette, mentions d'affiliation, description de
l'icône, description de la share card, section *Features*.

Ajouter à `DESIGN.md` une courte section **Branding** qui énonce la règle, pour
que la prochaine personne ne se pose pas la question : le PMPG soutient le jeu,
les auteurs le maintiennent, le logo apparaît sur la carte d'accueil, le footer,
l'icône et la share card, et la marque n'est pas couverte par la GPL.

Passe finale : les trois suites, sur une base propre.

**Terminé quand.** `composer test`, `npm test` et `npm run e2e` sont verts, la CI
passe sur les deux versions de PHP (8.1 et 8.4), et aucune affirmation de la
documentation n'est démentie par le code.

---

## Hors périmètre

À ne pas faire sans décision explicite :

- renommer le jeu ou le dépôt ;
- ajouter un lien sortant vers le site du PMPG depuis un écran de jeu (piège en
  mode kiosque) ;
- migrer de force le thème des installations existantes ;
- désigner le PMPG comme responsable de traitement RGPD.
