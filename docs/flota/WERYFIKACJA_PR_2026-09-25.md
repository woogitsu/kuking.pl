# Weryfikacja otwartych PR-ów względem kryteriów issues — 25.09.2026

Sesja robocza „weryfikacja PR”. Audyt z 25.09 pokazał, że około 156 otwartych
issues ma otwarty PR, ale nikt nie porównał PR-a z kryteriami akceptacji.
Ten plik to wynik takiego porównania, PR po PR-ze.

## Jak czytać

- **domyka?** — `TAK` znaczy: diff i testy pokrywają wszystkie kryteria
  akceptacji issue; sesja główna może zamknąć issue po scaleniu PR-a.
  `CZĘŚCIOWO` — część kryteriów bez pokrycia (kolumna „czego brak”).
  `NIE` — PR nie realizuje issue. `SPRZECZNOŚĆ` — PR stoi w sprzeczności
  z `AGENTS.md` albo decyzją.
- **co dopisano** — commit dopisany na gałęzi PR-a (tylko gałęzie `claude/*`,
  zawsze po `git merge origin/main`, zwykły push). „—” = nic.
- Testy uruchamiano **celowane**, na stanie połączonym z bieżącym `main`
  (`9dddf0f0`) i na własnej bazie PostgreSQL 18, nie całą baterię —
  wyjątki opisane przy PR-ze. Kontrola ujemna = zepsucie strażnika w kodzie,
  test musi oblać, przywrócenie.
- Pominięte z założenia: gałęzie `claude/api-*`, PR #1478 i #1511.

## Wyniki

> **Środowisko:** lokalny PostgreSQL to **16**, nie 18 — `TestyChodzaNaPostgresieTest`
> oblewa tu niezależnie od PR-a, a wyniki lokalne są z PG16. CI (PG18) rozstrzyga.
> Testy przeglądarkowe szły na Chromium 1194 z `/opt/pw-browsers`.

| PR | issue | domyka? | czego brak | co dopisano |
|---|---|---|---|---|
| #1405 | #821 | TAK | — (kryteria 1–4 pokryte; zastrzeżenie o czerwonej bramce nieaktualne, niżej) | — (gałąź `codex/*`) |
| #1538 | #946, #988 | #946 TAK, #988 CZĘŚCIOWO | #988: brak rodzaju „ostrzeżenie”; `with('status')` bez rodzaju nadal domyślnie daje sukces (kryterium: nowe wywołanie nie może go odziedziczyć); sklasyfikowano ~30 ze 114 wywołań; brak testu NVDA/VoiceOver i pomiaru 200%/320 px | — |
| #1499 | #994 | CZĘŚCIOWO | kryt. 1: plan Hobby i 7 dni to deklaracja, niepotwierdzona w Railway; kryt. 3: kraj przechowywania dziennika przez Railway nieustalony (lista odbiorców otwarta); kryt. 2, 4, 5 spełnione | — |
| #1532 | #967, #1001 | #967 TAK, #1001 CZĘŚCIOWO; KONFLIKT z `main` | #1001: brak pomiaru Lighthouse/trace LCP przed i po oraz ekranu wpisu w `scripts/wydajnosc.mjs`; konflikt w `posts/show.blade.php` i `CHANGELOG.md` | — (konflikt) |
| #1593 | #1308, #1309 | #1308 TAK, #1309 CZĘŚCIOWO | #1309: pomiar EXPLAIN/czas/pamięć jest dopiero w #1628; grupy `tests/Dwa` nie uruchomiono lokalnie | — |
| #1628 | #1037, #1309 | #1037 TAK; #1309 TAK razem z #1593; KONFLIKT z `main` | konflikt w 5 plikach (`Post.php`, `DailyBoard.php`, `TagController.php`, `kontrole-negatywne-alfa08.py`, `CHANGELOG.md`); w commicie śmieciowy `scripts/__pycache__/*.pyc` | — (konflikt) |
| #960 | brak issue (D-223) | TAK wobec opisu PR | opis nieaktualny: „nie scalać, dopóki wpięcia nie ma” — krok już jest w `ci.yml` i `check.sh` (3c-bis) | — (gałąź `flota/*`) |
| #966 | brak issue (D-228, D-243) | CZĘŚCIOWO | konflikt z `main` w `kontrole-negatywne-alfa08.py`; konflikt z #1194 i #960 w `check.sh`; tytuł („nazwa ze skrótu ścieżki”) mówi więcej niż kod (główny checkout dalej `kuking_test`) | — (gałąź `flota/*`) |
| #1194 | #732 | CZĘŚCIOWO | kryt. 1: sonda bez nazwy bazy/użytkownika, brak parametrów → domyślne zamiast odmowy; kryt. 2: brak scenariusza 127.0.0.1:55439; kryt. 3: `pg_ctlcluster … main start` zostaje dla 5432, a issue każe go usunąć; kryt. 4 TAK. Dubluje poprawkę `pg_isready` z #966 | — (gałąź `gpt-zalegle`) |
| #1399 | #1053 | TAK | — | — (gałąź `codex/*`) |
| #1406 | #1093 | TAK | — (asercja `notifications = 0` słaba: fixture bez odbiorcy) | — (gałąź `codex/*`) |
| #1447 | #973 | CZĘŚCIOWO; KONFLIKT z `main` | komentarz 2 w issue: CLI dalej wypisuje surowe `getMessage()` (`SprawdzPoczte.php:152`, `:456`, `SprawdzZdjeciaPoPrzenosinach.php:92`), bez testu; konflikt w `HealthController.php`, `PurgePublicMediaCache.php`, `kontrole-negatywne-alfa08.py` | — (konflikt) |
| #1453 | #746, #748, #749 | TAK (×3) | — (test Playwright `service-worker-aktualizacja.mjs` tylko w CI) | — (gałąź `g29/*`) |
| #1653 | #684 | CZĘŚCIOWO | scenariusz 2 z issue nieruszony: `aside.szybki-wyglad-podpowiedz` (fixed, z-index 26) dalej zasłania kafel przy 360/390 px, bez testu wskaźnikiem (autor przyznaje w opisie) | — |
| #1542 | #819, #892 | #819 TAK (po dopisku), #892 CZĘŚCIOWO | #819: brakowało testu kryt. 2 (ekran po nocnym `ready → expired`, pozostałe stany zachowują komunikat) — dopisany; #892: brak stanu offline (`wire:offline`, komentarz 23.09), `kreator-zachowanie.mjs` nieuruchomiony | `ba1c26a7` (2 testy w `TerminPaczkiDanychTest`) |
| #1213 | #906 | CZĘŚCIOWO | brak testu głównej reguły w wariancie „zapis w A → autor czyta → ta sama osoba zapisuje w B”; kontrola ujemna: usunięcie strażnika `$wlasneZeszytyZTymPrzepisem > 1` → 27/27 dalej zielone (deduplikacja `savers` maskuje) | — (gałąź `naprawa/*`) |
| #1608 | #987, #1000 | #987 CZĘŚCIOWO, #1000 NIE | #987: brak odbioru na fizycznym iPhonie (Safari/PWA, pion/poziom, 100/140/200%); D-260 wybiera `cover` „do odbioru”, bez decyzji właściciela. #1000: podzbioru fontu nie ma (transfer nadal 133 kB), brak pomiaru przed/po, testu grubości 100–900, fallbacku, CLS — jest tylko strażnik polskich znaków w `unicode-range` | `fc29b903` (usunięty wgrany `.pyc`, pusty wiersz przed D-260) |
| #1500 | #1046 | CZĘŚCIOWO; KONFLIKT z `main` (`CHANGELOG.md`) | kryteria z opisu issue spełnione; brak testów współbieżnych dla trzech przypadków dopisanych w komentarzach 25.09: potwierdzenie zmiany e-maila, włączenie 2FA (stara sesja na `auth` i `/admin/**`), awans roli | — (konflikt) |
| #1501 | #1034 | TAK | — | — |
| #1503 | #1394 | TAK | — | — |
| #1505 | #1289 | TAK | — | — |
| #1508 | #961 | TAK | — | — |
| #1510 | #1023 | CZĘŚCIOWO; KONFLIKT z `main` (`kontrole-negatywne-alfa08.py`) | brak pomiaru kosztu zapytania i rozmiaru stanu przed/po (PR przyznaje); ręczny `od_przepisu`/`od_osoby` bez kursora dalej idzie nieograniczonym `OFFSET`, bez limitu i testu (komentarz w issue) | — (konflikt) |
| #1513 | #936 | CZĘŚCIOWO | kryterium „wynik starego zadania nie jest przedstawiany jako ocena nowszej wersji”: brak wersji/migawki w jobie i testu kolejności „job czyta A → edycja na B → zapis zgłoszenia” (D-258 nazywa to „znaną granicą”) | `63409a31` (H1 na ekranie `edit-pod-decyzja`, test przekierowania z edycji, D-258 → „obowiązuje”) |
| #1475 | #953 | CZĘŚCIOWO | kryt. 6 (polityka, COMPLIANCE.md i ekran mówią o tym samym zakresie): `resources/legal/polityka-prywatnosci.md:88` dalej mówi, że „historii zgłoszeń albo korespondencji z nami” nie ma w paczce, a PR dodaje `moje_zgloszenia` i `wiadomosci_do_serwisu`. Tekst prawny z `wersja_polityki` — zmiana dla właściciela | — |
| #1476 | #930 | TAK (issue już zamknięte przez #1428; PR domyka resztę); KONFLIKT z `main` (`CHANGELOG.md`) | — | — (konflikt) |
| #1485 | #950, #933, #989 | #950 TAK, #933 TAK, #989 CZĘŚCIOWO; KONFLIKT z `main` (`CHANGELOG.md`, `tests/Dwa/bin/scenariusz.php`) | #989: brak zestawienia „raportowanie przejrzystości liczy odwrócenie decyzji i rodzaj nowego działania” (dane są: `appeal_id`, `new_decision` w `audit_log`) | — (konflikt) |
| #1491 | #1479 | TAK; KONFLIKT z `main` (`CHANGELOG.md`) | — | — (konflikt) |
| #1493 | #1400 | TAK; KONFLIKT z `main` (`CHANGELOG.md`, `kontrole-negatywne-alfa08.py`) | — (w commicie śmieciowy `scripts/__pycache__/*.pyc`) | — (konflikt) |
| #1496 | #1395 | TAK (po dopisku) | test nie sprawdzał „wpis z własnym zdjęciem zostaje” (zielony po usunięciu `orWhereHas('media')`) — dopisany | `640d609f` |
| #1498 | #992 | TAK; KONFLIKT z `main` (`kontrole-negatywne-alfa08.py`) | — (brak testu „APP_URL bez hosta → przepuszczenie”, PR przyznaje; śmieciowy `.pyc`) | — (konflikt) |
| #1527 | #769 | TAK | — (odczyt czytnikiem ekranu to krok ręczny, niewykonany) | — |
| #1528 | #823, #824 | TAK (×2) | — (wspólna transakcja z #824 jest już na `main` z `56f83886`; PR dokłada polski komunikat zamiast 500) | — |
| #1531 | #875 (Refs) | TAK — usprawnienie; kryteria #875 już na `main` | — (tolerancja spacji, braku myślnika, małych liter; bez zamiany 0↔O, zgodnie z issue) | — |
| #1533 | #964, #965 | #964 TAK, #965 CZĘŚCIOWO | #965 kryt. 5: sitemapa nie obejmuje niepustych publicznych zeszytów (`SitemapController` zmienia tylko robots.txt) | — (zmiana nie jest mała) |
| #1537 | #766, #802 (Refs) | TAK — usprawnienie; oba issues zamknięte na `main` | — | — |
| #1539 | #939 | TAK | — (opis: scalać po #1520; drugi scalający nakłada porcje na zapytanie z #1520) | — |
| #1540 | #853 (Refs) | CZĘŚCIOWO — usprawnienie (#853 zamknięte); KONFLIKT z `main` (`CHANGELOG.md`, `scenariusz.php`) | z komentarzy w issue: brak kontroli danych wykrywającej obserwowania ukrytych/scalonych tagów i testu dwóch połączeń dla ukrycia tagu i onboardingu | — (konflikt) |
| #1514 | #1435 | TAK | — | — |
| #1516 | #1018 | TAK; KONFLIKT z `main` (`posts/show.blade.php`: okruszki i JSON-LD z `main` + znacznik „Ukryte przez moderację”) | — (PR sam odkłada: moderator może zgłosić komentarz spod ukrytego wpisu) | — (konflikt) |
| #1519 | #1461 | TAK | — (baza PR-a to gałąź #1494, więc diff zawiera też jej zmiany) | `2317abcb` (usunięty wgrany `.pyc`) |
| #1520 | #938 | TAK | — | — |
| #1521 | #982 | TAK (po dopisku) | kryt. „druga poprawka nie zleca skutków ubocznych edycji” bez własnej asercji — dopisany test | `1da2c561` (`test_konflikt_nie_zleca_ponownej_analizy`) |
| #1522 | #996 | TAK | — | — |
| #1524 | #836 | TAK (kod) | uruchomienie komendy czyszczącej z `--wykonaj` na produkcji — decyzja właściciela (triaż issue) | — |
| #1552 | #1344, #1025 | TAK (×2) | — | — |
| #1554 | #1317 | TAK (po dopisku) | opis PR-a obiecywał wpis audytu `parent_restored_as_placeholder`, żaden test go nie sprawdzał — dopisany. Drobna luka: przywrócenie odpowiedzi pod korzeniem zdjętym przez moderację nie mówi, że odpowiedź dalej jest niewidoczna | `42bcb8ca` |
| #1566 | #1385 (Refs #1394) | TAK; KONFLIKT z `main` (`CHANGELOG.md`, `DECISIONS.md`) | — (scalać po #1503; odejście od „blokada odcina dostęp” pokryte decyzją właściciela D-265 w #1503) | — (konflikt) |
| #1567 | #1318 | CZĘŚCIOWO | kryt. 1 + decyzja „wmieszać własne wpisy”: reguła „jeden wpis na autora” (#940) pokazuje tylko najnowszy własny wpis — starszy „tylko dla obserwujących” znika ze Startu po nowszym publicznym (potwierdzone testem jednorazowym); brak wariantu onboardingu „Pomiń ten krok” ×2 z komentarza | — (zmiana logiki feedu) |
| #1571 | #871, #872, #874 | #871 TAK, #872 CZĘŚCIOWO, #874 CZĘŚCIOWO | #872: brak wymaganej próby w przeglądarce (jest tylko HTTP); #874: fokus i czytnik ekranu niesprawdzone; `aria-describedby`/`aria-invalid` przy polu zdjęć w 3 formularzach — robi to osobny #1621 (#1572) | — |
| #1575 | #1024, #1059 | #1024 CZĘŚCIOWO, #1059 TAK | #1024: brak testu „wyścig i awaria w połowie spełniają gwarancje #950”. Kod i `MODERATION.md` powołują się na „decyzję właściciela, wariant b” — na GitHubie brak takiego komentarza; opis PR-a nieaktualny (po `e29ef193` znacznik stanu, nie identyfikatory) | — |
| #1576 | #1323, #1325 | TAK (×2) | — (zamiana warunkowego `UPDATE` na sprawdzenie w PHP nie oblewa testu — brak testu współbieżności, kryteria go nie wymagają) | — |
| #1601 | #1010 | TAK | — | — |
| #1603 | #957, #1029 | #957 CZĘŚCIOWO, #1029 TAK | #957: brak testu w przeglądarce zapisującego żądania na 390 i 1280 px; brak LCP przed/po na desktopie (pomiar tylko 360×640); kryt. „<64 rem: zero pobrań” niespełnione — PR przyjmuje, że telefon pobiera 4 kafle, bez `<picture>` | — |
| #1604 | #1082, #1316 | TAK (×2) | — (zgodnie z „Decyzją właściciela” 24.09 w #1316) | — |
| #1605 | #884 (Refs #903) | CZĘŚCIOWO; **CI czerwone**; KONFLIKT z `main` (`CHANGELOG.md`) | brak testu w prawdziwej przeglądarce (A/B/C → usunięcie B → w FormData A/C), są tylko testy node z atrapą `DataTransfer`; 4 nowe `test_903_*` w `CookingModeTest` oblewają na gałęzi i po scaleniu (dublują `GotowanieOdPoczatkuTest`, #903 jest na `main`) | — (konflikt) |
| #1606 | #883 (Refs #768) | TAK | — | — |
| #1607 | #985 | CZĘŚCIOWO | kryt. 2 z komentarza: `/witaj/zainteresowania` nie zaznacza zapisanych tagów, brak testu prawdziwego `checked` (wznowienie omija krok, idzie do `/witaj/ludzie`) | `09f06d26` (usunięty wgrany `.pyc`) |
| #1609 | #1346, #1347, #1364 | #1346 TAK, #1347 SPRZECZNOŚĆ, #1364 TAK | #1347: `account.delete_requested` wszedł do transakcji (klasa 1 D-249), a komentarz w issue z 25.09 chce `recordBezWywracania()` po COMMIT i zakazu przenoszenia audytu do transakcji — rozstrzyga właściciel; przydziału wpisu do klasy brak w rejestrze D-249 | — |
| #1587 | #1302, #1368, #1304 | #1302 TAK (bez testu na urządzeniu), #1368 CZĘŚCIOWO, #1304 CZĘŚCIOWO | #1368: brak pomiaru położenia zdjęcia i LCP (desktop, tablet, 320 px, krótka/długa instrukcja); zdjęcie kroku zawsze od razu, bez leniwego, gdy leży niżej. #1304: autor nie może wpisać własnego opisu zdjęcia kroku (brak pola w kreatorze/edycji, `ZapiszPrzepisZFormularza` nie zapisuje `alt_text`); brak próby z czytnikiem ekranu | — |
| #1590 | #1297, #1319 | TAK co do treści; **CI czerwone**; KONFLIKT z `main` (`DailyBoard.php`, `TagController.php`) | `ListyWpisuZWlasnaTresciaTest::test_kontrola_dodatnia_dostepny_przepis…` oblewa zawsze („Odkrywanie: brak wpisu z własną treścią”) — część z #1377/#1584 zderza się z regułą „jeden wpis na autora” (#940) | — (konflikt) |
| #1592 | #845 | TAK (bez przebiegu w przeglądarce) | `scripts/kontakt-panel-browser.mjs` ma na sztywno bazę `kuking_flota_gpt-kontakt-panel` i port 55439, w CI nie chodzi — klawiatura i telefon niesprawdzone | — |
| #1594 | #1090 | TAK | — | — |
| #1595 | #1389, #1390 | TAK (×2) | — | — |
| #1596 | #1310, #1326 | CZĘŚCIOWO (×2) | brak pomiaru w przeglądarce wymaganego przez oba issues (adresy i bajty przy DPR 1/2/3, zimny cache, 320 px i desktop) — PR przyznaje | — |
| #1598 | #1032, #1280 | #1032 TAK, #1280 CZĘŚCIOWO | #1280 kryt. 3: zmiana widoczności publiczny → prywatny/dla obserwujących nie przesuwa `lastmod` profilu (PR: „znana granica”) | — |

### Uwagi

- **#1405 / #821.** Gałąź 113 commitów za `main`, merge bez konfliktu.
  Na stanie połączonym `DataExportTest` + `OdnosnikiDziennikaDecyzjiIstniejaTest`:
  32 PASS. Kontrola ujemna (`if ($written === false)` → `if (false)`): nowy
  test oblewa z komunikatem o braku wyjątku, po przywróceniu przechodzi.
  Strażnik, który w opisie PR-a był jedynym czerwonym, przechodzi już na
  `main`. Pełna bramka `PAO_DISABLE=1 KUKING_TESTY_ROWNOLEGLE=3 ./scripts/check.sh --szybko`
  na stanie połączonym (PG16, własna baza): 6882 testy, 15 FAIL + skrypt kopii
  bazy. **Te same czerwienie są na czystym `main`**: 13 z nich (PG16,
  `Health*`/`Wdrozenie*`/`Turnstile*`/`Ses*`/`KursorStartu*`) odtworzone
  szeregowo na `origin/main` 1:1, pozostałe dwa to wersja PG i przebieg
  równoległy. Żadna nie dotyka eksportu. #1405 nie dokłada czerwieni —
  rozstrzyga CI na PG18.
- **#1538.** Merge czysty, 11 testów PASS; kontrola ujemna w `NawigacjaOsobista`
  oblewa oba testy nawigacji. Kontrakt `Komunikat` przeczytany, bez kontroli ujemnej.
- **#1499.** Merge czysty, 75 PASS; przywrócenie zdania o „życiu instancji”
  oblewa 3 z 5 testów `PolitykaOpisujeRetencjeDziennikaSerweraTest`. Brakujące
  kryteria wymagają odczytu w panelu Railway — decyzja właściciela, nie kod.
- **#1532.** Na stanie gałęzi 20 PASS, kontrole ujemne (tytuł, priorytet zdjęcia)
  oblewają 9 testów. `main` ma już `Okruszki::nazwaWpisu()` (#1033) — przy
  rozwiązywaniu konfliktu połączyć w jeden generator nazwy wpisu (komentarz do #967).
- **#1593.** Merge czysty, 65 PASS; kontrole ujemne (drugie kryterium sortowania,
  lista UUID) działają.
- **#1628.** Na stanie gałęzi 69 PASS; usunięcie `recipe.heroMedia` z kontraktu
  oblewa 10 przypadków `KartaWpisuJednymKontraktemTest`. Opis każe scalać po
  #1584/#1590. #1309 zamykać dopiero po wejściu #1593 **i** #1628.
- **#1453.** Merge czysty, 231 testów PHP + `service-worker-marka.test.mjs` PASS;
  trzy kontrole ujemne (`href="/home"`, gołe `format`, przekierowanie pustej strony) oblewają.
- **#1399 / #1406.** Merge czysty; kontrole ujemne zdjęcia strażnika oblewają nowe testy.
- **#1194 i #966** zmieniają ten sam fragment `check.sh` (sonda `pg_isready`) — scalać
  jeden, drugi przepiąć; #732 zamykać dopiero po uzupełnieniu kryteriów 1–3.
- **#1213.** Brakujący test to kilka linii, ale gałąź `naprawa/*` — do dopisania
  przez autora. Grupa `tests/Dwa` nieuruchomiona (wymaga bazy `kuking_race_*`).
- **#1542.** Merge czysty, 50/50; kontrole ujemne dla istniejących i nowych testów oblewają.
- **#1608.** Merge czysty, 17/17, `npm run build` PASS; trzy kontrole ujemne oblewają.
  #1000 praktycznie nieruszone — nie zamykać.
- **#1653.** Merge czysty, testy przeglądarkowe 8/8; kontrola ujemna (`zakrywaCel`) oblewa.
- **#1500.** Na stanie połączonym (CHANGELOG rozwiązany lokalnie) 321 PASS;
  kontrola ujemna (`GeneracjaSesji::zgodna()` → `true`) oblewa wszystkie 6 operacji.
  Gałąź niesie commity #1475 i #1476 — scalać po nich.
- **#1501 / #1503 / #1505 / #1508.** Merge czysty; kontrole ujemne oblewają
  (odpowiednio: zamrożony `recipe_slug`; trzy strażniki Policy/karta/tytuł;
  dwa odnośniki powitania; `=== false` w każdym z trzech miejsc potoku).
- **#1510.** Na rozwiązanym lokalnie stanie 194 PASS; bez kursora kontrola wykrywa
  duplikat i pominięcie.
- **#1513.** Zmiana statusu D-258 oparta na komentarzu w PR „Decyzja właściciela
  (24.09.2026): D-258 zaakceptowane”.
- **#1475–#1498.** Konflikty tylko mechaniczne (CHANGELOG, lista w
  `kontrole-negatywne-alfa08.py`, `scenariusz.php` — obie strony zostają);
  na lokalnie rozwiązanych stanach wszystkie celowane testy PASS, a kontrole
  ujemne oblewają. #1485: grupa `tests/Dwa` nieuruchomiona; otwarte ryzyko —
  kolejność blokad appeals→users odwrotna niż reguła `ZamekKonta` (kontrprzykładu
  nie znaleziono).
- **#1498 — do sprawdzenia przez właściciela:** testowe klucze Cloudflare
  zwracają obcy `hostname`, więc lokalnie i na stagingu z kluczami testowymi
  formularze z Turnstile będą odrzucane.
- **#1527–#1540.** Celowane testy zielone na stanie połączonym (#1540 — na
  lokalnie rozwiązanym), każda kontrola ujemna oblewa. Jedyny czerwony
  `KursorStartuPamietaZrodloTest::test_odkrywanie_zmienione_na_tagi…` oblewa
  też na czystym `main` (patrz #1405) — nie pochodzi z PR-ów.
- **#1514–#1524.** Celowane testy zielone (w tym grupy na dwóch połączeniach dla
  #1514, #1521, #1522 na własnych bazach `kuking_race_wt_*`), każda kontrola
  ujemna oblewa. #1516 po lokalnym połączeniu obu stron konfliktu: 69 PASS.
- **#1552–#1576.** Celowane testy zielone, kontrole ujemne oblewają. #1552:
  migracja z odmawiającym rollbackiem, kontrolą dodatnią i wpisem w `DATABASE.md`.
  **#1575 — do potwierdzenia przez właściciela:** „wariant b” (znacznik stanu grupy)
  nie ma zapisanej decyzji właściciela.
- **#1601–#1609.** Celowane testy zielone (poza #1605), kontrole ujemne oblewają.
  #1603: `fetchpriority="high"` na kolażu pod pierwszym ekranem telefonu może
  konkurować z właściwym obrazem LCP — niezmierzone. #1604: `scripts/menu-karty-wpisu.mjs`
  przechodzi w Chromium (z `KUKING_DEMO_HASLO` jak w CI). #1609: indeks częściowy
  nie uwzględnia `rodzaj` — przy nowym rodzaju żądania zablokuje drugą sprawę konta.
- **#1605 — do decyzji sesji głównej / autora.** Na stanie połączonym z `main`
  (CHANGELOG rozwiązany lokalnie, niewypchnięty) oblewa
  `CookingModeTest::test_903_przy_postepie_jest_potwierdzenie…` — blok `test_903_*`
  dopisany w `5dd2b4ef` sprawdza tekst UI „Zostaw odhaczenia”, a #903 weszło na
  `main` inną drogą z `GotowanieOdPoczatkuTest`. Naprawa = usunąć albo przepisać
  blok #903 pod UI z `main`; ta sesja nie usuwa testów z cudzego PR-a.
- **#1587–#1598.** Celowane testy zielone (poza #1590), kontrole ujemne oblewają.
  #1590: same poprawki #1297/#1319 działają (8 PASS, kontrole ujemne oblewają 2 i 7).
  #1598: test pilnuje braku N+1 w sitemapie; zależny #1570 jest już na `main`.
