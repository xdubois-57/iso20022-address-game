# Roadmap — Contenu, partage, tokens et preuves de test

Sept itérations, deux pistes. Les trois premières touchent l'application, les
quatre suivantes la chaîne d'intégration. Elles sont indépendantes : si le temps
manque, la piste application peut partir seule.

## Contexte des décisions

Ces choix ont été arbitrés en amont. Ne les rouvre pas sans le dire.

| Sujet | Décision |
|---|---|
| Échéance par défaut | `2027-11-28T00:00`, minuit en début de journée |
| Instances déployées | **Pas** de migration : la constante change, les instances sans date enregistrée suivent |
| Partage désactivé | **Interface seulement.** Les routes serveur continuent de répondre |
| Partage par défaut | **Activé**, comportement actuel préservé |
| Token absent ou périmé | Retour au **mode normal avec menus**, silencieusement |
| Analyse statique | PHPStan + `tsc`, avec baselines. **Pas** d'ESLint, **pas** de PHPCS |
| Rapport ZAP publié | **Résumé par sévérité uniquement**, jamais les détails |
| Déclencheur des preuves | Tag `v*` et `workflow_dispatch` |

---

# Itération 1 — Textes et échéance

**Objectif.** La page Privacy ne parle plus du PMPG, et le compte à rebours vise
la bonne date.

**Fichiers.** `public/assets/js/app.js`, `app/Controllers/GameController.php`,
`app/Models/Database.php`, `README.md`.

**Détail.**

**Retirer le PMPG de l'écran Privacy** (`app.js` ~l.2605). La phrase à supprimer
est celle qui nomme le PMPG comme soutien.

Attention au piège : **ne réintroduis pas** l'ancienne formule *« not affiliated
with or endorsed by any organisation »*, retirée précisément parce qu'elle était
devenue fausse. La page doit rester **silencieuse** sur l'affiliation, pas la
nier pendant que l'accueil affiche « Supported by ». Le paragraphe des
responsables de traitement ne nomme déjà que Xavier Dubois et Niel Buchan : n'y
touche pas.

Vérifie la section *Legal notice* du README et aligne-la sur le même silence.

**Changer l'échéance par défaut.** `GameController::DEFAULT_DEADLINE` passe de
`'2026-11-14T18:00'` à `'2027-11-28T00:00'`.

Rien ne sème cette valeur en base à l'installation : `SettingsModel::get('unstructured_deadline')`
renvoie `null` tant qu'aucun admin n'a enregistré de date, et la constante sert
alors de valeur effective. Une instance déployée sans date enregistrée verra donc
son compte à rebours changer à la mise à jour. **C'est assumé** — ne construis
aucune migration pour l'empêcher.

**Corriger le fait semé.** `Database.php` ~l.384 sème un fait « Did You Know »
annonçant novembre 2026 comme échéance. Laissé tel quel, le jeu se contredirait à
l'écran. Mets le texte à jour.

Note que les installations existantes gardent l'ancien libellé en base : le
semis ne rejoue pas. Signale-le dans le README, l'admin peut corriger le fait
depuis son écran de gestion.

**Tests.** La constante vaut la nouvelle valeur. L'écran Privacy ne contient plus
« PMPG » ni « Payments Market Practice Group », et ne contient pas non plus « not
affiliated ».

---

# Itération 2 — Switch de partage

**Objectif.** Pouvoir masquer entièrement le partage sans rien casser.

**Fichiers.** `public/index.php`, `app/Views/layout.php`,
`public/assets/js/app.js`, `app/Controllers/AdminController.php`, `README.md`.

**Détail.**

Clé `sharing_enabled` dans `SettingsModel`, défaut **`'1'`** — le comportement
actuel est préservé sur une installation neuve.

**La coupure est côté interface uniquement.** Les cinq routes serveur —
`/share`, `/share/go`, `/share/image`, `/share/home-image`, `POST api/share/token`
— **continuent de répondre normalement**. Trois raisons, à respecter :

- un lien déjà partagé par un joueur doit continuer de fonctionner, sinon on
  casse quelque chose qui vit dans le fil LinkedIn de quelqu'un ;
- `/share/home-image` n'est pas du partage de score, c'est l'image OpenGraph du
  site lui-même. La couper dégraderait l'aperçu de tout lien vers le jeu ;
- c'est une décision produit — masquer une fonctionnalité — et non un contrôle
  d'accès. Ne la présente pas comme une mesure de sécurité.

**Transmission au client.** Passe la valeur comme le mode d'affichage : un
attribut sur le `<body>` dans `layout.php`, lu une fois au démarrage. Pas de
requête supplémentaire, et le même mécanisme que `data-mode`, déjà en place et
déjà compris.

**Surfaces à masquer** quand le partage est désactivé, dans `renderFinalScore()` :
`shareScoreBtn`, `linkedinShareBtn`, `copyLinkBtn`, et le bloc
`kioskQrContainer`. Ne te contente pas de les cacher en CSS : ne les rends pas et
ne lie aucun gestionnaire.

**Ne défais pas le blocage du mode `play`.** Il coupe déjà tout le chemin de
partage pour sa propre raison — `navigator.share` ouvre une interface de l'OS
par-dessus le kiosque. Les deux mécanismes sont orthogonaux et doivent coexister :
le mode `play` ne partage jamais, quel que soit le réglage.

**Admin.** Un interrupteur dans une section claire, avec une phrase disant que
les liens déjà partagés continueront de fonctionner. Sans ça, un admin croira
avoir révoqué quelque chose.

**Tests.** Réglage à `0` : aucun des quatre éléments n'est présent dans le DOM.
Réglage à `1` : comportement actuel inchangé. Et, réglage à `0`, `/share?d=<token>`
répond toujours 200 — c'est le test qui verrouille l'intention.

---

# Itération 3 — Token sur les URL de mode

**Objectif.** Des URL d'affichage indevinables, révocables d'un bouton.

**Fichiers.** `public/index.php`, `app/Models/SettingsModel.php` (usage),
`app/Controllers/AdminController.php`, `public/assets/js/app.js`, `README.md`.

**Détail.**

Clé `display_mode_token`. Génère-la avec `bin2hex(random_bytes(16))` — un jeton
opaque et aléatoire. **N'utilise pas la classe `Encryption`** : il n'y a rien à
chiffrer ici, seulement à comparer. Génération paresseuse à la première lecture
si la clé est absente, pour que les installations existantes en obtiennent une
sans migration.

**Validation** dans `index.php`, là où le mode est déjà validé. Le mode n'est
retenu que si `$_GET['t']` correspond au jeton stocké, comparé avec
`hash_equals()`. Toute autre situation — jeton absent, faux, clé jamais générée —
retombe sur `''`.

**Ce comportement de repli est délibéré et doit être documenté.** Un jeton
inconnu vaut un mode inconnu, donc le mode par défaut, exactement comme
`?mode=nimportequoi` aujourd'hui. Pas de page d'erreur : un mur ne doit jamais
afficher une erreur devant une salle.

Sa contrepartie est réelle : **régénérer pendant un événement transforme
silencieusement les deux écrans en pages ordinaires avec menus.** Aucun message
n'apparaît. C'est une panne muette, plus difficile à diagnostiquer qu'une panne
bruyante — d'où les exigences ci-dessous, qui ne sont pas cosmétiques.

**Bouton de régénération.** Route `admin/regenerate-display-token`, session admin
et jeton CSRF exigés comme les routes admin voisines. Derrière la modale de
confirmation maison, dont le texte doit dire explicitement que **les deux écrans
retomberont en mode normal sans prévenir** et qu'il faudra les rouvrir avec les
nouvelles adresses.

Après régénération, le panneau admin affiche immédiatement les nouvelles URL, QR
et commandes `chrome --kiosk`, sans rechargement. La remise en route doit être
une affaire de trente secondes, pas une chasse au trésor.

**Panneau admin.** Les deux URL de la section *Dedicated screens* portent
désormais `&t=<token>`, ainsi que les QR et les commandes de lancement.

**À dire clairement dans la documentation** : le jeton rend les URL
indevinables, il ne ferme rien. `/board/data` reste public et non authentifié par
conception. C'est un durcissement du garde-fou, pas une barrière de sécurité.

**Tests.** Bon jeton et `?mode=hof` → shell sans nav. Mauvais jeton → shell
normal avec nav. Jeton absent → shell normal. Après régénération, l'ancienne URL
retombe en mode normal. La route de régénération refuse sans session admin et
sans CSRF.

Ne journalise jamais le jeton.

---

# Itération 4 — Analyse statique

**Objectif.** Attraper les défauts que les tests ne voient pas, sans reformater
une ligne.

**Fichiers.** `composer.json`, `package.json`, `phpstan.neon`,
`phpstan-baseline.neon`, `tsconfig.json`, `scripts/js-typecheck.mjs`,
`js-typecheck-baseline.json`, `.github/workflows/ci.yml`, `README.md`.

**Détail.**

Pas d'ESLint, pas de PHP_CodeSniffer. On ne cherche pas du style, on cherche des
défauts : identifiants non résolus, mauvais nombre d'arguments, accès à une
propriété inexistante. C'est l'approche retenue dans le projet scoutmagic et
elle se transpose telle quelle.

**PHP.** `phpstan/phpstan` en `require-dev`, `phpstan.neon` au niveau **6**,
analysant `app/` et `public/index.php`. Script `composer run analyse`.

**JavaScript.** `typescript` en `devDependencies` — **uniquement comme
vérificateur**, jamais comme étape de construction. `tsconfig.json` avec
`allowJs`, `checkJs`, `noEmit`, `strict: false`, sur `public/assets/js/**/*.js`.
Rien n'est émis, rien ne part en production, le SPA reste du JavaScript brut non
bundlé.

**Le mécanisme de baseline est le cœur de l'itération.** PHPStan sait ignorer une
dette préexistante ; `tsc` non. Porte `scripts/js-typecheck.mjs` depuis
scoutmagic : il exécute `tsc`, compare aux constats enregistrés dans
`js-typecheck-baseline.json`, et n'échoue que sur un constat **nouveau**.

Le point à ne pas rater dans ce portage : la baseline indexe par **fichier + code
d'erreur + message, jamais par numéro de ligne**. Une modification ailleurs dans
le fichier ne doit pas produire de faux « nouveau constat » par décalage de
lignes. Script `npm run typecheck`.

**Cette itération ne corrige aucun constat.** Elle installe les outils, génère
les deux baselines, et **rapporte leur taille**. `app.js` fait près de trois
mille lignes et appelle `.value` sur des `Element` génériques d'un bout à
l'autre : `tsc` en signalera beaucoup. Résorber cette dette est un autre travail,
qui ne doit pas bloquer celui-ci. Sans baseline, le job serait rouge en
permanence et tout le monde apprendrait à l'ignorer, ce qui est pire que pas
d'analyse du tout.

Documente dans le README qu'on ne régénère une baseline que pour accepter
délibérément une dette existante — **jamais** pour masquer un constat qu'on vient
d'introduire.

**CI.** Un job `static-analysis` qui exécute les deux commandes.

**Terminé quand.** Les deux commandes passent au vert sur `main` sans qu'une
ligne de code applicatif ait changé, et les tailles de baseline sont rapportées.

---

# Itération 5 — CodeQL

**Objectif.** Analyse de sécurité du code, dans les limites de ce que l'outil
sait faire ici.

**Fichiers.** `.github/workflows/codeql.yml`.

**Détail.**

Workflow CodeQL standard sur `push`, `pull_request` et une planification
hebdomadaire. Permissions `security-events: write`.

**CodeQL ne supporte pas PHP.** Le langage analysé sera `javascript-typescript`,
et rien d'autre. C'est-à-dire que la majorité de la logique de cette application
— tout `app/` — **n'est pas couverte**. Écris-le en commentaire en tête du
workflow, sinon quelqu'un finira par croire que le PHP est analysé parce qu'un
badge est vert. La couverture du PHP vient de PHPStan et de SonarCloud, pas de
là.

**Terminé quand.** Le workflow tourne et publie ses résultats dans l'onglet
Security, avec la limitation documentée.

---

# Itération 6 — DAST passif avec ZAP

**Objectif.** Un scan passif sur chaque push, qui fait échouer la build à partir
de Medium.

**Fichiers.** `scripts/dast.sh`, `scripts/dast-tls-proxy.php`,
`tests/dast/zap-passive.yaml`, `.github/workflows/ci.yml`, `SECURITY.md` ou
`README.md`.

**Détail.**

Transpose le harnais de scoutmagic. Sa structure est la bonne : provisionner une
instance jetable, la servir par le **vrai** point d'entrée `public/index.php`, la
parcourir avec la suite Playwright existante à travers ZAP en proxy, produire un
rapport, tout démonter par un trap `EXIT`.

**La suite Playwright est la surface d'attaque, pas le spider de ZAP.** Elle
traverse déjà des parcours qu'aucun crawler n'atteint : l'écran admin derrière
son code PIN, les modes d'affichage, la fin de partie. C'est l'image la plus
fidèle de la surface réelle qui existe.

**Ce qui se simplifie chez toi.** Scoutmagic provisionne MySQL dans un conteneur ;
ici `scripts/e2e.sh` fournit déjà une instance SQLite jetable, un port libre, une
sonde de démarrage et un nettoyage complet. Réutilise ce provisionnement plutôt
que d'en écrire un second. Ton `dast.sh` sera nettement plus court que le sien.

**Ce qui ne se simplifie pas : il faut du HTTPS.** `scripts/e2e.sh` sert en HTTP
simple, or deux protections de l'application sont conditionnées à HTTPS —
l'en-tête HSTS (`index.php` ~l.97) et `session.cookie_secure` (l.155). Un scan en
clair remonterait « pas de HSTS » et « cookie sans Secure » : deux constats
**faux**, portant sur du code correct, qu'on serait tenté de taire par des
filtres d'alerte. C'est ainsi qu'on cesse de voir les vrais. Porte donc
`scripts/dast-tls-proxy.php` depuis scoutmagic et sers le scan en HTTPS.

**Pas de collision avec `npm run e2e`.** Ports propres, instance propre. Rien ne
doit toucher ce que `e2e.sh` possède.

**Seuil.** `DAST_THRESHOLD` par défaut à `Medium` : la build échoue sur tout
constat au niveau Medium ou au-dessus.

**Pas d'upload SARIF vers l'onglet Security.** Scoutmagic documente précisément
pourquoi et la raison vaut ici : le code scanning veut ancrer un constat à un
fichier du dépôt, alors qu'un constat DAST porte sur une **instance en cours
d'exécution**, servie sur un port tiré au hasard. Réécrire ces URL en chemins du
dépôt reviendrait à inventer un emplacement source à un constat qui n'en a pas.
Le gate est assuré par le code de sortie, pas par l'onglet.

**CI.** Un job `dast` sur push, avec le pull explicite de l'image ZAP.

---

# Itération 7 — Workflow de release et dossier de preuves

**Objectif.** Un tag produit une Release en brouillon accompagnée des preuves.

**Fichiers.** `.github/workflows/release.yml`, workflow réutilisable,
`tests/e2e/playwright.config.js`, `composer.json`, `package.json`, `README.md`.

**Détail.**

**Déclencheurs :** push d'un tag `v*`, et `workflow_dispatch`. Le second compte
autant que le premier : il permet de produire des preuves de ce qui est déployé,
ou de répéter la chaîne, sans couper de version.

**Ne fusionne pas avec `ci.yml`.** Le CI reste la boucle de rétroaction rapide
sur chaque push ; la release est la passe lente et complète. En revanche,
**extrais les jobs communs dans un workflow réutilisable** appelé par les deux :
la configuration PHP est déjà dupliquée quatre fois dans `ci.yml` et ce travail
en ajouterait deux.

**Contenu du dossier**, uniquement ce que les outils émettent nativement. Aucune
documentation rédigée à la main : elle ne serait pas maintenue et finirait par
mentir.

| Preuve | Origine |
|---|---|
| Tests PHP 8.1 et 8.4 | PHPUnit `--log-junit` |
| Tests JavaScript | Vitest `--reporter=junit` |
| Bout en bout | Rapport HTML Playwright, une capture par test |
| Analyse statique PHP | Sortie PHPStan |
| Analyse statique JS | Sortie `tsc` via `npm run typecheck` |
| CodeQL | SARIF du workflow |
| SonarCloud | Résumé de quality gate via leur API |
| DAST | **Résumé par sévérité uniquement** |

**Le rapport ZAP complet ne part pas.** Le dépôt est public, donc les assets de
Release le sont aussi — et les artefacts de workflow également, contrairement à
ce qu'on croit souvent. Publier un rapport DAST détaillé, c'est offrir une
cartographie. Seul le décompte par sévérité est publié. Le rapport complet reste
consultable dans les journaux du job pour qui débogue une build rouge.

**Captures d'écran.** `screenshot: 'only-on-failure'` aujourd'hui. Passe-le à
`'on'` **conditionnellement**, piloté par une variable d'environnement
d'évidence, pour qu'un `npm run e2e` ordinaire reste léger. Exclus vidéos et
traces des tests réussis : c'est ce qui fait exploser le poids du pack.

**Ordonnancement.** Tous les gates d'abord. La Release n'est créée **que si tout
est vert**, et en **brouillon** — tu lis les preuves avant qu'elles ne deviennent
publiques. Si un gate échoue, aucune Release n'est créée : le tag existe et ne
pointe sur rien de publié, ce qui se rattrape en le supprimant et en le reposant.

**Terminé quand.** Un `workflow_dispatch` produit un brouillon de Release avec
toutes les preuves attachées, et aucun détail de scan de sécurité n'y figure.

---

## Hors périmètre

- Résorber les baselines PHPStan ou `tsc` — c'est un autre travail.
- Rendre `/board/data` authentifié.
- Faire du token un contrôle d'accès.
- Couper les routes de partage côté serveur.
- Migrer l'échéance des instances déployées.
- Un profil ZAP actif : le passif seul est un gate, l'actif attaque le site.
