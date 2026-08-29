# Portage Rails → Symfony

Ce document sert de carte du chantier. Il indique où en est le portage, comment
les concepts Rails se traduisent en Symfony, et ce qu'il reste à faire.

L'application Rails d'origine (`../pikaichu`) reste **la référence de
comportement**. En cas de doute sur une règle métier, lisez le modèle Rails
correspondant plutôt que de deviner.

---

## 1. État d'avancement

### Socle — terminé

- Stack Docker (PHP-FPM 8.5, nginx, PostgreSQL 16, Adminer), `Makefile`, outils
  qualité alignés sur ceux de l'équipe.
- **18 entités Doctrine** couvrant l'intégralité du schéma Rails (dont
  `LogEntry`, propre à la piste d'audit — voir plus bas), migration initiale
  appliquée, index et contraintes repris à l'identique.
- Authentification (connexion, déconnexion, inscription), droits par taikai.
- Traductions `fr` / `en`.
- **PHPStan niveau 7 sans erreur**, 155 tests verts.

### Fonctionnalités portées

| Domaine | État | Détail |
|---|---|---|
| Tableau de bord | ✅ | Racine `/`, nombre de taikai et de clubs gérés (voir pièges) |
| Machine à états du taikai | ✅ | 5 étapes, gardes et effets de bord, avant et arrière |
| Feuilles de marque | ✅ | Création et suppression au changement d'étape |
| Saisie des marques | ✅ | Ajout, rotation, validation de série, avancement des tachis |
| Tirage au sort | ✅ | Individuel, équipes, 2-en-1 ; déclenchable depuis la vue d'ensemble |
| Classements | ✅ | Individuel, équipes, matchs ; regroupement des ex æquo |
| Référentiel des clubs | ✅ | CRUD complet, normalisation et suppression protégée |
| Clubs hôtes | ✅ | CRUD complet ; choix du club par liste, l'autocomplétion JSON existe mais n'est pas encore branchée en JS (voir pièges) |
| Participants | ✅ | Saisie manuelle, import Excel, réordonnancement par glisser-déposer au sein d'une équipe (voir pièges) |
| Tie-break | ✅ | Ajustement manuel des rangs ; individuel/équipes/2-en-1 |
| Tableau final | ✅ | Bracket 4/8, propagation des vainqueurs, écrans d'administration et de marque (voir pièges) |
| Rectification | ✅ | Réservée aux administrateurs du taikai (voir pièges) |
| Staff du taikai | ✅ | CRUD complet ; refuse de laisser le taikai sans administrateur |
| Équipes | ✅ | CRUD complet ; composition par sélection, pas par glisser-déposer (voir pièges) |
| Suivi du shajo | ✅ | Lecture seule, ouvert à tout utilisateur authentifié |
| Affichage public | ✅ | Par clé d'API, HTML et JSON, sans authentification |
| Autocomplétion | ✅ | Points d'entrée JSON (licenciés, staff, clubs) ; pas encore branchés à un contrôleur Stimulus (voir pièges) |
| Compte utilisateur | ✅ | Modification de son identité et de sa langue (voir pièges) |
| Classement 2-en-1 | ✅ | `show` combine équipes et individuel ; `public` et l'écran dédié isolent les équipes |
| Génération 2ᵉ partie | ✅ | Copie staff/clubs hôtes/équipes qualifiées depuis un 2-en-1 terminé, construit le tableau (voir pièges) |
| Réinitialisation de mot de passe | ✅ | `symfonycasts/reset-password-bundle` + Mailpit en local (voir pièges) |
| Export Excel d'un taikai | ✅ | 5 onglets (résumé, staff, participants, résultats, journal), `phpoffice/phpspreadsheet` (voir pièges) |
| Championnat | ✅ | Classement annuel (3 meilleures participations) et export Excel à 5 onglets (voir pièges) |
| Piste d'audit | ✅ | `gedmo/doctrine-extensions` (Loggable), 11 entités instrumentées, pas d'écran de consultation — comme côté Rails (voir pièges) |
| Confirmation d'adresse par courriel | ✅ | N'existait pas côté Rails (voir pièges) ; compte inactif tant que le lien reçu par courriel n'a pas été suivi |

### Reste à faire

Plus aucun contrôleur Rails sans équivalent Symfony, ni aucun chantier
transverse identifié dans `MIGRATION.md`. Le portage fonctionnel est complet.

---

## 2. Correspondances

### Concepts

| Rails | Symfony |
|---|---|
| Modèle ActiveRecord | Entité Doctrine + service |
| `has_many` / `belongs_to` | `OneToMany` / `ManyToOne` |
| `validates` | Contraintes `Assert\*` |
| Scope | Méthode de `Repository` |
| Concern | Trait ou service |
| Statesman | `TaikaiStateMachine` (service maison) |
| `audited` | `gedmo/doctrine-extensions` (Loggable) |
| Mobility (i18n en base) | Colonnes JSON + `translatedLabel()` |
| Kaminari | KnpPaginatorBundle |
| Stimulus | Stimulus (identique) |
| Vue ERB | Template Twig |

### Renommages notables

| Rails | Symfony | Raison |
|---|---|---|
| `Match` | `TaikaiMatch` | `match` est un mot réservé depuis PHP 8 |
| `Score#add_result` | `MarkingService::addResult()` | logique métier hors entité |
| `Leaderboard` | `LeaderboardService` + `Ranker` | séparation calcul / tri |
| `RankedAssociationExtension` | `Ranker` | idem |

Les **noms de tables et de colonnes sont inchangés**, ce qui permet de reprendre
une base Rails existante.

### Machine à états

`TaikaiStateMachine` transcrit une à une les règles de
`app/models/taikai_state_machine.rb` :

| Transition | Garde | Effet de bord |
|---|---|---|
| `new` → `registration` | — | — |
| `registration` → `marking` | staff obligatoire + tirage fait | crée tachis, scores, flèches |
| `marking` → `tie_break` | toutes les flèches validées | fige les rangs |
| `tie_break` → `done` | — | — |
| `registration` → `new` | — | — |
| `marking` → `registration` | — | **supprime** tachis et scores |
| `tie_break` → `marking` | — | remet les rangs à zéro |
| `done` → `tie_break` | — | — |

Le staff obligatoire pour le marquage est : directeur de tournoi (`chairman`),
juge de shajo (`shajo_referee`), juge de cible (`target_referee`).

---

## 3. Reprise des données

La structure étant identique, une base Rails se reprend telle quelle, à trois
réserves près.

### Mots de passe — compatibles

Rails `has_secure_password` produit des empreintes **bcrypt**, et
`config/packages/security.yaml` configure bcrypt côté Symfony. **Les mots de
passe existants restent valides**, aucune réinitialisation n'est nécessaire.

### Types énumérés PostgreSQL

Rails déclare `taikai_form`, `taikai_scoring` et `result_status` comme de vrais
types `ENUM` PostgreSQL. La migration Doctrine crée à la place des colonnes
`VARCHAR`, l'énumération étant portée par PHP. Les **valeurs** sont identiques ;
sur une base reprise, convertissez les colonnes :

```sql
ALTER TABLE taikais  ALTER COLUMN form    TYPE VARCHAR(255) USING form::text;
ALTER TABLE taikais  ALTER COLUMN scoring TYPE VARCHAR(255) USING scoring::text;
ALTER TABLE results  ALTER COLUMN status  TYPE VARCHAR(255) USING status::text;
```

### Table `audits`

La piste d'audit est portée par `gedmo/doctrine-extensions` (Loggable), qui
utilise sa **propre table** (`ext_log_entries`, structure différente,
créée par la migration `Version20260827202641`) plutôt que de reprendre le
schéma du gem `audited` (`audits`). Sur une base Rails reprise, l'historique
`audits` existant n'est **pas migré automatiquement** vers
`ext_log_entries` : conservez la table de côté si cet historique doit être
préservé, mais l'application ne le lira plus.

---

## 4. Pièges rencontrés

Notés ici parce qu'ils ont coûté du temps et se reproduiront.

**Les deux applications lancées côte à côte révèlent des trous invisibles aux
tests.** Rails (`docker compose up`, port 3000, fixtures de test chargées en
base) et Symfony (port 8000) comparés écran par écran ont fait remonter trois
régressions qu'aucun test automatisé ne couvrait, toutes dans la « chrome »
autour des écrans métier plutôt que dans la logique métier elle-même (déjà
rigoureusement portée depuis les modèles Rails) :

- La racine `/` redirigeait vers `/taikais` au lieu de rendre le tableau de
  bord de `home_controller#index` (nombre de taikai/clubs gérés) — un
  contrôleur entier jamais porté, avec un commentaire affirmant à tort qu'il
  « redirige comme Rails ». Corrigé (`HomeController`, `templates/home/`).
- `public/logo_fr.jpg`, `public/docs/guide.pdf` et `public/docs/tutorial.pdf`
  n'avaient jamais été copiés depuis Rails, et Font Awesome (utilisé par
  plusieurs gabarits, ex. `fa-grip-vertical` du glisser-déposer) n'était chargé
  nulle part : les icônes étaient invisibles. Copié les fichiers statiques,
  ajouté Font Awesome 6.5.1 par CDN à côté de Bulma, ajouté les liens
  Guide/Tutoriel dans la navbar pour les utilisateurs connectés.
- Le lien « Championnat » était toujours visible dans la navbar PHP, alors que
  Rails ne le lie **nulle part** (route atteignable seulement en tapant
  l'URL) — retiré pour rester fidèle au comportement Rails.
- `app_taikai_delete` existait côté contrôleur (avec sa traduction de
  confirmation flash) mais n'était lié depuis aucun gabarit : impossible de
  supprimer un taikai sans connaître l'URL. Rails l'expose depuis la liste.
  Ajouté un bouton sur la page de détail (`taikai/show.html.twig`), protégé
  par la même permission `TAIKAI_EDIT` que l'édition (Rails : `destroy?` ==
  `update?` == `admin?`). Couvert par `TaikaiCrudTest`, qui n'existait pas non
  plus — seule la machine à états avait des tests, pas le passage par les
  écrans HTTP de base (new/create/edit/update/destroy).

Une recherche systématique des noms de route jamais référencés hors de leur
propre contrôleur a aussi remonté `app_championship_index` (intentionnel,
voir ci-dessus), `app_scoreboard_show` et `app_tachi_index` (non plus liés
nulle part côté Rails — clé d'API/URL secrète transmise hors application, pas
un bug), et les trois points d'entrée JSON d'autocomplétion (déjà documentés
plus haut comme non branchés à un contrôleur Stimulus). Rien d'autre trouvé.

**Index unique partiel sur `most_recent`.** Doctrine émet tous les `INSERT`
avant les `UPDATE`. En insérant la nouvelle transition avant d'avoir retiré le
drapeau de l'ancienne, on viole l'index. `TaikaiStateMachine::recordTransition()`
intercale donc un `flush()`. Ne pas « simplifier ».

**Les ex æquo consomment des places.** Deux premiers ex æquo sont suivis d'un
troisième, pas d'un deuxième. C'est le comportement Rails, couvert par
`RankerTest`.

**Ordre de comparaison des scores.** On compare d'abord les **points** (enteki)
puis les **touchés**. En kinteki les points valent 0 partout et seuls les touchés
départagent. Voir `ScoreValue::compareTo()`.

**Un enum ne peut pas être clé de tableau en PHP.** Utiliser une liste de paires.

**Domaine de traduction des messages de validation.** Symfony traduit les
violations dans le domaine `validators`, alors que le projet range toutes ses
clés dans `messages.*.yaml` : les messages de contrainte s'affichaient en clé
brute (`taikai.shortname.already_used`) à l'écran. `config/packages/validator.yaml`
force `translation_domain: messages`.

**Rapprochement des licenciés à l'import.** `participants#import` compare les
noms en majuscules (`upcase`), alors que `Kyudojin` les normalise en casse de
titre depuis janvier 2026 : côté Rails la recherche ne peut plus aboutir. Le
portage reprend l'intention — comparaison insensible à la casse, aux accents et
aux traits d'union — plutôt que la lettre, qui serait morte-née.

**`TaikaiVoter` divergeait de `TaikaiPolicy` sur deux points.** Découvert en
portant `tie_break_controller`/`rectification_controller`, donc antérieur à ce
travail : `canMark()` omettait `target_referee` (présent dans `MARKING_ROLES`
Rails), et `canRectify()` autorisait `chairman`/`target_referee` alors que
`rectification_update?` ne couvre que `ADMIN_ROLES` (`taikai_admin` seul) —
une politique plus permissive que la référence sur une action sensible. Les
deux ont été corrigés pour coller exactement à `TaikaiPolicy`. Si d'autres
voters s'avèrent divergents, comparer systématiquement à la policy Pundit
correspondante plutôt que de deviner l'intention.

**`staffs_controller` n'a aucune autorisation Pundit.** N'importe quel
utilisateur authentifié pourrait, côté Rails, s'auto-nommer administrateur d'un
taikai qui ne lui appartient pas — une élévation de privilèges plutôt qu'un
choix voulu. `StaffController` réserve ces actions à `TAIKAI_EDIT`, comme le
reste des écrans d'administration du taikai. Si d'autres contrôleurs Rails
restants s'avèrent dépourvus d'`authorize`, vérifier au cas par cas plutôt que
de supposer l'omission volontaire. Même constat pour `teams_controller` et
`teaming_controller` : réservés à `ParticipatingDojoVoter::EDIT`, comme le
reste des écrans du club hôte.

**Ordre des champs et recopie d'identité.** `Staff::setUser()` recopie déjà
prénom/nom par commodité pour le code programmatique (fixtures, tests). Mais
dans un formulaire où `user` est soumis avant `firstname`/`lastname` — l'ordre
naturel, qui reprend celui du formulaire Rails — ces derniers écrasent la
recopie après coup. Rails l'évite en plaçant la recopie dans un
`before_validation`, qui s'exécute après l'assignation de tous les attributs.
`StaffController` reproduit ce timing en appliquant la recopie explicitement
après `$form->handleRequest()`, plutôt que de compter sur l'effet de bord du
setter. Le même risque existe pour tout futur formulaire combinant un champ de
sélection et des champs texte qu'il doit prévaloir sur.

**Identity map et tests fonctionnels.** Une entité construite par le test reste
dans l'identity map ; ses collections `OneToMany`, créées vides par le
constructeur, passent pour déjà chargées et la requête qui suit ne voit pas les
relations pourtant écrites en base. Un test passe alors — ou échoue — pour de
mauvaises raisons. Appeler `commitSeeding()` (voir `App\Tests\DatabaseResetTrait`)
entre la préparation du jeu de données et la première requête.

**CSRF.** La recette Symfony active le mode « stateless », qui exige du
JavaScript pour renseigner le jeton. L'application rendant ses pages côté
serveur, `config/packages/csrf.yaml` rétablit le mode session.

**Mode sombre de Bulma 1.x.** Bulma bascule seul en thème sombre selon les
préférences système, ce qui rend l'application illisible. `base.html.twig` force
le thème clair, exactement comme le correctif appliqué à l'application Rails.

**Suppression d'une équipe et de ses participants.** `Team` Rails porte
`has_many :participants, dependent: :destroy` : supprimer une équipe supprime
ses participants, pas seulement leur affectation. La génération initiale des
entités (phase 1) avait mis `onDelete: 'SET NULL'` sur `participants.team_id`,
qui se contente de les détacher — une régression silencieuse par rapport à la
référence. Le schéma Rails (`db/schema.rb`) ne déclare aucun `on_delete` sur
cette clé étrangère, ce qui, combiné à `dependent: :destroy`, correspond à
`onDelete: 'CASCADE'` côté Doctrine (le même choix que `scores.team_id`, déjà
correct dans la même migration d'origine — l'incohérence entre les deux aurait
dû mettre la puce à l'oreille). Corrigé par la migration
`Version20260827143052`. Si un autre `dependent: :destroy` Rails semble ne pas
avoir d'équivalent `onDelete` en base, vérifier systématiquement plutôt que de
supposer que `SET NULL`/`RESTRICT` par défaut convient.

**`ConstraintViolation::getMessage()` est déjà traduit.** Pour distinguer par
code la raison d'une violation construite via `Assert\Callback` (ex. « nom déjà
pris » vs « nom vide » dans `teaming_controller#create_team`), comparer sur
`getMessageTemplate()` (la clé de traduction telle que passée à
`buildViolation()`), jamais sur `getMessage()` qui renvoie le texte déjà
traduit dans la locale courante.

**`users_controller#update` est inatteignable côté Rails.** Aucune vue
`app/views/users/edit.html.erb` n'existe, et rien ne rend le formulaire nulle
part : l'action plante même sur une erreur de validation (`render :edit` sans
gabarit). Le portage (`UserAccountController`) reprend l'intention — modifier
son identité et sa langue — avec un écran réellement accessible depuis la
navigation, plutôt que de reproduire une action morte.

**Dériver un contexte partagé depuis sa vraie source, pas depuis un enfant
optionnel.** Premier jet de `ScoreboardController` : les informations du
taikai étaient dérivées de `$tachi?->getParticipatingDojo()?->getTaikai()`,
donc absentes tant qu'aucun tachi n'existe (avant le tirage au sort) — alors
que côté Rails `@taikai` vient directement de
`@scoreboard.participating_dojo.taikai`, indépendamment du tachi affiché.
Trouvé en vérifiant l'écran en conditions réelles sur le taikai de démo (pas
encore tiré au sort), pas par les tests automatisés dont le contexte incluait
toujours un tachi actif — un rappel que les tests doivent aussi couvrir les
états « pas encore commencé ».

**Autocomplétion : JSON plutôt que fragment HTML.** Les vues Rails de
`search_controller` rendent un fragment HTML consommé par un contrôleur
Stimulus (`autocomplete_controller.js`), non porté — `SearchController`
expose la même recherche en JSON, prête à être branchée le jour où
l'autocomplétion sera portée ; les formulaires concernés continuent d'utiliser
des listes déroulantes en attendant. Le glisser-déposer, lui, est bien porté
(voir plus bas, `participants#reorder`) : l'infrastructure Stimulus/AssetMapper
fonctionne, il ne restait qu'à écrire le contrôleur JS.

**`Taikai.create_from_2in1` recopie le staff avant de savoir vers quel club
hôte du nouveau taikai le rattacher.** Le code Rails affecte
`participating_dojo: staff.participating_dojo` — la valeur de l'**ancien**
taikai — à un `Staff` qui appartient déjà au **nouveau** : une référence
inter-taikai qui ne casse jamais parce que `Staff#participating_dojo` n'est
jamais validé comme appartenant au même taikai que le staff lui-même (voir
`Staff` Rails). Repéré en lisant l'ordre des opérations, pas par un plantage —
Rails ne remarque jamais l'incohérence. `TaikaiGenerationService` construit
d'abord la correspondance ancien → nouveau club hôte, puis affecte le club
remappé. Test de non-régression :
`TaikaiGenerationTest::testCopiedDojoAdminStaffPointsToTheNewParticipatingDojo`.

**Les collections inverses `OneToMany` ne se synchronisent pas en mémoire.**
`TaikaiMatch::$results` (mappedBy: 'match') n'a pas de méthode `addResult()` :
créer un `Result` et lui assigner ce match ne l'ajoute pas à la collection en
mémoire de `TaikaiMatch`. Sur une entité qui vient d'être chargée par une
requête, la collection est une `PersistentCollection` paresseuse qui interroge
la base à la première lecture et voit donc bien le nouveau `Result` — mais sur
une entité construite dans le même process sans jamais retraverser la base
(le cas typique d'un test qui enchaîne plusieurs appels de service), la
collection reste l'`ArrayCollection` vide du constructeur, et
`TaikaiMatch::hasDefinedResults()`/`isFinalized()` mentent silencieusement.
Symptôme observé dans `MatchServiceTest` : un contrôle censé bloquer
`selectWinner()` ne se déclenchait pas. Corrigé en simulant la frontière
HTTP réelle — `flush()` puis `clear()` puis rechargement depuis le
repository — entre deux appels de service qui, en production, seraient deux
requêtes séparées. Même famille que le piège « Identity map et tests
fonctionnels » ci-dessus, mais dans l'autre sens : là où `commitSeeding()`
répare une collection qui semble déjà chargée, ici c'est l'absence de
rechargement qui masque une collection réellement stale.

**Un score d'équipe ne se recalcule pas tout seul.** `Score::getHits()` /
`getValue()` sont des compteurs mis en cache, mis à jour uniquement par
`recalculateFromResults()` (score individuel) et `recalculateFromTeam()`
(score d'équipe) — exactement ce que `MarkingService::recalculate()` fait
après chaque saisie. Modifier des `Result` directement en base (par exemple
dans un test qui construit un scénario sans passer par le service) laisse ces
compteurs à zéro : `TaikaiMatch::isDecidable()` comparait alors deux scores
tous les deux nuls, donc « égaux », et refusait de désigner un vainqueur
pourtant évident. Toujours appeler `recalculateFromResults()`/
`recalculateFromTeam()` après une modification de `Result` qui contourne
`MarkingService`.

**`hits` (compteur définitif) et `intermediateHits` (compteur provisoire)
répondent à des questions différentes.** Une flèche marquée mais pas encore
validée compte dans `intermediateHits`, pas dans `hits`. Un test qui vérifie
qu'une saisie a bien été prise en compte juste après `marking#add` (avant
toute validation de série) doit donc lire `getIntermediateHits()`, pas
`getHits()` qui resterait à zéro.

**Réinitialisation de mot de passe : bundle plutôt que portage littéral.**
Rails 8 génère un `signed_id` en mémoire (`User#generate_password_reset_token`,
`expires_in: 15.minutes`) sans rien persister. Plutôt que de réimplémenter ce
mécanisme à la main, le portage utilise `symfonycasts/reset-password-bundle`
(même choix que `../projet-GM`) : jeton à usage unique persisté
(`ResetPasswordRequest`), protection contre l'énumération de comptes (écran de
confirmation identique que l'adresse existe ou non — Rails a la même
propriété, mais par accident plutôt que par conception) et anti-abus par
utilisateur. La durée de vie du jeton est alignée sur Rails (`lifetime: 900`
dans `config/packages/reset_password.yaml`) ; en revanche l'anti-abus diverge
volontairement : Rails limite à 5 tentatives par IP sur 10 minutes
(`rate_limit`), le bundle limite plutôt le *renvoi* par utilisateur
(`throttle_limit: 300`, 5 minutes) — mécanisme différent, intention identique
(décourager le spam sans bloquer une erreur de frappe légitime).

**Mailpit en local, mais attention à la fuite entre `.env` et `.env.test`.**
Le DSN mailer de développement (`MAILER_DSN=smtp://mailer:1025` dans `.env`,
conteneur `axllent/mailpit` ajouté à `compose.override.yaml`, IHM sur
`localhost:8025`) est hérité par l'environnement de test si `.env.test` ne le
surcharge pas. Les assertions de `MailerAssertionsTrait`
(`assertEmailCount`, `getMailerMessage`) fonctionnent en interceptant les
messages via le collecteur de la barre de débogage, **avant** l'appel réseau
réel — donc les tests passaient malgré tout, mais envoyaient en plus de vrais
emails à Mailpit à chaque exécution (repéré en inspectant l'IHM Mailpit après
un lot de tests, pas par un échec). Corrigé en fixant
`MAILER_DSN=null://null` dans `.env.test`, comme le ferait la recette Flex
d'un projet qui isole ses environnements dès le départ.

**`StreamedResponse` en test fonctionnel : lire `getInternalResponse()`, pas
`getResponse()`.** Les exports Excel (`taikais#export`, `championship#export`)
renvoient une `StreamedResponse` : son `getContent()` HttpFoundation renvoie
toujours `false`, le contenu n'étant jamais bufferisé côté réponse elle-même.
`KernelBrowser` le capture bien, mais dans `sentContent`/`getInternalResponse()`
(l'objet `Response` de BrowserKit, distinct de la réponse Symfony) — accessible
via `$client->getInternalResponse()->getContent()`. Premier symptôme : un test
qui échoue en tentant de charger un classeur de 0 octet avec PhpSpreadsheet.

**Un score n'est jamais réellement `null`, donc `??` ne distingue pas
kinteki d'enteki.** `Score::$value` est une colonne entière non nullable par
défaut à `0`, donc `Score::toScoreValue()->value` vaut `0` — jamais `null` —
même pour un score kinteki où seul `hits` compte. Écrire
`$score->value ?? $score->hits` affiche donc toujours `0` (la valeur enteki)
y compris en kinteki, puisque `0` n'est pas `null` et ne déclenche jamais le
repli. `TaikaiExportService::displayScore()` s'en sortait déjà correctement en
branchant explicitement sur `$taikai->getScoring()->usesArrowValues()` ;
`ChampionshipExportService` a d'abord reproduit l'erreur par un `??`
avant d'être corrigé pour recevoir un paramètre `bool $enteki` explicite par
onglet, exactement comme le fait la vue Rails (`hash[:total].hits` pour
Kinteki, `hash[:total].value` pour Enteki — deux blocs distincts, pas un
repli conditionnel).

**Score absent : générer les colonnes de flèches vides, pas les omettre.**
Sur la feuille de résultats individuels/équipes, quand un participant n'a pas
encore de `Score` (taikai pas encore passé à l'étape « Marquage »), la
première version n'écrivait aucune cellule de flèche pour cette ligne au lieu
des `total_num_arrows` cellules vides attendues — ce qui décalait le score
final dans la première colonne de la série 1. Repéré en conditions réelles
(export du taikai de démo avant tirage au sort), pas par les tests
automatisés (qui créaient toujours un score). Corrigé en générant
`array_fill(0, $taikai->getTotalNumArrows(), '')` à la place d'un tableau
vide quand `getScore()` est `null`.

**PhpSpreadsheet vide les cellules fusionnées par défaut.** `mergeCells()`
efface la valeur de toutes les cellules de la plage sauf la première — c'est
le comportement voulu (identique à Excel/axlsx pour les ex æquo, où seule la
première ligne du groupe affiche le rang), mais surprenant si on s'attend à
retrouver la valeur dans les cellules « masquées » en relisant le classeur.

**Classement du championnat : regroupement par nom affiché, pas par
identifiant.** `ChampionshipController#rank` groupe les participants par
`display_name` (chaîne), pas par une entité « personne » stable — chaque
taikai a ses propres lignes `Participant`, sans lien entre eux d'une année
sur l'autre. Le portage reprend cette même limite plutôt que d'introduire un
concept absent côté Rails (et donc une divergence de comportement) : deux
personnes homonymes dans des clubs différents seraient à tort regroupées, un
angle mort déjà présent dans l'original.

**`participant.score.first(12).score_value` ne peut pas s'exécuter tel quel
côté Rails.** `Score` ne définit pas de méthode `first` : ce code planterait
au premier appel si jamais exécuté avec un score existant. Code mort, jamais
déclenché en pratique (aucun test Rails ne couvre cette action). Le portage
reprend l'intention évidente — `participant.score.score_value` — plutôt que
cette impasse.

**URL de taikai codée en dur côté Rails.** `taikai_uri(id)` construit
`https://pikaichu.kyudo.fr/taikais/#{id}` en dur dans la vue d'export du
championnat. Le portage génère l'URL via `UrlGeneratorInterface::generate(...,
UrlGeneratorInterface::ABSOLUTE_URL)`, qui reste correcte quel que soit
l'environnement (dev, démo, production) sans dépendre d'un domaine figé.

**Réordonnancement par glisser-déposer : même piège que `most_recent`, sur un
autre index unique partiel.** `participants.index_in_team` porte un index
unique partiel `(team_id, index_in_team)` (équivalent de `acts_as_list`).
`TeamingService::reorderParticipant()` réassigne l'index de tous les membres
de l'équipe en une seule passe, mais Doctrine n'écrit pas forcément les
`UPDATE` dans l'ordre du tableau PHP — un participant peut recevoir la valeur
qu'un autre n'a pas encore libérée, violant l'index même si le résultat final
est cohérent. Repéré en vérifiant l'écran en conditions réelles (glisser un
troisième participant d'une équipe vers la première position), pas par le
premier jet des tests automatisés, dont aucun ne donnait d'index initial aux
participants avant de les réordonner — sans valeur existante à collisionner,
le bug ne pouvait pas se manifester. Corrigé en vidant tous les index du
groupe (`flush()`) avant de réécrire les valeurs finales, exactement le
motif déjà utilisé par `TaikaiStateMachine::recordTransition()` pour
`most_recent`. Si un futur réordonnancement touche une autre colonne à index
unique partiel, appliquer le même motif plutôt que d'assigner les valeurs en
une seule passe.

**Gabarit d'URL pour une action Stimulus : identifiant factice plutôt que
placeholder littéral.** Le contrôleur `reorder` a besoin d'une URL par ligne
déplacée, mais l'identifiant du participant n'est connu qu'au moment du
`drop`. `path(route, {participantId: ':id'})` échoue : Symfony valide
`participantId` contre son `requirement` (`\d+`) au moment de générer l'URL,
avant même de savoir qu'elle sera retouchée côté client. La vue génère donc
l'URL avec l'identifiant factice `0` (aucune entité ne peut porter cet
identifiant) et le contrôleur Stimulus remplace `/0/reorder` par l'identifiant
réel avant l'appel `fetch`.

**Confirmation par courriel : aucune référence Rails à porter.**
`registrations_controller#create` confirme le compte immédiatement, avec le
commentaire du code source Rails lui-même : « Auto-confirm for now, can add
email confirmation later ». Il n'existe ni mailer, ni vue, ni contrôleur de
confirmation côté Rails — seules les colonnes (`confirmed_at`,
`confirmation_token`, `confirmation_sent_at`, `unconfirmed_email`) existent en
base, jamais utilisées. `EmailConfirmationService` construit donc la
fonctionnalité de A à Z, sur le modèle de la réinitialisation de mot de passe
déjà portée (jeton envoyé par courriel, Mailpit en local) plutôt que de la
recopier littéralement. Une différence de comportement assumée par rapport à
Rails : l'inscription ne connecte plus automatiquement, le compte restant
inactif tant que le lien n'a pas été suivi (`ConfirmedUserChecker`, un
`UserCheckerInterface` qui bloque `checkPreAuth`).

**Piste d'audit : deux pièges d'intégration propres à Doctrine ORM 4 / Doctrine
Bundle 3.x, aucun rapport avec Rails.**

- Le tag `doctrine.event_subscriber` (qui, historiquement, suffisait à
  enregistrer une classe implémentant `Doctrine\Common\EventSubscriber` et ses
  `getSubscribedEvents()`) n'est traité nulle part dans ce projet : ni
  `doctrine/doctrine-bundle` 3.x, ni `symfony/doctrine-bridge` ne le
  reconnaissent — seul `doctrine.event_listener` (un tag par événement,
  attribut `event` obligatoire) est câblé par
  `RegisterEventListenersAndSubscribersPass`. `Gedmo\Loggable\LoggableListener`
  a donc besoin de trois tags `doctrine.event_listener` distincts (`onFlush`,
  `loadClassMetadata`, `postPersist`), pas d'un seul `doctrine.event_subscriber`.
  Symptôme sans la moindre erreur : aucune entrée créée, silencieusement.
- Le type Doctrine `array` (sérialisation PHP) a été retiré de DBAL 4 — sa
  constante `Types::ARRAY` n'existe même plus. `Gedmo\Loggable\Entity\LogEntry`
  (colonne `data`) le déclare encore par son nom littéral `'array'`, en connaissance
  de cause (commentaire du code source de Gedmo). Sans réenregistrer un type
  portant ce nom (`App\Doctrine\LegacyArrayType`, un `Types::TEXT` sérialisé en
  PHP, dans `config/packages/doctrine.yaml`), toute génération de migration
  touchant `ext_log_entries` échoue avec « Unknown column type "array" ».

**`Gedmo\Loggable\Entity\LogEntry` du vendor n'est jamais découvert par
Doctrine.** `doctrine.yaml` ne scanne que `src/Entity/` (`dir:
'%kernel.project_dir%/src/Entity'`) : le mapping fourni par Gedmo, dans
`vendor/`, n'y est jamais inclus. Il faut soit ajouter un chemin de mapping
séparé, soit — l'intégration standard recommandée, retenue ici — sous-classer
`AbstractLogEntry` directement dans `src/Entity/LogEntry.php`, puis passer
`logEntryClass: LogEntry::class` à chaque attribut `#[Gedmo\Loggable]` (l'ORM
adapter de Gedmo renvoie sinon toujours sa propre classe par défaut,
`Gedmo\Loggable\Entity\LogEntry`, qui échoue avec « class not found in the
chain configured namespaces App\Entity »).

**`DatabaseResetTrait` avait deux tables absentes de sa liste figée.**
`ext_log_entries` (ajoutée par ce chantier) et `reset_password_requests`
(oubliée lors du chantier précédent) n'étaient jamais vidées entre deux tests,
contrairement aux autres tables listées explicitement dans
`RESETTABLE_TABLES`. Sans effet visible tant qu'aucun test ne comptait les
lignes d'une de ces deux tables — jusqu'à ce qu'un test sur la piste d'audit
compte les entrées de journal d'une entité et en trouve une centaine au lieu
d'une, accumulées par tous les tests précédents du même run. Corrigé en
complétant la liste ; à surveiller pour toute future table qui, comme
celles-ci, n'est référencée par aucune relation `ManyToOne`/`OneToMany`
directement vidée par `CASCADE` depuis les tables déjà listées.

---

## 5. Méthode conseillée pour la suite

L'ordre ci-dessous suit les dépendances métier et permet de garder une
application utilisable à chaque étape :

1. ~~`dojos` et `participating_dojos`~~ — faits.
2. ~~`participants` (saisie manuelle et import Excel)~~ — faits.
3. ~~`staffs`~~ — fait.
4. ~~`tie_break` et `rectification`~~ — faits.
5. ~~`teams` puis `teaming`~~ — faits ; l'affectation à une équipe se fait par
   sélection, le réordonnancement au sein d'une équipe par glisser-déposer
   (voir point 10).
6. ~~`tachis`, `scoreboard`, `search`, `users`, `leaderboard#show_2in1`~~ — faits.
7. ~~`matches`, `taikais#generate`, `marking#show_match`~~ — faits.
8. ~~`passwords` (réinitialisation de mot de passe)~~ — fait.
9. ~~Exports Excel (`taikais#export`, `championship`)~~ — faits.
10. ~~`participants#reorder`~~ — fait.
11. ~~Piste d'audit et confirmation par courriel~~ — faits.

Le portage fonctionnel est terminé : chaque contrôleur Rails a désormais un
équivalent Symfony. Pour la suite du projet (nouvelles fonctionnalités, dette
technique), reprendre la même boucle : écrire d'abord un test fonctionnel qui
échoue, lire le contrôleur et la vue Rails correspondants s'il y a une
référence, puis implémenter. C'est la boucle
décrite dans [AGENTS.md](AGENTS.md).
