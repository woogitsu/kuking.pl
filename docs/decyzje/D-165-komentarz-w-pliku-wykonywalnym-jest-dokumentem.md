## D-165 · Komentarz w pliku wykonywalnym jest dokumentem i podlega tej samej regule co dokument

**Data:** 12 września 2026 · PR #416 · issue #342 · Status: **obowiązuje** ·
rozwinięcie D-157, ciąg dalszy D-121

### Co stało w pliku obowiązującym

Nagłówek `.github/workflows/ci.yml` (linie 13-14) mówił:

> „GDZIE TO CHODZI: na własnej puli (…), wskazanej ZESTAWEM ETYKIET,
> nie nazwą runnera i **nie zmienną repozytorium**"

i cytował `runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`.
Wszystkie dziewięć jobów **tego samego pliku** miało
`runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}`. Ten sam nagłówek
zapisywał jako koszt, że joby „NIE mają już zapasu w runnerach GitHuba" — a
`|| '"ubuntu-latest"'` jest tym zapasem i jest wartością **domyślną**.
`docs/infra/SELF_HOSTED_RUNNER.md` powtarzał obie nieprawdy, **160 linii nad
własnym pomiarem**, który mówił coś przeciwnego (D-121).

### Dlaczego to nie był „nieaktualny akapit"

**Ta nieprawda miała kierunek.** Kto czytał „nie zmienną repozytorium", ten nie
sprawdzał wartości `CI_RUNS_ON` — a to właśnie ta wartość, ustawiona na samo
`self-hosted`, wysyłała przebiegi na starą pulę WSL, czyli tam, gdzie komplet
sześciu etykiet miał ich **nie wpuścić**, i na tę samą maszynę, która dała wyścig
o binarkę Composera z #262. Dokument nie tylko mylił — kierował uwagę z dala od
jedynego miejsca, w którym leżała przyczyna.

Drugi ładunek niósł „Krok 2": kazał odkomentować blok `on:` (aktywny od dawna)
i usunąć `workflow_dispatch` (zostawiony celowo). Instrukcja, która każe zrobić
rzecz zrobioną, uczy pomijania instrukcji — a przy okazji kazałaby zabrać jedyny
ręczny wyzwalacz bramki deployu.

### Decyzja

1. **Komentarz w `ci.yml` jest dokumentem.** Obowiązuje go D-157 w całości: przy
   rozjeździe z kodem poprawiamy komentarz, nie kod. Zmiana mechanizmu wyboru
   runnera jest osobną decyzją (D-121), nie skutkiem ubocznym porządkowania opisu.
2. **Komentarz cytujący linię kodu ma test porównujący jedno z drugim.** Cytat bez
   testu starzeje się cicho; cytat z testem starzeje się na czerwono.
3. **Uzasadnienie niewybranego wariantu zostaje jawnie.** Powód, dla którego pulę
   wskazuje się kompletem sześciu etykiet, a nie nazwą runnera, jest najcenniejszą
   treścią tego nagłówka i nie znika razem z nieprawdą o mechanizmie. Zakaz idzie
   na to, co komentarz podaje jako **obowiązujący** `runs-on:`, nie na wystąpienie
   słowa „etykiety".
4. **Plik wykonywalny CI zmieniamy wyłącznie w liniach `#`**, a po zmianie
   sprawdzamy, że YAML dalej się parsuje. `ci.yml` jest bramką deployu Railway
   („Wait for CI"): zepsuty parser zatrzymuje wdrożenie.

### Strażnik pilnuje OBU stron, bo jedna nie wystarcza

`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php` odczytuje mechanizm
**z jobów** i od niego uzależnia zakazy: gdy `runs-on:` czyta `vars.*`, zakazane
są zdania odmawiające zmiennej tej roli; **gdyby ktoś wpisał etykiety na sztywno,
zakazane stają się zdania oddające zmiennej wybór** — dokument ma wtedy przestać
o niej mówić. Sprawdzenie samego dokumentu złapałoby połowę; cofnięcie **kodu**
zostawiłoby dokument prawdziwym w literze, a czytelnika w złym miejscu.

Zdania porównywane po normalizacji (sklejenie linii, `**`, backticki, wielkość
liter): feralne zdanie było złamane **między liniami 13 a 14** i każdy wzorzec
jednoliniowy by je przepuścił. `ci.yml` jest czytany dwa razy i rozdzielnie —
linie `#` jako twierdzenia, `runs-on:` spoza komentarzy jako kod.

### Kontrola ujemna, i co w niej wyszło

„nie zmienną repozytorium" w `ci.yml` → 1 z 5 czerwone · stary cytat etykiet →
1 z 5 · joby przepisane na sztywne etykiety → 3 z 5 · „nie wybiera już żadna
zmienna" w dokumencie → 2 z 5 · wskrzeszony „Krok 2" → 1 z 5 · zepsuty wykrywacz
`runs-on:` → 4 z 5 (kontrola pustego skanu).

**Jeden sabotaż nie nałożył się za pierwszym razem i test wtedy przechodził na
zielono** — wzorzec podmiany łapał także linię komentarza, więc licznik się nie
zgodził i podmiana nie wykonała się wcale. Złapane wyłącznie dlatego, że md5 było
**porównane, a nie założone**. To ta sama, trzecia z czterech przyczyn nieoblanej
kontroli ujemnej co w D-157.

### Czego ten strażnik świadomie nie pilnuje

Wartości zmiennej `CI_RUNS_ON` — żyje w ustawieniach repozytorium i z kodu jej nie
widać; jej wybór należy do właściciela (D-121). Oraz nagłówków `deploy.yml`,
`preview.yml` i `railway-iac.yml`: niosą **dokładnie tę samą nieprawdę** przy
identycznym `runs-on:`, ale ich poprawka jest poza zakresem #342. Kopia jest
miejscem, w którym taka nieprawda odrasta (D-104), więc to jest dług, nie
zamknięta sprawa — dopisanie trzech ścieżek do listy `DOKUMENTY` to jedna linia.

📄 `.github/workflows/ci.yml` (linie wykonywalne nietknięte) ·
`docs/infra/SELF_HOSTED_RUNNER.md` ·
`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php` ·
D-104 · D-121 · D-132 · D-157 · issue #342
