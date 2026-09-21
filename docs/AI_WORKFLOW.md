# Praca nad Kuking z modelami AI

Nad tym repozytorium pracuje kilka modeli (Claude, GPT/Codex, Gemini, Copilot).
Ten dokument opisuje, jak to zorganizować, żeby nie deptały sobie po palcach
i nie kosztowały więcej, niż dają.

---

## 1. Jedno źródło prawdy

Wszystkie zasady projektu są w [`AGENTS.md`](../AGENTS.md).

Pliki, które modele czytają automatycznie, są **cienkimi wskaźnikami** na ten plik:

| Plik | Kto czyta |
|---|---|
| `AGENTS.md` | Codex/GPT, Claude Code, Jules i coraz więcej narzędzi — **kanoniczny** |
| `CLAUDE.md` | Claude Code |
| `GEMINI.md` | Gemini CLI |
| `.github/copilot-instructions.md` | GitHub Copilot |
| `.cursor/rules/kuking.mdc` | Cursor |
| `.windsurfrules` | Windsurf |

**Zasadę zmieniasz wyłącznie w `AGENTS.md`.** Rozjazd między plikami
instrukcji jest gorszy niż brak instrukcji: każdy model dostaje wtedy inną
wersję prawdy i różnice wychodzą dopiero na review.

---

## 2. Jak pracujemy

```text
issue (P0 → P1 → P2)
  → agent czyta AGENTS.md i dokument obszaru
  → branch
  → kod + testy + docs
  → Pull Request
  → GitHub Actions (pint, PHPStan, testy na PostgreSQL 18, build assetów)
  → review
  → main
  → Railway (auto-deploy)
```

Zasady, które oszczędzają najwięcej czasu i tokenów:

- **Jedno issue = jeden PR.** Pomysł, który wpadł po drodze, ląduje jako nowe
  issue, nie jako dodatkowy commit w niepowiązanym PR-ze.
- **Bugfix zawsze z testem regresyjnym.** Poprawka bez testu to zaproszenie
  do powtórki.
- **Agent nie potrzebuje SSH na produkcję.** Wszystko idzie przez PR i CI.

---

## 3. Automatyczne przygotowanie środowiska

`.claude/hooks/session-start.sh` uruchamia się na starcie sesji Claude Code
i robi to, czego inaczej każdy agent szuka po omacku: startuje PostgreSQL,
tworzy bazy `kuking` i `kuking_test`, dociąga zależności, tworzy `.env`
i puszcza migracje.

Dla innych narzędzi to samo ręcznie:

```bash
pg_ctlcluster 16 main start
createdb kuking && createdb kuking_test
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

---

## 4. Skille projektowe

W `.claude/skills/` są procedury specyficzne dla tego repozytorium:

| Skill | Kiedy |
|---|---|
| `kuking-ekran` | nowy ekran, strona albo formularz — z checklistą UX 50+ |
| `kuking-migracja` | zmiana schematu bazy — migracja + test + docs + rollback |

Zawierają rzeczy, których model nie zgadnie: wzorzec migracji z `CHECK`-ami,
listę gotowych komponentów Blade, zakazy (`UNIQUE` w `cooked_events`,
`status` w `$fillable`) i pułapki, na które już się tu nadziano.

---

## 5. Skille i wtyczki spoza repozytorium, które realnie pomagają

Kolejność jest według stosunku korzyści do kosztu tokenów.

### Wbudowane w Claude Code — używaj od razu

| Narzędzie | Do czego w tym projekcie |
|---|---|
| `/code-review` | przegląd diffa przed PR-em; łapie IDOR-y, brak `authorize()`, nieobsłużone wyjątki |
| `/security-review` | przegląd bezpieczeństwa zmian — obowiązkowo przy dotykaniu uploadu, autoryzacji i moderacji |
| `/simplify` | sprzątanie po sobie: powtórzenia, zbędne warstwy, martwy kod |
| `/init` | odświeżenie `CLAUDE.md`, gdy struktura repo mocno się zmieni |
| `/run` | uruchomienie i sprawdzenie aplikacji w przeglądarce zamiast zgadywania |
| `/loop` | cykliczne zadania, np. pilnowanie zielonego CI |
| `/fewer-permission-prompts` | mniej pytań o zgodę na komendy, które i tak wykonujemy stale |

### Warte rozważenia

| Narzędzie | Uwaga |
|---|---|
| `efficient-delegation` | zanim odpalisz kilku agentów naraz — realnie ogranicza dublowanie pracy i zużycie kontekstu |
| `superpowers-manager` / `dev-superpack` | metodyka pracy + zestaw skilli specjalistycznych; sensowne przy dłuższych, wieloetapowych zadaniach |
| `fullstack-dev-skills-manager` | skille językowe i frameworkowe; przydatne, jeśli ktoś dojdzie do projektu bez doświadczenia w Laravelu |
| `skill-creator` | gdy jakaś procedura powtarza się trzeci raz — zamień ją na skill w `.claude/skills/` |

### Czego świadomie NIE używamy

- **Generatorów treści pod SEO.** Masowo generowane przepisy skasowałyby jedyny
  realny wyróżnik Kuking i są nieodwracalne (`AGENTS.md`, sekcja 9).
- **Automatycznych „ulepszaczy” architektury**, które proponują mikroserwisy,
  Redisa albo osobne SPA. To jest wprost na liście zakazów.

---

## 6. Aktualny układ zespołu agentów

Projekt jest prowadzony w trybie **jeden agent researchu + kilku agentów kodu**,
każdy w **osobnym worktree gita** i z **osobną bazą testową**.

| Rola | Zakres |
|---|---|
| research (ciągły) | `docs/research/repos/`, `docs/INSPIRATION_DECISIONS.md` — nie dotyka kodu |
| kod: kreator przepisu | `resources/views/pages/recipes/`, `RecipeController`, `app/Domain/Recipes/` |
| kod: eksport danych | `app/Jobs/`, `app/Mail/`, `app/Console/Commands/`, `app/Domain/Users/` |
| kod: profil i komentarze | `ProfileController`, `SocialController`, `comment-thread`, `resources/views/pages/profile/` |

W tabeli nie ma już kolumny „baza testowa": **nazwy baz nie przydziela się
ręcznie**. Wylicza ją `tests/nazwa-bazy.php`: `kuking_test` w głównym
checkoucie, `kuking_test_<worktree>` w `git worktree`, a w kopii bez `.git`
(runtime, archiwum, obraz) `kuking_test_kat_<katalog>_<skrót ścieżki>`.
Ręczne `DB_DATABASE=…` dalej ma
pierwszeństwo, ale nie jest już do niczego potrzebne. Swoją nazwę sprawdzisz
poleceniem:

```bash
php -r 'require "tests/nazwa-bazy.php"; echo kuking_nazwa_testowej_bazy(__DIR__);'
```

Cztery rzeczy, które sprawiają, że to działa:

1. **Rozłączne zakresy plików**, wypisane wprost w zadaniu każdego agenta —
   razem z listą plików, których dotykać NIE wolno.
2. **Osobne worktree** — agenci nie nadpisują sobie plików w trakcie pracy,
   a każdy commituje na własną gałąź.
3. **Osobne bazy testowe.** To jest najczęściej pomijany szczegół: `RefreshDatabase`
   czyści bazę na starcie każdego testu, więc dwóch agentów na jednej bazie
   testowej kasuje sobie dane w połowie przebiegu i dostaje losowe błędy —
   963, 3737 i kilkaset porażek `QueryException` w trzech sesjach 19 września
   wzięło się dokładnie stąd. Od naprawy #736 i #920 nie trzeba z tym nic
   robić: `tests/nazwa-bazy.php` liczy nazwę z worktree albo — gdy `.git`
   nie ma wcale, czyli w runtime — z KATALOGU kopii roboczej, więc dwa
   stanowiska to z definicji dwie bazy. Porzucone bazy sprząta
   `./scripts/cleanup-test-dbs.sh` (kasuje tylko te, po których kopia robocza
   zniknęła z dysku — nigdy bazy trwającego przebiegu).
4. **Symlink na `vendor` i `node_modules`** z głównego katalogu zamiast
   ponownej instalacji — oszczędza minuty i omija limity pobierania z GitHuba.

### ⚠️ Pułapka symlinku: `APP_BASE_PATH`

Symlink na `vendor` ma jedną poważną konsekwencję, która potrafi zmarnować
godzinę i — co gorsza — **dać fałszywie zielone testy**.

Laravel wylicza `base_path()` z lokalizacji `vendor/composer/ClassLoader.php`.
Gdy `vendor` jest dowiązaniem do głównego repozytorium, framework uruchomiony
w worktree ładuje **trasy i konfigurację z głównego katalogu**, a nie
z worktree. Objawy są mylące: `php artisan route:list` pokazuje nowe trasy
poprawnie, ale test dostaje „Route not defined"; albo test przechodzi,
mimo że w ogóle nie wykonał nowego kodu.

Dlatego **każda komenda artisana w worktree** idzie z jawną ścieżką bazową:

```bash
APP_BASE_PATH=$(pwd) php artisan test   # nazwa bazy liczy sie sama, patrz wyzej
APP_BASE_PATH=$(pwd) php artisan migrate --force
APP_BASE_PATH=$(pwd) php artisan serve --port=8201
```

W głównym katalogu repozytorium nie jest to potrzebne — `vendor` jest tam
prawdziwym katalogiem.

### ⚠️ Druga połowa tej samej pułapki: `APP_BASE_PATH` NIE naprawia klas

Ten akapit mówił wcześniej, że `APP_BASE_PATH` załatwia „trasy, konfigurację
i klasy". Trzeci człon był nieprawdą i kosztował agenta pół zadania: pisał
kod, którego testy w ogóle nie wykonywały.

`composer.json` ma `optimize-autoloader: true`, więc
`vendor/composer/autoload_classmap.php` jest **zamrożoną mapą klasa → ścieżka
bezwzględna**, zapisaną tam, gdzie ktoś ostatnio uruchomił `composer install`.
PHP wylicza `__DIR__` przez ścieżkę RZECZYWISTĄ, więc `$baseDir` w tej mapie
zawsze wskazuje na główne repozytorium. Autoloader Composera to osobny,
statyczny mechanizm i o `APP_BASE_PATH` nic nie wie.

Zmierzone (gołe `require vendor/autoload.php`, bez bootstrapu Laravela,
uruchomione z worktree):

```text
Post załadowany z: /workspace/kuking.pl/app/Models/Post.php
worktree:          /workspace/kuking.pl/.claude/worktrees/sprawdzenie
```

Czyli: **każda klasa PHP dodana albo zmieniona w worktree jest dla testów
niewidoczna** — ładuje się jej wersja z głównego katalogu. Test sprawdza kod,
którego w tym worktree nie ma, i świeci na zielono albo na czerwono z zupełnie
niezwiązanego powodu.

Naprawa siedzi w `tests/bootstrap.php`: własny autoloader PSR-4 dla `App\`,
`Database\Factories\`, `Database\Seeders\` i `Tests\`, wepchnięty PRZED
classmap Composera (`spl_autoload_register(..., prepend: true)`) i wskazujący
na katalogi tego checkoutu. W głównym katalogu nie zmienia niczego — wskazuje
dokładnie te same pliki, co classmap.

**To działa dla testów, nie dla całej reszty.** `php artisan tinker`,
`migrate --seed` z własnym seederem czy `serve` w worktree dalej ładują klasy
z głównego katalogu, bo nie przechodzą przez `tests/bootstrap.php`. Jeśli
kiedyś będzie to potrzebne, właściwym rozwiązaniem jest prawdziwy `vendor`
w worktree (dowiązania na pojedyncze pakiety + własny `vendor/composer`
i `composer dump-autoload`), a nie kolejna łatka.

Scalanie: gałęzie wracają pojedynczo, po każdej pełny `./scripts/check.sh`.
Konflikt jest praktycznie zawsze w `routes/web.php` — dlatego każdy agent ma
polecenie dopisywać tam tylko własne trasy i wspominać o tym w raporcie.

## 7. Praca wieloagentowa — kiedy się opłaca

Opłaca się przy zadaniach, które da się **rozdzielić bez wspólnych plików**:
research, analiza konkurencji, dokumentacja obszarowa, przegląd kilku
niezależnych modułów.

Nie opłaca się przy jednej spójnej zmianie w kodzie — dwóch agentów piszących
w te same pliki kosztuje więcej niż jeden i produkuje konflikty.

Zasady, które sprawdziły się przy budowie tego repozytorium:

1. Każdy agent ma **rozłączny katalog wyjściowy** i wprost zapisany zakaz
   dotykania cudzych plików.
2. Agenci researchowi piszą do katalogu roboczego, a wyniki dopiero potem
   trafiają do `docs/` — dzięki temu nie kolidują z osobą piszącą kod.
3. Każdy agent dostaje na wejściu ścieżkę do blueprintu i wymóg podania źródeł
   oraz jawnego oznaczania rzeczy niepotwierdzonych.
4. Agent, który generuje kod albo konfigurację, ma obowiązek **zweryfikować
   składnię** (`bash -n`, `python3 -m json.tool`, `yaml.safe_load`, `php -l`).

---

## 8. Definicja gotowości

Zmiana jest gotowa do PR-a, kiedy:

```bash
vendor/bin/pint        # bez zmian do wprowadzenia
php artisan test       # zielone, na PostgreSQL
npm run build          # assety się budują
```

oraz:

- [ ] dotknięte ekrany spełniają checklistę z `.claude/skills/kuking-ekran/SKILL.md`,
- [ ] zmiana schematu ma migrację, test, wpis w `docs/DATABASE.md` i rollback,
- [ ] bugfix ma test regresyjny,
- [ ] dokumentacja obszaru jest aktualna,
- [ ] w PR-ze jest: co, dlaczego, ryzyka, rollback.
