# Kolejka zadań floty — stan 20.09.2026

Baza wszystkich stanowisk: `main` = `534e0a51` (po scaleniu #790).
Zasady: `flota/_wspolne/ZASADY_FLOTY.md`.

## W TOKU (10 stanowisk)

| stanowisko | model | zadanie |
|---|---|---|
| `r47-skan` | opus | R47 — strażnik na `.env` w repo / hasło admina / wyłączony CSRF |
| `r73-feed` | opus | R73 — strażnik algorytmicznego feedu + rejestr wyjątków |
| `r49-trasy` | opus | R49 — mutacja na spisie tras + luka 1a `scopeZWidocznymPrzepisem` |
| `hero-ekran` | opus | dokończyć `robota/hero-pierwszy-ekran` + CHANGELOG 0.68→0.69 |
| `dsa-odwolania` | opus | issues #796–#800 — odwołania, sankcje, terminy (DSA) |
| `gotowanie` | sonnet | issues #739 #740 #751 #755 #756 #764 — tryb gotowania i minutnik |
| `wyszukiwarka` | sonnet | issues #737 #738 #753 #763 — wyszukiwarka i analityka |
| `zeszyty` | sonnet | issues #773–#777 — zeszyty, licznik, prywatność |
| `komentarze` | sonnet | issues #757–#762 — komentarze i powiadomienia |
| `zdjecia-formularze` | sonnet | issues #742–#745 #747 — zdjęcia, x-field, kreator |

## NASTĘPNE W KOLEJCE (bierz z góry, gdy stanowisko zwolni)

1. `kaskada` (opus) — dokończyć `robota/kaskada-straznik` (`fe641488`,
   worktree `Codex/kuking-kaskada`): kontrola ujemna + strażnik pytający
   o **wynik kaskady** (`getComputedStyle`), nie o tekst arkusza, plus pomiar
   kolizji `.przepis-liczby`.
2. `straznik-r60` (opus) — R60 z sekcji C mapy reguł: nic nie oblewa na SQLite,
   a wtedy cała reszta mapy przestaje znaczyć, co znaczy.
3. `art17` (opus) — `docs/legal/COMPLIANCE.md` §7, dwa wiersze `BRAK:`:
   trzy elementy uzasadnienia z art. 17 ust. 3 w `NotifyModerationDecision`
   (nie sięga po `PodstawaDecyzji` ani razu) oraz droga z „Zgłoś" do drogi prawnej
   w `resources/views/pages/report.blade.php`. UWAGA:
   `test_dowod_o_uzasadnieniu_mierzy_to_co_obiecuje` pilnuje tego w OBIE strony —
   zadanie domyka kod **i** wiersz w §7.
3. `siedem-adr` (opus) — siedem ADR-ów ma dotąd tylko warstwę mechaniczną:
   `KUKING_JEZYK`, `OPERATOR`, `REPO_PUBLICZNE`, `OCENA_RETENCJI_ZEWNETRZNA`,
   `PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI`, `PRZEGLAD_SPEC_9_DECYZJI`,
   `ZRODLA_PRAWNE_ZEWNETRZNE`. Nazwy i ścieżki istnieją — to nie znaczy,
   że treść zgadza się z kodem.
4. `relacje` (sonnet) — issues #780 #791 #793 — blokady i obserwowanie.
5. `prywatnosc-formularz` (sonnet) — issues #792 #794 #795 — formularz zgłoszenia.
6. `powiadomienia` (sonnet) — issues #733 #734 #746 #759 #770 #771 #772.
7. `profil-wykonania` (sonnet) — issues #735 #736 #766 #767 #768 #769.
8. `wydruk-offline` (sonnet) — issues #749 #765 — druk A4 i tryb offline.
9. `check-sonda` (sonnet) — issue #732: `check.sh` sprawdza domyślny PostgreSQL
   i próbuje uruchamiać współdzielony klaster `main`.
10. `zapisy-rownolegle` (sonnet) — issues #778 #779 — równoległe zapisy zeszytu.

**Źródło ciągłe:** ChatGPT 6 „astra" dokłada nowe issues w `woogitsu/kuking.pl`
bez przerwy. Zanim weźmiesz pozycję z listy, sprawdź świeże:
`gh issue list --repo woogitsu/kuking.pl --state open --limit 30`.

## DECYZJE WŁAŚCICIELA — nie domykać samemu

- **Luka 1b**: autor/moderator gotujący nieopublikowany przepis
  (`RecordCookedEvent.php`). NIE DO NAPRAWY bez decyzji właściciela.
- **D-091**: decyzja obiecuje liczby o osobie renderowane dwukrotnie, kod renderuje
  raz, a test wskazany jako jej strażnik asertuje BRAK wariantu szynowego.
- **Tablica „kuKINGi na dziś"** nie dolicza stanu zeszytu (D-081).
- **Reguła o worktree w `AGENTS.md`** (commit `942b7d84`) — zostawić czy wyjąć.
- **Umowy powierzenia / DPA**, **R7** (adresy osób bez konta) — nietknięte.
- **CHANGELOG „Alfa 0.68"**: późniejsza pozycja dostaje 0.69, nic nie znika.

## Decyzje właściciela podjęte 20.09.2026

- **#773 (porządkowanie niedostępnych zapisów w zeszycie) → ODKŁADAMY.**
  Zgłoszenie zostaje otwarte jako P3, powód zapisany w komentarzu pod nim.
  Krótko: problem dotyczy zeszytu „po dłuższym używaniu", a portal nie ma jeszcze
  użytkowników; auto-powrót niedostępnych zapisów jest pilnowaną gwarancją
  (`ZeszytNiedostepneZapisyTest`, cztery scenariusze), a licznik po #774 mówi prawdę,
  więc nikt nie jest wprowadzany w błąd. **Nie domykać tego asercją.**
- **#798 (jak długo strona ma służyć śledzeniu sprawy) → nadal otwarte**, czeka
  na wybór okresu. Ustalenie przy okazji: gałąź „termin minął" w widoku odwołań
  jest nieosiągalna zwykłym linkiem, bo podpis wygasa dokładnie wtedy, gdy ta gałąź
  zaczęłaby obowiązywać.

## Nowe pozycje do decyzji właściciela (z prac floty 20.09)

- **#758 — kontrakt powiadomienia o edytowanym komentarzu.** NIE jest tym samym
  co #757 (tam komentarz naprawdę zniknął; tu żyje, tylko został poprawiony).
  Nic w kodzie ani w `docs/DECISIONS.md` tego nie rozstrzyga.
  **A — migawka (stan dzisiejszy):** powiadomienie trzyma treść z chwili zdarzenia.
  Koszt zerowy, zgodne z komentarzem „Powiadomienie jest migawką zdarzenia
  z przeszłości" i z precedensem D-052. Wada: pokazuje nieaktualny tekst nawet
  15 minut po uprawnionej poprawce.
  **B — śledzenie treści:** wycinek liczony przy renderze. Zawsze prawdziwy, ale
  wymaga zbiorczego dociągnięcia (inaczej N+1 na liście 30 pozycji) i otwiera
  drugie pytanie: czy eksport RODO też ma być żywy, czy ma zostać zapisem tego,
  co powiedziano wtedy.
- **#760 — okno bez znacznika, 5–18 września 2026.** `CommentController::destroy()`
  wpisywał wtedy tekst zastępczy **bez** `body_removed_at`, bo kolumny jeszcze nie
  było. Prawdziwe usunięcia z tego okna są dziś nie do odróżnienia od sytuacji,
  w której ktoś tę samą frazę naprawdę napisał. **Nie da się tego uzupełnić bez
  zgadywania** — stąd decyzja, nie cicha łatka.

## Wątki zostawione świadomie, do wzięcia później

- **Ponowne użycie nazwy użytkownika przy zwykłym obserwowaniu.** #793 zamknęło tę
  lukę dla blokad (pole `oczekiwany_id` + `assertToTaSamaOsoba()`), ale formularze
  obserwuj/przestań obserwować jej nie mają. Niższa waga, bo odwracalne i bez śladu
  — ale to ta sama klasa błędu. Decyzja o zakresie, nie usterka do cichego domknięcia.
- **`kuking-board/people.blade.php` (tablica Poznaj) nie był sprawdzony** pod kątem
  martwego przycisku przy koncie zawieszonym. #780 nazywało wyłącznie listę relacji,
  a ta tablica jest karmiona innym zapytaniem.

# DECYZJE WŁAŚCICIELA — komplet z 20.09.2026

## Wykonane

| Rzecz | Decyzja | Stan |
|---|---|---|
| Hasło moderatora demo w `DemoSeeder` | **Wyjąć do `KUKING_DEMO_HASLO`**, bez wartości domyślnej | `8510ec82` na `robota/poswiadczenia-decyzje` |
| `.gitignore` a rodzina `.env` | **`.env.*` z wyjątkiem `!.env.example`** | ten sam commit |
| Pusty pas pod stopką | **Obie naprawy**: koniec podwójnej rezerwy + rezerwa zależna od obecności `.bottom-nav` | `9a9c3db2` na `robota/stopka-pusty-pas`; zmierzone −352 px |
| CHANGELOG „Alfa 0.68" | **Kolizji nie ma** — odpuszczamy, nikt jej więcej nie szuka | zamknięte |
| #773 porządkowanie zeszytu | **Odkładamy**, zgłoszenie zostaje P3 | komentarz pod issue |

## Podjęte, do wykonania

| Rzecz | Decyzja | Uwaga przy robieniu |
|---|---|---|
| **#758** powiadomienie o edytowanym komentarzu | **Śledzi treść, eksport RODO też** | W toku. Bramka z #757 (`body_removed_at`) obowiązuje niezależnie — usunięta treść nie ma prawa wrócić do widoku. Wymagany pomiar liczby zapytań przed i po. |
| **#794** rozpoznawalny cel zgłoszenia | **Nazwa + krótki fragment treści.** Bez miniatury i awatara | Wartości domyślne ustalone: 200 znaków, cięcie po granicy słowa, wielokropek w cudzysłowie, przy treści pustej lub usuniętej — sama nazwa bez cudzysłowu |
| **#798** strona śledzenia sprawy | **Link żyje, dopóki sprawa jest otwarta** | Podpis wiązany ze STANEM sprawy, nie ze stałym terminem. Gałąź „termin minął" w `appeals/reporter.blade.php` stanie się wtedy osiągalna PIERWSZY RAZ — przeczytać ją, nie zakładać, że działa |
| **#797** zaległe potwierdzenia zgłoszeń | **Dopisać komendę obchodzącą** | Wzorzec gotowy: siedem komend retencyjnych w `COMPLIANCE.md` §7.3. Przy DSA potwierdzenie przyjęcia jest obowiązkiem, nie uprzejmością |
| **R1** — polityka obiecuje „pełną kopię" | **Zawęzić zdanie w polityce** | Brzmienie gotowe w `docs/legal/DECYZJE_WLASCICIELA_R1_R6_DPA.md` §R1 wariant A. Paczki nie rozszerzamy; reszta danych na prośbę, ręcznie, w terminie z art. 12 ust. 3 |
| **Martwe reguły `.przepis-liczba`** | **Usunąć martwe deklaracje** z warstwy `components` | Dopiero po tym strażnik kaskady może być bramką na cały selektor, nie tylko na `svg` |
| **Próg PostgreSQL w R60** | **Podnieść do 18 wszędzie** | UWAGA: to jest **zmiana reguły w `AGENTS.md`**, nie samego strażnika. `AGENTS.md` mówi dziś „lokalnie i w CI wystarczy 16+". Zmienić regułę, potem próg |

## Odłożone z podanym powodem

- **Zadanie CI `Testy (PostgreSQL 18)` jako wymagane do scalenia.** Właściciel
  zdecydował **TAK**, ale GitHub oddaje **403**: ochrona gałęzi wymaga planu
  płatnego albo publicznego repozytorium. Decyzja stoi, wykonanie czeka na
  decyzję o planie. Gdy się zmieni — to jedno polecenie, nie ponowne
  rozstrzyganie.
- **#760, okno 5–18 września 2026.** Usunięcia komentarzy z tamtych dwóch
  tygodni są nie do odróżnienia od sytuacji, w której ktoś naprawdę napisał
  zdanie zastępcze. Zostawiamy jak jest, z adnotacją w kodzie — zgadywanie na
  danych ludzi jest gorsze niż jawna luka.

## Migotanie `Port marki` na `main` — 20.09.2026, zmierzone

`main` = `4c811cc7` (po scaleniu #789) ma **czerwone zadanie `Port marki —
rodziny ekranów, zoom i kreator`** w dwóch przebiegach pod rząd na tym samym
SHA. **To NIE jest regresja** i nie należy tego ścigać w kodzie.

Dowód — asercja **przeskakuje** między przebiegami:

| przebieg | `aktualny_czas_dostepny_bez_spamu_live` | `niezalezne_minutniki` |
|---|---|---|
| pierwszy | **FAIL** („AX nie zawiera aktualnej wartości: `- timer: 0:08`") | PASS |
| ponowienie | PASS | **FAIL** (`1 !== 0`) |

Obie należą do rodziny testów **minutnika**, czyli mierzą upływ czasu w żywej
przeglądarce. Obciążenie maszyny w obu przebiegach: **13–18** przy 24 rdzeniach,
bo równolegle chodziły trzy stanowiska Opus i pozostałe zadania CI.

**Uściślenie reguły rejestru migotania.** Reguła brzmi „czerwony drugi raz
w TYM SAMYM miejscu = nie migotanie". Tutaj czerwień powtórzyła się, ale
**miejsce się zmieniło** — i to jest mocniejszy dowód migotania niż zieleń po
ponowieniu, bo pokazuje, że wynik zależy od czegoś spoza kodu. Poprzestanie na
„czerwone dwa razy, więc prawdziwe" posłałoby kogoś na poszukiwanie usterki,
której nie ma.

**Co zrobić:** ponowić to zadanie, gdy maszyna będzie spokojna (load < 8).
Dopiero czerwień na cichej maszynie, w tym samym miejscu, jest wynikiem
o kodzie. Wpisać do `docs/infra/MIGOTANIE_CI.md` jako nową pozycję — rejestr
żyje na niezłączonej gałęzi `fix/migotanie-ci`.
