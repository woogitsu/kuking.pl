# Handover — kuking.pl, branch `claude/kuking-development-muukrs`

Written 2026-09-06, updated the same day at commit `5b90d72`. This is a session
handover for the next model. Everything below is verified against the
repository at that commit, not recalled from memory.

This file is the one deliberate exception to the Polish-only rule, because the
owner asked for it in English.

---

## 0. Read these first, in this order

1. `AGENTS.md` — the single source of truth for project rules. `CLAUDE.md` is
   only a pointer to it. **Do not restate these rules back to the owner; follow
   them.**
2. `docs/ROADMAP.md` — what is MVP and what is V2. Issues **#22 (groups),
   #23 (forks), #27 (meal planner)** are out of scope, gated by this file.
3. `docs/DECISIONS.md` — especially **D-017** (recipe stays free text),
   **D-018** (account deletion erases photos, anonymises text), **D-019**
   (light theme is always the default) and **D-020** (image URLs are an
   application route). Do not relitigate them.
4. `docs/DATABASE.md`, `docs/ARCHITECTURE.md`, `docs/MEDIA_PIPELINE.md`.
5. `docs/legal/BRAMKA_BETY.md` — what is actually closed from the wave 7
   audit, with evidence, and what still blocks the beta decision.
6. `docs/AI_WORKFLOW.md` — **read §6 before you spawn any agent in a worktree.**
   It documents two traps that cost real time today; the second one produced
   green tests that had not executed a single line of new code.
7. `docs/design/kit-v2/IMPLEMENTATION_GUIDE.md` and
   `docs/design/STAN_WDROZENIA_KITU.md` — the UI kit and what of it is done.
   **Careful: the second file is stale outside the recipe screen** — its
   `/home` table describes a topbar, side nav and bottom nav that have since
   been built. Verify against the code before trusting it.

## 1. Non-negotiables (short version — the long one is in AGENTS.md)

- **Everything in Polish**: UI text, code comments, commit messages, docs.
  Comments explain **why**, and often record what went wrong before.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 (CSS-first
  `@theme`, no `tailwind.config.js`) · PostgreSQL 18 · Railway · FrankenPHP.
- **Tests run on PostgreSQL, never SQLite** — search depends on `pg_trgm`,
  `unaccent`, `similarity()`.
- Before every push: `vendor/bin/pint`, `vendor/bin/phpstan analyse`,
  `php artisan test`, plus `npm run build` if you touched `resources/`.
  `php artisan test --parallel` does **not** work here (no ParaTest).
- A bugfix without a regression test is not a bugfix. A schema change is:
  migration + test + `docs/DATABASE.md` + rollback plan.
- `status` and `role` on `User` are never in `$fillable`. **A UUID in a URL is
  not authorization** — every access to someone else's content goes through a
  Policy and gets a test proving a stranger gets 403 (or 404 where the
  existence itself must stay hidden — see W7-05 below).
- CSP is enforcing; `config/livewire.php` has `csp_safe => true` and it stays
  true. No `style=` attributes in views (`BrakAtrybutowStyleWWidokachTest`).
  CSP is enforced against tooling too: an attempt to inject `<style>` from the
  accessibility automation was refused, and correctly so.
- Never run destructive operations against the production database.
- Answer the owner **in Polish**. Present genuine owner decisions as short
  clickable choices (`AskUserQuestion`), not walls of prose.
- Develop on `claude/kuking-development-muukrs`. Do not open a PR unless asked.

## 2. Where things stand

- **1093 tests pass** (was 889 at the start of this session), PHPStan clean
  (level 1 + Larastan), Pint clean.
- Accessibility automation (`node scripts/dostepnosc.mjs`): **everything green**
  — 0 axe violations across all four variants, 0 horizontal overflows,
  0 topbar misalignments, 0 inconsistent page widths. The last overflow (the
  recipe screen at 320 px with text at 150%) was fixed at the end of the
  session: `.przepis-siatka` had no `grid-template-columns`, so its single
  column sized to `auto` and a grid item will not shrink below its min-content
  width — 310.5 px against a 288 px container. `minmax(0, 1fr)` fixes it, the
  same idiom the ≥60rem rule a few lines below already used.
- Seven audit waves have been delivered by the owner. **Wave 7 is now mostly
  closed** — see §4.
- Closed in the previous session: **#107, #109, #110, #111, #112, #113, #116**.
- Still open because they need real infrastructure: **#119** (real HEIC support)
  and **#120** (R2 staging gate). **#120 is now blocking, not merely open** —
  see the warning in §3.

### The recurring root cause worth keeping in mind

Across all seven audits the same shape keeps appearing:

> a rule exists correctly in one layer, and a second layer re-implements it
> differently or bypasses it entirely.

It appeared three more times today: the post card decided who may edit a post
with `auth()->id() === $post->author_id` while the controller asked
`PostPolicy`; Caddy and Laravel disagreed on `X-Frame-Options`; and the topbar,
the content grid and the footer each computed the page width from a different
number. When you fix one of these, put the rule in **one** place and add a
cross-layer invariant test rather than fixing the second copy.

## 3. What this session did

All merged into `claude/kuking-development-muukrs` and pushed.

| area | what |
|---|---|
| **W7-02** (P0) | Image URLs are now an application route (`/zdjecia/{uuid}/{wariant}`) that asks the parent content's Policy and 302s to a short-lived signed R2 URL. Bytes do not pass through PHP. `App\Domain\Media\DostepDoZdjecia` calls the existing Policies instead of repeating their conditions; missing ones (`RecipeStepPolicy`, `ProfilePolicy`) were added as delegations. 24 tests built on `WidocznoscTestCase`, including the handwritten source scan and an image with two parents of different visibility. Closes W5-05 by the same mechanism. **D-020.** |
| **W7-05** (P1) | The existence oracle is closed: refusal throws the same `ModelNotFoundException` as a missing target, so the 404 is indistinguishable. The gate lives in `ReportContent`, not the controller. `CommentPolicy` gained the missing `view()`. Also added the audit's SEC-08 test locking in that DSA notices are deliberately **not** deduplicated. |
| **W7-07** (P2) | `data_exports.failure_reason` is now a closed set of codes (the `Report::REASONS` pattern); the raw exception text stays in the log only. Data migration included. `docs/DATABASE.md` gained the whole `data_exports` table, which it had never documented. |
| **W7-11** (P2) | Caddy and Laravel both send `DENY`. The test pins the *rule* (any header set in both layers must match), not the literal. |
| **W7-09** (part) | `npm audit --omit=dev` was auditing an empty list: `npm ls --all` sees 106 packages, `--omit=dev` sees 41, and the only top-level survivor was `@laravel/multiplex`. Flag removed. `continue-on-error` deliberately left alone — that is a policy call for the owner. |
| **UI** | One page width everywhere (owner request); topbar, grid and footer aligned; post editing and deletion (an MVP gap, not a missing button); light theme always default with an explicit theme switch (**D-019**). |
| **Diagnostics** | A 429 now leaves a trace naming the route that produced it. It used to leave none at all, which is why the owner's "429 on my first photo upload" could not be diagnosed. |
| **#114** (P0) | `php artisan kuking:wac` counts Weekly Active Cooks from Postgres, excluding banned / `pending_delete` accounts, the host account and a configurable list of test accounts. The eligibility rule lives in one class used by both the command and the D1/D7/D30 cohort query. `docs/seo/ANALYTICS.md` §2.2 and §3.2 updated so the document does not say something different from the code. Two errors in the issue itself, caught by checking: `host_username` lives under `community`, not `account`, and the referenced `docs/research/ANALITYKA.md` does not exist. |
| **#115** (P1) | `product_signals` table plus instrumentation for `photo_upload_failed` (per-reason) and `search_performed`. The search phrase is refused **by Postgres itself** — a CHECK constraint rejects any row whose `properties` contains `query_text`, verified with a real INSERT. Retention job at 04:00 drops rows older than 90 days. Also found: the issue says five error paths, the code has four `throw`s and one of them is unreachable; and a plain try/catch around the signal insert did **not** protect the parent operation on PostgreSQL, because a failed INSERT poisons the surrounding transaction — fixed with `DB::transaction()` (savepoint) and pinned by a test. |

**The W7-02 warning, in full, because it is easy to misread as done:** removing
`url` and `AWS_URL` from the configuration does **not** detach `cdn.kuking.pl`
from the variants bucket on Cloudflare's side. While that domain still points
there, every previously copied address keeps working and the leak continues.
W7-02 is fixed in the application, not in the infrastructure. That is issue
**#120** and it belongs to the owner.

## 4. What to do next

### 4.1 W7-01 — measured today, decision pending

**The application-layer half is no longer a hypothesis. It is measured.**
Run through the real middleware stack in this repository:

```text
no headers                 ip=127.0.0.1     (real REMOTE_ADDR)
X-Forwarded-For single     ip=203.0.113.7   (the client's own value)
X-Forwarded-For chain      ip=203.0.113.7   (first element wins, chain stripped)
XFF + CF-Connecting-IP     ip=203.0.113.7   (CF-Connecting-IP ignored entirely)
XFF + X-Forwarded-Proto    secure()=true    (this is why trustProxies is needed)

login rate limit, no XFF          -> blocked on attempt 6 (limit is 5/min)
login rate limit, XFF changed     -> NEVER blocked in 8 attempts
```

So inside the application a client-controlled header fully determines
`$request->ip()`, resets every IP-keyed limit, and chooses the value hashed
into `audit_log.ip_hash`. What is **still** unknown is only whether Cloudflare
or Railway strip a client-supplied `X-Forwarded-For` before it reaches the
container. That is the audit's SEC-01 staging test and it belongs to the owner.

Do not "fix" this by removing `trustProxies(at: '*')`. The comment in
`bootstrap/app.php` is right about `X-Forwarded-Proto`: without it
`$request->secure()` is false, `url()` emits `http://` and Cloudflare loops.
The safer design trusts a canonical single-valued header instead of an
arbitrary chain — but it has an infrastructure unknown the owner must settle
first: preview environments (`*.up.railway.app`) have **no Cloudflare in front
of them** (`docs/infra/INFRA_DECISION.md`), so `CF-Connecting-IP` would be
equally forgeable there. Ask before implementing.

### 4.2 The beta gate

**Started: `docs/legal/BRAMKA_BETY.md`.** Wave 7 is done there — all twelve
findings with state, commit SHA and regression test name: 7 CLOSED, 2 PARTIAL,
2 OPEN, 1 ACCEPTED. The four findings inherited from the previous session were
re-falsified while writing it (revert the fix, watch the named tests go red,
restore), because "the previous session says it fixed this" is not evidence.

**Waves 1–6 are missing from it** and that is the next step: only the wave 7
audit file was available in this session. Ask the owner to re-send waves 1–6,
then extend the same table. Writing those rows from memory or second-hand
would be exactly what the document exists to prevent.

### 4.3 Waiting on the owner (asked, answered, not yet built)

- **Tags.** The owner chose open user-created tags plus AI suggestions. Note
  before building: `Topic` already exists as a closed editorial dictionary with
  follow/unfollow and its own public page, and posts carry one. Either merge the
  two or you will maintain two systems. Still needs: model provider, cost per
  post, and a no-JavaScript path.
- **Recipe screen width.** The `wide` exception was removed with the fixed
  grid. If that screen turns out too cramped, give it the rail column it does
  not use — do not reintroduce a per-page page width.
- **W7-08 remainder.** Protecting `main`, pinning actions and base images to
  SHAs, and Railway's "wait for CI" are settings outside the repository (SHA
  pinning also needs registry access this container does not have).
- **429 on photo upload.** Root cause still unknown. The log now records the
  route, so the next occurrence is diagnosable. Measured on framework code:
  for routes behind `auth` the throttle key is the **account id**, not the IP,
  and `post` is 20 per 10 minutes.

### 4.4 Still queued

- ~~**`docs/research/ANALITYKA.md` does not exist.**~~ **Written 2026-09-07.**
  It is deliberately NOT a reconstruction of the document #114/#115 cite — no
  one knows what was in that one — but a record of the analytics that actually
  exist in the code: what WAC counts and who is excluded from it, the
  `product_signals` schema and its two CHECK constraints, exactly which fields
  each of the two signals carries, the 90-day retention, and a section listing
  what from `docs/seo/ANALYTICS.md` is still only a plan (PostHog is not wired
  up at all). Writing it exposed one real gap, now closed: nothing tested that
  the database refuses a `signal_name` outside the closed set.

- **#38** — rewrite UI copy per `docs/brand/COPY_STYLE.md`. The kit says
  "Jak wyszło innym?" where the app says "Komu wyszło"; that rename belongs
  here, not to the kit work.
- **UI kit v2 stage D** — search, profile/archive as a photo grid, the `/dodaj`
  flow, mobile menu. One finding from the survey is still open: **Settings are
  unreachable by touch on a phone** — the theme switch in the footer only
  partly fixes this. (The other one, numbered pagination on the profile, was
  fixed; `PaginacjaToPokazWiecejTest` now guards the rule, with the moderation
  queue deliberately exempt.)
- Leftovers from waves 3, 5 and 6 that were never reached: W3-03, W3-06, W3-07,
  W3-10, W3-11, W3-15..W3-18; W5-03, W5-04, W5-06, W5-07, W5-10..W5-25;
  W6-03, W6-04, W6-08, W6-09, W6-10, W6-11, W6-13..W6-17. (W5-05 is now closed
  by W7-02.)
- **Open, found while fixing W7-05, not filed yet:** `PostPolicy::view()` does
  not let a moderator see an unpublished post, while `RecipePolicy::view()`
  does; and neither lets a moderator see published-but-`private`/`followers`
  content. "A moderator sees everything" is therefore not true today. Worth an
  issue about the intended scope of moderator access.

## 5. Working method that has held up

- **Verify every audit claim in the code (or in `vendor/`) before fixing it.**
  Several were wrong. Say so plainly in the commit message when an audit is
  wrong — do not fix a phantom. This paid off again today: six dark-theme
  contrast violations turned out to be a measurement artifact (see below), and
  "fixing" the palette would have made the product worse for no reason.
- **Measure instead of estimating.** Every claim in this session's commits has
  numbers behind it: 106 vs 41 audited packages, logo at 212 px against nav at
  68 px, `rgb(255,255,255)` immediately after a theme switch against
  `rgb(42,36,30)` after 400 ms, login blocked on attempt 6 without XFF and
  never with it.
- **Write the test so that it fails without the fix, then actually check that.**
  Four traps now documented, all found the hard way:
  - `assertSessionHasNoErrors()` passes on a 500 — pair it with `assertRedirect`
    or a status assertion;
  - `old()` outside a request lifecycle reads an empty session;
  - a test asserting a single word can match the comment explaining the rule —
    assert a whole sentence, or strip comments first;
  - **a test that renders a page needs `RefreshDatabase`** even when it seems to
    be about something else. One committed today passed only because an earlier
    test had migrated the database.
- **Forcing a real 419 in tests**: CSRF is skipped under PHPUnit. Swap
  `$this->app['env']` to `'production'` around the call — see `zPrawdziwymCsrf()`
  in `tests/Feature/StronyBleduPoPolskuTest.php`.
- **Watch out for Pint after you edit.** It reorders imports, hoists FQCNs and
  adds trailing commas, so exact-match patch scripts stop matching after the
  first run. Re-read the file before the second patch.

### Two traps specific to running agents in worktrees

Both cost real time today. `docs/AI_WORKFLOW.md` §6 now documents them.

1. **`APP_BASE_PATH` does not fix classes.** `vendor` is a symlink and
   `composer.json` has `optimize-autoloader: true`, so the classmap is frozen
   with absolute paths into the main checkout. Every new or changed `App\`
   class in a worktree was invisible to tests — they ran the main checkout's
   copy and went green without executing a line of new code. Fixed in
   `tests/bootstrap.php` with a PSR-4 autoloader registered ahead of Composer's
   classmap; it reads the mappings from `composer.json` and no-ops in a normal
   checkout. **Two agents wrote this fix independently and git merged both
   copies into one file.** Check for that if you see it again.
2. **Never tell an agent to `cp` a shared file into its worktree.** One agent
   had already written its own uncommitted fix to exactly that file; the copy
   would have destroyed it. Ask for `git diff --stat <file>` first.

### The measurement artifact worth remembering

`.btn` has `transition: background-color .15s`. Since the dark theme is now
activated by an attribute rather than `prefers-color-scheme` set before page
load, flipping it starts that transition — and axe read the colours mid-flight,
seeing a background halfway between white and dark. Six false `color-contrast`
violations. The fix is `reducedMotion: 'reduce'` on the Playwright context (the
stylesheet honours it and collapses transitions to 0.01 ms), not a palette
change. Injecting a `<style>` with `transition: none` does not work here: CSP
refuses it, correctly.

### Environment notes for this container

- `composer install` cannot fetch dist archives: the proxy answers 403 for
  `api.github.com/.../zipball` and `codeload`. Source (git) installs work, so
  `--prefer-source` gets everything except `phpstan/phpstan`, which has no
  source in `composer.lock`. Workaround used: clone the tagged commit, zip it
  in GitHub-zipball shape and seed Composer's file cache.
- Each `git worktree` needs its own PostgreSQL database. The name is computed
  by `tests/bootstrap.php` as `kuking_test_<worktree>`; create it before
  running tests there.

## 6. Open product questions the owner has not been asked

Adding "Zapisz" to a post (not just a recipe) is a **new product feature**, not
a missing button — the notebook currently holds recipes only. The kit shows it
on the post card. `docs/design/STAN_WDROZENIA_KITU.md` flags it as needing an
owner decision. Do not decide it yourself.

Two smaller ones, both surfaced today and both unanswered:

- The bottom navigation says "Moje" while `AGENTS.md` §5 still specifies
  "Zeszyt". Code and contract disagree; one of them has to move.
- Stage D's mobile menu screen would change where the "Profil" tab leads. That
  is an information-architecture decision, not CSS.
