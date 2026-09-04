# AGENTS.md

## Development cycle

- ALWAYS start by creating an integration test which tests the bug (if the user asks you to correct a bug), or the wanted functionality.
- If the user hasn't given you detailed enough information on the feature/bug, or if you see in the code something which contradicts what the user said/asked for, you MUST raise this concern and ask for precisions.
- The test MUST be a failing test at this point, since you haven't yet developed anything.
- ALWAYS run `make fix` and `make test-functional` after having finished modifying the code.
- The test MUST succeed at this point, to validate that your development correctly

## Environment

- **All commands run inside Docker containers.** Use `docker compose exec php-fpm <cmd>` or the Makefile commands.
- PostgreSQL 16 database. Container name: `database`, DB: `app`, user: `app`, password: `password`.
- Symfony 8.1 + PHP 8.5 (aligned with the production target).
- The application is served on http://localhost:8000, Adminer on http://localhost:8080.

## Makefile Commands

- `make up`: Build, start, install dependencies and migrate
- `make diff`: Generate a migration diff
- `make migrate`: Runs the migrations
- `make fixtures`: Load the demo dataset
- `make reset-database`: Drop, recreate, migrate and reload fixtures
- `make test-functional`: Prep test DB (create, migrate), then run `tests/Functional/` with `--testdox`
- `make test-unit`: Run `tests/Unit/`
- `make rector`: Run Rector with PHP 8.5 + dead code + coding style presets
- `make csfixer`: Run php-cs-fixer (short array syntax, @Symfony + @PSR12)
- `make phpstan`: Run PHPStan level 7 on `src/` and `tests/`
- `make fix`: Run rector → php-cs-fixer → phpstan (in that order)

The Make commands are made to be run outside docker, they invoke commands into docker themselves.
Tool config files live in `tools/` (e.g. `tools/phpstan.dist.neon`, `tools/.php-cs-fixer.dist.php`, `tools/rector.php`). The Makefile uses those paths.

## Code style

- @Symfony + @PSR12, short array syntax.
- `declare(strict_types=1)` required (enforced by tools).
- Comments and user-facing strings are written in French; code identifiers stay in English.
- All user-facing text goes through the translator. Keys live in `translations/messages.fr.yaml` and `messages.en.yaml` — **always add both**.

## This project specifically

This is a port of a Ruby on Rails application that still lives in `../pikaichu`.
It is the reference for any behaviour question: when in doubt about a business
rule, read the Rails model rather than guessing. `MIGRATION.md` maps Rails
concepts onto their Symfony counterparts and tracks what is still missing.

### Domain rules that are easy to get wrong

- **`Match` is a reserved word in PHP.** The entity is `TaikaiMatch`; the table stays `matches`.
- **Business logic belongs in `src/Service/`**, not in entities. Entities carry data, simple derived predicates, and recalculation of their own cached counters (e.g. `Score::recalculateFromResults()`); anything that reaches across entities or enforces a cross-entity rule belongs in a service.
- **Score counters are derived, never set directly.** Go through `MarkingService`, which recomputes the participant score and then the team score.
- **`hits`/`value` count only validated arrows**; `intermediate_hits`/`intermediate_value` also count arrows that are merely marked. Provisional leaderboards use the latter.
- **Ties consume places.** Two competitors tied at rank 1 are followed by rank 3, not 2. See `Ranker`.
- **From the tie-break stage onwards, the stored rank wins over the score** — it can be adjusted by hand.
- **A validated arrow (`final`) is immutable** except through the rectification screen, which sets `overriden`.
- **`taikai_transitions` has a partial unique index on `most_recent`.** Doctrine emits INSERTs before UPDATEs, so clearing the previous flag needs its own flush before inserting the new transition. `TaikaiStateMachine::recordTransition()` does this — do not "simplify" it away.

### Tests

- Functional tests live in `tests/Functional/` and boot the kernel; unit tests in `tests/Unit/` are pure PHP and must stay fast.
- Domain services are not all injected into a controller yet, so the container inlines them. `config/services_test.yaml` exposes them publicly for tests — add new services there when a test needs to fetch them from the container.
- Functional tests are isolated from each other by `dama/doctrine-test-bundle`: each test runs inside a transaction that's rolled back at the end (see `phpunit.xml.dist` and `config/packages/test/dama_doctrine_test.yaml`), so no manual database reset is needed between tests. `App\Tests\DatabaseResetTrait::commitSeeding()` is still needed after seeding data and before the first request — see the identity-map pitfall in `MIGRATION.md`.
