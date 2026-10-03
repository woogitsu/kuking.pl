# Odbiór paczki W — Codex, 3.10.2026

## Zakres i historia

Kopia `codex/paczka-w-20261003`; trzy istniejące poprawki na odebranej V:

- #2881: retencja pomija wersje przepisu w świeżym koszu również przy końcowym
  DELETE; blokada rodziców zabezpiecza wyścig usunięcia. Bez zmiany okresów
  retencji, zachowania trzech ostatnich wersji i zabezpieczeń dowodu.
- #2880: przypomnienie urodzin ponownie sprawdza świeżą datę i decyzję pod
  blokadą, przed wysłaniem. Przegląd i rzeczywisty PostgreSQL ujawniły jeszcze
  deadlock SHARE odbiorcy kontra blokada pary: FK powiadomienia potrzebuje
  blokady autora. Poprawka `c0b5fa332` blokuje SHARE oba konta rosnąco według
  UUID i używa świeżego odbiorcy. Bez nowej zgody, limitu ani kosztu.
- #2787: odebrany link pomocnika odmawia także w GET po blokadzie którejkolwiek
  strony. Neutralne 410 nie pokazuje nazwiska, powodu ani oferty dołączenia.
  Odblokowanie i ważny link nadal działają; POST sprawdza świeży stan.

Źródłowe commity: `0b61acc444340000c1bd3858c0e735c56c7f9614`,
`1d9750484ee7af59c58f5c0aaa7fceaf519fd988`,
`fe293b30940a7970b2451dab822bfb1976d3c03d`.
Wspólny poprawiony kod `acbb118178184c548e0b73813a433ee59b5a1a39` zawiera
ponadto poprawkę deadlocku `c0b5fa332` i ścisły JUnit #2880 `00a20ca0c`.

## Rzeczywisty wspólny odbiór root

Izolowany Linux, PHP 8.4, PostgreSQL 18.6, host `127.0.0.1`, port `55488`,
rola `kuking_pg18_owner`, własne bazy `kuking_test_w20261003` i
`kuking_race_repo_w`. Rzeczywiste zależności z locków, bez dowiązania vendor.

- 136/7150 całych wybranych klas Feature/Unit PASS.
- 17/209 całych wybranych klas dwóch połączeń PASS.
- Dwie zarejestrowane kontrole #2881 i #2787: właściwe porażki
  `POTWIERDZONA`, po odtworzeniu całe klasy PASS.
- Trzy fizyczne kontrole #2880: wyłączone przypomnienie, podmieniona data,
  brak blokady odbiorcy. Ostatnia rzeczywiście wywołuje 40P01 i właściwy
  `URODZINY_2880_BLOKADA_PARY_NIE_ZAKLESZCZA`; po restore PASS.
- Osobna fizyczna kontrola #2881 podmienia końcowy DELETE na usunięcie po ID
  bez świeżego warunku. Test dwóch połączeń oblewa na
  `KOSZ_2881_PRZELOT_PO_WYBORZE`, restore PASS.
- Przyrząd #2880: 10 przypadków PASS, osobny fizyczny mutant pomijający
  sprawdzenie wykonania oblewa na `PRZYRZAD_2880_POMINIETY_NIE_JEST_PASS`;
  po dokładnym restore 10 PASS. Skip/error/brak/obcy przypadek nie są zielenią.
- Build assetów PASS; pełny PHPStan bez błędów. Źródła wszystkich fizycznych
  kontroli zachowane co do bajtów i mtime.

Logi i JUnit leżą poza repo we własnym katalogu koordynatora na normalhp:
`transfer/W-final-feature.*`, `W-final-dwa.*`, `W-central-controls.log`,
`W-2880-mutants.log`, `W-2881-mutant.log`, `W-build.log`, `W-phpstan.log`.
To pomiary izolowanej aplikacji; nie zmieniano danych produkcyjnych.

## Złożenie z naprawami CI N–U

`4cb4e9554f578c61c6e529c2b8c7d6b1a310c4bd` włącza zwykłym merge odebrane
naprawy narzędzi z `05a0f8695`. Konflikty `ci.yml`, `check.sh` i `zakres.sh`
rozwiązano sumą: #2811/#2815/#2880 chodzą każda raz, trzy kompletne bloki
w haku, scope zachowuje #2847/#2880 i oba importy przyrządu #2811.
Niezależny przegląd ACCEPT. Diff względem `acbb118` dla `app`, widoków
produktu oraz `tests/Dwa` jest pusty.

Po merge własny odbiór: 23/6435 strażników Feature PASS; Python 12+7+10+37
PASS; prawdziwa tabela Bash 45 przypadków oraz wszystkie kontrole fizyczne
wyjść PASS, składnia powłoki PASS. Źródłowy odbiór domeny opisany wyżej
pozostaje pomiarem tego samego kodu aplikacji.

## Ryzyko, rollback i pozostałe bramki

Nie ma migracji ani zmiany retencji. Wycofanie kodu przywróciłoby opisane
błędy; preferować nową poprawkę, zachowując zabezpieczenie dowodu i kosza.
Ta paczka nie zawiera #2882 ani #2884 — mają osobne późniejsze kopie.

**Nie jest jeszcze wydana.** Wymagane: normalny pełny push, pełne CI
niedraftowego PR-a na świeżej C po N–U, przegląd i merge przez expectedHeadSha,
końcowy PR wydania z CodeQL, terminalny pełny push main, SUCCESS wszystkich
trzech usług Railway oraz produkcyjne `/wydanie` dokładnego SHA i `/health`
200. Dopiero potem ocena zamknięcia issues według całych ich kryteriów.
Zachować otwarte piloty 50+, prawo i kroki panelowe właściciela.

## Świeże zależności po odbiorze lokalnym

3.10.2026 włączono zwykłym merge V `4d3f5d3ba01b32482ef54da2f8390af174e7f414`
oraz C `30be87d7d6c140275cce12d05f8a253b29452951` po terminalnym źródłowym CI N–U.
Konflikt filtra wyścigów rozstrzygnięty sumą: wariant W zachowuje wszystkie
wpisy V i dodaje #2880. Aplikacja, widoki oraz testy Dwa są bitowo bez zmian
względem odebranego W `19b565455c73df8fe32565d725680081992957d1`.
Nowy zwykły pełny push i przyszłe pełne CI świeżej bazy są nadal wymagane.
