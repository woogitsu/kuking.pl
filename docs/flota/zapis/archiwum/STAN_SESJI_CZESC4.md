# Stan sesji, część 4 — CI i plan techniczny (#611, #614)

Raport sporządzony na podstawie wiernego odczytu dwóch plików:
- `C:\Users\matma\Documents\kuking-flota\gpt-ci-architektura\docs\infra\MAPA_CI_611.md`
- `C:\Users\matma\Documents\kuking-flota\gpt-ci-architektura\docs\infra\PLAN_TECHNICZNY_614.md`

Oba pliki istnieją i zostały odczytane w całości. Poniżej zapisano wyłącznie
to, co stoi w treści tych dokumentów — bez interpretacji ponad to, co
dokumenty same stwierdzają.

## #611 — Mapa CI

**Stan odczytany:** `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź robocza
`gpt/ci-architektura`, data odczytu 20.09.2026.

### Czy mapa powstała i czy ma kolumnę skutku usunięcia

Tak. Dokument zawiera tabelę „Mapa wszystkich 20 jobów” z kolumnami:
`Workflow / job | Co uruchamia i zależności | Co robi | Co stracimy bez niego`.
Ostatnia kolumna („Co stracimy bez niego”) opisuje wprost skutek usunięcia
dla każdego z 20 pozycji (12 jobów `ci`, `deploy/verify`, `deploy/operate`,
`preview/smoke`, `preview/manual-create`, `preview/manual-delete`,
`railway-iac/plan`, `railway-iac/apply`).

### Kroki uznane za niechroniące — i jakość dowodu

Dokument podaje jedną, wyraźnie ograniczoną listę: sekcja „Co rzeczywiście
przestało chronić” stwierdza wprost: „Lista udowodnionych zbędnych operacji
jest krótka: pobieranie całej historii w trzech checkoutach: `port_marki`,
`port_funkcje`, `dostepnosc`”. Usunięto ich `fetch-depth: 0` — nie checkout,
test ani izolowane środowisko.

Dowód podany dla tej listy:
- Tabela historii zmian: commit `a049259a` (dostępność robiła diff względem
  bazy sama, potrzebowała historii) zastąpiony przez `6586a870`, który
  przeniósł porównanie do jobu `zakres` i bramek jobów; commity `dcd1c595`,
  następnie `7cbdef8a` (port i jego podział miały własne filtrowanie i pełny
  checkout) — również zastąpione przez `6586a870`, scalone przez PR #783
  (`6d49ee60`).
- Odczyt bieżących poleceń i wywoływanych skryptów nie znalazł konsumenta
  historii w tych trzech jobach; skrypty `zoom-marki.mjs`, `zeszyty-marki.mjs`
  i `regresja-liczb-profilu.mjs` używają `git ls-files --error-unmatch`, co
  wymaga indeksu (zachowywanego przez zwykły checkout), a nie przodków HEAD.
- Powołanie na kontrakt `actions/checkout`: domyślnie pobiera jeden commit,
  wartość `0` pobiera całą historię.
- Dokument zastrzega: „Nie podajemy oszczędności czasu bez pomiaru po
  zmianie na runnerze”, i podaje tylko historyczny przykład kosztu (przebieg
  `35217360716`, job `105188950373`, checkout od 11:46:15 do 11:56:31,
  zabrakło limitu na Lighthouse) z adnotacją „[pomiar cudzy: komentarz w
  #611; nie jest pomiarem tej poprawki]”.

### Czy cokolwiek faktycznie usunięto, czy tylko opisano

Faktycznie usunięto (nie tylko opisano). Dokument podaje dowód wykonania:
- Test przed edycją workflowu (niezmienione drzewo, filtr `Ci`):
  **1548 testów, 11329 asercji, 198,50 s, PASS**.
- Nowy test regresyjny na oryginalnym workflowie: **1 FAIL, 6 PASS,
  93 asercje** — powód: `fetch-depth: 0` w `port_marki`.
- Po zmianie: **7 PASS, 105 asercji**.
- Dowody wskazane pod ścieżkami: `evidence/ci611-20260920/test-przed.txt`,
  `evidence/ci611-20260920/test-po.txt`, oraz
  `evidence/ci611-20260920/WERYFIKACJA.md`.
- Rollback opisany wprost: „przywrócić trzy opcje pełnego checkoutu wraz ze
  zmianą testu regresyjnego (revert
  `abef3c94d9313de35f146d2c7110c84612400f51`)”.
- Dokument stwierdza też: „Nie ma zmiany schematu, danych, sekretów, cache
  ani infrastruktury” oraz „Nie uruchamiano wdrożenia, preview, zastosowania
  IaC ani publikacji komentarzy”.

Dokument nie opisuje żadnego innego usunięcia poza tymi trzema opcjami
`fetch-depth: 0`. Wszystkie pozostałe pozycje w sekcji „Zabezpieczenia z
historią awarii — zostają” są wyraźnie oznaczone jako pozostawione (m.in.
dynamiczne porty PostgreSQL/D-121, prywatne narzędzia PHP/#262, blokady
przeglądarki/#577, pominięcie ponownej migracji w Lighthouse, kontrakty
smoke, odbiór marki po wdrożeniu/#488, flaga uruchomienia IaC/`76cd5802`).

**ZASTRZEŻENIE:** w treści obu dokumentów nie znaleziono dowodu usunięcia
czegokolwiek innego niż opisane trzy opcje `fetch-depth: 0` bez dowodu — nie
ma więc podstawy do zapisania dodatkowego zastrzeżenia o usunięciu bez
dowodu. Jeśli w innych, nieudostępnionych materiałach istnieje taki
przypadek, ten raport go nie obejmuje (brak dostępu do pliku równałby się
zgadywaniu, którego zasady zapisu zabraniają).

### Luka: zależność jobów od narzędzi runnera (kontekst z 20.09.2026)

Dodatkowy fakt przekazany do zestawienia z raportem: po przestawieniu
zmiennej repozytorium `CI_RUNS_ON` na `ubuntu-latest` dwa joby zaczęły padać,
ponieważ klient PostgreSQL na tym runnerze jest starszy niż 18, a
`pg_restore` odmawia odczytu zdrowego archiwum (kod 51 zamiast 0). Na
`self-hosted` te same joby były zielone.

`MAPA_CI_611.md` **wspomina** zmienną `CI_RUNS_ON` — w sekcji „Ustawienia
repozytorium i polityka gałęzi” podaje: „Zmienna repozytorium `CI_RUNS_ON`:
`"ubuntu-latest"`, updated_at `2026-09-20T16:27:59Z`” oraz „Wszystkie 20
jobów w kodzie: `fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"')`”. Dokument
zastrzega też: „Odczyt zmiennej nie jest potwierdzeniem, na jakiej maszynie
wykonał się każdy wcześniejszy przebieg”.

Dokument **nie** opisuje zależności jobów `docker-build` (który sprawdza
m.in. „pg_dump 18”) ani innych jobów od konkretnej wersji klienta
PostgreSQL dostępnej na wybranym runnerze, ani nie wspomina awarii
`pg_restore` z kodem 51 na `ubuntu-latest`. Zgodnie z poleceniem: **mapa CI
nie obejmuje zależności jobów od narzędzi runnera — to realna luka**,
potwierdzona faktem z 20.09.2026 ustalonym niezależnie od tego dokumentu.

## #614 — Plan techniczny

**Zakres:** `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, odczyt GitHub
20.09.2026. Dokument opisuje siebie jako „datowane uzgodnienie” zaleceń
audytu z 16.09.2026 z repozytorium i stanami zgłoszeń, „nie nowym audytem
ani konkurencyjną listą zadań”.

### Rozjazdy między audytem z 16.09.2026 a wykonaniem

Dokument ma dedykowaną sekcję „Rozjazdy dokumentacyjne, które trzeba
widzieć” z siedmioma ponumerowanymi pozycjami:

1. Niezaznaczone pozycje historycznego #614 nie opisują dzisiejszego braku
   kodu: **#596/#606/#585/#609/#607/#608 są zamknięte**. Zalecenie: przy
   aktualizacji zgłoszenia wpisać źródło zakończenia i oddzielić brakujące
   odbiory, szczególnie całego uploadu.
2. `POMIAR_FEEDU_585.md` zachował etap „niewysłane / brak CI / otwarte
   zgłoszenia”; późniejszy opis #609 i merge #628 dokumentują zakończenie.
   Miejsce: `POMIAR_FEEDU_585.md` vs. opis #609 / merge `e951554c`
   (wskazany w tabeli wyżej dla wiersza Feed #585 i #609), CI `35117632571`.
3. `ODBIOR_JEDNEGO_DEKODOWANIA_601.md` opisuje filtrowanie pomiarów kolejki
   przez poziom warning. Późniejszy kanał `pomiary` (historia `0c96de71`)
   rozwiązuje część kodową tego problemu — nadal brakuje pomiaru
   produkcyjnego. Zastrzeżenie w dokumencie: „nie wolno z tego zrobić
   zdania »monitoring jest odebrany«”.
4. `UPROSZCZENIE_CI_611.md` §7 opisuje brak narzędzi i zdalnego uruchomienia,
   choć §5.7 dokumentuje późniejszy wynik. Dodano oznaczenie historycznego
   zakresu §7. Miejsce: `UPROSZCZENIE_CI_611.md`, §7 vs §5.7.
5. Opisy starej puli runnerów i „dziewięciu jobów” nie są bieżącą
   konfiguracją. Własny odczyt: **13 jobów CI, 20 we wszystkich
   workflowach**, zmienna repo `CI_RUNS_ON = "ubuntu-latest"`. Zastrzeżenie:
   „Nie przepisano starej decyzji właściciela tak, jakby od początku
   wybierała dzisiejszą wartość”.
6. Przygotowany podział usług, harmonogram alarmów, narzędzie backupu i
   test obciążeniowy nazwane są „czterema przygotowaniami”, nie czterema
   odebranymi operacjami. Zastrzeżenie: „Ich pomylenie zmieniłoby kolejność
   planu bez jawnej decyzji”.
7. #597 nazywa wydłużenie publicznego podpisu zmianą bez wpływu na model
   zagrożeń; bieżący komentarz w `MediaController` wprost opisuje TTL jako
   górną granicę opóźnienia zmiany prywatności, blokady lub moderacji.
   Miejsce w kodzie: komentarz w `MediaController`. Dokument nazywa to
   „pytaniem o akceptowane okno, nie usterką do zamknięcia asercją”.

Dokument wprost łączy ten rodzaj problemu z wcześniej znalezionym w tym
repozytorium rozjazdem: „To ten sam rodzaj problemu, który ujawnił
[PR #787], scalony jako `def9534a`: historia decyzji wymaga adnotacji, nie
cichego nadpisania. [pomiar cudzy: opis PR #787 — 16 cicho odwróconych
decyzji]. Nie powtórzono tu całego audytu 202 wpisów; porównanie dotyczy
zaleceń #614 i ich zależności.”

### Dodatkowe rozjazdy z tabeli „Zalecenia audytu a to, co już wykonano”

Poza wyżej wymienioną, dedykowaną listą, tabela zaleceń zawiera dalsze
przypadki rozbieżności między konfiguracją/dokumentacją a stanem faktycznym:

- **Podział procesów #595** (OPEN): `.railway/railway.ts` ma
  `PRODUCTION_SPLIT_SERVICES = true`, ale dokumenty odbioru mówią o jednej
  usłudze `all`. Dokument: „Flaga konfiguracji nie dowodzi zastosowania.”
- **DR bazy #594, kopia offsite #193, media #617, R2 #619** (OPEN): kod
  backupu i lokalne roundtripy istnieją, ale [pomiar cudzy:
  `PRZEKAZANIE_PRAC_OPERACYJNYCH_2026_09_18.md`] nie odebrano produkcyjnej
  kopii/alertu ani produkcyjnego RTO; `LOKALIZACJA_DANYCH_R2.md` nie
  potwierdza jurysdykcji z panelu.
- **Dysk Livewire #608** (CLOSED): tytuł sugerujący niedokończony odbiór
  jest starszy niż aktualizacja opisu; wynik konsoli [pomiar cudzy: #608,
  komentarz `5733174838`, 18.09] potwierdza jawne i efektywne `r2`, ale „nie
  zastępuje odbioru całego uploadu i workera”.

### Czy audyt wdrożono kodem w ramach tego uzgodnienia

Dokument stwierdza wprost: „Nie wdrażamy zaleceń audytu kodem w ramach tego
uzgodnienia. Nie zamieniamy otwartego checkboxa w dowód braku implementacji
ani zamknięcia issue w dowód działania produkcji.” Na końcu: „Nie zmieniono
issue ani produkcji, nie publikowano komentarzy.”

## Podsumowanie zgodności z zadaniem sesji

- Mapa CI dla #611 **powstała** i **ma** kolumnę skutku usunięcia
  („Co stracimy bez niego”).
- Kroki uznane za niechroniące: **jeden zestaw** (trzy opcje
  `fetch-depth: 0` w `port_marki`, `port_funkcje`, `dostepnosc`) — z dowodem
  (historia commitów, brak konsumenta w skryptach, kontrakt
  `actions/checkout`, testy przed/po z konkretnymi liczbami).
- To, co usunięto, zostało **faktycznie usunięte** (nie tylko opisane) —
  potwierdzone testem regresyjnym przed/po i rollbackiem do konkretnego SHA.
- Brak dowodu na usunięcie czegokolwiek innego bez dowodu w tych dwóch
  plikach — stąd brak dodatkowego ZASTRZEŻENIA ponad to, co odnotowano
  wyżej.
- Luka rzeczywiście istnieje: mapa CI **nie** obejmuje zależności jobów od
  wersji narzędzi runnera (np. klienta PostgreSQL/`pg_restore` na
  `ubuntu-latest`), co potwierdza niezależnie ustalony fakt z 20.09.2026.
- Dla #614 znaleziono **siedem** nazwanych rozjazdów dokumentacyjnych
  (ponumerowanych 1–7 w źródle) plus dodatkowe rozbieżności w tabeli
  zaleceń (m.in. #595, #594/#193/#617/#619, #608) — każdy z lokalizacją w
  dokumentacji lub kodzie, jak wypisano wyżej.
