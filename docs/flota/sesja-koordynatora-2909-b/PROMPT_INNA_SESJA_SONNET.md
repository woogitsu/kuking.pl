# Prompt dla drugiej sesji (Kuking.pl, 5 agentów Sonnet)

You are a coordinator session for the Kuking.pl project (repo `woogitsu/kuking.pl`), running in the cloud. Another coordinator session ("main coordinator") owns integration, PRs and CI. Your job: fix the issues listed below using **up to 5 Sonnet subagents**, push each fix to its own branch on GitHub, and finish with a summary report that the owner will forward to the main coordinator.

Write code comments, commit messages, UI texts and the final report **in Polish** (the project is Polish). This prompt is in English only for convenience.

## 1. Read first (every agent)

- `AGENTS.md` (the single source of project rules), `CLAUDE.md`, `docs/PULAPKI_TESTOW.md`.
- The D-333 table in `docs/DECISIONS.md`: the owner's decisions of 30 September 2026. Don't ask about anything already decided there.
- The full worker prompt used by the main coordinator is on branch `claude/rejestr-koordynatora-2909`, file `docs/flota/sesja-koordynatora-2909-b/PROMPT_ROBOTNIKA_J.md`. Follow its rules. Its environment section (paths like `/workspace/kuking.pl/vendor`) may need adapting to your container.

## 2. What to do

1. Base: `origin/main` (currently `b1c96678f` or newer, after "paczka K"). Every issue gets its own new branch from `origin/main`, named `claude/<issue-number>-<short-polish-slug>`. Example: `claude/2231-avatar-json-ld`.
2. **Verify first that the problem still exists on `origin/main`.** Another agent has just closed 37 issues that main already fixed. Treat issue text as material, not as truth. If an issue is already fixed, don't write code; put the evidence (file, test) in the report.
3. Fix it:
   - Every bugfix needs a regression test **and** a negative control. The negative control means: break the fix, see the test fail, restore the fix, and describe it in the report.
   - A test that reads source code as text needs either an entry in `scripts/kontrole-negatywne-alfa08.py` plus a pattern in `scripts/kontrole_oczekiwana_przyczyna.py`, or `@bez-kontroli-dodatniej <reason>` in the class docblock.
4. Run before pushing:
   - `vendor/bin/pint --test` on changed PHP files and `php -l`;
   - the touched and related tests on **PostgreSQL** (never SQLite);
   - PHPStan on changed files if feasible.
   - PostgreSQL version: CI runs PG18; your local may be older. Queries on `pg_constraint` must filter by `contype`, because PG18 also lists NOT NULL constraints there.
5. **Save work continuously.** After every step (at least every few minutes), commit `WIP: …` and `git push -u origin <branch>`. Sessions can be killed by limits, and pushing to `claude/*` branches does not trigger CI.
6. End every commit message with the attribution trailers your own environment tells you to use (Co-Authored-By / Claude-Session).
7. A user-visible change needs a CHANGELOG entry under `## Nieopublikowane`, without bumping the version.
   - A schema change also needs: a migration following AGENTS.md §6 (`NOT VALID` + `VALIDATE`), a rollback that may refuse when data exists (D-088), an update to `docs/DATABASE.md`, and a migration timestamp unique across all `origin` branches.

## 3. What NOT to do

- **Do NOT open pull requests. Do NOT merge anything into `main` or into `claude/paczka-*` branches.** The main coordinator integrates your branches into "paczka M", opens the PR and watches CI.
- Don't comment on GitHub issues or PRs, and don't close issues.
- Never rebase, force-push, `reset --hard` on a pushed branch, or use `--no-verify`. Integrate only by merging.
- Don't touch other people's branches, especially `claude/paczka-*`, `claude/rejestr-*`, `claude/2331-*`, `claude/2308-2327-*`, `claude/ux-2243-2246` and `claude/2270-*`.
- Don't connect to production or Railway, and don't read or print secrets. Use `Mail::fake` and `Http::fake` in tests.
- Don't skip, disable or quarantine tests.
- Never put controlling fields in `$fillable` (user `status`/`role`, entry `kind`).
- Don't add a four-digit decision number (D-xxxx) to docs. For a decision, add a row to the D-333 table instead.
- Don't run `npm ci` or `npm install` through a symlinked `node_modules`.
- UX for users aged 50+:
  - text at least 18 px, touch targets at least 48 px;
  - no hover-only or swipe interactions;
  - error messages in Polish that say what to do next;
  - it works without JS;
  - correct input the user typed never disappears.

## 4. Issues and suggested assignment (5 Sonnet agents)

NOTE (update 30.09): #2229, #2231, #2267 and #2287 UX-02 were already fixed in paczka L (PR open) — removed from this list. 6 issues remain; one per agent, agent 5 takes two.

Hints come from the verification on `b1c96678f`. Confirm them yourself.

| Agent | Issue | Hint |
|---|---|---|
| 2 | **#2325** robots.txt check uses the query string, not just the path | Same class: `PobieraczStron::sciezka()` appends the query string. Pass only the path. Tests. #2229 (same class) is ALREADY fixed on `claude/paczka-l-kandydat` (allow-list of params) — build on top of it: branch `claude/2325-robots-sciezka` from `origin/claude/paczka-l-kandydat` (exception to the base rule), or from `origin/main` once paczka L is merged. |
| 3 | **#2259** Backup: future timestamp in file name gives a false "fresh" state | `StanKopiiBazy.php` ~l.111 uses `diffInHours(absolute: true)`. A future date must become its own state or raise an alarm. Boundary test. |
| 3 | **#2300** CI on `main`: a pending run gets replaced by a newer one, but the comment says "queue" (IN-08) | `.github/workflows/ci.yml` ~l.305–307. Either fix the comment or implement a real queue (separate concurrency group). If you change the workflow, add or extend a test that reads the workflow. Keep it minimal. |
| 4 | **#2276** P3 flow findings | BP-05 is already fixed; do only **BP-03**: `PublishRecipe.php` ~l.628 should check "never published" instead of `$existing === null`. Test. |
| 5 | **#2283** P3 privacy/legal findings Z7–Z11 | Mostly text: the policy (e.g. ~l.154 "gdy będziemy już wysyłać"), the processing register, and `failed_jobs` retention. Policy edits: the anchor sentence "…umowy. Ta poprawka obowiązuje od dnia publikacji." must stay, and additions go AFTER it. Run all policy/legal guard tests (`--filter='Polityka|DokumentyPrawne|TekstyNiePrzypisujaPlci'`). |
| 5 | **#2292** P3 performance findings F5–F7 | Comment counter, cleanup of the `cache` table, PostgreSQL version in docs. A scheduled job must be at least 10 minutes from other daily jobs and not share a cron (`Harmonogram*` tests). |

Don't work on any other issue. If you find a new bug, list it in the report and don't fix it.

## 5. Final summary (required)

When all agents are done, write **one report in Polish** for the owner to paste to the main coordinator. Also commit it as `docs/flota/sesja-sonnet-3009/RAPORT.md` on a branch `claude/raport-sesji-sonnet-3009`. For each issue:

- the branch and final SHA, pushed and matching `origin`;
- the evidence the problem existed on main, or evidence it was already fixed;
- what changed, with the changed files;
- tests run and their result (counts), including which test is the regression test;
- the negative control: what was broken and which test failed with what message;
- risks, migrations (yes/no), any owner steps;
- a proposed PR title and a 3–6 line PR description in Polish ending with `Closes #N`.

At the end, add:
- a list of all pushed branches and their SHAs;
- anything you could not finish and why;
- any permission denial you hit (describe it, don't work around it).

Clean up at the end: remove `vendor`, `node_modules` and any test databases you created.
