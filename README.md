# Gauntlet

Tournament management API. The engineering value isn't in the screens — it's in keeping the
**state always coherent**: standings, tiebreak criteria, and bracket advancement that
recompute, within a transaction, on every result submitted.

Core principle: **state is a projection, not a datum.** The source of truth is the match
results; standings, goal difference, who advanced, and the champion are all _derived_ by pure
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
- [x] `ProjectScenario` — the "what if?" projection: a `ScenarioOverlay` layers hypothetical results
      over the real matches and the _same_ pure engines recompute the whole tournament **without writing
      anything**; a hypothetical group result cascades into the bracket seeds. A public, unauthenticated read.
- [x] Demo seeder: the full "Copa Atlas 2026" (4 decided groups + knockout in progress) in a
      single command, with an organizer of known credentials — a rich, browsable API instantly.
- [x] Per-session demo sandbox: the shared `demo@bracket.test` login deep-clones the template
      into a private copy scoped to that session's token (`CloneTournament` remaps every internal
      id, incl. the `winner:` tie refs), so concurrent visitors never collide and the base template
      stays read-only for everyone. `POST /demo/reset` re-clones it; sandboxes expire after 24h and
      a scheduled `demo:prune-sandboxes` sweeps them hourly.
- [x] Tournament assembly (CRUD): create a tournament, add teams, set up the group stage, and
      **generate** the single round-robin and the bracket — via new pure engines (`RoundRobinScheduler`,
      `KnockoutSeeder`, with the A1×B2 crossover and the chained `winner:` refs) + transactional Actions.
      A rich `TournamentDetailResource` (stages → groups → matches with `version`) feeds the front end.
- [x] Tournament editing: `PATCH /tournaments/{id}` (rename) and `PATCH /tournaments/{id}/teams/{team}`
      (rename / flag, partial-safe — untouched fields survive) — owner-gated Actions. `TournamentDetailResource`
      carries `can_manage` (the authoritative policy result for the token-bearing caller) so the UI shows edit
      controls only to the owner and keeps the demo template read-only. Renames are safe: standings and the
      bracket are keyed by id, not name.
- [x] Cross-engine conformance: a shared `tests/Vectors/standings.json` (byte-identical to the front end's
      copy) that both the PHP `GroupTable` and the TypeScript standings engine must reproduce — so the two
      implementations can't silently drift on tiebreaks (e.g. head-to-head).
- [x] Tests: Domain scenarios + property test + feature tests (real database + end-to-end API,
      incl. knockout advancement, penalties, the seeder, the full assembly, the what-if scenario,
      and the demo sandbox clone/isolation/prune). **88 tests, ~3900 assertions.**

## API

Interactive OpenAPI docs (auto-generated from the code via [Scramble](https://scramble.dedoc.co/), rendered
with [Scalar](https://scalar.com/)) are **public** and live at:

- **Docs UI:** https://gauntlet-api.marianacastro.dev/docs/api — try-it console included
- **OpenAPI 3.1 spec:** https://gauntlet-api.marianacastro.dev/docs/api.json

Locally they're at `/docs/api` and `/docs/api.json`, or export a static copy with `php artisan scramble:export`.

| Method         | Route                                       | Auth  | What                                                                                                |
| -------------- | ------------------------------------------- | ----- | --------------------------------------------------------------------------------------------------- |
| `POST`         | `/api/register` · `/api/login`              | —     | issues a Sanctum token (the demo login also provisions a per-session sandbox)                       |
| `GET`          | `/api/groups/{group}/standings`             | —     | group standings (projection of the matches)                                                         |
| `GET`          | `/api/stages/{stage}/bracket`               | —     | resolved bracket + champion                                                                         |
| `POST`         | `/api/tournaments/{tournament}/scenario`    | —     | projects hypothetical results (standings + bracket) **without persisting** — the "what if?" engine  |
| `PUT`          | `/api/matches/{fixture}/result`             | owner | submits/edits a result → group returns standings, knockout returns bracket; 409 on version conflict |
| `GET`          | `/api/tournaments/{tournament}`             | —     | full view (stages → groups → matches with `version`); adds `can_manage` for the token-bearing caller |
| `GET` · `POST` | `/api/tournaments`                          | owner | lists mine · creates one (draft)                                                                    |
| `PATCH`        | `/api/tournaments/{tournament}`             | owner | renames the tournament                                                                              |
| `DELETE`       | `/api/tournaments/{tournament}`             | owner | removes (cascade)                                                                                   |
| `POST`         | `/api/tournaments/{tournament}/teams`       | owner | adds teams in bulk                                                                                  |
| `PATCH`        | `/api/tournaments/{tournament}/teams/{team}`| owner | renames a team / updates its flag (partial-safe)                                                    |
| `POST`         | `/api/tournaments/{tournament}/group-stage` | owner | sets up groups + generates the single round-robin                                                   |
| `POST`         | `/api/tournaments/{tournament}/knockout`    | owner | generates the bracket from the groups (422 if not complete)                                         |
| `POST`         | `/api/demo/reset`                           | demo  | drops this session's demo sandbox and clones a fresh one from the template                          |
| `GET`          | `/api/user` · `POST /api/logout`            | token | session                                                                                             |

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

Logging in as the demo organizer provisions a **per-session sandbox** — a token-scoped clone of
the template — so edits never touch the shared data and concurrent visitors stay isolated.
`POST /api/demo/reset` restores a clean copy. Sandboxes expire after 24h (`DEMO_SANDBOX_TTL_HOURS`);
run `php artisan demo:prune-sandboxes` to sweep them, or let the scheduler do it hourly (needs
`php artisan schedule:run` on a cron in production).

## Notes

- `docs/mocks/` holds the high-fidelity mock of the interface (design reference; the UI itself
  will live in the front end, a separate project).
- Simplifications documented in the engine: the exact order of the FIFA rulebook is tunable by
  just reordering `TiebreakRules::fifa()`; the drawing-of-lots criterion (random) is replaced by a
  deterministic input order, better for reproducibility and testing.
