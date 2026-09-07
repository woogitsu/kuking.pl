# Handover — kuking.pl, branch `claude/kuking-development-handover-o5r19z`

Written 2026-09-06, last updated 2026-09-07 (later evening) on branch
`claude/kuking-development-handover-o5r19z`, with `main` at `e24f30d`. This is
a session handover for the next model. Everything below is verified against
the repository, not recalled from memory.

**If you are picking this up in a new session, read section 8 first** — it is
the newest and it supersedes anything older that contradicts it. Section 8
also lists two things earlier sections got wrong.

This file is the one deliberate exception to the Polish-only rule, because the
owner asked for it in English.

---

> **Newest section wins.** As of 2026-09-07 (later evening) that is
> **section 8**. Where it contradicts anything above, section 8 is right and
> the older text is a record of what was believed at the time — section 8
> corrects two claims from section 7 explicitly.

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

- **1304 tests pass** (889 two sessions ago, 1152 one session ago), 53998
  assertions, PHPStan clean (level 1 + Larastan), Pint clean. The suite runs
  serially — `--parallel` does not work in this container.
- **The tag base is now a delivered dictionary, not a hand-written array**
  (D-026): `database/seeders/dane/slownik-tagow.json` (1250 canonical names,
  2366 aliases, 13 categories, and a `uwagi` field with 44 editorial rulings
  that must not be deleted) plus a curated `-uzupelnienia.json` (169 concepts
  the dictionary lacks). 1419 tags, 2448 aliases, seeded in 0.4 s, idempotent.
  Two categories exist only because of the 50+ audience and no tag in the old
  base covered them: `pamiec` („przepis po babci", „z rodzinnego zeszytu") and
  `okolicznosci` („dla wnuków", „z czerstwego chleba", „mało zmywania").
- **`MergeTags` exists at last** (`app/Domain/Tags/Actions/MergeTags.php`).
  `tags.status = 'merged'` and `merged_into_tag_id` had a complete READ path
  since the tags migration — the tag page redirects, suggestions exclude
  merged tags, `ResolveTagsForPost` resolves through `tagKanoniczny()` — and
  no write path at all outside `forceFill` in tests, even though the model
  comment and the migration comment both referred to `MergeTags` as if it
  existed. The seeder uses it to merge the 43 old canonical names the new
  dictionary treats as aliases, but only when the old tag is empty and
  editorial (`is_seeded`, active, no posts, no followers, no promotion).
- **D-018 was never delivered and is now delivered** (D-022). Measured by
  asking what a human SEES: an anonymised account returned 403 on its recipes,
  posts and profile, and its comments vanished from other people's threads.
  `EraseAccountData` never changed `users.status`, so the account stayed
  `pending_delete`, which six Policies treat as "hide everything". There is now
  a terminal `erased` status, three separate visibility boundaries instead of
  two, and a deletion-scope checkbox (unchecked by default) that lets the human
  decide whether their texts survive anonymised or go with the account.
- **The three legal documents no longer lie.** `/prywatnosc`, `/regulamin` and
  `/zasady` are live pages rendered from `resources/legal/*.md`, and until
  today they showed users `[NAZWA OPERATORA]`, `[ADRES]`,
  `[Wariant A — jeśli wdrożony baner:]`, Sentry and PostHog as
  sub-processors (neither is wired), the sentence "every one of these providers
  has a signed data-processing agreement with us" (the owner, asked directly:
  none are signed), a 48-hour response promise with nothing measuring it, and
  retention periods for data nothing deletes. `DokumentyPrawneNieKlamiaTest`
  now guards all of it — including the rule that **every number of days left in
  the policy must match the configuration that enforces it**.
- **The appeal window was 14 days; art. 20(1) DSA requires at least six
  months.** The 14 came from `MODERATION_PLAYBOOK.md`, where it came from
  operational common sense rather than the regulation.
  `ModerationAction::appealDeadline()` now adds six calendar months and the
  config value is only a floor.
- **A legal notice may now be filed without giving any name**
  (art. 16(2)(c) DSA). `notifier_email` was already optional, with a comment
  citing the exact provision that also exempts the NAME — the rule existed in
  the code by half. The database CHECK still demands the illegality
  explanation and the good-faith statement: anonymous is not empty.
- Two measurement documents were produced and are the base for the next
  legal work: `docs/decyzje/DSA_POMIAR.md` (obligation → what the code does,
  file:line → whether it suffices → what may and may not be written in the
  terms) and `docs/decyzje/ADR_RETENCJE.md` (five tables with no retention
  mechanism; awaiting the owner's choice of periods).
- **Topics are gone; tags replaced them** (D-021, owner's decision „Tematy
  usuwamy, tylko tagi"). Five tables (`tags`, `tag_aliases`, `post_tags`,
  `tag_follows`, `tag_promotions`), a public `/tag/{slug}` page, tag
  following, a JS-free tag field on the post form, `TagFeed` in place of
  `TopicFeed`, and a host screen at `/admin/tagi-promowane` for the
  promoted-tag list that onboarding reads. The drop migration refuses to
  run if `topic_follows` or `posts.topic_id` carry any data — it measures
  instead of trusting the note in D-021.
- **A named tag caretaker („ambasador") is NOT built** and must not be
  promised: `tag_promotions` has no `curator_id` and there is no screen to
  assign people. `docs/product/COLD_START.md` §5 now says this in place of
  the old „Ambasadorzy tematów" row.
- `phpstan-bootstrap.php` is the PHPStan half of the worktree trap that
  `tests/bootstrap.php` documents for PHPUnit: with a symlinked `vendor/`,
  Larastan instantiates models through Composer's frozen classmap, which
  points at the main checkout — so a model that exists only in the worktree
  gets a false „undefined property", and one that exists in both is
  analysed from the **stale** copy. That second case is false green, which
  is worse. No-op in the main checkout and in CI.
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

## 3. What the sessions did

### 2026-09-07 (the most recent session)

| area | what |
|---|---|
| **Tags** | The delivered 1250-tag dictionary went in as data files, `MergeTags` was written, and the suggester ranking was fixed after measuring it on real data: typing „chleb" put „chlebek bananowy" first, typing „marchewka" put „marchewka z groszkiem" ahead of „marchew" whose exact alias it is. Exact match now beats partial, shortest name next, alphabet last so the same phrase always yields the same list. Ten general concepts the dictionary lacked („barszcz", „kotlety", „krem", „kasza", „sok" …) were added after measuring which first words of compound names had no standalone tag. |
| **D-022 / D-018** | Terminal `erased` status, deletion-scope checkbox, three visibility boundaries. See §2. |
| **#17** | Photo deletion survives a mid-loop storage failure: every file goes through a per-file path that catches, verifies with `exists()`, logs and never aborts the loop; the row is deleted only on full success so the orphan sweeper has something to retry; the `r2_legacy` copy is finally targeted (audit N01). |
| **N02** | `Cache-Control: no-store` reached only the 302; the signed R2 URL now carries `ResponseCacheControl`, so the response that actually holds the bytes carries the same rule. Whether R2 honours it needs a real bucket — written down, not assumed. |
| **N05** | `photo_upload_failed` was recorded only when a file reached `StoreUploadedImage`. Form validation and rate limiting — probably the most common ways a human hits "the photo would not upload" — were invisible from Postgres. Both now record, with the reason, no PII. `post_max_size` remains unmeasurable in-process and is documented as such rather than papered over. |
| **Legal** | Placeholders, unwired tools, the DPA sentence, the 48-hour promise, unenforced retention periods and "password is encrypted" all removed; the appeal window raised to six months; anonymous legal notices allowed; §5.2 of the terms now describes the deletion-scope choice that D-022 actually implements. |
| **Measurement** | `DSA_POMIAR.md`, `ADR_RETENCJE.md`, and a false sentence withdrawn from a live notification: the decision letter promised the case would return to "a person who had not handled it before", which nothing in the code provides — a one-person service cannot promise an independent reviewer. |

### 2026-09-06 (previous session)

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
| **#114** (P0) | `php artisan kuking:wac` counts Weekly Active Cooks from Postgres, excluding banned / `pending_delete` accounts, the host account and a configurable list of test accounts. The eligibility rule lives in one class used by both the command and the D1/D7/D30 cohort query. `docs/seo/ANALYTICS.md` §2.2 and §3.2 updated so the document does not say something different from the code. One error in the issue itself, caught by checking: `host_username` lives under `community`, not `account`. I also claimed the referenced `docs/research/ANALITYKA.md` did not exist — **that claim was wrong** (see §4.4); it existed on unmerged `research/*` branches and is in fact the spec for this issue. The implementation matches it, which was luck rather than diligence. |
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

- **Tags — BUILT, this note is kept only for its lesson.** D-021 removed
  Topics entirely and D-026 replaced the hand-written tag base with the
  delivered dictionary. The warning in the original note („either merge the two
  or you will maintain two systems") turned out to be exactly right twice: once
  for Topics versus tags, and again for the old 651-name array versus the new
  dictionary, where 43 names collided and doing nothing would have produced 43
  live duplicate pairs. **Doing nothing was not the neutral option.**
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

- **`docs/research/ANALITYKA.md` DOES exist — I was wrong about this, twice.**
  I wrote that no one had committed it. It had been: 494 lines on
  `research/analityka-monetyzacja` and `claude/kuking-research-audit-16gpve`,
  never merged, so absent from my working tree. "Not here" and "never written"
  are different claims and I made the second one on evidence for the first.
  An external audit (U13) caught it. The original is now restored under its
  own name and it turns out to be the spec for #114 and #115: its §1.3 is the
  WAC exclusion gap, §2.3 is the `product_signals` path, §3.5 sets the 90-day
  retention **and explains why 90 days rather than the 6–14 months
  `COMPLIANCE.md` states** — because the table carries `user_id` per row.
  My own document was renamed to `ANALITYKA_STAN_WDROZENIA.md`, which is
  what it actually is: a record of the analytics that exist in the code: what WAC counts and who is excluded from it, the
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

**Answered on 2026-09-07, so no longer open:** the 48-hour response promise
(remove the number, write „bez zbędnej zwłoki"), anonymous legal notices (name
optional for the legal path), and the contact address (`kontakt@kuking.pl` in
both directions — the Railway variable is still the owner's to change).

**Two blockers that cannot be solved with code**, both now written into the
published documents as explicit gaps rather than silently missing: the
administrator's identity and correspondence address, and the fact that
`MAIL_MAILER=log` means the service sends no e-mail at all — so a password
reset never arrives. For a 50+ audience the second one means the first person
to forget their password loses the account, and cannot even write in, because
the mailbox is not chosen yet.


Adding "Zapisz" to a post (not just a recipe) is a **new product feature**, not
a missing button — the notebook currently holds recipes only. The kit shows it
on the post card. `docs/design/STAN_WDROZENIA_KITU.md` flags it as needing an
owner decision. Do not decide it yourself.

Two smaller ones, both surfaced today and both unanswered:

- The bottom navigation says "Moje" while `AGENTS.md` §5 still specifies
  "Zeszyt". Code and contract disagree; one of them has to move.
- Stage D's mobile menu screen would change where the "Profil" tab leads. That
  is an information-architecture decision, not CSS.

---

## 7. Session of 2026-09-07, evening — what landed and what is waiting

This section was written as the session was ending on a usage limit. It is
deliberately blunt about what is unfinished.

**Everything described here is merged to `main`.** Two pull requests went in
during this session (#122, #123) and a third carries the work below. Eleven
stale branches were identified for deletion but **could not be deleted**: the
session's git proxy returns HTTP 403 on ref deletion and no branch-delete tool
was available. The owner has the exact command list in the chat; before
deleting, note that six documents lived *only* on those branches and were
rescued to `main` in #123 — do not skip that check if more branches appear.

### 7.1 What was delivered

**From an external review package the owner commissioned** (all source
material is in the repository, under `docs/decyzje/` and
`database/seeders/dane/`, and must not be "corrected" to agree with later
edits — it is evidence of what the reviewer said):

- **Food safety.** Recipe `p24` ("Pomidory we własnym soku do słoików") is
  gone from the seed content — the only BLOKUJE verdict, promising winter
  storage after ~20 minutes of pasteurisation without verified acidity. 28
  further recipes carry safety corrections rewritten in the authors' voice,
  with every number and condition preserved. `BezpieczenstwoZywnosciW...Test`
  pins the numbers, not the wording, so a future style edit cannot lose a
  temperature. Seed content is now **39 recipes**, and the `ref` numbering
  deliberately has a hole at `p24`.
- **Profanity filter: 48 hand-written entries → 463** from a commissioned
  language curation, with the source JSON in the repository and a test that
  pins the constant to it in both directions. Five families the supplier
  excluded on false-positive grounds were **restored to blocking** as a
  deliberate decision (`PRZYWROCONE_WBREW_ZRODLU`), and five were accepted as
  exclusions (`PRZYJETE_WYLACZENIA`); both lists carry their reasoning.
- **Tag dictionary v1.1**: 27 general concepts and three techniques, plus a
  new `nowe_aliasy` key the seeder had to learn (37 aliases to existing tags
  would otherwise have loaded as zero while the report said "0 rejected").
- **Retention**: legal basis moved off art. 442(1) k.c. to art. 6(1)(f) GDPR
  with a written balancing test (ADR §5.6, including four named gaps the code
  cannot close). Audit log 24 → 12 months, notifications 24 → 3.
- **Privacy policy**: seven false or overreaching sentences fixed (the
  password claim, the IP-hash claim, shifting responsibility for
  self-disclosed data, "only the operator has access", the 30-day grace vs
  art. 17, the anonymisation limit, the analytics legal basis).

**From the ADR decided this session**: `klucz_wyslania` idempotency for
`posts`, `cooked_events` and both reporting paths, four partial-unique
migrations, 37 tests. Fails **open** by design.

**UI kit stage D** finished (search, profile, add screen, mobile menu) plus
the stage-C leftover ("Komu wyszło" with a real number bar and pagination).

### 7.2 Three bugs found by accident that matter more than they look

1. **The accessibility automation had not been logging in.**
   `TrescZalazkowaSeeder` creates a persona `basia@example.test` with a random
   password (D-025: personas are not loggable), `DemoSeeder` then found it via
   `firstOrCreate` and never set the demo password — while still printing
   "log in as basia@example.test". So `scripts/dostepnosc.mjs` measured
   **guest** screens while believing it measured logged-in ones, for twelve of
   its twenty-three screens. Fixed: the seeder now prints only accounts where
   `Hash::check` actually passes, and the automation logs in as `ania`.
   **Re-run the full accessibility automation on merged `main` — its last
   results for logged-in screens cannot be trusted.**
2. **`/tag/zupy` in that automation returns 404**, because `zupy` is an alias
   of `zupa` in the delivered dictionary. Left as-is on purpose (its comment
   says it should report 404 rather than pass silently) but it needs settling.
3. **The audit log hashed IPs with `hash('sha256', $ip.$key)`** — the exact
   construction the rate-limiter audit had rejected in favour of `hash_hmac`,
   with a comment explaining why. One line, written twice, only one copy
   correct. Now both go through `App\Support\Skrot::hmac()`.

Also fixed: a test that failed **one run in sixteen** (it corrupted a
signature by hard-coding "0" over the first hex character, which sometimes
already was "0").

### 7.3 What the owner decided, so do not re-ask

- p24: **remove**, not rewrite.
- The 28 safety corrections: apply **in the authors' voice**, numbers unchanged.
- Retention: **change the basis and shorten to 36/12/3**; full scope
  minimisation explicitly **rejected** (needs a migration and redesign).
- Idempotency: **sending key in a database column**, not cache, not a window.
- **W7-01 (`trustProxies(at: '*')`) is deferred by the owner** — "later, no
  time". Do not bring it back without new information. The measurement stands:
  a client-supplied `X-Forwarded-For` fully determines `$request->ip()` and
  resets every IP-keyed limit.

### 7.4 What is waiting, in the order I would take it

1. **Re-run `node scripts/dostepnosc.mjs` on merged `main`** — see 7.2.1. This
   is first because the last known-good result is not known-good.
2. **Move the idempotency kill switch into `config/kuking.php`.** It currently
   sits as `private const KLUCZ_WYSLANIA_WLACZONY = true;` in three
   controllers, because that file was held by a parallel task at the time. The
   config snippet is in the session report and in ADR §8.4.
3. **Paste the prepared documentation blocks.** Three finished tasks handed
   back text for `docs/DATABASE.md` and `docs/DECISIONS.md` (entry **D-027**,
   number still unassigned) rather than editing those files themselves. The
   text is in the session transcript; the ADRs carry the same content.
4. **The report case number shown to reporters without an account is not
   unique** — the first 8 characters of a UUID v7 advance roughly once every
   65 seconds, so two cases accepted in the same window get the same number.
   For an anonymous reporter that number is the only trace of their case.
5. **Design system.** The owner is unhappy with the current look and has a
   package of 60 real screenshots (15 screens × desktop/mobile × light/dark),
   the binding documents, all stylesheets and a list of 25 Blade components.
   `scripts/zrzuty-wygladu.mjs` regenerates the screenshots. Eleven concrete
   problems are named in that package's brief; the shortest real ones are the
   English "Choose File" inside the Polish file picker and radio labels
   running into their help text ("WszyscyTakże osoby bez konta").
6. **Three of the eight commissioned documents are not yet acted on**: the
   DPIA screening (verdict: cannot be settled without two facts the owner must
   establish), 17 questions for a lawyer (ready to take to a meeting), and
   empty-state copy for 19 screens — that last one is **stale**, because stage
   D changed several of those screens after the inventory was made.

### 7.5 Still blocked on the owner

- **Administrator identity and a correspondence address** in the legal
  documents. Everything else in those documents is now true; this is the last
  placeholder, and the external review says a closed beta does not excuse it.
- **A mail provider.** The art. 20 DSA appeal right is implemented but
  **undeliverable**: the reporter's access is a signed link in the decision
  e-mail, and nothing sends e-mail. This is why the appeal right was
  deliberately *not* written into the terms — it would be a promise the site
  does not keep.
- Branch protection on `main`, and the five smaller design questions listed at
  the end of `docs/design/STAN_WDROZENIA_KITU.md`.

## 8. Session of 2026-09-07, later evening — what landed and what is waiting

Everything in this section is **on branch `claude/kuking-development-handover-o5r19z`
and in open pull request #125**, not on `main`. The owner decided to hold the
merge until the new runner pool is online (see 8.4).

Note on branch names: section 7 names `claude/kuking-development-muukrs`. That
branch is finished; this session worked on
`claude/kuking-development-handover-o5r19z`.

### 8.1 What was delivered

All four queued items from §7.4 are done, plus one request that arrived
mid-session.

1. **The accessibility automation was run on merged `main` — and it was
   lying.** The first run came back clean (23 screens × 4 variants, zero
   violations). It was clean too cheaply: the script checked the response
   *path*, never the response *code*. A 404 and a 403 have the path you asked
   for, so path comparison cannot see them — and an error page is a few lines
   of text and one link, which passes any accessibility audit without
   checking anything.

   After adding an HTTP-status assertion, **two of the twenty-three screens
   turned out to be 403 with a `✓` in the report**:
   - *odwołanie od decyzji* — the script seeded the moderation decision for
     `basia` while logging in as `ania` (the fix from earlier that day). Only
     the person a decision concerns can open that screen.
   - *kolejność i wygląd zdjęć* — the carousel post belonged to `basia`, and
     `/wpisy/{post}/zdjecia` is author-only (`PostPolicy::update`).

   Fixed by putting the account name in one module constant
   (`KONTO_ZALOGOWANE`), read by both the login and the decision seeding, and
   by giving the carousel post to `ania` in `DemoSeeder`. Verified by
   sabotage: pointing one screen at a non-existent path now prints
   `BŁĄD: … odpowiedział kodem 404` and exits 1.

   **Correction to §7.2.2:** it is `/tag/zupa` that returns 404 and
   `/tag/zupy` that returns 200, not the other way round. The reason is
   mechanical: the script seeds with `DemoSeeder` alone, without `TagSeeder`,
   so there is no tag dictionary and `ResolveTagsForPost` has nothing to merge
   into — `zupy` stays canonical. The merge to `zupa` from D-026 only happens
   after `db:seed` with both seeders.

   Final state of the run: 92 axe passes, 0 violations, 0 blocking,
   0 horizontal overflow, 0 header misalignment, exit code 0, **and every
   screen with a confirmed 200**.

2. **The idempotency kill switch is in `config/kuking.php`** as
   `kuking.formularze.klucz_wyslania_wlaczony` (env `KUKING_KLUCZ_WYSLANIA`).
   It had **no test at all** — a switch that is only exercised during an
   incident, with nobody checking whether it does anything. It has one now
   (`WylacznikKluczaWyslaniaTest`, 7 cases), including two control assertions
   and a test that the switch does **not** undo `reports_one_open_per_pair`.

3. **The prepared documentation is pasted.** `docs/DECISIONS.md` has
   **D-027** (the text from ADR §10, plus a paragraph on the switch).
   `docs/DATABASE.md` has the paragraphs `AGENTS.md` §6 requires at `posts`,
   `cooked_events` and `reports`.

4. **The case number is unique now — D-029.** It was computed in five places
   in code and two views as the first 8 characters of the row's UUID v7, which
   is the top 32 bits of a millisecond timestamp and advances once every 65.5
   seconds. Measured: `Str::uuid7('19:00:30')` and `Str::uuid7('19:01:10')`
   both give `01A07D3E`. Now: `reports.numer_sprawy varchar(12) NOT NULL`,
   UNIQUE, with a CHECK on the format, assigned by the model's `creating`
   hook, not in `$fillable`. Format `KU-XXXX-XXXX` from a 30-character
   alphabet without `0`, `1`, `I`, `L`, `O`, `U`.

5. **All 14 CI jobs are pinned to the new runner pool — D-028**, on the
   owner's instruction, by label set rather than runner name. Plus
   `docs/infra/WYMAGANIA_RUNNERA.md`, which lists what the machines must
   provide.

Tests: **1636 passed, 56064 assertions** (from 1621 / 56014). Pint clean.
`npm run build` passes.

### 8.2 Two mistakes worth keeping, one mine

1. **The alphabet said one thing, the comment said another.** The first
   version of `NumerSprawy::ALFABET` contained `U`, while the comment beside
   it said `U` was excluded. It surfaced on a generated number,
   `KU-F6XC-9U7Y`. The test did not catch it because it checked the
   **randomly drawn value**, so it passed in roughly three runs out of four.
   It now checks the alphabet itself and its length. This is the fourth time
   in this repository that a rule lived in a comment and not in the code.

2. **Five existing tests do raw `INSERT`s into `reports`** to test database
   constraints while bypassing the model, so the `NOT NULL` column broke
   them. They now supply a number — a different one per row, because
   otherwise the case-number index would fire instead of the constraint each
   test is actually about. Two of them (`test_baza_odbija_…`) also gained an
   assertion on the **index name in the exception message**: the table has
   three uniqueness constraints now, and a test that only checks the
   exception type would pass for the wrong reason.

### 8.3 What could not be verified here

**PHPStan was not run.** `phpstan/phpstan` has `"source": null` in
`composer.lock`, so its only installation path is
`api.github.com/repos/phpstan/phpstan/zipball/…`, and this session's
environment returns HTTP 403 for repositories outside its own scope. To get
the rest of the dependencies installed at all, the whole `vendor/` had to be
built from git sources (`--prefer-install=source`), and `larastan/larastan`
was removed locally for the duration (`composer.json` and `composer.lock`
were restored from git afterwards and are untouched in the diff).

Static analysis for this change therefore rests entirely on the first green
CI run. **Do not report it as checked.**

### 8.4 What is waiting, in the order I would take it

1. **PR #125 is waiting for the runner pool.** After D-028 nothing in this
   repository targets GitHub-hosted runners, so its CI will sit in `Queued`
   until `woogitsu-linux-01`–`10` are online with all six labels. The owner
   chose to wait rather than merge without CI. What the machines need:
   `docs/infra/WYMAGANIA_RUNNERA.md`. **The trap that is easiest to miss:**
   the `test` and `dostepnosc` jobs both map host port 5432, so two runner
   processes on one machine collide on `port is already allocated` — one
   machine, one job at a time.
2. **Design work** (was §7.4 pkt 5, untouched). The owner is unhappy with the
   current look. 60 real screenshots; `scripts/zrzuty-wygladu.mjs`
   regenerates them. The two shortest real fixes are still the English
   "Choose File" inside the Polish photo picker and radio labels running into
   their help text („WszyscyTakże osoby bez konta").
3. **Three of the eight commissioned documents are still not acted on** (was
   §7.4 pkt 6): the DPIA screening, 17 questions for a lawyer, and
   empty-state copy for 19 screens — that last one stale since stage D.
4. **A sentence to verify, not to fix blindly.** `docs/DATABASE.md`, the
   `reports` retention paragraph, still says the 36 months rest on
   art. 442¹ k.c., while §7.3 records that the retention basis moved to
   art. 6(1)(f) GDPR. These may be two different things (the processing basis
   versus the reason for the length) — but somebody who knows should read both
   sentences side by side. I did not touch it, because guessing here would
   put a false sentence into a legal document.

### 8.5 One handover claim that was not true

§7 says an hourly pulse routine (`trig_017xRL6PBoJtmXzfkVmiUh2B`) is pinned
to the previous session and keeps waking it. **That routine does not exist.**
The account's routine list holds only Lockstate, two Osadale ones, METRO BXL
(disabled) and a daily limit reset. Nothing was waking that session and
nothing was consuming the limit.

### 8.6 Still blocked on the owner

Unchanged from §7.5: administrator identity and a correspondence address in
the legal documents; a mail provider (the art. 20 DSA appeal right is
implemented but undeliverable); branch protection on `main`. Eleven unused
branches still exist on GitHub — before deleting any of them, repeat the
check that rescued six documents in #123.

---
