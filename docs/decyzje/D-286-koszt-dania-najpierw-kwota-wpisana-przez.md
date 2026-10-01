## D-286 — Koszt dania: najpierw kwota wpisana przez autora, jawnie jako jego szacunek (V2, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela
(dopuszczenie V2 z `docs/FEATURES.md` od 26.09 — D-282; wybór „oba":
koszt wg autora oraz przedział liczony z cen GUS, gdy autor nic nie wpisze)

**Problem.** „Koszt" jest na liście V2. Serwis nie zna cen w sklepie
czytelnika, a przepisy domowe nie mają gramów, więc każda liczba „od
serwisu" byłaby zgadywaniem przedstawionym jako fakt.

**Decyzja (część 1 — autor).**

1. Autor **może** (nie musi) wpisać przybliżony koszt CAŁEGO przepisu
   w złotych — w kreatorze i w formularzu szczegółów. Ekran dodawania
   („sześć rzeczy", #364) tego pola nie dostaje.
2. Walidacja: liczba ≥ 0, najwyżej 9999,99, najwyżej dwa miejsca po
   przecinku; „24,50", „24 zł" i „1 200" są poprawne. Komunikaty po polsku
   mówią, co zrobić. Źródło reguł i tekstów: `App\Domain\Recipes\KosztPrzepisu`.
3. Strona przepisu mówi pełnym zdaniem: **„Szacunkowy koszt: ok. 24 zł
   (wg autora)"**. Zawsze „ok." i zawsze „wg autora" — to deklaracja jednej
   osoby, nie cennik.
4. Brak kwoty to brak zdania. `0 zł` jest odpowiedzią i się wyświetla.
5. Koszt **nie** wchodzi do `CoMoznaDopisac` — zaproszenie „Dopisz
   szczegóły" nie ma namawiać do liczenia pieniędzy przy rodzinnym rosole.
6. Wyszukiwarka dostaje zakres **„Do 20 zł"** (`sekcja=tanie`) — sam filtr,
   bez żadnego wpływu na kolejność wyników (AGENTS.md §8: żadnego rankingu).
   Przepis bez kosztu z tego zakresu wypada: brak kwoty nie znaczy „tanio".
7. Skalowanie porcji (jeszcze nie na `main`): koszt przelicza się
   proporcjonalnie (`KosztPrzepisu::naPorcje`), zaokrąglony do pełnych
   złotych, z dopiskiem „przeliczone z kosztu podanego przez autora"
   (`zdaniePrzeliczone`). Bez liczby porcji autora nie przeliczamy.
   Strona przepisu niesie kwotę w `data-koszt-autora` dla tego przełącznika.

**Rollback kolumny odmawia**, gdy ktoś już wpisał koszt (D-088; opis
w `docs/DATABASE.md`, sekcja `estimated_cost_pln`).

**Czego świadomie nie ma:** cen sklepów, linków afiliacyjnych, porównań,
AI, sortowania po cenie.

### Wycofanie
Ukrycie funkcji: usunąć pole z dwóch formularzy, zdanie ze strony i zakres
z wyszukiwarki — kolumna może zostać. Zdjęcie kolumny: patrz rollback
migracji (najpierw kopia wartości).

### Część 2 — przedział z cen GUS, gdy autor nic nie wpisał (26 września 2026)

**Decyzja właściciela z 26.09 („oba").** Gdy autor nie podał kwoty, strona
przepisu może pokazać **przedział** liczony deterministycznie w PHP z cen
GUS — podpisany jako szacunek i tylko przy dostatecznym pokryciu
składników. Projekt: `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §8.4
(gałąź `claude/v2-import-ocr-plan`, PR #1854).

1. **Kwota autora zawsze wygrywa.** Przedział liczy się tylko przy
   `recipes.estimated_cost_pln IS NULL`.
2. **Dane:** tabela `ceny_skladnikow` wczytywana komendą
   `kuking:ceny-skladnikow` z pliku `database/data/ceny_skladnikow.csv`.
   Produkcja niczego nie pobiera z sieci; plik odświeża osoba prowadząca
   (`scripts/ceny-gus-pobierz.py`, API BDL GUS, temat P1466) i zmiana cen
   przechodzi przegląd w PR-ze. Każdy wiersz ma `zrodlo`; jedyny wiersz
   spoza GUS to woda z kranu (0 zł, poza pokryciem), opisany jako
   założenie Kuking.
3. **Rytm:** komenda raz na kwartał. Stan faktyczny źródła: BDL podaje dla
   tego tematu **średnie roczne** (dziś 2025), a **nie podaje cen warzyw**
   (ziemniaki, cebula, marchew są tylko w serii miesięcznej zakończonej
   w 2019). Przepisy z dużą masą warzyw uczciwie nie dostaną przedziału,
   dopóki właściciel nie zdecyduje o innym źródle albo ręcznym uzupełnieniu.
4. **Liczenie** (`SzacunekKosztuZCen`): ilość z tekstu składnika
   (`IloscZTekstu`) albo z rozbitych pól, dopasowanie całymi słowami
   (`CennikSkladnikow`), miary domowe per składnik. Przedział ±15%,
   zaokrąglony do pełnych złotych. Warunki: każdy składnik z ilością da się
   przeliczyć na gramy, a składniki z ceną to ≥ 90% masy. `no_amount`,
   drobiazgi bez ilości (sól, pieprz, zioła, „do smaku") i woda nie liczą
   się do masy.
5. **Tekst:** „Orientacyjny koszt: ok. 5–7 zł za całość (średnie ceny
   detaliczne GUS z 2025 r.). W Twoim sklepie może być inaczej." Gdy
   warunki nie są spełnione, ale choć jeden składnik ma cenę — jedno zdanie,
   dlaczego nie liczymy. Gdy żaden składnik nie trafił w cennik — cisza.
6. **Poza zakresem:** zakres „Do 20 zł" w wyszukiwarce patrzy wyłącznie na
   kwotę autora (przedział nie jest zapisywany w bazie); skalowanie porcji
   przedziału nie przelicza.

**Zbieżność z wartościami odżywczymi.** Projekt §8.1 przewiduje osobne
`miary_domowe` przy tabeli składników odżywczych. Tu miary siedzą w cenniku
(kolumny `g_*`), bo cennik jest samodzielny i mały; gdy powstanie wspólna
tabela miar, cennik ma z niej korzystać, a nie trzymać drugiej kopii.

### Wycofanie części 2
Usunąć przekazanie `szacunekKosztu` w `RecipeController::show` — strona
wraca do samej kwoty autora. Tabela może zostać albo zniknąć rollbackiem
(`DROP TABLE`, bez strat: odtwarza ją komenda z pliku).

### Część 3 — ceny warzyw z MRiRW/ZSRIR, bo GUS ich nie ma (26 września 2026)

**Problem właściciela.** Cennik z części 2 nie ma ANI JEDNEGO warzywa —
GUS (BDL, temat P1466) podaje dziś ceny mięsa, nabiału, pieczywa i suchych
produktów, ale seria z cenami ziemniaków, cebuli i marchwi jest MIESIĘCZNA
i skończyła się w 2019 roku (sprawdzone bezpośrednio w BDL 26.09.2026 —
metryka 1466 nie ma nowszych wartości dla tych trzech towarów). Właściciel:
„Znajdź może jakieś źródło, skąd można wziąć aktualne detaliczne ceny
warzyw. Jakiś sklep, jakaś hurtownia albo coś”.

**Sprawdzone źródła:**

| Źródło | Typ ceny | Aktualność | Format / API | Licencja | Ocena |
|---|---|---|---|---|---|
| GUS BDL, P1466 | detaliczna, średnia roczna | ziemniaki/cebula/marchew: **do 2019**; reszta towarów: 2025 | REST API (bez klucza) | dane publiczne GUS | źle pokrywa warzywa — stąd ten problem |
| **MRiRW, ZSRIR** (dane.gov.pl, zbiór 912), arkusz „ZAKUP WARZ DETAL — do 2 kg” | **cena zakupu warzyw przez podmioty handlu detalicznego**, opakowania do 2 kg — najbliższy oficjalny odpowiednik ceny detalicznej | **cotygodniowa**, publikowana też tego samego dnia co research (25.09.2026) | xlsx (biuletyn), zbiór ma REST API (`api.dane.gov.pl`) do listowania i pobierania zasobów | **CC BY 4.0 / domena publiczna** — jawnie wolno pobierać automatycznie i publikować przeliczenia | **wybrane** dla 5 warzyw: ziemniaki, cebula (biała), marchew, papryka czerwona, pomidory (okrągłe) |
| MRiRW, ZSRIR, arkusz „HURT WARZ” (Bronisze, Kalisz, Łódź, Poznań, Rzeszów) | **hurtowa**, min–max z 5 rynków | cotygodniowa | j.w. | j.w. | szersze pokrycie warzyw (kapusta, buraki, por, seler, pietruszka, sałata, ogórek…), ale **odrzucone jako automatyczne źródło** — patrz niżej |
| Sklepy internetowe (Frisco, Auchan, Carrefour) i gazetki (Biedronka, Lidl) | detaliczna, realny sklep | bieżąca | brak API do tego celu; regulaminy zwykle zakazują automatycznego pobierania i republikacji cen | zastrzeżona, per-sklep | **odrzucone** — to byłby scraping wbrew regulaminowi, którego D-286 świadomie unika |
| Eurostat (ceny konsumpcyjne) | wskaźniki zagregowane (HICP), nie ceny jednostkowe konkretnych warzyw w PLN | miesięczna | API/CSV, licencja otwarta | otwarta | **odrzucone** — nie da się z tego odtworzyć ceny za kg konkretnego warzywa |
| dane.gov.pl (poza zbiorem 912) | — | — | — | — | żadnego innego zbioru z cenami detalicznymi warzyw nie znaleziono |

**Dlaczego ZSRIR „do 2 kg”, a nie „HURT WARZ” z przelicznikiem.** Rozważono
policzenie brakujących warzyw (kapusta, buraki, por, seler, pietruszka,
sałata, ogórek) z arkusza hurtowego przez jeden, jawny mnożnik hurt→detal.
Sprawdzono to empirycznie na warzywach, które są w OBU arkuszach tego
samego biuletynu (cebula, marchew, ziemniaki, papryka czerwona): stosunek
ceny detalicznej („do 2 kg”) do średniej ceny hurtowej z 5 rynków wyniósł
odpowiednio ok. **1,0×** (cebula), **1,5×** (ziemniaki), **1,6×** (marchew)
i **2,2×** (papryka) — rozrzut zbyt duży, żeby jeden mnożnik dla wszystkich
warzyw był czymkolwiek innym niż zgadywaniem przedstawionym jako fakt.
Zamiast zmyślać liczbę, zostawiamy te warzywa BEZ ceny — dokładnie jak dziś
przy brakujących cenach GUS (`SzacunekKosztuZCen` już to obsługuje: milczy,
gdy żaden składnik nie trafił w cennik, i tłumaczy się, gdy trafił tylko
częściowo). Kalibrowany, PER-WARZYWO przelicznik jest możliwy w przyszłości,
ale to osobna decyzja właściciela, nie coś do zgadnięcia przy okazji.

**Wdrożenie.**

1. `database/data/ceny_skladnikow.csv` ma teraz 5 nowych wierszy: `ziemniaki`,
   `cebula`, `marchew`, `papryka_czerwona`, `pomidor`. `zrodlo` każdego
   zaczyna się od `MRiRW` (nie `GUS`) — to jedyne miejsce, po którym kod
   i testy rozpoznają źródło ceny (`SzacunekKosztuZCen::nazwaZrodla`).
2. Nowy skrypt `scripts/ceny-warzyw-zsrir-pobierz.py` (analogiczny do
   `ceny-gus-pobierz.py`) pobiera najnowszy biuletyn ZSRIR z dane.gov.pl,
   czyta arkusz „ZAKUP WARZ DETAL - DO 2 KG” i aktualizuje TYLKO te pięć
   wierszy. Uruchamia się na komputerze osoby prowadzącej, tak samo raz na
   kwartał — produkcja nadal niczego nie pobiera z sieci.
3. Zdanie na stronie przepisu wymienia oba źródła, gdy oba wystąpiły
   w jednym przepisie: „Orientacyjny koszt: ok. 8–12 zł za całość (średnie
   ceny detaliczne GUS i MRiRW/ZSRIR z 2025, 2026 r.). W Twoim sklepie może
   być inaczej.” — zamiast dawnego sztywnego „GUS z {rok} r.”.
4. Bez migracji: kolumny `ceny_skladnikow` już dopuszczały `zmienna_bdl`
   jako `NULL` (wiersze spoza GUS go po prostu nie mają).

**Stan do 26.09.2026 (druga tura decyzji, niżej):** kapusta, buraki
ćwikłowe, por, seler, pietruszka korzeniowa, sałata, ogórek nie miały ceny
z powodów opisanych wyżej. Właściciel, znając ten sam rozrzut 1,0×–2,2×,
zdecydował inaczej niż proponowane „zostawić bez ceny” — patrz niżej.

### Uzupełnienie z 26.09.2026 — decyzja właściciela: jednak hurt × 1,6,
### jawnie nazwane, i odświeżanie co tydzień

Właściciel, po przeczytaniu tabeli źródeł i zmierzonego rozrzutu (1,0×–2,2×)
zdecydował **inaczej niż zaproponowane wyżej „zostawić bez ceny”**:

> „DODAJ je jako cena hurtowa ZSRIR (środek min–max z rynków) × 1,6,
> z jawnym opisem. W źródle i w zdaniu pod kosztem musi być wprost
> napisane, że to szacunek z cen hurtowych. Mnożnik ma być jedną stałą
> z komentarzem i uzasadnieniem. Odświeżanie warzyw co tydzień.”

**1. Siedem warzyw dostaje cenę z arkusza „HURT WARZ”.** `kapusta`,
`buraki`, `por`, `seler`, `pietruszka`, `salata`, `ogorek` — cena to
średnia z min–max pięciu rynków (Bronisze, Kalisz, Łódź, Poznań,
Rzeszów) w biuletynie z 14-22.09.2026, pomnożona przez
`App\Domain\Recipes\Koszt\SzacunekKosztuZCen::MNOZNIK_HURT_DETAL = 1,6`
— **jedna nazwana stała z komentarzem w kodzie**, nie liczba wpisana po
cichu do CSV. 1,6 to środek zmierzonego zakresu 1,0×–2,2×, jawnie
przybliżony, nie zmierzony osobno dla każdego warzywa. `por` i `salata`
mają cenę **za sztukę** (arkusz notuje je w `szt.`, nie `kg`), pozostałe
pięć — za kilogram.

**2. Jawność w DWÓCH miejscach, nie jednym.** Pole `zrodlo` każdego z tych
siedmiu wierszy zaczyna się od frazy „szacunek z cen hurtowych” i podaje
sam mnożnik. `SzacunekKosztuZCen` wykrywa tę frazę i dokłada do zdania na
stronie przepisu zdanie wprost: „(…; część cen to szacunek z cen
hurtowych MRiRW/ZSRIR)” — czytelnik nie ma się domyślać z samego numeru
ceny, że to nie jest zwykła cena detaliczna.

**3. Odświeżanie: co tydzień, przez GitHub Actions, nie przez człowieka.**
Nowy workflow `.github/workflows/ceny-warzyw-auto.yml` (harmonogram
cotygodniowy + `workflow_dispatch`) uruchamia
`scripts/ceny-warzyw-zsrir-pobierz.py --zapisz` — TEN SAM skrypt, który
wcześniej uruchamiała tylko osoba prowadząca ręcznie. **Produkcja nadal
niczego nie pobiera z sieci** — automatyzacja dotyczy wyłącznie CI. Gdy
plik się zmieni, workflow pushuje gałąź `claude/ceny-warzyw-auto`
(NIGDY `main` wprost) i otwiera PR do `main`. Merge PR-a jest ręczny:
ktoś z zespołu przegląda różnicę cen, dokładnie jak dotąd.

**Stan do 26.09.2026 (tego samego dnia, później tego dnia):** workflow
pushował i otwierał PR domyślnym tokenem `GITHUB_TOKEN` tego przebiegu,
a siedem warzyw hurtowych nie było w nim automatyzowane w ogóle (osobna
ręczna aktualizacja arkusza „HURT WARZ”). Właściciel zmienił OBIE rzeczy
tego samego dnia — patrz „Uzupełnienie” niżej.

### Uzupełnienie z 26.09.2026 (dalszy ciąg) — osobisty token i wszystkie
### 12 warzyw w jednym PR-ze

Dwie kolejne decyzje właściciela z 26.09.2026, po uruchomieniu workflow
z punktu 3:

**A. `GITHUB_TOKEN` nie wystarcza — GitHub świadomie nie odpala CI na
PR-ze, który sam otworzył.** GitHub Actions ma wbudowane zabezpieczenie
przed pętlą automatów: `pull_request` NIE URUCHAMIA workflowów, gdy PR
został otwarty (albo zaktualizowany) domyślnym `GITHUB_TOKEN` tego samego
repozytorium. Efekt uboczny u nas: PR z cenami warzyw stał bez ani
jednego przebiegu `ci.yml` — recenzent nie miał czym sprawdzić, czy
zmiana w ogóle przechodzi testy, zanim scali.

Rozwiązanie: workflow dostaje osobisty, fine-grained PAT zapisany jako
sekret repozytorium — `CENY_WARZYW_PAT`. PR otwarty tym tokenem wygląda
dla GitHuba jak otwarty przez człowieka, więc `pull_request` rusza
normalnie. **Checkout i krok push/PR idą TYM SAMYM tokenem** — nie samym
pushem, bo checkout bez tokenu i tak by nie miał czym push zautoryzować.

*Uprawnienia PAT-a (minimalne, opisane też w
`docs/infra/DEPLOYMENT_RUNBOOK.md`, sekcja „Co tydzień”):* wyłącznie to
jedno repozytorium (nie „All repositories"), `Contents: Read and write`,
`Pull requests: Read and write`, z ustawionym terminem ważności — nie
„No expiration". Domyślny `GITHUB_TOKEN` zostaje w workflowie z uprawnieniem
`contents: read` (nic więcej go już nie potrzebuje).

*Gdy sekretu brakuje:* workflow ma osobny, pierwszy krok „Sprawdź sekret
CENY_WARZYW_PAT”, który sprawdza jego obecność PRZED checkoutem i kończy
przebieg czerwonym `::error::` po polsku, mówiącym dokładnie, co ustawić
i gdzie (Settings → Secrets and variables → Actions →
`CENY_WARZYW_PAT`) — nie cichym błędem gita czy `gh` przy pustym tokenie.

**Poprawka bezpieczeństwa, 26.09.2026 (#1957): checkout i uruchomienie
skryptu rozdzielone na dwa joby.** Zdanie wyżej — „checkout i krok
push/PR idą TYM SAMYM tokenem” — było prawdziwe i było błędem: między
tym checkoutem a pushem workflow uruchamiał
`python3 scripts/ceny-warzyw-zsrir-pobierz.py`, czyli kod z repozytorium,
mając już poświadczenie zapisu (`Contents`/`Pull requests: read/write`)
zapisane w konfiguracji gita przez ten sam checkout. Skompromitowany
skrypt (albo jego zależność `openpyxl`) mógł to poświadczenie odczytać
(`git config --local --get-regexp 'credential|url'`) i wynieść poza
kontrolę tego joba. Naprawa: `pobierz` (bez tokenu, `persist-credentials:
false`, uruchamia skrypt) i `publikuj` (z tokenem, ale bez ani jednego
wywołania kodu z `scripts/` — tylko `git`/`gh` z tego pliku workflow,
plik CSV wędruje między jobami jako artefakt przebiegu). Strażnik:
`tests/Feature/WorkflowCenNieUruchamiaKoduZTokenemZapisuTest.php`.

**B. Automatyzacja obejmuje też siedem warzyw hurtowych — jeden PR
tygodniowo na wszystkie 12 warzyw.** Ręczna aktualizacja arkusza
„HURT WARZ” z punktu 3 była tymczasowa: skoro ten sam biuletyn niesie oba
arkusze w jednym pliku xlsx, nie ma powodu automatyzować tylko jednego
z nich. `scripts/ceny-warzyw-zsrir-pobierz.py` dostał drugą funkcję
parsującą, `wyciagnij_ceny_hurt`, czytającą arkusz „HURT WARZ”: dla
każdego z pięciu rynków (Bronisze, Kalisz, Łódź, Poznań, Rzeszów) liczy
średnią z min–max, potem średnią arytmetyczną tych pięciu wartości (rynek
bez notowania w danym tygodniu jest POMIJANY, nie liczy się jako zero),
mnoży przez przelicznik i zapisuje jak dotąd z jawną klauzulą
„szacunek z cen hurtowych” w `zrodlo`.

**Mnożnik ma jedno źródło prawdy, także między językami.** Skrypt NIE
trzyma własnej kopii liczby 1,6 — funkcja `mnoznik_hurt_detal()` czyta ją
wprost z pliku PHP (`App\Domain\Recipes\Koszt\SzacunekKosztuZCen`,
wyrażeniem regularnym na stałą `MNOZNIK_HURT_DETAL`) i kończy działanie
czytelnym błędem, gdy tej stałej tam nie znajdzie — zamiast po cichu
przyjąć wartość domyślną. Test zgodności
(`MnoznikHurtDetalTest::test_czyta_stala_z_prawdziwego_pliku_php`) czyta
PRAWDZIWY plik repozytorium, nie kopię, więc zmiana stałej w PHP bez
odpowiadającej zmiany w Pythonie (albo odwrotnie) nie może po cichu
przejść — któryś z dwóch testów by to złapał.

Skrypt aktualizuje teraz WSZYSTKIE 12 wierszy (`MAPA` ∪ `MAPA_HURT`)
w jednym przebiegu, więc workflow otwiera **jeden PR tygodniowo** z całym
cennikiem warzyw, a nie dwa osobne progi przeglądu tej samej rzeczy.

**4. Testy.** `KosztZCenGusTest::kapusniak_mowi_wprost_ze_kapusta_to_szacunek_z_hurtu`
— ręcznie policzone danie (kiełbasa GUS + kapusta hurt×1,6), sprawdza
kwotę I dokładny tekst zdania z klauzulą o cenach hurtowych; kontrola
ujemna zepsuła wykrywanie frazy — test oblał, przywrócono, znów zielony.
`KosztZCenGusTest::ceny_hurtowe_warzyw_w_pliku_sa_srednia_razy_mnoznik`
— sprawdza, że KAŻDA z siedmiu cen w prawdziwym pliku CSV to naprawdę
`hurt_średnia × MNOZNIK_HURT_DETAL` (żeby ktoś, kto zmieni jedno, nie
zapomniał drugiego); kontrola ujemna zmieniła stałą — test oblał,
przywrócono. `scripts/ceny_warzyw_zsrir_pobierz_test.py` (`unittest`,
bez sieci, na małych fikturach .xlsx zbudowanych w pamięci) sprawdza
parser skryptu — teraz uruchamiany automatycznie co tydzień, więc musi
mieć własny test, nie tylko ręczne uruchomienie; ten test chodzi też jako
krok w `ceny-warzyw-auto.yml`, PRZED prawdziwym pobraniem. Od uzupełnienia
z 26.09.2026 dochodzą do niego: `WyciagnijCenyHurtTest` (parser arkusza
„HURT WARZ”, z kontrolą ujemną na rynek bez notowania — gdyby liczył się
jako zero zamiast być pominięty, średnia by spadła), `MnoznikHurtDetalTest`
(test zgodności — czyta `MNOZNIK_HURT_DETAL` z prawdziwego pliku PHP,
z kontrolą ujemną na plik bez tej stałej) i
`MainAktualizujeWszystkie12WarzywTest` (uruchamia cały `main()` na
fikturze z OBOMA arkuszami tego samego biuletynu i sprawdza, że
wszystkie 12 kluczy — `MAPA` ∪ `MAPA_HURT` — dostaje nową cenę w jednym
przebiegu, a klauzula „szacunek z cen hurtowych” trafia wyłącznie do
siedmiu wierszy hurtowych).

### Wycofanie części 3
Usunąć pięć wierszy detalicznych (`ziemniaki`, `cebula`, `marchew`,
`papryka_czerwona`, `pomidor`) i siedem wierszy hurtowych (`kapusta`,
`buraki`, `por`, `seler`, `pietruszka`, `salata`, `ogorek`) z
`database/data/ceny_skladnikow.csv`, skrypt
`scripts/ceny-warzyw-zsrir-pobierz.py` z testem
`scripts/ceny_warzyw_zsrir_pobierz_test.py` i workflow
`.github/workflows/ceny-warzyw-auto.yml` (razem z sekretem
`CENY_WARZYW_PAT`, jeśli nic innego go już nie używa). Bez migracji do
cofnięcia — kod `SzacunekKosztuZCen` obsługuje brak tych wierszy tak samo
jak dziś
obsługuje brak cen warzyw w ogóle.
