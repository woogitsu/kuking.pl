# Handover — kuking.pl, branch `claude/kuking-development-muukrs`

Written 2026-09-06 at commit `79d4919`. This is a session handover for the next
model. Everything below is verified against the repository at that commit, not
recalled from memory.

---

## 0. Read these first, in this order

1. `AGENTS.md` — the single source of truth for project rules. `CLAUDE.md` is
   only a pointer to it. **Do not restate these rules back to the owner; follow
   them.**
2. `docs/ROADMAP.md` — what is MVP and what is V2. Issues **#22 (groups),
   #23 (forks), #27 (meal planner)** are out of scope, gated by this file.
3. `docs/DECISIONS.md` — especially **D-017** (recipe stays free text) and
   **D-018** (account deletion erases photos, anonymises text). Both are owner
   decisions taken in this session; do not relitigate them.
4. `docs/DATABASE.md`, `docs/ARCHITECTURE.md`, `docs/MEDIA_PIPELINE.md`.
5. `docs/design/kit-v2/IMPLEMENTATION_GUIDE.md` and
   `docs/design/STAN_WDROZENIA_KITU.md` — the UI kit and what of it is done.

## 1. Non-negotiables (short version — the long one is in AGENTS.md)

- **Everything in Polish**: UI text, code comments, commit messages, docs.
  Comments explain **why**, and often record what went wrong before. Do not
  switch the repo to English. This handover file is the one exception, because
  the owner asked for it in English.
- Stack: Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 (CSS-first
  `@theme`, no `tailwind.config.js`) · PostgreSQL 18 · Railway · FrankenPHP.
- **Tests run on PostgreSQL, never SQLite** — search depends on `pg_trgm`,
  `unaccent`, `similarity()`.
- Before every push: `vendor/bin/pint`, `vendor/bin/phpstan analyse`,
  `php artisan test`, plus `npm run build` if you touched `resources/`.
  `php artisan test --parallel` does **not** work here (no ParaTest); run serial.
- A bugfix without a regression test is not a bugfix. A schema change is:
  migration + test + `docs/DATABASE.md` + rollback plan.
- `status` and `role` on `User` are never in `$fillable`. **A UUID in a URL is
  not authorization** — every access to someone else's content goes through a
  Policy and gets a test proving a stranger gets 403.
- CSP is enforcing; `config/livewire.php` has `csp_safe => true` and it stays
  true. No `style=` attributes in views (there is a test:
  `BrakAtrybutowStyleWWidokachTest`).
- Never run destructive operations against the production database. Ask first,
  every time.
- Answer the owner **in Polish**. Present genuine owner decisions as short
  clickable choices (`AskUserQuestion`), not walls of prose.
- Develop on `claude/kuking-development-muukrs`, push with
  `git push -u origin claude/kuking-development-muukrs`. Do not open a PR unless
  asked.

## 2. Where things stand

- 26 commits ahead of `main`, all pushed.
- **889 tests pass**, PHPStan clean (level 1 + Larastan), Pint clean.
- Seven audit waves have been delivered by the owner. Waves 1–6 are largely
  worked through; **wave 7 (final security) has just started** — see §4.
- Closed in this session: issues **#107, #109, #110, #111, #112, #113, #116**.
- Filed and still open because they need real infrastructure:
  **#119** (real HEIC support / libheif decision) and
  **#120** (R2 staging gate: ACL-free write path, proof originals are not
  publicly reachable).

### The recurring root cause worth keeping in mind

Across all seven audits the same shape keeps appearing:

> a rule exists correctly in one layer, and a second layer re-implements it
> differently or bypasses it entirely.

Examples fixed so far: three copies of the "author is available" boundary; the
photo format list hardcoded in seven views; blocking enforced when rendering but
not when creating a reply; two separate blacklists deciding which form fields
are secret. When you fix one of these, put the rule in **one** place and add a
cross-layer invariant test, rather than fixing the second copy.

## 3. Just finished (last four commits)

| commit | what |
|---|---|
| `e45c8f2` | Public DSA art. 16 notice path (`/zglos-nielegalna-tresc`), no account needed; receipt + decision notifications; `target_type = 'unknown'` with nullable `target_id` for unresolvable URLs |
| `ed381b9` | Made that form findable: footer link, indexable, `Disallow: /zglos/` with the trailing slash that was swallowing it |
| `5f6c43c` | **UI kit v2 stage C** — the recipe screen |
| `79d4919` | Wave 7: W7-03, W7-04, W7-06, W7-12 (secret recovery policy + blocking on reply) |

Notes on the last two, because they carry decisions:

- **Stage C**: the biggest change is not cosmetic — "Ugotowałem" moved from the
  bottom of the page into the panel next to the photo. `wide` on `<x-layout>`
  now works and raises the column ceiling; the readability ceiling moves onto
  individual prose blocks (`.kolumna-czytania`). D-017 is held: ingredients are
  a plain list with no amount column, steps are numbered paragraphs with no
  invented titles.
- **W7-03/04**: `App\Support\OdzyskiwalneDane` is now the single answer to
  "which fields may be shown back to the user". It is an **allowlist of route
  names**, not a denylist of field names. If you add a form where losing the
  text would hurt, add its route name there — and never add an auth route.

## 4. What to do next

### 4.1 Wave 7 security audit — the remaining items

The audit file the owner uploaded is
`kuking_audit_wave7_final_security_20260906.md`. It is pinned to commit
`6bb65df`, so re-verify anything before calling it open. Done: W7-03, W7-04,
W7-06, W7-12. W7-10 was already addressed by design — `ZglosNielegalnaTresc`
deliberately does **not** deduplicate legal notices (two people may report the
same content on different legal grounds and each is owed an answer); it would be
worth adding the audit's SEC-08 test to lock that in.

Remaining, in the audit's own recommended order:

1. **W7-02 (P0, privacy)** — every processed image variant goes to the public
   CDN bucket regardless of the parent content's visibility. A follower who
   copied a CDN URL keeps it after being blocked, unfollowed, or after the
   recipe goes private. The source-scan photo (a scanned handwritten recipe,
   possibly with names and addresses) is the worst case. This is the largest
   remaining piece of work and needs a storage design, not a patch. It overlaps
   with earlier finding W5-05 (moderation hide/remove does not revoke public
   CDN bytes). **This is on the audit's "no public beta while open" list.**
2. **W7-01 (P0 conditional)** — `bootstrap/app.php` has
   `trustProxies(at: '*')` and `docker/Caddyfile` has
   `trusted_proxies static 0.0.0.0/0 ::/0`. If a client can control
   `X-Forwarded-For`, it can reset IP-based rate limits and choose its own audit
   attribution. **Cannot be confirmed from code alone** — Cloudflare or Railway
   may normalise the chain. Either run the audit's SEC-01 test on staging, or
   implement the safer design (trust the Railway/Cloudflare canonical
   single-valued header, not an arbitrary XFF chain) and document it.
3. **W7-05 (P1)** — the authenticated report endpoint resolves targets with
   `findOrFail` and never applies the target's `view` policy. It is an existence
   oracle for private/draft recipe slugs and lets someone report content they
   are not allowed to see. Fix the community path; the public DSA path is
   separate and must stay permissive but uniform in its responses.
4. **W7-07 (P2)** — `GenerateUserExport::reasonFor()` stores the raw exception
   message in `data_exports.failure_reason`, which is rendered to the user. The
   comment right above the method says it must not contain SQLSTATE. Replace
   with a small set of public codes; technical text goes to logs only.
5. **W7-11 (P2)** — Laravel sends `X-Frame-Options: DENY`, Caddy sends
   `SAMEORIGIN`. Pick DENY in both.
6. **W7-08 / W7-09 (P1/P2)** — release chain: `main` is unprotected, the repair
   branch has no PR so CI never runs on it, Railway's "wait for CI" is opt-in,
   GitHub Actions are pinned to moving tags, container images to mutable tags,
   and `composer audit` / `npm audit` are `continue-on-error`. Note
   `npm audit --omit=dev` omits almost everything, because this project's
   front-end toolchain is all `devDependencies`.

The audit ends with a **beta gate**: after the repairs, do one evidence-based
closure pass over every P0/P1 from waves 1–7, each marked FIXED / PARTIAL /
OPEN / ACCEPTED with the commit SHA and the regression test name. Do that
instead of a wave 8.

### 4.2 UI kit v2 — stages C and D

Stage C is done except one item: `CookedCard` in the kit's layout
("Jak wyszło innym?" with a counts bar and a "Zobacz N wpisów" button) — today
it is a plain list of cards.

**Stage D is untouched**: search screen and chips, profile and archive as a
photo grid, the `/dodaj` flow, mobile menu and mobile profile.
`docs/design/STAN_WDROZENIA_KITU.md` has a screen-by-screen comparison; it was
made from actual screenshots at 1280 px and 390 px, so trust it.

Two things the kit gets wrong for this product, already recorded: the recipe
ingredient/step columns (D-017), and the "Uśmiech" mark inside a primary button
(it renders as a white blob — the mark draws the pot in `currentColor` and the
smile in the surface colour).

### 4.3 Other queued work

- **#38** — rewrite UI copy per `docs/brand/COPY_STYLE.md`. Note the kit says
  "Jak wyszło innym?" where the app says "Komu wyszło"; that rename belongs to
  this issue, not to the kit work.
- **#114 / #115** — analytics: the `kuking:wac` command (P0) and
  `photo_upload_failed` / `search_performed` instrumentation.
- Leftovers from waves 3, 5 and 6 that were not reached: W3-03, W3-06, W3-07,
  W3-10, W3-11, W3-15..W3-18; W5-03 (structured art. 17 statement of reasons),
  W5-04, W5-06, W5-07, W5-10..W5-25; W6-03 (recipe lost updates), W6-04 (version
  sequence race), W6-08 (orphan cleanup TOCTOU), W6-09 (follow+block race),
  W6-10, W6-11 (concurrent export requests), W6-13..W6-17.

## 5. Working method that has held up

- **Verify every audit claim in the code (or in `vendor/`) before fixing it.**
  Several were wrong: this Laravel's `image` rule already includes AVIF and
  excludes SVG; cancelling account deletion does not auto-login; the queue
  worker has 1024 MB against a measured 452 MB peak. Say so plainly in the
  commit message when an audit is wrong — do not fix a phantom.
- **Measure instead of estimating.** There is a `kuking_bench` database and an
  RSS measurement harness from the image-memory work. A first estimate of
  735 MB turned out to be 452 MB because fixed overhead was counted twice.
- **Write the test so that it fails without the fix.** Two traps found the hard
  way, both now documented in the tests themselves:
  `assertSessionHasNoErrors()` passes on a 500 (the session is clean because
  validation never ran — pair it with `assertRedirect`), and `old()` outside a
  request lifecycle reads an empty session, so a test using it measures nothing.
- **Forcing a real 419 in tests**: CSRF is skipped whenever the app runs under
  PHPUnit. Swap `$this->app['env']` to `'production'` around the call — see
  `zPrawdziwymCsrf()` in `tests/Feature/StronyBleduPoPolskuTest.php` and
  `ekran419()` in `tests/Feature/SekretyNieWracajaNaEkranTest.php`.
- **Watch out for Pint after you edit.** It reorders imports, hoists FQCNs into
  `use` statements and adds trailing commas, so exact-match patch scripts stop
  matching after the first run. Re-read the file before the second patch.
- **Test assertions that hit their own justification.** Asserting a literal word
  can match the comment explaining why the rule exists. Assert a full sentence,
  or strip `{{-- --}}` comments first.

## 6. One open product question the owner has not been asked

Adding "Zapisz" to a post (not just a recipe) is a **new product feature**, not
a missing button — the notebook currently holds recipes only. The kit shows it
on the post card. `docs/design/STAN_WDROZENIA_KITU.md` flags it as needing an
owner decision. Do not decide it yourself.
