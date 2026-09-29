# Audyt stanu repozytorium Kuking.pl — 28.09.2026 (~21:30 UTC)

Tryb: tylko odczyt. Baza porównań: **INT** = `origin/codex/integracja-po-074-20260928` @ `2b59686f7` (35 commitów przed `origin/main`).
Metoda „wchłonięcia”: dla każdej gałęzi `git diff -U0 $(merge-base INT gałąź) gałąź`, każdy dodany wiersz (≥6 znaków, bez CHANGELOG) szukany w wersji pliku na INT → odsetek; do tego stan powiązanego issue, commit naprawy na INT (`git log --grep '#N'`) i punktowy `git grep` w kodzie INT. Surowe dane: `scratchpad/audyt/` (`absorb_stare.txt`, `absorb_red.txt`, `fala8.txt`, `cl_restore.md`, `map.json`, `dmig.txt`, logi CI w `logs2/`).

## 0. Najważniejsze wnioski

1. **Dużo otwartych issues jest już zrobionych na `main`.** PR-y scalane do gałęzi integracyjnych (`codex/integracja-*`) nie zamykają issues automatycznie, nawet gdy mają `Closes #N`. Około 45 otwartych issues ma naprawę na main albo na INT (lista w §4.4). Trzeba je zamknąć z dowodem po weryfikacji (SESJA_GLOWNA §4.3). W opisie PR-a INT→main trzeba wpisać `Closes #…` dla issues zamykanych przez PR-y z INT.
2. Z 69 starych gałęzi bez PR-a **46 można skasować od razu**, a 5 kolejnych po scaleniu PR-ów, które je zawierają (372-jsonld, 1751, 1752, 1997, laughing-edison). Wszystkie są wchłonięte, zdublowane albo przestarzałe. 14 raportów audytu **nie ma na INT**, a `docs/DECISIONS.md` na INT już się do nich odwołuje (martwe odsyłacze). Trzeba je uratować jednym PR-em z samymi dokumentami. Kilka gałęzi ma pracę wartą odzyskania (§1B).
3. Z 19 starych czerwonych PR-ów tylko 2 oblewają przez własny kod: #1511 (`NameError`) i #1885 (Pint i 11 testów). W #1759 i #1887 anulowano joby części, więc zbiorczy job „Testy” jest czerwony. W #1823, #1826 i #1830 czerwień pochodzi z ówczesnego czerwonego main (`KonsolaBezKomunikatuWyjatkuTest`). Reszta to anulowane joby; #960, #966, #1744 i #2142 są zielone. **Do zamknięcia: #1823 i #1830 (wchłonięte na main), #1478 i #1511 (do zrobienia od nowa); #966 i #1744 to pytania do właściciela.**
4. Fala 8: każda gałąź `claude/kopia-N` jest fast-forwardem heada swojego PR-a i łączy się z INT bez konfliktu. W każdej kopii, poza #1872, wypadły wpisy CHANGELOG PR-a. Do przywrócenia jest **22 unikalnych wierszy** (§3.2). Między samymi kopiami jest 5 par konfliktów.
5. Gorące punkty konfliktów to `scripts/kontrole-negatywne-alfa08.py` (16 z 49 PR-ów), `CHANGELOG.md` (17), `docs/DECISIONS.md` (6), wersja polityki prywatności (5 PR-ów) i znacznik migracji `2026_09_26_120000` (3 różne migracje).
6. Są gałęzie z pracą, które nie trafiły ani do PR-a, ani na listę recenzentów: `claude/2044-migracje-przed-workerem` (P1), `claude/kopia-2077`, `claude/2130-import-uciete-pliki`, `claude/2042-audyt-blednego-2fa`, `claude/1991-puste-skladniki-podglad`.
7. Zakres produktu: #1997 (zakresy czasu w wyszukiwarce) jest w `docs/FEATURES.md` na liście **„V2, ale nie teraz”**, a mimo to jest w przeglądzie (`claude/kopia-1997`). `AGENTS.md` §12 nadal zakazuje „planera posiłków”, choć D-310 wdrożył Planer tygodnia (`PlanerController` na main).

---

## 1. Stare gałęzie bez PR-a (69 szt., 09-09…09-26)

### 1A. Wchłonięte, przestarzałe lub zdublowane → do skasowania (46 od razu + 5 warunkowo)

| Gałąź(ie) | % dodanych wierszy na INT | Dowód |
|---|---|---|
| `claude/zglaszajacy-dostaje-odpowiedz` | 94% (15 058/15 901) | issues #116–#200 zamknięte; najstarsza gałąź (09-09) |
| `flota/dsa-odwolania`, `flota/zdjecia-formularze`, `naprawa/klient-pg18-w-ci`, `claude/934-kolejnosc-zdjec-po-bledzie`, `claude/1334-przepis-do-wpisu` | 96–100% | #1334 przez PR #1683; #934 przez 73e50be97 |
| `bramka-startowa` | 88% | brakujący test `Secure` ciasteczka: INT ma `config/session.php:179` + `HealthZglaszaDebugISesjeBezSecureTest` |
| `flota/ekran-zeszytu-2209-rozdzielenie`, `naprawa/775-zakres-usuwania-z-zeszytu` | 77% / 87% | #775–#777 zamknięte (PR #1680, D-267) |
| `claude/1040-korelacja-bledu` | 0 wierszy (tylko scalenia) | #1040 przez PR #1098 |
| `claude/940-983-984-start` | 60% | #940/#983 zamknięte; #984 zrobione na main (c0d5a840e, `PokazWiecejRozszerzaJednaListeTest`, `SearchController.php:105-173`) |
| `claude/1013-sekrety-per-usluga`, `claude/1014-referencje-uslug` | 9% / 4% | #1013/#1014 zamknięte przez PR #1459 (zmienne per rola w `.railway/railway.ts`) |
| `claude/1050-pusta-fraza` | 3% | **dubel**: #1050 naprawione na main 26da87a99 (`FrazaPustaPoNormalizacjiTest`) |
| `claude/666-liczniki-zdarzen` | 2% | #666 przez PR #721 |
| `claude/746-748-czas-obserwowani` | 5% | #746/#748 przez PR #1453 |
| `claude/774-licznik-zeszytu` | 0% (sam test) | #774 zamknięte; INT ma `LicznikKartyZeszytuSpojnyTest`, `LicznikiZeszytuDlaWidzaTest` |
| `claude/838-842-843-kontakt`, `claude/846-kontakt-wersja-karty`, `claude/847-termin-odpowiedzi` | 0–11% | INT: `contact_messages.version` (DATABASE.md:5049, #843/#846), `EdycjaNotatkiZamknietejWiadomosciTest`; #847 przez PR #1212 |
| `claude/880-list-po-wypisaniu` | 6% | #880 przez PR #1290 |
| `claude/888-bezpieczenstwo`, `claude/888-ostrzezenie-stary-adres` | 34% / 6% | #888 przez PR #1285 (da461ba9a „przeniesione asercje z gałęzi 888”) |
| `claude/998-sprzatanie-spraw-partiami`, `claude/999-1060-zapytania` | 16% / 4% | 434a9e722 (#998, #999, #1060) |
| `codex/issue-825` | 30% | 690593022 (pełne UUID w nazwach) |
| `claude/new-session-zpc41g-02-eksport` | 8% | #824 przez PR #1528; **nie scalać**: migracja `2026_09_20_180000_add_notified_at…` dubluje `2026_09_23_180000_add_notified_at…` z INT |
| `claude/naprawa-main-tagi-scalenie-853` | 25% | ae93ce8fc (test #853 poprawiony na INT) |
| `claude/1742-workflow-a7-uprawnienia` | 0% | workflow A7 **usunięty** na main (82890daa6) → zamknąć też issue #1742 |
| `flota/polityka-ue-linia-83` | 7% | polityka na INT (l. 85) mówi już „R2, część usługi zastrzeżona dla UE” (#1499) |
| `flota/straznik-wersji-changelog`, `flota/wersja-068-i-bramka` | 10% / 2% | zastąpione przez D-318/#1932 (końcówka wersji sama) i `PodbicieWersjiWymagaWpisuWChangelogTest`; `bramka-wersji.sh` sprzeczna z obecnym modelem |
| `claude/priorytet-w-kolejce-moderacji`, `flota/1139-dokumentacja-priorytetu` | 5% / 0% | PR #1139 nie był scalony; praca weszła przez PR #1284; **ich D-070 koliduje** z innym D-070 na INT |
| `robota/bazy-stanowisk` | 57% | zastąpione przez `naprawa/jedna-regula-nazw-baz` (scalona) |
| `gemini/dziennik-wgladow-moderatora` | 2% | bezprzedmiotowe: skrót moderatora usunięty (#1360, `DostepDoZdjecia.php:259`) |
| `gpt-openai-granice`, `gpt-moderacja-ai`, `gpt-pwa-push`, `gpt-zdjecia-limity`, `gpt-rozbicie-uslug` | 0–17% | kod stanowisk odzyskanych po awarii 09-20: #909/#911/#912/#826–#828 zamknięte, #829/#830 robi nowszy PR #1548; Web Push z D-303 na main (76010400f); #871/#872/#874 przez 4c267174f (PR #1195 nie był scalony); role Railway przez PR #1459 |
| `straznik-format` | 1% | 6 testów powiadomień do zamkniętych #759/#771/#907; niska wartość |
| `claude/new-session-cylbus` | 0% | opinia do #1781; decyzja zapadła (D-275), research jest na INT (`docs/research/PREFERENCJE_TRESCI.md`) |
| `naprawa/postpolicy-widocznosc-2209` | 0% (sam test) | PR #1108 nie był scalony; bramkę zapowiedzi ma main (3a0c40108, `DopiszPrzepisDoWpisuTest`) |
| `flota/retencja-wyjatkow-audytu` | 2% | ADR_RETENCJE §5.1 rozstrzygnięty na INT (średnia pewność: rzucić okiem przed skasowaniem) |
| `claude/801-804-komunikaty-js` | 0% (sam test JS) | #804 zamknięte, konflikt `package.json`; wartość niska |
| `claude/372-jsonld-odpowiedzi` | 1% | zawarta w `claude/372-pytania-link-tagu` (#1780) i `claude/kopia-1780` → skasować po scaleniu #1780 |
| `claude/1751-forma-decyzja`, `claude/1752-forma-ustawienie` | 1–2% | zawarte w `claude/1753-forma-teksty` (#1759) → skasować po decyzji o #1759 |
| `claude/1997-zakresy-czasu` | 1% | zawarta w `claude/kopia-1997` (w przeglądzie) |
| `alarm/kanal-mailowy-599` | 0% | patrz 1B (kasować dopiero po decyzji) |

`claude/laughing-edison-sz4k69`: kod jest wchłonięty, ale plik `docs/AUDYT_2026-09-13.md` istnieje tylko na tej gałęzi, a odwołuje się do niego `docs/DECISIONS.md:15697` na INT. Najpierw uratować plik (1C), potem skasować gałąź.

### 1B. Wartościowa praca, która nie weszła

| Gałąź | Issue | Co z nią zrobić |
|---|---|---|
| `alarm/kanal-mailowy-599` (09-22, 1080 wierszy: `EmailBleduHandler`, `KanalyAlarmowe`, `TrescAlarmu`) | #599 P0 | INT ma tylko kanał webhook (`WebhookBleduHandler`). **Pytanie do właściciela:** czy mail ma być drugim kanałem alarmów. Jeśli tak, nowa sesja na bazie INT (konflikt w `config/logging.php`); gałąź posłuży za wzór. |
| `gpt-dr-zdjecia` (DR_ZDJEC_617_602.md, test MinIO, `scripts/proba-dr-zdjec.py`) | #617 P1 | Konflikt z INT. Odzyskać dokument i skrypt jako PR `Refs #617`; kroki w panelu R2 (wersjonowanie, blokada) zostają u właściciela. |
| `claude/1387-kreator-krok3` (`App\Livewire\Forms\PrzepisForm`) | #1387 P2 | Konflikt z INT, bo kreator zmieniał się potem (#1642). Nowa sesja od INT, gałąź jako wzór. |
| `feat/retencja-wersji-przepisu` (`kuking:sprzataj-wersje-przepisow`, 09-20) | brak issue | Na INT nie ma retencji `recipe_versions`. **Pytanie:** czy wersje przepisu mają mieć termin? To koliduje z #2024 (historia wersji, V2 „nie teraz”). Jeśli tak, założyć issue. |

### 1C. Raporty audytu `claude/audyt-raporty-*`

Na INT jest tylko `docs/audyt/2026-09-25-A4-porzadek-repo.md` i raporty „PO-FALI”. Pozostałych **14 raportów nie ma na INT**: A1, A2, A3, A5, B1–B10 (`docs/audyt/2026-09-25-*.md`). Każda gałąź dodaje tylko jeden nowy plik i łączy się z INT bez konfliktu. `docs/DECISIONS.md:17506` na INT cytuje `docs/audyt/2026-09-25-B1.md`, którego nie ma.
→ **Jeden PR z samymi dokumentami: 14 raportów + `docs/AUDYT_2026-09-13.md` z `claude/laughing-edison-sz4k69`.** Potem skasować 15 gałęzi (dla A2 i B7 wziąć ostatni commit, bo mają poprawki).

---

## 2. Stare czerwone PR-y bez kopii (19)

„Anulowane” oznacza, że joby zostały anulowane (runner albo nowszy przebieg), a nie że oblały. Logi z `/actions/jobs/{id}/logs` przejrzano tam, gdzie konkluzja brzmiała `failure`.

| PR | Issue (stan) | CI i przyczyna | Na INT? | Praca | Rekomendacja |
|---|---|---|---|---|---|
| #960 draft `flota/martwe-kaskady` | — (D-223 już na INT) | zielone na starej bazie (09-27) | D-223 tak, strażnik i dowody nie (1%) | mała: merge INT + CI | **PYTANIE** (to był „nr 1 planu” floty lokalnej). Jeśli nadal potrzebne: zdjąć draft, merge INT, CI |
| #966 draft `flota/scal-786` | #786 zamknięte | zielone na starej bazie | 1%; kontrakt nazw baz żyje w `tests/bootstrap.php` | duża: 5 konfliktów (ci.yml, package.json, check.sh, galeria-orientacje.mjs, kontrole) | **PYTANIE**, rekomendacja ZAMKNĄĆ (bezpiecznik `.mjs` dla floty lokalnej, 2657 commitów za INT) |
| #1478 | — | anulowane | 0% | duża: skrypt kontroli urósł do 1562 wierszy | **ZAMKNĄĆ** i zlecić podział skryptem od nowa na INT w cichym oknie (to główne źródło konfliktów) |
| #1511 | #1011 P2 otwarte | **czerwone z kodu PR-a**: `NameError: name 'os' is not defined` (`kontrole-negatywne-alfa08.py:31`) | 0% | zależy od #1478 | **ZAMKNĄĆ** razem z #1478; #1011 po nowym podziale |
| #1531 | #875/#817 zamknięte (Refs) | anulowane | 0%: INT ma tylko `Str::upper(trim())` (`TwoFactorAuthenticator.php:371,407`) | mała: CHANGELOG → „Nieopublikowane”, merge INT, CI | **NAPRAWIĆ** (kod zapasowy ze spacją lub bez myślnika pomaga osobom 50+) |
| #1548 | #829/#830 P2 otwarte | anulowane (Port marki) | 1% | średnio-duża: CHANGELOG, DECISIONS (numer D); `AlarmujModeratora` zmieniony przez #2132/#2186; bloker z opisu: `DolozDoOznaczenia` ustawia `alarm_pilny_stan=ZALEGLY` + test | **NAPRAWIĆ** |
| #1598 | #1032 (bez etykiety), #1280 P3 | anulowane | 1% (`SitemapController.php:146` nadal `profile->updated_at`) | mała: CHANGELOG | **NAPRAWIĆ** |
| #1617 | **#1324 P1** otwarte | anulowane | 2% (`EraseAccountData` nie rusza `product_signals`) | mała do średniej: CHANGELOG, EraseAccountData, kontrole; `wersja_polityki` koliduje z #1681/#1725/#1879 | **NAPRAWIĆ** (P1) |
| #1674 | — (A4-5.1) | anulowane tylko joby CodeQL | 1% | mała: check.sh i kontrole; lista skryptów powłoki urosła | **NAPRAWIĆ** |
| #1681 | **#619 P0** | anulowane (Port marki) | 8%: zdanie w polityce już jest (#1499); brakuje §0 w `LOKALIZACJA_DANYCH_R2.md` i testu | mała | **NAPRAWIĆ** (zostawić §0 i test) + PYTANIE: nazwa bucketu do §5 |
| #1698 | — (audyt B8-02) | anulowane tylko joby CodeQL | 3% | średnia: `AlarmujModeratora`/`DoslijPilneAlarmy` zmienione przez #2132/#2169/#2186 | **NAPRAWIĆ**; założyć issue B8-02 |
| #1744 | — (decyzja 25.09) | zielone | 0%; INT ma od podziału ~100 nowych decyzji | duża | **PYTANIE**: zamknąć i wygenerować podział skryptem od nowa, gdy w kolejce nie będzie PR-ów z decyzjami |
| #1759 | #1751–#1753 P2 | kontrole negatywne anulowane → zbiorczy job „Testy” czerwony | 1% | duża: 5 konfliktów, migracja `2026_09_25_140000`, DATABASE.md | **NAPRAWIĆ** po potwierdzeniu, czy „pytanie do prawnika” z D-268 jest zamknięte |
| #1823 | #1806 P0 | czerwone przez ówczesny main (`KonsolaBezKomunikatuWyjatkuTest`, to samo w #1826/#1830) | **96%**: D-275 na main (`AGENTS.md:506`, 407c04ed4) | — | **ZAMKNĄĆ** (wchłonięty); zamknąć #1806 z dowodem |
| #1826 | — (audyt D) | jak wyżej | 7% | mała (14 wierszy, 1 plik) | **NAPRAWIĆ** albo włączyć do nowego podziału kontroli |
| #1830 | #1807 P1 | jak wyżej | **97%**: `DiscoverFeed.php:83` `row_number() OVER (PARTITION BY author_id)`, D-276, `pusty-stan-odkrywania` na main | — | **ZAMKNĄĆ**; zamknąć #1807 |
| #1885 | #1743 P2 | **czerwone z kodu PR-a**: Pint (5 plików); 11 testów (`ZamekParyObejmujeBlokowanieTest`×3, `AutoryzacjaTrasZWiazaniemModelu`, `KazdaTrasaZIdentyfikatoremPodPolicy`, `EksportWygladObietnicePaczki`, `PaczkaNieObiecujeCudzychPrzepisow`, graf modułów bez cykli, kontrola dodatnia strażnika tekstu, skrypty kopii); kontrola ujemna „Zapis przepisu do cudzego zeszytu” nie pasuje do `SaveRecipeToCollection` | 2% | duża: co najmniej 12 blokerów, 6 konfliktów, 2 migracje, D-302 | **NAPRAWIĆ** na bazie `claude/kopia-2077` (zawiera #1885, #2077 i merge main; nie ma jej na liście recenzentów) |
| #1887 | brak issue (D-285); zależne #1958 i #1969 | kontrole negatywne anulowane → zbiorczy job czerwony | 1% | średnia: 5 konfliktów, migracja `pantry_items` `2026_09_26_120000` | **NAPRAWIĆ przez `claude/1958-spizarnia-limit-dwa-polaczenia`** (zawiera `v2-pantry`, jest w przeglądzie); nie naprawiać dwa razy, #1887 zastąpić |
| #2142 draft | #2014 P2 | zielone; baza `integracja-nastepna` | 0% | mała: #1899 już scalony, trzeba przestawić bazę na INT; konflikt w `PublishRecipe.php` | **DUBEL**: `claude/kopia-2014` zawiera #2142; `codex/2014-date-modified-jsonld` ma inną implementację (26 wierszy). Rekomendacja: kopia-2014 → #2142, codex/2014 skasować. Styk z #2189 |

---

## 3. PR-y fali 8 i ich kopie

### 3.1 Relacja kopii do PR-a

Wszystkie 13 kopii to **fast-forward heada PR-a** (FF=True), stoją 35 commitów za INT i **łączą się z INT bez konfliktu** (`git merge-tree`). „Dodaje” oznacza commity spoza heada PR-a i spoza INT.

| PR | Kopia | Co kopia dodaje ponad head PR-a | Diff INT…kopia | Wpisy CL usunięte |
|---|---|---|---|---|
| #1533 | 700e0a509 | merge main + test „gość otwiera publiczny zeszyt” (#965) | 12 plików +296/−34 | 2 |
| #1567 | d599bd18b | merge main (TagFeed → FollowingFeed) | 4 pliki +261 | 1 |
| #1603 | 2e9dbd951 | merge main (package.json) | 7 plików +323 | 2 |
| #1608 | e397b52ed | merge main (D-260, kotwice kontroli) | 12 plików +287 | 1 |
| #1621 | 1341b2477 | merge + test zepsutego id zachowanego zdjęcia (#1572) | 7 plików +296 | 1 |
| #1627 | f99b7f0d3 | merge + 2: tryb ścisły dla kodu od 26.09 i `actingAs` przez PDO | 36 plików +510 | 1 |
| #1629 | 480a0b62f | merge main | 13 plików +779 | 1 |
| #1725 | edb087a2c | merge (polityka: R2 w UE + 3 zastosowania R2; mutacja w commicie scalenia) | 8 plików +167 | 1 |
| #1771 | 956ba6222 | merge main | 3 pliki +273 | 1 |
| #1780 | dc5ec28ae | merge + poprawka testu pod PHPStan | 60 plików +3643 | 7 |
| #1849 | 76b8a8531 | merge main (CelPowiadomienia) | 12 plików +713 | 1 do przywrócenia (+2 już na INT) |
| #1872 | 4209509e4 | merge (CHANGELOG bez 33 zdublowanych wpisów) | 3 pliki +119/−33 | 0 (to jest PR porządkujący CHANGELOG, więc zweryfikować, że 33 usunięte to faktycznie duble) |
| #1879 | 9ebd1b812 | merge + „Zgoda odczyt AI zapisuje wersję polityki (D-327)” | 52 pliki +2590 | 4 do przywrócenia (+2 już na INT) |

**Konflikty między kopiami** (`merge-tree` parami): 1567×1879 `pages/home.blade.php`; 1603×1629 `package.json` i kontrole; 1608×1629 kontrole; 1627×1780 `AppServiceProvider.php`; 1849×1879 kontrole. #1879 zawiera 4 commity z #1814 (#1849), ale nie ma dwóch późniejszych poprawek #1849 (3a3c5746f, e21942d79). Obie kopie mają **D-283**, więc najpierw #1849, potem #1879.

### 3.2 Wiersze CHANGELOG do przywrócenia pod „## Nieopublikowane” (dokładne brzmienie)

Wpisy „Smakowicie wygląda” i „Ukryj ten wpis” z #1849 i #1879 **są już na INT** (wydane), więc ich nie przywracać. Wiersz „Metryki doboru” występuje w #1849 i #1879; wpisać go raz. Żaden wiersz nie ma dopisku `[nowa funkcja]`. Jeśli koordynator go doda (np. #1533, #1629, #1780, #1879), `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest` wymaga akapitu w `resources/nowosci/tresc.md`.

**#1533** (2)

```text
- Zeszyt ustawiony na „Wszyscy” otwiera się teraz także bez konta — można wysłać link rodzinie. Osoba niezalogowana widzi w nim tylko publiczne przepisy i wpisy, a zamiast przycisków zapisu dostaje „Zaloguj się” albo „Załóż konto”. Zeszyt ustawiony na „Tylko ja” (także domyślny, dopóki nie zmienisz go na „Wszyscy”) nadal widzi tylko właściciel.
- Wyniki wyszukiwania nie są już blokowane w robots.txt, dzięki czemu wyszukiwarka może odczytać, że nie należy ich indeksować.
```

**#1567** (1)

```text
- Jeśli jeszcze nikogo nie obserwujecie, na Starcie widzicie teraz także własne opublikowane wpisy — również te „tylko dla obserwujących” — razem z wpisami z Waszych tagów albo ze „Świeżo z Kuking”, w kolejności od najnowszych. W „Świeżo z Kuking” Wasze wpisy podlegają tej samej regule co wpisy każdej innej osoby — najpierw stoi Wasz najnowszy wpis, obok najnowszych wpisów innych. Nagłówek mówi wtedy, że to Wasze wpisy i wpisy innych. Inne osoby nadal nie widzą wpisu „tylko dla obserwujących”, jeśli Was nie obserwują (#1318).
```

**#1603** (2)

```text
- Na szerokim ekranie pierwsze, największe zdjęcie kolażu na stronie powitalnej zaczyna się wczytywać od razu, a nie dopiero po ułożeniu strony. Pozostałe zdjęcia kolażu wczytują się jak dotąd (#957).
- Automat wydajności oblewa ekran, na którym największy element wczytuje się dłużej niż 4 sekundy, nawet gdy łączny wynik wydajności jest wysoki. Raport pokazuje przy każdym ekranie także odległość do celu 2,5 s (#1029).
```

**#1608** (1)

```text
- Na telefonach z wycięciem ekranu (np. iPhone) i w aplikacji dodanej do ekranu głównego tło strony sięga do krawędzi, a górny pasek, dolna nawigacja i przycisk wyglądu nie wchodzą pod pasek stanu, wycięcie ani wskaźnik Home — także w poziomie (#987).
```

**#1621** (1)

```text
- Czytnik ekranu przy polu wyboru zdjęcia odczytuje teraz także komunikat błędu, a nie tylko podpowiedź — we wpisie, pytaniu, „Ugotowałem”, formularzach przepisu i w kreatorze. Pole ze złym plikiem jest oznaczone jako wymagające poprawy (#1572).
```

**#1627** (1)

```text
- Pod spodem, bez zmian na ekranie: na serwerze próbnym (staging) przeoczenia w dostępie do danych — dociąganie powiązanych danych po jednym, odczyt niepobranej kolumny i pole odrzucone przy zapisie — trafiają do dziennika jako ostrzeżenie, zamiast przechodzić bez śladu. Strona działa przy tym tak samo jak dotąd; na serwerze produkcyjnym nic się nie zmienia (#976).
```

**#1629** (1)

```text
- Przepis wydrukowany z przeglądarki (Ctrl+P) mieści się czytelnie na kartkach A4: zostają tytuł, autor, adres przepisu, porcje, składniki z grupami i uwagami, wszystkie kroki i „Skąd ten przepis”. Na papier nie idą już menu, górna i dolna belka, przyciski ani komentarze, motyw ciemny drukuje się czarnym na białym, a krok nie przełamuje się między stronami. Długi przepis zajmuje 4 strony zamiast 8–9, a żaden napis na kartce nie jest mniejszy niż 12 punktów. Przy przepisie jest też przycisk „Drukuj przepis”, który od razu otwiera okno drukowania; gdy przeglądarka nie wczyta skryptu, ten sam przycisk pokazuje, jakie klawisze nacisnąć albo co wybrać w menu telefonu (#765).
```

**#1725** (1)

```text
- Polityka prywatności opisuje teraz sesję logowania: zapisujemy zgrubny adres IP i to, jak przedstawia się przeglądarka, do 30 dni od ostatniej aktywności. Mówi też, że w magazynie Cloudflare R2 oprócz zdjęć leżą paczki z Waszymi danymi (najwyżej 7 dni) i zaszyfrowane kopie bazy (najwyżej 30 dni). Z wpisów, które na stałe dokumentują usunięcie konta, skrót adresu IP znika po 12 miesiącach.
```

**#1771** (1)

```text
- Dziennik serwera zapisuje przy każdym wejściu ten sam adres, którego serwis używa do limitów prób i śladu w dzienniku zdarzeń. Wcześniej serwer brał adres z części nagłówka, którą może wpisać sam odwiedzający, więc ślad po nadużyciu mógł prowadzić pod cudzy adres (#1306).
```

**#1780** (7)

```text
- „Czeka na odpowiedź (N)” na `/pytania` nie jest już liczone od zera przy każdym wejściu. Liczba dla gości jest przeliczana w tle, po każdej odpowiedzi i zmianie pytania oraz co 5 minut, a zalogowanej osobie doliczamy dokładną poprawkę: pytania i odpowiedzi osób w blokadzie oraz pytania „dla obserwujących”. Licznik pokazuje dokładnie tyle, ile jest na liście „Czeka na odpowiedź”. W pomiarze na 200 000 wpisów koszt odsłony spadł ze 95–150 ms do ułamka milisekundy dla gościa i do ok. 13 ms dla zalogowanej osoby (#372).
- Dopisek autora pod własnym pytaniem nie liczy się już jako odpowiedź na liście „Poradźcie”, w liczbie odpowiedzi ani w „Czeka na odpowiedź”. Pytanie zostaje w oczekujących, dopóki nie odpowie ktoś inny — tak jak w kolejce gospodarza (#372).
- Dane strukturalne `QAPage` na stronie pytania (`answerCount`, `suggestedAnswer`) też już nie liczą dopisku autora pod własnym pytaniem jako odpowiedzi — te same reguły, co na liście „Poradźcie” i w kolejce gospodarza (#372).
- Lista „Poradźcie” i licznik „Czeka na odpowiedź” mają indeks częściowy na opublikowanych pytaniach (`posts_questions_published_idx`). Pomiar przed włączeniem działu (200 000 wpisów, 5% pytań) pokazał pełny przegląd tabeli wpisów przy każdym wejściu na `/pytania`; z indeksem licznik dla zalogowanej osoby spadł z ok. 450 do ok. 120 ms. Skrypt pomiaru (`scripts/pomiar-pytan-372.py`), wyniki i instrukcja włączenia oraz wyłączenia działu: `docs/product/WLACZENIE_PYTAN_372.md`. (#372).
- Panel „Bez odpowiedzi”: mediana czasu oczekiwania na odpowiedź w zakładce „Wpisy” liczy już tylko dania. Wcześniej wliczały się do niej pytania, więc szybko obsłużone pytanie zaniżało czas reakcji na wpisy. Zakładka „Pytania” pokazuje własną medianę — liczoną do pierwszej głównej odpowiedzi innej osoby, bez dopisków pod cudzym komentarzem (#372).
- Panel „Bez odpowiedzi”: pytanie bez odpowiedzi stoi już tylko w zakładce „Pytania”, a nie jednocześnie w „Wpisach”. Powiadomienie gospodarza o pierwszej publikacji nowej osoby nadal przychodzi raz na osobę, ale gdy tą publikacją jest pytanie, mówi „pierwsze pytanie w Kuking” i prowadzi do zakładki „Pytania”. Gdy dział pytań jest wyłączony, takie powiadomienie nie ma przycisku „Zobacz”, zamiast prowadzić do nieistniejącej strony (#372).
- Pod pytaniem w „Poradźcie” jest teraz sekcja „Inne pytania na ten temat” z odnośnikami „Pytania: <tag>”. Prowadzą do listy pytań z tym tagiem (`/pytania?tag=…`), a nie do ogólnej strony tagu z daniami. Na liście tag zostaje widoczny, można go zdjąć odnośnikiem „Pokaż wszystkie tagi”, a „Czeka na odpowiedź” zawęża pytania bez gubienia tagu (#372).
```

**#1849** (1)

```text
- W panelu (tylko dla admina) jest ekran „Metryki doboru”: ile pierwszych wpisów dostało odpowiedź w ciągu doby, ile osób publikuje ponownie, ilu różnych autorów pisze dziennie, jaką część wpisów piszą najaktywniejsi, ile wpisów ma tag i ilu autorów praktycznie nie trafia na pierwszą stronę „Świeżo z Kuking”. Same liczby — bez nazw osób i bez śledzenia, kto co oglądał (#1814).
```

**#1879** (3)

```text
- Nowa strona „Jak dobieramy wpisy” opisuje każdą listę w serwisie: Start, „Świeżo z Kuking”, tablicę na dziś, wyszukiwarkę i tygodniowy e-mail — oraz czego nie robimy (nie układamy wpisów według popularności ani reakcji i nie uczymy się Waszego gustu z tego, co oglądacie). Pod nagłówkiem „Świeżo z Kuking” stoi odnośnik „Skąd te wpisy i jak to zmienić”, a gdy coś ukrywacie — „Ukrywasz wpisy N osób. Zmień”. Pozycje na tablicy wybrane przez gospodarza mają napis „Wybór gospodarza” (#1811).
- Gdy zmieniamy politykę prywatności albo regulamin w sposób istotny, nowa wersja obowiązuje 14 dni po opublikowaniu, a do tego dnia obowiązuje poprzednia. Pasek o zmianie regulaminu mówi wtedy, od kiedy obowiązuje nowa wersja. Drobne poprawki, które nie zmieniają Waszych praw ani obowiązków, obowiązują od razu. Zgoda na e-maile zapisuje wersję polityki, która obowiązuje w chwili zgody (D-327).
- Zmieniliśmy regulamin: w punkcie 2 jest opis doboru wpisów i odnośnik do nowej strony. Po zalogowaniu zobaczycie raz pasek „Zmieniliśmy regulamin” z odnośnikiem do tego, co się zmieniło; przycisk „Zamknij” chowa go na dobre. Nie wysyłamy o tym e-maili (#1811).
```

(Wiersz „Metryki doboru” jest raz pod #1849. #1879 też go dodaje; przy scalaniu #1879 po #1849 nie dublować.)

---

## 4. Issues (194 otwarte, pobrane z paginacją)

| Grupa | Liczba |
|---|---|
| P0 | 11 |
| P1 | 53 |
| P2 | 89 |
| P3 | 15 |
| bez etykiety priorytetu | 26 (w tym 5 z etykietą V2) |

### 4.1 P0 (11): żadne nie nadaje się dziś wprost dla subagenta

| # | Stan | Kto |
|---|---|---|
| #8 prawnik, #15 testy z ludźmi 50+, #29 cold start | poza kodem | właściciel |
| #120 bramka R2 na prawdziwych bucketach, #594 zrzut i próba odtworzenia bazy (PR #1725 dotyka), #595 `railway config apply`, #597 reguła cache Cloudflare, #598 budżet połączeń PG, #599 monitoring i zewnętrzny uptime (dużo kodu na main; zostają panel i ewentualny kanał mail z 1B) | produkcja i panele | właściciel/operator (bez zgody nie ruszać) |
| #619 R2 EU | PR #1681 do naprawy + nazwa bucketu od właściciela | PR + właściciel |
| #1806 reguła doboru | **zrobione na main** (D-275, `AGENTS.md:506`) | zamknąć z dowodem, zamknąć PR #1823 |

### 4.2 P1 (53): stan

| Stan | Issues |
|---|---|
| **Zrobione na main, zamknąć z dowodem** (PR miał `Closes`, ale bazę integracyjną) | #836 (PR #1524, `PageContext`/`KontekstKontaktuBezSekretowTest`), #1046 (PR #1500, `SesjaPoUniewaznieniuNieWracaTest`), #1974 #1977 #1980 (c7f4674a8 „Closes #1973, #1974, #1977, #1980”, PR #1898), #2033 (PR #2162), #2066 (PR #2157/#2132, `PilnyAlarmDwaPolaczeniaTest`) |
| **Zrobione na main, weryfikacja przed zamknięciem** (PR z `Refs`) | #994 (PR #1499, polityka l. 36), #1746 i #1747 (PR #1799, `Visibility/PowiadomieniaZgodneZPolicyTest`), #1807 (DiscoverFeed row_number, D-276), #1808 (FollowingFeed z tagami, 417755500), #1809 (PR #1832, `post-card.blade.php:268-274`), #1810 (ukrycia na main), #1813 (`SmakowicieController`, D-280, `SmakowicieWygladaTest`), #1927 (dubel #1851, `deploy.yml:256` przez env), #2048 (PR #2185, cf7f90bab), #2061 (554c75ab9, 2548aee5b), #2064 (PR #2182, `PonowienieOdczytuLiczySieRazTest`) |
| **Zrobione tylko na INT** (zamknąć po INT→main) | #1993 (PR #2164), #2026 (PR #2155) |
| **Ma PR albo gałąź** | #1324 (PR #1617), #1811 (#1879/kopia), #1814 (#1849/kopia), #1952 (#2148), #2013 (kopia, w przeglądzie), #2017 (#2163), #2031 (kopia, w przeglądzie), #2038 (#2145), #2044 (**`claude/2044-migracje-przed-workerem`, bez PR-a i bez recenzenta**), #2057 (#2174), #2071 (#2156), #2130 (kopia-2130 + `claude/2130-import-uciete-pliki` + codex; dubel), #2178 (#2188) |
| **Zablokowane na właścicielu** | #1895 (sekrety i panele), #1925 (reviewerzy środowiska production), #2025 (bramka CI→Railway nieaktywna: brak `KUKING_CI_GATED_RAILWAY_DEPLOY` i tokenu), #2051 (retencja livewire-tmp w panelu R2; runbook 2cf23799a jest), #35 (kod z D-303 jest, uruchomienie kanału i VAPID), #119, #193, #600, #601, #604, #610 (infra i produkcja), #27 (lista zakupów; D-310 wdrożył planer, `AGENTS.md` §12 do poprawy), #22 (grupy: czy zostaje P1?) |
| **Wolne dla subagenta** | #2086 (ponownie otwarte), #2189, #1816, #581, #617, #605 (niska gotowość) |

### 4.3 Kolejka pracy dla subagentów (bez gałęzi i PR-a, niezrobione, bez blokady właściciela)

| Kol. | Issue | Zakres (1 zdanie) | Znane styki |
|---|---|---|---|
| 1 | **#2086** P1 | Objąć `RozstrzygnijZgloszenie` i `RestoreContent` (także `ModerationController::restore`) wspólnym `ZamekUprzywilejowanegoAktora`, z testem degradacji na dwóch połączeniach. | #1548 i #1698 (moderacja), #2190 |
| 2 | **#2189** P1 | `PublishRecipe`: sprawdzać zatwierdzoną sankcję autora pod blokadą, przed zapisem. | `PublishRecipe.php`: #2142, `claude/kopia-2014`, `codex/2014-*` |
| 3 | **#2190** (proponuję P1) | `DeleteComment`: nie usuwać cudzej wypowiedzi, gdy sankcja wykonawcy zatwierdziła się w trakcie. | jak #2086 (ta sama klasa błędu) |
| 4 | **#1816** P1 | Polityka prywatności: paczka danych, obserwowane tagi, ukrycia, reakcje; **jedno** podbicie `wersja_polityki`. | robić po #1617, #1681, #1725 i #1879 (wszystkie ruszają wersję polityki) albo połączyć z nimi |
| 5 | **#581** P1 | Dokończyć port kompozycji panelu moderacji do marki. | #1548, #1698, #1885 (widoki panelu) |
| 6 | **#617** P1 | Odzyskać z `gpt-dr-zdjecia` procedurę DR zdjęć i skrypt próby na bazie INT (kroki w panelu R2 zostają u właściciela). | #120, #1895 |
| 7 | #1944 P2 | Regresja #947: przycisk „Zapisz” znika przy klawiaturze na telefonie. | formularze z #1621 |
| 8 | #2028 P2 | Gość zapisuje przepis do zeszytu po rejestracji (zachować intencję). | `codex/2058-zamiar-ugotowania` (ten sam mechanizm intencji), #2027 na INT |
| 9 | #2154 (proponuję P2) | Strażnik dziennika decyzji: odrzucać roboczy D-1009 i martwe odsyłacze (D-235). | #1744 (podział decyzji) |
| 10 | #2149 P2 | Rozcinać etapami cykl sześciu modułów domenowych. | test grafu modułów (czerwony w #1885) |
| 11 | #1011 P2 | Kontrola ujemna czerwona tylko z oczekiwanej przyczyny, po nowym podziale skryptu kontroli. | #1478, #1511, #1826 |
| 12 | #1387 P2 | Wydzielić `PrzepisForm` z kreatora od INT (`claude/1387-kreator-krok3` jako wzór). | kreator (#1621, #2050) |
| 13 | #1969 P2 | Pantry: rdzeń „mąka” a produkt „mak”, fałszywe dopasowania. | dopiero po scaleniu `claude/1958`/#1887 |

Poza kolejką (meta, pomiar albo etapy w toku): #492, #602, #611 (styk #1674), #614, #713, #970, #1015, #1045, #1687, #1731, #1860.

### 4.4 Otwarte issues zrobione na main albo INT: do zamknięcia po weryfikacji (poza P1 z §4.2)

- **P2:** #684 (PR #1653 w fali A), #769 (891fecc01), #871, #872 i #874 (4c267174f), #934 (73e50be97), #946 i #988 (7648b7570), #975 (c08a554fc), #982 (d8b7ee7ea), #984 (c0d5a840e), #1023 (7b0a11c58), #1037 (ee268ac33), #1289, #1400 (9e79f134c), #1748 (e8262d3ba), #1754 (752fd6253), #1755, #1812 (PR #1834), #1851 (e25f22125), #1909 (8e15161c3), #1932 (e549e420a), #2008 (PR #2015), #2056 (ea78ef8dc), #2069 (d4a846245), #2135 (045947422); na INT: #2027 (5b9d81373).
- **Bez etykiety:** #1050 (26da87a99), #1657 (bb00a6cd8), #1740 (e77989bb4), #1741 (1c8936482), #1742 (workflow usunięty), #1749 (Mój stół, D-304), #1805 (a949f6aed), #1824 (67b1e6d22), #2165 (d67e7c4cb); na INT: #2167 (eaec101e4), #2169 (PR #2186).

### 4.5 Propozycje priorytetu dla issues bez etykiety

| Issue | Propozycja |
|---|---|
| #2190 DeleteComment po sankcji | **P1** (bezpieczeństwo i współbieżność, jak #2086/#2189) |
| #1032 sitemapa, huby | P2 (PR #1598) |
| #1572 a11y błędu zdjęć | P2 (PR #1621) |
| #1804 `posts.kind` w AGENTS/Copilot | P2 (docs, kopia-1804 w przeglądzie) |
| #2112 przełącznik wartości odżywczych po zdjęciu przepisu | P2 (codex w przeglądzie) |
| #2154 dziennik decyzji | P2 |
| #1029 bramka LCP | P3 (razem z #957, PR #1603) |
| #2083 docs sezonowość | P3 (PR #2158) |
| #1983 AI zamienniki, #1985 import własnej paczki | P3 / V2 (FEATURES: „podpowiedzi AI — jeszcze nie”) |
| #1902, #1903, #1904, #1906, #2067 | bez P, tylko **V2 „nie teraz”** (FEATURES.md) |
| #1050, #1657, #1740, #1741, #1742, #1749, #1805, #1824, #2165, #2167, #2169 | bez priorytetu: **zamknąć** (§4.4) |

---

## 5. Ryzyka

**Duble i nakładki**
- **#2014:** PR #2142 (draft, nowa kolumna `tresc_zmieniona_at`) ⊂ `claude/kopia-2014`, do tego `codex/2014-date-modified-jsonld` (inna, 26-wierszowa implementacja). `claude/1991-puste-skladniki-podglad` zawiera merge `kopia-2014` przez lokalną „paczka-b”. Wybrać kopię, resztę skasować.
- **#1991:** `codex/1991-*` (w przeglądzie) i `claude/1991-puste-skladniki-podglad` (spoza listy, niesie merge'e paczki B: kopia-2014, 2030, 2057, 2073). **Tej drugiej nie scalać.**
- **#2042:** `codex/2042-*` i `claude/2042-audyt-blednego-2fa`. **#2130:** `claude/kopia-2130`, `claude/2130-import-uciete-pliki` i `codex/2130-kontrola-ujemna` (już scalona).
- **#2066:** naprawa scalona (#2157/#2132); `codex/2066-urgent-alert-negative` w przeglądzie jest prawdopodobnie zbędna.
- **#1807/#1806:** PR-y #1830/#1823 są wchłonięte przez main. **#1849 ⊂ #1879** częściowo (D-283 w obu). **#1887 ⊂ `claude/1958`**, **#1885 ⊂ `claude/kopia-2077`**, **#1759 ⊃ 1751/1752**, **#1780 ⊃ 372-jsonld**, **kopia-1997 ⊃ claude/1997**.
- Gałęzie z pracą spoza list: `claude/2044-migracje-przed-workerem` (#2044 P1), `claude/kopia-2077`, `claude/2130-import-uciete-pliki`, `claude/2042-audyt-blednego-2fa`, `claude/1991-puste-skladniki-podglad`.

**Numery decyzji**
- **D-070:** `claude/priorytet-w-kolejce-moderacji` i `flota/1139-*` mają inną decyzję niż D-070 na INT (skasować gałęzie).
- **D-283:** w kopia-1849 i kopia-1879. **D-302:** w `claude/1743` i `kopia-2077`. **D-285:** w `v2-pantry` i `claude/1958`. **D-268:** w 1751/1752/1753 (jedna rodzina, tylko pilnować jednej wersji).
- **D-305, D-306, D-327:** tylko w kopia-1879. Na INT ich nie ma, choć maksimum na INT to D-328; przed scaleniem sprawdzić, czy nikt ich nie zajął. D-228/D-243 (#966) są wolne. Na INT jest roboczy **D-1009** (#2154).
- `docs/DECISIONS.md:15697` i `:17506` odsyłają do plików, których nie ma (§1C).

**Migracje**
- `2026_09_26_120000_*` ×3 różne: `create_collection_sharing_tables` (#1885/kopia-2077), `add_terms_notice_dismissed_version_to_users` (#1879), `create_pantry_items_table` (#1887/1958). INT ma już podwójny `2026_09_26_110000` (ceny_skladnikow i post_reactions). **Nie ma strażnika unikalności znaczników.** Kolejność ustala nazwa pliku, więc przy scalaniu przenumerować.
- `2026_09_25_200000_add_questions_published_index_to_posts` (#1780) ma ten sam znacznik co `add_birthday_to_users` na INT.
- `2026_09_28_210000_add_tresc_zmieniona_at_to_recipes` jest w 3 gałęziach (#2142, kopia-2014, claude/1991).
- `claude/new-session-zpc41g-02`: dubel `add_notified_at_to_data_exports` z inną datą.

**Pliki dotykane przez wiele PR-ów** (kolejność sprawdzać `merge-tree`)
- `scripts/kontrole-negatywne-alfa08.py`: 16/49 PR-ów. Po fali warto zrobić podział katalogowy od nowa (zamiast #1478).
- `CHANGELOG.md` (17), `docs/DECISIONS.md` (6).
- Polityka prywatności i `wersja_polityki`: #1617, #1681, #1725, #1879 (D-327: data publikacji ≠ data wejścia w życie), #1816. Jedno podbicie na paczkę.
- `AlarmujModeratora` i `DoslijPilneAlarmy`: #1548, #1698 oraz #2186 już na INT.
- `PublishRecipe.php`: #2142, codex/2014, #2189.
- `CelPowiadomienia.php`: #1780, #1849, #1879, #1885.
- `EraseAccountData` i `CollectUserExportData`: #1617, #1759, #1885, #1887.
- `home.blade.php`, `DiscoverFeed`, `FeedController`: #1567, #1879, #2148.
- `package.json`: #1603, #1629, #966, #1887.
- `AppServiceProvider`: #1627, #1780.

**Proces i zakres**
- PR-y paczki B (#2145…#2176) i #2142 mają bazę `codex/integracja-nastepna-20260928`, nie INT.
- #1997 jest w przeglądzie, choć stoi na liście „V2, ale nie teraz”. #2000 i #1996 też tam są. Nie otwierać PR-ów bez decyzji właściciela.
- `AGENTS.md` §12 nadal mówi „planer posiłków”, a D-310 wdrożył Planer tygodnia (939b83d94), a `claude/kopia-2037` (#2037, planer) jest w przeglądzie. Rozjazd instrukcji jest do poprawy.
- #1872/kopia usuwa 33 wpisy CHANGELOG jako duble. Zweryfikować, że żaden nie jest jedynym wpisem swojej zmiany.
