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
- **17 entités Doctrine** couvrant l'intégralité du schéma Rails, migration
  initiale appliquée, index et contraintes repris à l'identique.
- Authentification (connexion, déconnexion, inscription), droits par taikai.
- Traductions `fr` / `en`.
- **PHPStan niveau 7 sans erreur**, 53 tests verts.

### Fonctionnalités portées

| Domaine | État | Détail |
|---|---|---|
| Machine à états du taikai | ✅ | 5 étapes, gardes et effets de bord, avant et arrière |
| Feuilles de marque | ✅ | Création et suppression au changement d'étape |
| Saisie des marques | ✅ | Ajout, rotation, validation de série, avancement des tachis |
| Tirage au sort | ✅ | Individuel, équipes, 2-en-1 ; déclenchable depuis la vue d'ensemble |
| Classements | ✅ | Individuel, équipes, matchs ; regroupement des ex æquo |
| Référentiel des clubs | ✅ | CRUD complet, normalisation et suppression protégée |
| Clubs hôtes | ✅ | CRUD complet ; choix du club par liste, l'autocomplétion attend `search_controller` |
| Participants | ⚠️ | Saisie manuelle et import Excel faits ; **réordonnancement manquant** |
| Tie-break | ⚠️ | Calcul et figeage des rangs faits ; **écran de saisie manquant** |
| Tableau final | ⚠️ | Bracket 4/8 et propagation des vainqueurs faits ; **écrans manquants** |
| Rectification | ⚠️ | `MarkingService::rectify()` fait ; **écran manquant** |

### Reste à faire

Chaque ligne correspond à un contrôleur Rails sans équivalent Symfony.

| Contrôleur Rails | Rôle | Difficulté |
|---|---|---|
| `participants#reorder` | Réordonnancement par glisser-déposer | **élevée** (Stimulus) |
| `staffs_controller` | Staff du taikai | faible |
| `teams_controller` | CRUD des équipes | faible |
| `teaming_controller` | Composition des équipes en glisser-déposer | **élevée** (Stimulus) |
| `tie_break_controller` | Saisie manuelle des rangs | faible |
| `rectification_controller` | Correction d'une marque validée | faible |
| `matches_controller` | Écrans du tableau final | moyenne |
| `tachis_controller` | Suivi des tachis sur le shajo | faible |
| `scoreboard_controller` | Affichage public par clé d'API | faible |
| `search_controller` | Autocomplétion (users, kyudojins, dojos) | faible |
| `championship_controller` | Classement annuel, **export Excel** | moyenne |
| `passwords_controller` | Réinitialisation de mot de passe | moyenne (mailer) |
| `users_controller` | Gestion du compte | faible |
| `taikais#export` | **Export Excel** d'un taikai (4 onglets) | moyenne |
| `taikais#generate` | Génération de la 2ᵉ partie d'un 2-en-1 | moyenne |
| `leaderboard#show_2in1` | Vue combinée équipes + individuel | faible |
| `marking#show_match` | Feuille de marque d'un match | moyenne |

Transverses également absents :

- **Piste d'audit.** Rails utilise le gem `audited` sur presque tous les modèles
  (table `audits`). Aucun équivalent n'est en place : à traiter avec
  `gedmo/doctrine-extensions` (Loggable) ou une solution maison.
- **Confirmation d'adresse par courriel.** Les colonnes existent
  (`confirmation_token`, `confirmed_at`, `unconfirmed_email`) mais l'inscription
  active le compte immédiatement.

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
| `audited` | *non porté* |
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

La piste d'audit n'étant pas portée, la table `audits` n'est pas recréée par la
migration. Conservez-la de côté si l'historique doit être préservé.

---

## 4. Pièges rencontrés

Notés ici parce qu'ils ont coûté du temps et se reproduiront.

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

---

## 5. Méthode conseillée pour la suite

L'ordre ci-dessous suit les dépendances métier et permet de garder une
application utilisable à chaque étape :

1. ~~`dojos` et `participating_dojos`~~ — faits.
2. ~~`participants` (saisie manuelle et import Excel)~~ — faits.
3. `staffs` — condition d'accès au marquage.
4. `tie_break` et `rectification` — les services existent, seuls les écrans manquent.
5. `teams` puis `teaming` — débloque les formes « équipes » et « 2 en 1 ».
6. `matches` — débloque la forme « matchs » et la 2ᵉ partie du 2-en-1.
7. Exports Excel, `scoreboard`, `championship`.
8. Piste d'audit et confirmation par courriel.

Pour chaque écran : écrire d'abord un test fonctionnel qui échoue, lire le
contrôleur et la vue Rails correspondants, puis implémenter. C'est la boucle
décrite dans [AGENTS.md](AGENTS.md).
