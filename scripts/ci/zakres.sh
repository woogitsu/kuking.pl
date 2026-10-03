#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — bramka `zakres` (job `zakres` w .github/workflows/ci.yml)
# =============================================================================
#  Od #611 (etap 5) skrypt bramki mieszka TU, a nie w YAML-u. Wcześniej było
#  to ~11 tys. znaków powłoki w `run: |`, które testy wyjmowały z `ci.yml`
#  tekstem. Teraz da się je uruchomić lokalnie na liście ścieżek, sprawdzić
#  `shellcheck`-iem i przetestować wprost: `bash tests/skrypty/zakres.sh`
#  (tabela: lista zmienionych plików -> siedem wyjść, plus kontrola ujemna
#  na każdym wyjściu).
#
#  ZACHOWANIE JEST IDENTYCZNE Z WERSJĄ Z `ci.yml`: te same sześć wyjść
#  (kod, widok, dokumenty, obraz, obciazenie, wyscigi) i te same reguły.
#  Od 2.10.2026 jest SIÓDME, `pelny` (draft PR = zakres skrócony, niżej).
#  Nowe są dwie rzeczy: `scripts/ci/` stoi w filtrach „zmiana przyrządu =
#  mierz wszystko" (zmiana tego pliku nie może obejść bramki), oraz tryb
#  testowy opisany niżej.
#
#  WEJŚCIE (zmienne środowiska, ustawia je krok w `ci.yml`):
#    BAZA       — SHA bazy porównania (PR: baza PR-a, push: `event.before`);
#    ZDARZENIE  — `github.event_name`; zawężanie ciężkich jobów tylko na PR-ach;
#    DRAFT      — `github.event.pull_request.draft` (`true` na drafcie, puste
#                 poza PR-em); steruje SIÓDMYM wyjściem `pelny` (niżej);
#    GITHUB_OUTPUT — plik wyjść kroku.
#  TRYB TESTOWY: gdy ustawione `ZAKRES_LISTA_PLIK`, lista zmienionych plików
#  idzie z tego pliku (jedna ścieżka na wiersz), a `BAZA` i `git` nie są
#  potrzebne. W CI ta zmienna nie jest ustawiana nigdzie. Plik, a nie
#  zmienna z treścią: lista z dużego diffu przekracza limit jednej zmiennej
#  środowiska (128 kB), a strażnik SIGPIPE-a używa listy 450 kB.
# =============================================================================
set -euo pipefail

# -----------------------------------------------------------------------------
# WYJŚCIE `pelny` — CZY TO JEST PEŁNY PRZEBIEG (decyzja właściciela z 2.10.2026,
# bez nowego numeru D; wiersz w D-333).
#
# PO CO: pomiar z 2.10.2026 — w 12,5 h 3509 jobów, ok. 28 000 minut runnera,
# średnio 34 joby naraz, szczyt 157. Prawie całość to `CI` na DRAFT PR-ach
# agentów do `codex/integracja-*`, które NIGDY nie są scalane bezpośrednio:
# scalamy paczki, a paczka ma pełne CI. Na drafcie biegną więc tylko szybkie
# kontrole (Pint, Larastan, testy w częściach 1–4); kontrole negatywne
# i ciężkie joby przeglądarkowe, obrazu, audytu itd. czekają na „ready".
#
# `pelny=false` WYŁĄCZNIE gdy zdarzenie to `pull_request` I `DRAFT` brzmi
# dokładnie `true`. Każdy inny przypadek — push na main/staging/integrację,
# PR nie-draft, `workflow_dispatch`, brak `ZDARZENIE` albo `DRAFT` — daje
# `pelny=true`: pomyłka w konfiguracji ma dać nadmiarowy przebieg, nie
# pominięty pomiar. Zapisane NA POCZĄTKU, żeby ścieżki „brak bazy" niżej
# (które kończą skrypt wcześniej) też je wystawiały.
# Tabela i kontrola ujemna: tests/skrypty/zakres.sh; joby na drafcie:
# tests/Feature/CiNaDrafcieMaSkroconyZakresTest.php.
if [ "${ZDARZENIE:-}" = "pull_request" ] && [ "${DRAFT:-}" = "true" ]; then
  echo "Draft PR — zakres skrócony (Pint, Larastan, testy 1–4)."
  echo "pelny=false" >> "$GITHUB_OUTPUT"
else
  echo "pelny=true" >> "$GITHUB_OUTPUT"
fi

# Tryb testowy (patrz nagłówek): lista z pliku zamiast z `git diff`.
if [ -n "${ZAKRES_LISTA_PLIK:-}" ]; then
  ZMIENIONE="$(grep -v '^$' "${ZAKRES_LISTA_PLIK}" || true)"
else
  # Stan bez punktu odniesienia (pierwszy push gałęzi, wymuszony
  # przebieg) — wtedy NIE ZGADUJEMY i puszczamy pełny zestaw.
  if [ -z "${BAZA}" ] || [ "${BAZA}" = "0000000000000000000000000000000000000000" ]; then
    echo "Brak punktu odniesienia — pełny zestaw."
    echo "kod=true" >> "$GITHUB_OUTPUT"
    echo "widok=true" >> "$GITHUB_OUTPUT"
    echo "dokumenty=true" >> "$GITHUB_OUTPUT"
    printf '%s\n' obraz=true obciazenie=true wyscigi=true >> "$GITHUB_OUTPUT"
    exit 0
  fi

  if ! git cat-file -e "${BAZA}^{commit}" 2>/dev/null; then
    echo "Baza ${BAZA} nieosiągalna — pełny zestaw."
    echo "kod=true" >> "$GITHUB_OUTPUT"
    echo "widok=true" >> "$GITHUB_OUTPUT"
    echo "dokumenty=true" >> "$GITHUB_OUTPUT"
    printf '%s\n' obraz=true obciazenie=true wyscigi=true >> "$GITHUB_OUTPUT"
    exit 0
  fi

  ZMIENIONE="$(git diff --name-only "${BAZA}" HEAD)"
fi
echo "Zmienione pliki:"
echo "${ZMIENIONE}"

# Wyłącznie te dwa katalogi są uznane za „nie kod". Wszystko inne —
# łącznie z `.github/`, `pint.json` czy `composer.json` — uruchamia
# pełny zestaw. Lista jest krótka CELOWO: pomyłka w stronę
# nadmiarowego przebiegu kosztuje minuty, a w drugą stronę
# przepuszcza niesprawdzoną zmianę.
POZA="$(echo "${ZMIENIONE}" | grep -vE '^(docs/|README\.md$)' || true)"

# -------------------------------------------------------------
# WARSTWA DOKUMENTACJI — bo `kod=false` NIE ZNACZY „nie ma czego
# mierzyć".
#
# CO BYŁO ZEPSUTE (zmierzone 22.09.2026). Między 08:55 a 13:05 na
# `main` nie było ani jednego uczciwego pomiaru: ponad 60 przebiegów
# skończyło się jako `cancelled` (grupa współbieżności — każde
# kolejne pchnięcie ubijało poprzednie), a SIEDEM zameldowało
# `success`, NIE MIERZĄC NICZEGO: ta bramka uznała je za zmianę
# wyłącznie w `docs/`, ustawiła `kod=false` i pominęła 12 z 13 jobów.
# Tą dziurą weszły na `main` DWIE wady — PR-ami dokumentacyjnymi,
# którym ta sama bramka pominęła dokładnie te joby, które by je
# złapały.
#
# DLACZEGO `docs/` NIE JEST „NIE KODEM". To jest ZMIERZONE, nie
# przyjęte na wiarę — pilnuje tego
# `BramkaZakresuNiePomijaJobowCzytajacychTest`, który czyta z DYSKU,
# kto naprawdę otwiera pliki spod wykluczeń tej bramki:
#
#   `lint` — Pint skanuje CAŁE repozytorium, a w `docs/` leżą pliki
#            `.php` (paczki dowodowe audytów, fixture'y badawcze).
#            To jest jedna z dwóch wad, które tędy weszły.
#   `test` — kilkanaście plików z `tests/` OTWIERA pliki z `docs/`:
#            strażnicy martwych odnośników, zgodność
#            `docs/DATABASE.md` ze schematem, numeracja decyzji.
#            To jest druga.
#
# Dlatego oba chodzą także przy `kod=false`. Pozostałe jedenaście
# jobów zostaje pominiętych — one `docs/` nie czytają, a nadmiarowy
# przebieg przeglądarkowy kosztuje kilkanaście minut.
#
# RÓŻNICA LIST, A NIE DRUGA KOPIA WZORCA: `POZA` to już „zmienione
# bez dokumentacji", więc gdy listy się różnią, jakiś plik
# dokumentacji wypadł — czyli był. Drugi `grep` z tym samym wzorcem
# dałoby się zmienić w jednym miejscu i zapomnieć o drugim, a to
# jest dokładnie ta wada, którą ten blok naprawia, piętro wyżej.
#
# `dokumenty=false` przy PUSTEJ liście zmian nie jest przeoczeniem:
# gdy nie zmienił się żaden plik, nie ma czego sprawdzać.
if [ -n "${ZMIENIONE}" ] && [ "${POZA}" != "${ZMIENIONE}" ]; then
  echo "dokumenty=true" >> "$GITHUB_OUTPUT"
else
  echo "dokumenty=false" >> "$GITHUB_OUTPUT"
fi

# -------------------------------------------------------------
# PACZKA WZORCOWA MARKI TO DANE, NIE DOKUMENTACJA.
#
# `docs/design/references/` leży w `docs/`, ale `scripts/fixtures/
# kompozycje-marki.php` i `scripts/fixtures/panel-marki.php`
# OTWIERAJĄ stamtąd archiwum wzorców — a te fixture'y są wejściem
# trzech jobów przeglądarkowych (`port_marki`, `port_funkcje`
# przez `scripts/port-projektu.mjs`, `port_panelu` przez
# `scripts/panel-marki-run.mjs`). Podmiana wzorca bez ani jednego
# przebiegu portu marki znaczyłaby, że port marki mierzy się wobec
# pliku, którego nikt nie sprawdził.
#
# Dopisujemy więc te ścieżki z powrotem do `POZA` (czyli na stronę
# „to jest kod"), zamiast dokładać trzem jobom osobnych warunków:
# warunki tych jobów są pilnowane przez `PortMarkiMaWlasnaBramkeCiTest`
# i mają zostać proste. Ta sama ścieżka jest też w filtrze warstwy
# widoku niżej.
# Test grafu Railway w jobie `assets` czyta docs/DEPLOYMENT.md.
# Ta instrukcja jest więc wejściem testu tak samo jak wzorce marki.
WZORCE_MARKI="$(echo "${ZMIENIONE}" | grep -E '^(docs/design/references/|docs/DEPLOYMENT\.md$)' || true)"

if [ -n "${WZORCE_MARKI}" ]; then
  POZA="$(printf '%s\n%s\n' "${POZA}" "${WZORCE_MARKI}" | grep -v '^$' || true)"
fi

if [ -z "${POZA}" ]; then
  echo "Zmiana wyłącznie w dokumentacji — ciężkie zadania pominięte."
  echo "kod=false" >> "$GITHUB_OUTPUT"
else
  echo "kod=true" >> "$GITHUB_OUTPUT"
fi

# -------------------------------------------------------------
# WARSTWA WIDOKU — jedno miejsce dla trzech jobów przeglądarkowych.
#
# Do 19 września 2026 ten sam filtr stał TRZY RAZY, osobno w
# `port_marki`, `port_funkcje` i `dostepnosc`, i pilnował KROKÓW,
# a nie jobów. Skutek był taki, że przy zmianie kodu poza warstwą
# widoku job się URUCHAMIAŁ, wszystkie jego kroki pomiarowe były
# pomijane, a job kończył się na ZIELONO — nie do odróżnienia na
# liście kontrolnej od joba, który naprawdę wszystko zmierzył.
# Zmierzone na 25 przebiegach: „Port marki (kompozycje…)" miał
# 15 sukcesów, z czego 4 trwały 19–22 s wobec 462–506 s przy
# rzeczywistym pomiarze. Tak samo „Port marki — rodziny ekranów"
# (4 × 19–21 s wobec 1456–1638 s) i „Dostępność" (4 × 18–22 s
# wobec 745–955 s). Razem dwanaście zielonych bez ani jednego
# pomiaru.
#
# Teraz warunek stoi na JOBIE. Gdy warstwa widoku jest nietknięta,
# cały job jest `skipped` — czyli widać, że nic nie zmierzono.
# Zielony wynik znowu znaczy „zmierzone".
#
# Wzorzec obejmuje TAKŻE SAME SKRYPTY POMIAROWE i `ci.yml` oraz `scripts/ci/` (ten skrypt): zmiana
# przyrządu musi uruchomić pomiar, inaczej dałoby się zepsuć
# miernik bez jednego czerwonego przebiegu. Pilnuje tego
# `PortMarkiMaWlasnaBramkeCiTest`.
#
# OBEJMUJE TAKŻE `.github/actions/` — i to nie jest ozdoba. Te joby
# wołają lokalne akcje przez `uses: ./…` (konfiguracja PHP,
# sprawdzenie sekretu hasła demonstracyjnego). Zmierzone przed tą
# poprawką: zmiana `.github/actions/haslo-demo/action.yml` dawała
# `kod=true`, ale `widok=false`, czyli WSZYSTKIE trzy joby
# przeglądarkowe były pomijane. Dałoby się więc zepsuć albo odwrócić
# warunek w akcji-strażniku i nie zobaczyć ani jednego czerwonego
# przebiegu. Pilnuje tego
# `BramkaZakresuNiePomijaJobowCzytajacychTest::test_zmiana_lokalnej_akcji_uruchamia_joby_ktore_jej_uzywaja`.
#
# Lista pomiarów niesie też `referrer-sekret-browser` (z `main`).
# Sterujące nim `ApplySecurityHeaders.php` i `AnalitykaCloudflare.php`
# łapie już szersze `app/`, więc nie stoją na liście osobno.
# PHP także steruje ekranem: komponenty, trasy, kontrolery, polityki
# i modele. Konserwatywnie obejmujemy całe app/, konfigurację,
# start aplikacji, tłumaczenia, bazę oraz zależności PHP (#1039).
# Here-string nie gubi trafienia przez SIGPIPE przy dużym diffie.
#
# POZA PR-EM (push na `main`/`staging`, uruchomienie ręczne) filtr
# nie zawęża: `widok=true` zawsze, bo `main` mierzy pełny zestaw
# (decyzja właściciela 24.09.2026). Brak `ZDARZENIE` też znaczy
# „pełny" — pomyłka w konfiguracji ma dać nadmiarowy przebieg,
# nie pominięty pomiar. `scripts/panel-*` są na liście, bo od
# 24.09 filtr pilnuje także `port_panelu`.
if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -qE '^(app/|routes/|config/|bootstrap/|lang/|database/|composer\.(json|lock)$|resources/|public/|scripts/(referrer-sekret-browser|hero-nad-zgieciem|dostepnosc|kafel-dodawania|kafel-dodawania-bramka\.test|port-projektu|pwa-install-browser|port-grupy|port-grupy\.test|szybki-wyglad|pasek-przewijany|zwarte-kolumny|lista-osob-szerokosc|kompozycje-marki|zeszyty-marki|zainteresowania-powiadomienia-marki|tagi-marki|katalog-tagow|kontrast-notice(-wyglad\.test)?|nawigacja-niski-widok|nawigacja-(etykiety|zoom|negatywy)|zoom-marki|regresja-liczb-profilu|fokus-karty-dania|fokus-zdjec|menu-karty-wpisu|kroki-kreatora|kreator-zachowanie|przegladarka/(strona-nieaktualna|kolaz-lcp|panel-validation-errors)\.test|service-worker-aktualizacja|minutnik-regresja|minutnik-fokus|lib/stan-ustalony|kaskada-martwe-reguly|pasek-uklady|lead-wstep|eksport-bloki-692|turnstile-csp|lib/serwer-lokalny)\.mjs|scripts/kaskada-kontrola-(polecenie|ujemna)\.sh|scripts/panel-[a-z-]+(\.test)?\.(mjs|php)$|scripts/fixtures/|docs/design/references/|docker/Caddyfile|package(-lock)?\.json|\.github/(workflows/ci\.yml|actions/)|scripts/ci/)' <<< "${ZMIENIONE}"; then
  echo "widok=true" >> "$GITHUB_OUTPUT"
else
  echo "Warstwa widoku nietknięta — joby przeglądarkowe pominięte."
  echo "widok=false" >> "$GITHUB_OUTPUT"
fi

# -------------------------------------------------------------
# CIĘŻKIE JOBY WĄSKIEGO OBSZARU — zawężane TYLKO NA PR-ACH.
#
# PO CO (decyzja właściciela 24.09.2026): 138 otwartych PR-ów, każdy
# przebieg to ~13 ciężkich jobów na self-hosted runnerach, joby
# wypadały na limitach czasu. Trzy joby mierzą wąski obszar, a
# chodziły przy KAŻDEJ zmianie kodu:
#
#   obraz      → `docker-build`: Dockerfile'e, `docker/`,
#                `.dockerignore`, manifesty zależności, konfiguracja
#                builda (Vite/Tailwind/PostCSS), `.railway/`, to, co
#                czyta `artisan package:discover` w obrazie
#                (`bootstrap/`, `config/`, `app/Providers/`), oraz
#                testy JS z polecenia `npm run build` — obraz
#                uruchamia je BEZ `tests/`, `docs/` i `*.md`
#                (`.dockerignore`), czego job Vite nie odtworzy.
#                Sam build Vite ze zmienionych źródeł mierzy job
#                `assets` na każdym PR-ze.
#   obciazenie → `przyrzad_605`: pliki `scripts/*605*` i dowody
#                `scripts/fixtures/obciazenie605/`, które przyrząd czyta.
#   wyscigi    → `dwa-polaczenia`: kod i dane aplikacji (testy tej
#                grupy chodzą przez HTTP i zatwierdzają dane, więc
#                także widoki i tłumaczenia), `tests/Dwa/`, wspólne
#                zaplecze testów i skrypt uruchamiający.
#
# Każdy wzorzec obejmuje `ci.yml` i `scripts/ci/`, a `wyscigi` także akcję PHP:
# zmiana przyrządu uruchamia pomiar. Że job ruszy przy zmianie
# każdego pliku, który naprawdę czyta, pilnuje z DYSKU
# `BramkaZakresuNiePomijaJobowCzytajacychTest`.
#
# Poza PR-em (i bez `ZDARZENIE`) wszystkie trzy są `true` — jak
# dotąd; o pominięciu nadal decyduje wyłącznie `kod`.
ciezki() {
  if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -Eq "$2" <<< "${ZMIENIONE}"; then
    echo "$1=true"
  else
    echo "Obszar joba (${1}) nietknięty — job pominięty na tym PR-ze." >&2
    echo "$1=false"
  fi >> "$GITHUB_OUTPUT"
}

ciezki obraz '^(Dockerfile$|docker/|\.dockerignore$|composer\.(json|lock)$|package(-lock)?\.json$|\.npmrc$|\.railway/|railway\.(json|toml)$|vite\.config\.[cm]?[jt]s$|(tailwind|postcss)\.config\.|artisan$|bootstrap/|config/|app/Providers/|resources/js/[^/]+\.test\.mjs$|scripts/([^/]+|fixtures/[^/]+)\.test\.mjs$|scripts/kontrast-marki\.mjs$|scripts/ci/|\.github/workflows/ci\.yml$)'
ciezki obciazenie '^(scripts/[^/]*605[^/]*$|scripts/fixtures/obciazenie605/|scripts/ci/|\.github/workflows/ci\.yml$)'
ciezki wyscigi '^(app/|bootstrap/|config/|database/|routes/|lang/|resources/views/|artisan$|composer\.(json|lock)$|phpunit\.xml$|\.env\.example$|tests/(Dwa/|Support/|TestCase\.php$|bootstrap\.php$)|scripts/(testy-dwa-polaczenia\.sh|kontrola-negatywna-(2165|240[234]|2418|2427|2437|2551|2598|2815|2851-2854|2861-2862)\.py)$|scripts/ci/|\.github/(workflows/ci\.yml$|actions/php/))'
