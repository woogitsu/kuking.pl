# Mapa CI i granice uproszczenia — #611

Stan odczytany 20.09.2026 na `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
gałąź robocza `gpt/ci-architektura`. Dokument uzupełnia
[pierwszy etap uproszczenia](UPROSZCZENIE_CI_611.md) i
[zgłoszenie #611](https://github.com/woogitsu/kuking.pl/issues/611).
Nie zastępuje AGENTS.md ani decyzji właściciela. „Odczyt” oznacza kod,
historię lub API; „pomiar” oznacza wykonanie. Odbiór produkcji jest osobną rzeczą.

## Przepływ i odpowiedzialność

```text
PR → main/staging lub push → main/staging, ewentualnie ręczne CI
  → zakres → 12 jobów zależnych (kod / kod i widok)
  → wynik zestawu kontroli → Railway GitHub autodeploy („Wait for CI”)
  → deployment_status:success → deploy.verify → pomiar działającej strony

Ręczny deploy.operate → operacja utrzymaniowa wskazanego środowiska
PR wewnętrzny → preview.smoke, jeżeli wdrożenia preview są włączone
PR zmieniający IaC → plan; zamknięty i scalony PR → apply, jeśli włączone
```

Kanoniczna ścieżka zwykłego wdrożenia aplikacji to **GitHub → Railway
autodeploy → kontrola po wdrożeniu**, dla gałęzi przypisanej do środowiska.
`docker-build` niczego nie publikuje (`push: false`). `deploy.operate` jest
ręcznym narzędziem utrzymaniowym, nie drugim automatycznym wdrożeniem tej samej
wersji. IaC zarządza konfiguracją usług; preview ma osobny cykl życia.
Żaden job CI nie zależy od końcowego pomiaru produkcji: ten powstaje dopiero
po zdarzeniu wdrożenia. Nie należy mylić zielonego CI z udanym smoke-testem.

[Railway opisuje](https://docs.railway.com/deployments/github-autodeploys)
oczekiwanie na CI przed autodeployem. Czerwony zestaw w chwili scalenia może
oznaczać również pominięte wdrożenie; nie zakładamy, że samo późniejsze
naprawienie CI ponowi wcześniejsze wdrożenie.
[pomiar cudzy: przekazanie właściciela z 20.09.2026 dla `4c811cc7`].
Po naprawie trzeba potwierdzić SHA rzeczywiście działającej wersji i w razie
potrzeby jawnie wznowić wdrożenie przez kolejkę operacyjną.

## Mapa wszystkich 20 jobów

Warunek **K** to `needs.zakres.outputs.kod == 'true'`; **W** to dodatkowo
`needs.zakres.outputs.widok == 'true'`. „CI” poniżej oznacza push i PR do
`main`/`staging` oraz `workflow_dispatch`. Brak/nieosiągalna baza porównania
uruchamia cały zestaw. Same `docs/` i `README.md` mogą pominąć konsumentów.
Zmiana konfiguracji CI jest traktowana jak kod i warstwa widoku.

| Workflow / job | Co uruchamia i zależności | Co robi | Co stracimy bez niego |
|---|---|---|---|
| ci / `zakres` | CI; bez `needs` | Porównuje bazę z HEAD; wystawia K i W | Albo kosztowny komplet na każdą dokumentację, albo rozbieżne filtry i fałszywe pomijanie pomiarów. Nie zastępować globalnym `paths-ignore`: zestaw kontroli musi powstać. |
| ci / `lint` | CI, `needs: zakres`, K | Pint i testy skryptów kopii zapasowych | Brak kontroli formatowania i regresji narzędzi backupu. |
| ci / `przyrzad_605` | CI, zakres, K | Testuje przyrząd obciążeniowy na serwerze kontrolnym, m.in. timeout i przerwanie | Raport po zerwaniu lub przekroczeniu czasu może udawać wiarygodny pomiar wydajności. To nie test obciążeniowy produkcji. |
| ci / `static-analysis` | CI, zakres, K | PHPStan, jeśli istnieje konfiguracja | Utrata analizy typów; sam zielony PHPUnit tego nie zastąpi. Warunek istnienia konfiguracji pozostaje osobnym kandydatem do przeglądu. |
| ci / `test` | CI, zakres, K; własny PostgreSQL | Migracja, odtworzenie migracji, kontrole ujemne, testy aplikacji | Brak głównej kontroli zachowania, schematu i przyrządów regresyjnych. |
| ci / `dwa-polaczenia` | CI, zakres, K; własny PostgreSQL | Wyścigi dwóch połączeń; job `continue-on-error: true` | Zniknie sygnał o błędach współbieżności niewidocznych w transakcjach pojedynczego testu. D-105 wymaga stabilności, dokumentuje początkowe 20 zielonych przebiegów i zabrania blokującej bramki dla migającej grupy. |
| ci / `assets` | CI, zakres, K | Buduje Vite, sprawdza manifest i zachowanie CSS przy skalowaniu | Możliwy poprawny PHP z niedziałającym lub nieczytelnym frontendem. |
| ci / `port_panelu` | CI, zakres, K (bez W); własna baza i blokada przeglądarki | Pusty/pełny panel, dane 600, TOTP, artefakty | Zniknie odbiór panelu i jego stanów, których zwykły port marki nie pokrywa. |
| ci / `port_marki` | CI, zakres, K+W; własna baza i blokada przeglądarki | Grupa bazowa ekranów, zoom, kompozycje i kontrole ujemne | Regresje układu, czytelności i nieskutecznych pomiarów mogą przejść. |
| ci / `port_funkcje` | CI, zakres, K+W; osobna baza i checkout | Rozszerzenia portu, kreator i minutnik | Brak pokrycia rodzin ekranów oraz interakcji nieobecnych w grupie bazowej. Scalenie baz grozi wzajemnym niszczeniem fixture. |
| ci / `dostepnosc` | CI, zakres, K+W; własna baza i blokada przeglądarki | Aktualizacja service workera, listy, kafel, fokus, axe i Lighthouse, kontrole ujemne | Brak odbioru dostępności, wydajności i aktualizacji PWA; sam build tych usterek nie znajdzie. |
| ci / `audit` | CI, zakres, K | Audyt Composer i npm, także zależności deweloperskich; same kroki audytu nieblokujące | Utrata sygnału o znanych podatnościach. Przygotowanie środowiska nadal może oblać job — komentarz „nigdy nie blokuje” nie jest pełnym opisem. |
| ci / `docker-build` | CI, zakres, K | Buduje bez publikacji obraz aplikacji i backupu; sprawdza rozszerzenia, brak klucza, pg_dump 18, użytkownika i odmowę bez konfiguracji | Testy PHP mogą przejść, a właściwy obraz nie uruchomi aplikacji lub bezpiecznej kopii zapasowej. |
| deploy / `verify` | `deployment_status` ze statusem success; bez `needs` | Normalizuje nazwę środowiska, sprawdza HTTP/HTTPS, 404, brak debug i markę; odmawia sukcesu przy pominiętym właściwym pomiarze | Możliwe ciche „zielone” po wdrożeniu niedziałającej strony — historycznie 249 pominięć. |
| deploy / `operate` | Wyłącznie ręcznie; GitHub environment z wejścia | Token danego środowiska, migracja/redeploy i health; rollback wyświetla instrukcję, nie wykonuje cofnięcia | Brak jawnej drogi operacyjnej z kontrolą środowiska. To nie automatyczny rollback po czerwonym smoke-teście. |
| preview / `smoke` | Wewnętrzny PR opened/synchronize/reopened, `KUKING_DEPLOY_ENABLED == 'true'`; bez `needs` | Czeka na URL wdrożenia dla SHA, sprawdza health i stronę, publikuje wynik w PR | Brak sprawdzenia preview i drogi do jego wyniku. Brak URL dziś daje notice i sukces: to luka kontraktu, nie zbędny job. |
| preview / `manual-create` | Ręcznie, action=create; środowisko staging | Tworzy środowisko na podstawie staging | Brak ręcznej drogi utworzenia preview. Nie podlega warunkowi zmiennej użytemu przez smoke. |
| preview / `manual-delete` | Ręcznie, action=delete; staging | Usuwa wskazane preview | Osierocone płatne zasoby. Obecne `|| true` może ukryć błąd usuwania; nie dowodzi, że krok jest niepotrzebny. |
| railway-iac / `plan` | Wewnętrzny PR zmieniający `.railway/**` lub workflow, przed closed, włączona flaga | Przygotowuje plan konfiguracji i artefakt/komentarz | Utrata możliwości przeglądu zmian infrastruktury przed scaleniem. |
| railway-iac / `apply` | Taki PR closed+merged, włączona flaga; environment production | Checkout merge SHA i zastosowanie IaC, również zmian destrukcyjnych według obecnej konfiguracji akcji | Brak zarządzanego zastosowania infrastruktury. Nie zweryfikowano tu działania zewnętrznej akcji ani zgodności planu z apply na żywo. |

Preview i IaC **nie filtrują docelowej gałęzi PR tak jak CI**. Ich warunki
nie są zamienne. `plan` i `apply` nie mają `needs` między zdarzeniami.
W CI anulowany jest starszy przebieg tej samej referencji. Deploy i IaC nie
anulują wykonywanej operacji; preview anuluje starszy przebieg danego PR.
Nie usuwamy tej serializacji pod hasłem uproszczenia.

## Co rzeczywiście przestało chronić

Lista udowodnionych zbędnych operacji jest krótka: **pobieranie całej historii
w trzech checkoutach: `port_marki`, `port_funkcje`, `dostepnosc`**. Usuwamy
ich `fetch-depth: 0`, nie checkout, test ani izolowane środowisko.

| Historia | Pierwotny powód | Co zmieniło sytuację |
|---|---|---|
| `a049259a` | Dostępność sama robiła diff względem bazy; potrzebowała historii | `6586a870` przeniósł porównanie do `zakres` i bramek jobów |
| `dcd1c595`, następnie `7cbdef8a` | Port i jego podział zachowały własne filtrowanie i pełny checkout | Ten sam `6586a870`; scalone przez #783 (`6d49ee60`) |

Odczyt bieżących poleceń i wywoływanych skryptów nie znalazł konsumenta
historii w tych trzech jobach. `zoom-marki.mjs`, `zeszyty-marki.mjs` i
`regresja-liczb-profilu.mjs` używają `git ls-files --error-unmatch` do ochrony
plików przy kontroli ujemnej. Potrzebują indeksu, który zwykły checkout
zachowuje, a nie przodków HEAD. `zakres` nadal pobiera pełną historię.
[Kontrakt actions/checkout](https://github.com/actions/checkout) mówi, że
domyślnie pobiera jeden commit; wartość 0 pobiera całą historię.

Nie podajemy oszczędności czasu bez pomiaru po zmianie na runnerze.
Przykład kosztu historycznego: [komentarze #611](https://github.com/woogitsu/kuking.pl/issues/611)
opisują przebieg `35217360716`, job `105188950373`, w którym checkout trwał
od 11:46:15 do 11:56:31 i zabrakło limitu na Lighthouse.
[pomiar cudzy: komentarz w #611; nie jest pomiarem tej poprawki].

## Zabezpieczenia z historią awarii — zostają

| Zabezpieczenie / źródło | Dlaczego nie usuwać |
|---|---|
| Dynamiczne porty usług PostgreSQL, D-121 i komentarze CI | Równoległe joby muszą trafić do własnego kontenera. Zastąpienie portem hosta 5432 znosi izolację. |
| Prywatne narzędzia PHP, #262; `.github/actions/php/action.yml` | Współdzielone instalowanie Composera powodowało ETXTBSY. Composite action już obsługuje sześć jobów; dalsza migracja nie jest dowodem zbędności pozostałych przygotowań. |
| Blokady przeglądarki i rozdział portów, #577 | Ciężkie pomiary i mutacje fixture nie mogą sobie zmieniać wyników. |
| Pominięcie ponownej migracji w Lighthouse | Wcześniejszy pomiar przygotował bazę; ponowna migracja może zniszczyć jego dane. |
| Smoke: `2357ab8b`, `cbb1a58c`, `89265e7a`, `c1b367fc`, `55718db1` | Historia obejmuje odwrócony warunek, nieistniejące usługi, 249 pominięć, zduplikowany klucz YAML i błędny format nazwy środowiska. Normalizacja, odmowa pominięcia i prawdziwy outcome są zabezpieczeniami, nie ozdobą. |
| Odbiór marki po wdrożeniu, `66980acc` / #488 | HTTP 200 nie dowodzi właściwego HTML i CSS. |
| Flaga uruchomienia IaC, `76cd5802` | Nieprzygotowana infrastruktura nie powinna zostać aktywowana samym pojawieniem się pliku. Brak aktywacji nie dowodzi zbędności mechanizmu. |

Kontrakt `TestDymnyNieJestZielonyGdyNieChodziTest` już istnieje. Sprawdza
normalizację, odmowę zielonego pominięcia i raportowanie rzeczywistego wyniku.
Jego część powiela kod powłoki, dlatego nie nazywamy tego odtworzeniem
całego zdarzenia Railway. Dodatkowo `PlikiWorkflowNieMajaPowtorzonychKluczyTest`
chroni przed kolejną klasą „zielonego” bez uruchomienia właściwego workflowu.
Nie dodano drugiego identycznego strażnika.

## Ustawienia repozytorium i polityka gałęzi

Własny odczyt API GitHub 20.09.2026 (bez zmiany ustawień):

| Ustawienie | Wynik |
|---|---|
| Zmienna repozytorium `CI_RUNS_ON` | `"ubuntu-latest"`, updated_at `2026-09-20T16:27:59Z` |
| Wszystkie 20 jobów w kodzie | `fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"')` |
| `delete_branch_on_merge` | `true` |
| Ochrona `main` | Endpoint protection: HTTP 404, „Branch not protected” |
| Rulesets | Endpoint repozytorium: `[]` |
| Gałąź `staging` i jej ochrona | Oba endpointy: HTTP 404, „Branch not found”; deklaracja wyzwalacza nie dowodzi istnienia tej gałęzi ani działającego stagingu |

Komentarze „dziewięć jobów” i pomiar runnera z 11–12.09 są historyczne:
obecnie CI ma 13 jobów, wszystkie workflowy 20. Nie ma dwóch implementacji
CI dla dwóch rodzajów maszyn — jest jeden selektor runnera. Nie zmieniamy
zmiennej, etykiet ani decyzji D-121. Odczyt zmiennej nie jest potwierdzeniem,
na jakiej maszynie wykonał się każdy wcześniejszy przebieg.

Automatyczne kasowanie **scalonych** gałęzi jest już włączone. Dla starych,
**niescalonych** gałęzi nie dodajemy automatu: zawierają niewydaną pracę.
Właściciel musi wybrać retencję i sposób potwierdzania archiwizacji. Ten
pakiet nie usuwa żadnej gałęzi. Ustawienie kasowania gałęzi zdalnej nie
upoważnia do usuwania lokalnych worktree ani wspólnego stosu stash.

## Decyzje i odbiory, których kod tego pakietu nie zastępuje

1. **Bramka scalenia.** Wariant A: obowiązkowe kontrole z jawnie zaakceptowanym
   `skipped` dla dokumentacji — koszt utrzymania listy/statusów, ochrona przed
   scaleniem z czerwonym CI. Wariant B: ręczna kolejka jak dziś — brak kosztu
   konfiguracji, ale możliwość pominiętego deployu. Właściciel wybiera regułę
   i wyjątki awaryjne; nie ustawiono rulesetu bez tej decyzji.
2. **Niescalone gałęzie.** Ręczne potwierdzenie właściciela gałęzi zachowuje
   pracę kosztem przeglądu; automatyczna retencja wymaga wieku, wyjątków i
   archiwum. Sama data ostatniego commita nie świadczy o zbędności gałęzi.
3. **Ciężkie pomiary.** Obecny podział K/W już istnieje, `port_panelu` nadal
   działa dla K. Zawężenie panelu wymaga mapy zależności fixture/kodu, a
   obowiązkowy `dwa-polaczenia` — odbioru bieżącej stabilności według D-105.
   Historyczne 20 zielonych wyników nie jest pomiarem dzisiejszej wersji.
   Nie usunięto pomiarów.
4. **Preview i IaC.** Brak URL daje sukces, usunięcie preview tłumi błędy,
   apply może przyjąć zmiany destrukcyjne i nie filtruje gałęzi. To nazwane
   granice obecnego kontraktu, nie dowód niepotrzebnych kroków. Ich aktywacja
   i zasady wymagają osobnego odbioru. Lista zmiennych repo nie zawierała
   `KUKING_DEPLOY_ENABLED`; nie sprawdzano zmiennych organizacji/środowisk.
5. **Sentry i PHPStan.** Opcjonalne wydanie Sentry nie jest dowodem działającego
   SDK (D-041), a obecność konfiguracji PHPStan nie usuwa historycznej
   semantyki warunku. Nie wycięto ich przy okazji trzech checkoutów.

Nie można uczciwie zamknąć całego #611 jako odebranego wdrożenia: ten pakiet
domyka mapę, wąskie uproszczenie i zapis stanu, lecz nie wybiera polityki za
właściciela ani nie wykonuje zabronionego pusha/produkcji.

## Sprawdzenie i wycofanie

Przed edycją workflowu wykonano na niezmienionym drzewie testy z filtrem
`Ci`: **1548 testów, 11329 asercji, 198,50 s, PASS**. Filtr dopasował też
nazwy metod poza CI; to nie był pełny zestaw projektu.

Nowy test na oryginalnym workflowie: **1 FAIL, 6 PASS, 93 asercje** —
powód: `fetch-depth: 0` w `port_marki`. Po zmianie: **7 PASS, 105 asercji**.
Dowody: [przed](evidence/ci611-20260920/test-przed.txt),
[po](evidence/ci611-20260920/test-po.txt). Kontrola dodatnia wymaga znalezienia
każdego checkoutu, zależności i bramki; drugi test wymaga pełnej historii
oraz rzeczywistego diff w `zakres`.

Pozostałe kontrole końcowe są zapisane w
[raporcie wykonania](evidence/ci611-20260920/WERYFIKACJA.md).
Testy lokalne: fizyczne zależności, osobny runtime, PostgreSQL
`127.0.0.1:55439`, baza `kuking_flota_gpt-ci-architektura`. Nie uruchamiano
wdrożenia, preview, zastosowania IaC ani publikacji komentarzy.

Rollback: przywrócić trzy opcje pełnego checkoutu wraz ze zmianą testu
regresyjnego (revert `abef3c94d9313de35f146d2c7110c84612400f51`). Nie ma zmiany schematu,
danych, sekretów, cache ani infrastruktury. Pierwszy dozwolony przebieg
zdalny musi potwierdzić wykonanie trzech pomiarów i zmierzyć checkout;
lokalny wynik nie zastępuje tego odbioru.
