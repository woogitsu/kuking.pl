# HANDOVER — koordynator Kuking.pl (30.09.2026, ~12:30 UTC)

You are taking over as coordinator of Kuking.pl (repo `woogitsu/kuking.pl`) for about 1 hour. The owner speaks Polish; write to them in Polish. Read `AGENTS.md` first. It is the single source of project rules.

## Your role
- Coordinate, delegate code work to subagents (owner allows up to 10 Opus agents), integrate their branches into "paczka" branches, open PRs to `main`, watch CI, fix CI, merge.
- Owner's standing orders:
  - "scalaj do main co można i rób PR": merge when CI is green, **always with a merge commit** (`merge_method: merge`).
  - Save work to GitHub regularly: the owner checks in only occasionally, and sessions die on limits.
  - Agents must commit and push WIP after every step.
- Decisions for the owner: in Polish, clickable (AskUserQuestion), with a recommended option first.

## Hard rules (never)
- No force-push, rebase, or `reset --hard` of pushed branches. Integrate only by merging (`merge --no-ff`).
- No destructive operations on production, and no writes to Railway or production. No secrets in docs.
- Don't launder permission denials. If an agent or another session gets a permission denial, report it to the owner. Don't do it yourself instead.
- Don't skip, disable or quarantine tests. Every bugfix needs a regression test and a negative control.
- Never run `npm ci` through a symlinked `node_modules`.
- The owner deletes branches; the session gets 403.
- An owner-consented rule: an integrator agent MAY merge reviewed `claude/*` branches into `claude/paczka-*` branches ("Tak, zgoda").

## Current state

### 1. PR #2339, paczka L (just opened, CI running)
- Branch `claude/paczka-l-kandydat`, head `63a5160de`, base main `b1c96678f`.
- Contains:
  - `dzwonek-mailem-i-dziennik-wpisow`: "Napisz do nas" also by email, plus the moderator access log for banned accounts;
  - `2270-ukrywanie-wersji`: hiding one recipe version as a DSA moderation decision;
  - `seo-i-drobne-astra`: erased-account profile indexed, plus #2229, #2231, #2235/#2236, #2267, #2287 UX-02 and a partial #2331;
  - `2227-kanaly-atom`: Atom feeds.
- TODO:
  1. Subscribe to PR activity, watch CI and fix failures on the branch. Use a worktree on `claude/paczka-l-kandydat` and push there.
  2. When everything is green: merge with a merge commit.
  3. Then close manually (the "Closes" lines often don't auto-close) #2270 and #2227, with a comment "Naprawione w paczce L — PR #2339, merge commit <sha>". Also verify #2229, #2231, #2235, #2236, #2267 on main and close them with evidence (file + test).
- Known CI traps:
  - Local is PG16, CI is PG18. PG18 lists NOT NULL constraints in `pg_constraint` (`contype='n'`), so filter by `contype`.
  - `HarmonogramBezWspolnychSlotow`: daily jobs must be at least 10 minutes apart. `HarmonogramBezKolizjiTerminow`: no two jobs may share a cron.
  - `PolitykaOpisujePaczkeUkryciaIReakcje`: every NA_ZADANIE column must be in the policy sentence "Poza paczką, ale na Twoją prośbę, wydajemy" AND in `POZA_PACZKA`.
  - `StraznikTekstuMaKontroleDodatnia`: a test that reads source needs a `checks` entry or `@bez-kontroli-dodatniej <powód>`.
  - `DziennikDecyzjiOdwolania`: no four-digit D-numbers in docs.
  - The policy anchor "…umowy. Ta poprawka obowiązuje od dnia publikacji." must stay, and additions go after it.
- CI log helpers in the old scratchpad may not exist for you. Use the GitHub MCP tools: `pull_request_read get_check_runs`, `get_job_logs`.

### 2. Paczka M (to build after L merges)
Integrate these branches, all pushed and reviewed by agents, all based on `b1c96678f`:

| Branch | SHA | Issue |
|---|---|---|
| `claude/2331-szukanie-emoji` | 8c650ea9f | #2331 search with emoji. **Conflict with L's partial #2331 fix:** take `FrazaWyszukiwania::normalizuj()` from this branch and keep one CHANGELOG entry |
| `claude/ux-novalidate-wszedzie` | 9e34d174e | Contains `claude/ux-2243-2246` (#2243–#2246), so merge ONLY this one. It adds `novalidate` to 52 forms, the "Cofnij usunięcie konta" button on refused login, and D-333 rows. Also has the "Odwołaj się" button for banned accounts (done) |
| `claude/2228-migrator-checksum` | c44203aee | #2228: the photo migrator checks size and SHA-256 |
| `claude/2302-infra-p3` | 6b4bd425a | #2302 IN-13/IN-14 (Railway restart policy, 1000 retries on prod). Closes #2302 |
| `claude/2326-limit-obserwowanych-tagow` | dd122cb4d | #2326: limit 500 followed tags (owner's decision, D-333 row). Possible conflict with 2331 in `TagFollowWindow` |
| `claude/2308-2327-kursor-i-uuid` | ed7e493e2 | #2308/#2327: `KursorListy` and `Route::patterns` UUID. **Check `Route::patterns` against the Atom feed routes from L** (`/zeszyt/{uuid}/kanal`, `/@{username}/kanal`) |

Plus branches from the other owner session (5 Sonnet agents, prompt `PROMPT_INNA_SESJA_SONNET.md` here). Issues: #2325, #2259, #2300, #2276 (BP-03), #2283, #2292. Their report will come on branch `claude/raport-sesji-sonnet-3009`, file `docs/flota/sesja-sonnet-3009/RAPORT.md`. That session was blocked on `composer install` (PHPStan zip 403); the owner is deciding the network settings or A2.

- Integrator procedure (proven in K and L):
  1. Create `claude/paczka-m-kandydat` from main after L, and merge each branch with `--no-ff`, pushing after each.
  2. Resolve conflicts keeping both sides (CHANGELOG, `resources/nowosci/tresc.md`, D-333).
  3. Run pint, PHPStan, the guard tests, the changed tests, the negative-control preflight, `node --test scripts/railway/iac.test.mjs`, and migrate:fresh + rollback.
  4. Open the PR following `.github/pull_request_template.md`.

### 3. Owner steps (collect, don't do)
- `KUKING_ALARM_EMAIL` (optional `KUKING_ALARM_EMAIL_KONTAKT_NA_DOBE`); `R2_ENDPOINT` must be https; `RAILWAY_TOKEN_PRODUCTION`.
- #1925: required reviewers for the production environment. #2025: activate the gated deploy. #2049: DMARC routing. #2051: R2 lifecycle for `livewire-tmp/`.
- #2291: `ALTER ROLE … SET jit=off`. #2295: `kuking:zaleznosc-od-starego-bucketu --pliki` before `railway config apply`. #2296: the first `config apply`. A paid Railway plan is required before apply (#2302).
- #1895 point 5: mark as "not applicable". #2218/#2220: legal review.
- Photo migration: `--dry-run` first (#2228).
- Optional: add `*/kanal` to the Cloudflare cache rule.

### 4. Remaining open issues (not assigned)
- #2299 (CI time, L)
- #2218 and #2220 (partial, need legal)
- epics and V2 items

Don't start V2 items from the "Nie wcześnie" list.

## Where things are
- Register: branch `claude/rejestr-koordynatora-2909`, `docs/flota/sesja-koordynatora-2909-b/REJESTR-NA-ZYWO.md`. It has every SHA and decision. Append to it.
- Worker prompt: `docs/flota/sesja-koordynatora-2909-b/PROMPT_ROBOTNIKA_J.md` on the same branch (BAZA = origin/main; update the SHA). Give it to every code agent plus a task section.
- Commit trailers: use the ones your environment gives you.
