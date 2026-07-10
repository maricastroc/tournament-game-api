# Gauntlet

Tournament management API. The engineering value isn't in the screens — it's in keeping the
**state always coherent**: standings, tiebreak criteria, and bracket advancement that
recompute, within a transaction, on every result submitted.

Core principle: **state is a projection, not a datum.** The source of truth is the match
results; standings, goal difference, who advanced, and the champion are all *derived* by pure
functions. Editing a result means recomputing the projection — not syncing mutable state.

## Architecture

The rules core lives in `app/Domain/Tournament`, with no framework dependency whatsoever
(zero `Illuminate\`, zero Eloquent). Controllers, Eloquent, Requests, and migrations stay
Laravel; the Eloquent → DTO translation happens at the edge (the Actions layer).

```
app/Domain/Tournament/
├── Input/
│   ├── MatchResult.php   # DTO: result of a finished match
│   └── TeamRef.php       # DTO: team reference
├── Standings/
│   ├── Criterion.php     # enum of tiebreak criteria
│   ├── TiebreakRules.php # ordered chain — ::fifa(), ::of(...)
│   ├── Standing.php      # immutable value object (a table row)
│   └── GroupTable.php    # the pure standings engine
└── Bracket/
    ├── SlotSource.php      # where a side comes from (group seed | tie winner)
    ├── Tie.php             # topology of a knockout tie
    ├── TieResult.php       # DTO: score + penalties
    ├── MatchOutcome.php    # decides the winner (regular time and penalties)
    ├── ResolvedTie.php     # value object: resolved tie (what the UI consumes)
    └── BracketResolver.php # pure knockout engine (derives slots, winners, champion)
```

**Dependency rule:** `Laravel → Domain`, never the other way around. Nothing in `app/Domain`
may import `Illuminate\*` or `App\Models\*`.

## Current state

- [x] `GroupTable` — pure standings engine, with configurable criteria and recursive
      head-to-head (mini-league among the tied teams).
- [x] `BracketResolver` — pure knockout engine: derives participants, decides winners
      (penalties included), elects the champion, and propagates "to be determined" through the rounds.
- [x] Migrations + Eloquent models (`tournaments`, `teams`, `stages`, `groups`, `matches`, `ties`),
      with indexes and the `version` column (optimistic lock).
- [x] `ConfirmMatchResult` action — stitches Eloquent ↔ Domain inside the transaction, with an
      optimistic lock that rejects concurrent edits (`StaleResultException` → HTTP 409).
- [x] REST API with Sanctum: token auth, public reads (standings and bracket), result
      submission protected by owner (Policy), validation (422), and version conflict (409).
- [x] `BracketResolver` wired to the database: knockout derived from the seeds (projection of the
      groups) + topology; the same result endpoint serves groups (→ standings) and knockout (→ bracket).
- [x] Demo seeder: the full "Copa Atlas 2026" (4 decided groups + knockout in progress) in a
      single command, with an organizer of known credentials — a rich, browsable API instantly.
- [x] Tournament assembly (CRUD): create a tournament, add teams, set up the group stage, and
      **generate** the single round-robin and the bracket — via new pure engines (`RoundRobinScheduler`,
      `KnockoutSeeder`, with the A1×B2 crossover and the chained `winner:` refs) + transactional Actions.
      A rich `TournamentDetailResource` (stages → groups → matches with `version`) feeds the front end.
- [x] Tests: Domain scenarios + property test + feature tests (real database + end-to-end API,
      incl. knockout advancement, penalties, the seeder, and the full assembly). **51 tests, ~3550 assertions.**

## API

| Method | Route | Auth | What |
|--------|------|------|------|
| `POST` | `/api/register` · `/api/login` | — | issues a Sanctum token |
| `GET`  | `/api/groups/{group}/standings` | — | group standings (projection of the matches) |
| `GET`  | `/api/stages/{stage}/bracket` | — | resolved bracket + champion |
| `PUT`  | `/api/matches/{fixture}/result` | owner | submits/edits a result → group returns standings, knockout returns bracket; 409 on version conflict |
| `GET`  | `/api/tournaments/{tournament}` | — | full view (stages → groups → matches with `version`) — the front-end read model |
| `GET` · `POST` | `/api/tournaments` | owner | lists mine · creates one (draft) |
| `DELETE` | `/api/tournaments/{tournament}` | owner | removes (cascade) |
| `POST` | `/api/tournaments/{tournament}/teams` | owner | adds teams in bulk |
| `POST` | `/api/tournaments/{tournament}/group-stage` | owner | sets up groups + generates the single round-robin |
| `POST` | `/api/tournaments/{tournament}/knockout` | owner | generates the bracket from the groups (422 if not complete) |
| `GET`  | `/api/user` · `POST /api/logout` | token | session |

## Running

Before Composer, the engines already run — the smoke runners depend on nothing:

```bash
php scripts/smoke.php          # group standings
php scripts/smoke-bracket.php  # knockout
```

After installing dependencies, the Pest suite:

```bash
composer install
./vendor/bin/pest
```

### Demo

A single command populates the entire "Copa Atlas 2026" (decided groups + knockout in progress):

```bash
php artisan migrate:fresh --seed
```

Test organizer — for the protected endpoints: **`demo@bracket.test`** / **`password`**.
Then just browse: `GET /api/stages/{id}/bracket`, `GET /api/groups/{id}/standings`.

## Notes

- `docs/mocks/` holds the high-fidelity mock of the interface (design reference; the UI itself
  will live in the front end, a separate project).
- Simplifications documented in the engine: the exact order of the FIFA rulebook is tunable by
  just reordering `TiebreakRules::fifa()`; the drawing-of-lots criterion (random) is replaced by a
  deterministic input order, better for reproducibility and testing.
