# Pikaichu — Symfony

Gestion des *taikai* (tournois de kyudo) de France Kyudo : saisie en ligne des
résultats, classements, et compilation du championnat de France annuel.

Ce dépôt est le portage PHP/Symfony de l'application Ruby on Rails d'origine
(`../pikaichu`). Le modèle de données, les règles métier et le vocabulaire sont
repris à l'identique. Voir [MIGRATION.md](MIGRATION.md) pour l'état d'avancement
du portage et les correspondances Rails → Symfony.

## Stack

| | |
|---|---|
| PHP | 8.4 |
| Symfony | 8.1 |
| Base de données | PostgreSQL 16 |
| ORM | Doctrine ORM 3 |
| Templates | Twig + Bulma 1.0 |
| Tests | PHPUnit 13 |
| Qualité | PHPStan (niveau 7), php-cs-fixer (@Symfony + @PSR12), Rector |

## Démarrage

Tout tourne dans Docker ; aucune installation de PHP ou de PostgreSQL n'est
nécessaire sur le poste.

```bash
make up
```

La commande construit les images, démarre les conteneurs, installe les
dépendances Composer et applique les migrations.

| Service | URL |
|---|---|
| Application | http://localhost:8000 |
| Adminer (base) | http://localhost:8080 |
| Mailpit (emails envoyés en local) | http://localhost:8025 |

Pour charger un jeu de données de démonstration :

```bash
make fixtures
```

Comptes créés par les fixtures (mot de passe `password123`) :

| Email | Rôle |
|---|---|
| `admin@pikaichu.test` | administrateur de l'application |
| `juge@pikaichu.test`  | juge de cible |

## Commandes

Toutes les cibles du `Makefile` s'exécutent depuis le poste ; elles appellent
elles-mêmes les conteneurs.

### Environnement

| Commande | Effet |
|---|---|
| `make up` | Démarre la stack, installe les dépendances, migre |
| `make down` | Arrête la stack |
| `make restart` | `down` puis `up` |
| `make php` | Ouvre un shell dans le conteneur PHP |
| `make logs` | Suit les logs PHP et nginx |

### Base de données

| Commande | Effet |
|---|---|
| `make diff` | Génère une migration à partir des entités |
| `make migrate` | Applique les migrations |
| `make fixtures` | Charge les données de démonstration |
| `make reset-database` | Recrée la base, migre et recharge les fixtures |

### Tests et qualité

| Commande | Effet |
|---|---|
| `make test` | Tests unitaires puis fonctionnels |
| `make test-unit` | Tests unitaires |
| `make test-functional` | Prépare la base de test et lance `tests/Functional/` |
| `make fix` | `rector` → `csfixer` → `phpstan` |
| `make phpstan` | Analyse statique seule |

Les fichiers de configuration des outils vivent dans `tools/`.

## Organisation du code

```
src/
├── Controller/     Points d'entrée HTTP
├── DTO/            Objets de valeur (ScoreValue, RankedGroup)
├── Entity/         Entités Doctrine
├── Enum/           Énumérations métier (forme, scoring, état, rôles)
├── Exception/      Exceptions de domaine
├── Form/           Formulaires Symfony
├── Repository/     Dépôts Doctrine
├── Security/Voter/ Droits d'accès par taikai
└── Service/        Règles métier
```

Les règles métier sont regroupées dans `src/Service/` plutôt que dans les
entités, contrairement aux modèles ActiveRecord d'origine :

| Service | Responsabilité |
|---|---|
| `TaikaiStateMachine` | Étapes du tournoi, gardes et effets de bord |
| `ScoreInitializer` | Création et suppression des feuilles de marque |
| `MarkingService` | Saisie, rotation et validation des marques |
| `DrawService` | Tirage au sort de l'ordre de passage |
| `MatchService` | Tableau final et désignation des vainqueurs |
| `LeaderboardService` | Classements et figeage des rangs |
| `Ranker` | Tri et regroupement des ex æquo |

## Domaine

Un **taikai** traverse cinq étapes, dans cet ordre :

1. **Préparation** — création, clubs hôtes, staff
2. **Enregistrement** — participants, équipes, tirage au sort
3. **Marquage** — saisie des flèches
4. **Tie-Break** — départage manuel des ex æquo
5. **Terminé** — résultats figés, export

Deux gardes conditionnent l'avancement :

- passer au **Marquage** exige un directeur de tournoi, un juge de shajo et un
  juge de cible, et que le tirage au sort soit fait pour chaque club hôte ;
- passer au **Tie-Break** exige que toutes les flèches soient validées.

Les étapes se parcourent aussi en arrière. Revenir de « Marquage » à
« Enregistrement » supprime les feuilles de marque : c'est volontaire et
l'interface le signale.

### Vocabulaire

| Terme | Sens |
|---|---|
| *taikai* | tournoi |
| *kyudojin* | archer licencié |
| *tachi* | groupe d'archers tirant ensemble |
| *shajo* | pas de tir |
| *kinteki* | tir à 28 m (seul le touché compte) |
| *enteki* | tir à 60 m (chaque flèche porte des points) |
| *yatori* | ramasseur de flèches |
| *azuchi* | butte de tir |

### Formes de tournoi

| Forme | Description |
|---|---|
| Individuel | classement individuel |
| En équipes | classement par équipes |
| 2 en 1 | équipes *et* individuel sur la même saisie |
| Matchs | phase finale à élimination directe (4 ou 8 équipes) |

## Marques

Trois symboles, comme sur la feuille papier :

| Symbole | Sens |
|---|---|
| `◯` | touché |
| `⨯` | manqué |
| `?` | incertain, en attente du juge de cible |

Une série de 4 flèches ne peut être validée que si elle est complète et ne
contient plus d'incertain. Une flèche validée n'est modifiable que par l'écran de
rectification, qui trace le passage en force.

## Configuration

Les variables d'environnement sont dans `.env` ; surchargez-les dans
`.env.local` (non versionné).

| Variable | Rôle |
|---|---|
| `APP_ENV` | `dev`, `test` ou `prod` |
| `APP_SECRET` | secret applicatif — **à changer en production** |
| `DATABASE_URL` | connexion PostgreSQL |
| `MAILER_DSN` | envoi des courriels |
