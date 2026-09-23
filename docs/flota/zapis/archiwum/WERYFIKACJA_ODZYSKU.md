# Weryfikacja wielkiego odzysku (56 gałęzi) — 20 września 2026

Zakres: `origin/main` = `4c811cc7` (kanoniczny). Sprawdzono `git diff --stat origin/main..<gałąź>`
dla wszystkich 56 gałęzi bez ukośnika (`gpt-*`, `zeszyty`, `jedna-droga`, `naprawa-858`, `stan-sesji`
itd.) w `C:\Users\matma\Documents\Codex\kuking.pl`. Wyłącznie odczyt: `git diff`, `git show`, `git log`,
`git merge-base`, `git rev-parse`. Żadnej gałęzi nie zmieniono, nie scalono, nie pchnięto.

---

## 1. Gałęzie z podejrzanymi usunięciami w plikach współdzielonych

### 1a. Cztery pliki wskazane wprost w zadaniu

| Gałąź | Plik | + | − | Werdykt |
|---|---|---|---|---|
| **gpt-n1-powiadomienia** | `docs/DECISIONS.md` | 34 | 31 | **POTWIERDZONA SZKODA** — kasuje D-224 |
| **notyfikacja-zywa** | `docs/DECISIONS.md` | 34 | 31 | **POTWIERDZONA SZKODA** — kasuje D-224 (identyczna treść co wyżej) |
| gpt-moderacja-ai | `docs/DECISIONS.md` | 13 | 7 | OK — świadome zastąpienie własnego wcześniejszego akapitu („JEDNO ZADANIE, NIE DWA") nowym opisem tej samej funkcji (patrz 2a) |
| jedna-droga | `docs/DECISIONS.md` | 76 | 0 | OK — same dodania |
| gpt-cloudflare-cache / gpt-konto-poczta / gpt-openai-granice / gpt-skladniki / gpt-tagi-miejsce / gpt-wersje-przepisu | `docs/DECISIONS.md` | — | 0 | OK — same dodania |
| **zeszyty** | `resources/views/components/post-card.blade.php` | 40 | 56 | **POTWIERDZONA SZKODA** — cofa D-224/#789 poza widokiem zeszytu |
| jedna-droga | `post-card.blade.php` | 58 | 13 | OK — rozszerza przycisk „Usuń z zeszytu" o zakres globalny/lokalny, zachowuje zachowanie z #789 |
| gpt-zeszyt-droga | `post-card.blade.php` | 1 | 1 | OK — kosmetyka |
| jedna-droga / gpt-zeszyt-droga / zeszyty | `app/Http/Controllers/CollectionController.php` | różne | różne | OK — `catch (UniqueConstraintViolationException)` obecny we wszystkich trzech wersjach i w origin/main (2 wystąpienia w każdej), tylko przesunięty w pliku (patrz 2b) |
| gemini-794 / gpt-zalegle / gpt-zeszyt-droga / naprawa-858 / tagi-filtr | `resources/css/app.css` | 1–8 | **0** | OK — blok `.flash-powrot` (17 linii) nienaruszony w żadnej z 56 gałęzi; żadna z nich go nie kasuje |
| jedna-droga | `resources/views/components/layout.blade.php` | 8 | 0 | OK — same dodania |

**Wniosek 1a:** Dwie pary/gałęzie naprawdę kasują cudzą pracę:
1. `gpt-n1-powiadomienia` i `notyfikacja-zywa` — kasują wpis **D-224** w `docs/DECISIONS.md`.
2. `zeszyty` — cofa w kodzie skutek commita `4c811cc7` „Dodaj drogę wyjścia wpisu z zeszytu (audyt L1) (#789)” (czyli tego samego D-224), w widoku `post-card.blade.php` poza konkretnym zeszytem.

Obie sprawy dotyczą **tej samej decyzji D-224** — jedna kasuje jej zapis w dokumentacji, druga cofa jej
skutek w interfejsie. To nie przypadek: `notyfikacja-zywa`/`gpt-n1-powiadomienia` bazowały (branch point,
`git merge-base` = `origin/main` = `4c811cc7`) na repo, które D-224 już miało — więc to nie jest artefakt
starej podstawy, tylko realna nadpisana treść w skopiowanym pliku stanowiska. To samo dotyczy `zeszyty`
(merge-base też `4c811cc7`, czyli już PO #789).

Dowód dla (1) — identyczna zmiana w obu gałęziach, ten sam wynikowy blob pliku:
```
git diff origin/main..gpt-n1-powiadomienia -- docs/DECISIONS.md
git diff origin/main..notyfikacja-zywa      -- docs/DECISIONS.md
```
obie kasują cały akapit „## D-224 — Wpis wychodzi z zeszytu tam, gdzie widać..." i wstawiają w tym samym
miejscu nowy „## D-223 — Powiadomienie śledzi treść komentarza (#758)”. Plik wynikowy jest identyczny
(`0fafc65b`) w obu gałęziach — to prawie na pewno **dwa stanowiska wykonujące ten sam kawałek pracy**
(patrz sekcja 3, para gpt-n1-powiadomienia/notyfikacja-zywa).

Dowód dla (2):
```
git merge-base origin/main zeszyty   → 4c811cc7 (= origin/main, zawiera już #789)
git diff origin/main..zeszyty -- resources/views/components/post-card.blade.php
```
Gałąź `zeszyty` (issue #775/#776 — usuwanie z konkretnego zeszytu) zamienia bezwarunkowy układ
„odnośnik + przycisk «Usuń z zeszytu»” (wprowadzony przez #789) na układ warunkowy: przycisk kasujący
pojawia się TYLKO wtedy, gdy karta jest renderowana wewnątrz konkretnego zeszytu (`$zeszyt !== null`),
a w feedzie zostaje wyłącznie odnośnik „Masz to w zeszycie” — dokładnie ten stan sprzed #789, który D-224
uznało za błąd („trasa istniała, ale nikt jej nie wołał, więc nie było drogi wyjścia”). Dla porównania
`jedna-droga` i `gpt-zeszyt-droga` (też dotykają tego samego przycisku) **zachowują** globalny przycisk
„Usuń z zeszytu” z #789 i tylko go rozszerzają o wariant lokalny.

### 1b. Szerszy skan — wszystkie pliki, gdzie usunięcia przewyższają dodania (dowolna gałąź)

| Gałąź | + | − | Plik | Werdykt |
|---|---|---|---|---|
| gpt-tagi | 42 | 84 | `app/Http/Controllers/TagFollowController.php` | OK — logika przeniesiona do `App\Domain\Tags\Actions\UpdateTagFollows` (nowy plik w tej samej gałęzi), nie zgubiona |
| gpt-tablica | 12 | 59 | `tests/Feature/OdstepPodPodpisemPolaTest.php` | nie do rozstrzygnięcia z samego odczytu treści testu — wymaga uruchomienia testu, żeby ocenić czy nowa wersja pokrywa to samo |
| gpt-kontakt-formularz | 38 | 56 | `resources/views/pages/napisz-do-nas-potwierdzenie.blade.php` | nie sprawdzono treści — niski priorytet (nie plik współdzielony z inną gałęzią, patrz sekcja 3) |
| zeszyty | 40 | 56 | `post-card.blade.php` | patrz 1a — POTWIERDZONA SZKODA |
| gpt-moderacja-ai | 3 | 52 | `app/Moderacja/OcenaModelem.php` | OK, patrz 2a — świadomy rozdział na osobny job |
| gpt-eksport | 33 | 51 | `app/Jobs/GenerateUserExport.php` | OK, patrz 2c — przeniesione do `NotifyUserExportReady` |
| gpt-kontakt-panel | 50 | 51 | `app/Http/Controllers/Admin/WiadomosciController.php` | nie sprawdzono treści — plik dotyka tylko tej gałęzi (brak kolizji, niski priorytet) |
| gpt-openai-granice | 11 | 39 | `app/Http/Controllers/CommentController.php` | nie sprawdzono treści szczegółowo; gałąź współdzieli `OcenaModelem.php`/`PrzeanalizujTresc.php` z gpt-moderacja-ai (patrz sekcja 3) — **wymaga sprawdzenia przy scalaniu obu naraz** |
| gpt-zeszyt-droga | 31 | 33 | `CollectionController.php` | OK, patrz 1a/2b |
| gpt-tagi / naprawa-858 / tagi-filtr | — | — | `OnboardingController.php` (4/33 w każdej) | trzy gałęzie ruszają ten sam plik identycznym wzorcem zmiany — prawdopodobnie ta sama refaktoryzacja powielona (patrz sekcja 3) |

Reszta pozycji z listy „usunięcia > dodania” (gpt-wspomnienia-prywatnosc, gpt-zdjecia-limity,
gpt-zdjecia-publikacja, gpt-konto-poczta, gpt-skladniki, gpt-harmonogram, gpt-tagi-miejsce) ma niewielkie
różnice (≤ 24 linii) i dotyczy plików nie współdzielonych z inną gałęzią w sposób konfliktowy — **nie do
rozstrzygnięcia bez czytania każdego wiersza treści**, ale niski priorytet ryzyka.

---

## 2. Cztery zmiany zgłoszone jako wątpliwe — werdykt

**2a. `gpt-moderacja-ai` → `app/Moderacja/OcenaModelem.php` (−50 linii oceny zdjęć).**
**Werdykt: PRACA STANOWISKA, nie przypadkowe cofnięcie.** Diff usuwa `zdjeciaDoOceny()` i wywołanie oceny
obrazu wewnątrz `dla()`, ale ta sama gałąź dodaje w tym samym commicie: `app/Jobs/PrzeanalizujZdjecieWpisu.php`
(65 linii, nowy job), `app/Domain/Moderation/PostImageAnalysis.php` (54 linie), `AutomaticAnalysisAccess.php`
(64 linie), plus nowy wpis w `docs/DECISIONS.md` („uzupełnienie 20 września 2026 (#829, #830)”) i dokument
`docs/legal/MODERACJA_AI_826_830.md`. Ocena zdjęć nie zniknęła — przeniesiono ją do osobnego, etapowego
joba z własnym budżetem. Cztery nowe testy (`ModeracjaEtapyTest.php` i inne) pokrywają nowy przepływ.

**2b. `zeszyty` i `jedna-droga` → `CollectionController.php` (usuwa m.in. `catch (UniqueConstraintViolationException)`).**
**Werdykt: OBSŁUGA WYŚCIGU ZACHOWANA, to przebudowa/przesunięcie, nie utrata zabezpieczenia.**
Sprawdzono liczbę wystąpień `UniqueConstraintViolationException` w wynikowym pliku:
`origin/main` = 2, `jedna-droga` = 2, `zeszyty` = 2, `gpt-zeszyt-droga` = 2. Diff pokazuje usunięcie
starego `catch` w jednym miejscu i dodanie identycznego w nowym (przesunięta linia w przebudowanej
metodzie) — zabezpieczenie przed wyścigiem `unique index` nie zniknęło w żadnej z trzech gałęzi.

**2c. `gpt-eksport` → `GenerateUserExport.php` (−31 linii).**
**Werdykt: PRACA STANOWISKA.** Usunięta metoda `notifyOwner()` (wysyłka e-maila wprost z joba) została
zastąpiona nowym jobem `app/Jobs/NotifyUserExportReady.php` (71 linii) dispatchowanym przez nowy
`ExportQueue` (31 linii) — architektura „niezawodność eksportu” opisana w nowym
`docs/infra/EKSPORT_NIEZAWODNOSC.md` i pokryta nowymi testami (`ExportNotificationTest.php`,
`ExportQueueLifecycleTest.php`, `ExportVisibleStateTest.php`). Nic nie zginęło, tylko przeniesiono.

**2d. `gpt-testy-50plus` → `docs/product/TESTY_Z_UZYTKOWNIKAMI.md` (przepisany protokół, 343 na 221 linii).**
**Werdykt: ŚWIADOMA PODMIANA CAŁEGO DOKUMENTU, nie utrata treści.** Nowa wersja zaczyna się od
„Wersja 1.0, 20 września 2026 (...) Zastępuje poprzednią instrukcję operacyjną tego pliku, ale **nie
zmienia bramki #15**” — dokument sam deklaruje, że jest świadomym następcą starego, z zachowaniem
kryterium akceptacji z issue #15. Realna treść: 222 usunięte / 344 dodane linie (nie 343/221 jak
podano w zgłoszeniu recovera, ale kierunek ten sam: plik urósł, nie skurczył się).

---

## 3. Pary gałęzi ruszające ten sam plik (ryzyko konfliktu scalania)

Pełny skan 56×numstat dał **138 plików dotkniętych przez więcej niż jedną gałąź**. Poniżej klastry
o realnym znaczeniu (pominięto pliki-dowody z `docs/research/**` powielone tylko dlatego, że dwie gałęzie
naprawiają to samo zgłoszenie — wypisane osobno niżej):

| Plik / obszar | Gałęzie | Ryzyko |
|---|---|---|
| `docs/DECISIONS.md` | gpt-cloudflare-cache, gpt-konto-poczta, **gpt-moderacja-ai**, **gpt-n1-powiadomienia**, gpt-openai-granice, gpt-skladniki, gpt-tagi-miejsce, gpt-wersje-przepisu, jedna-droga, **notyfikacja-zywa** | Wysokie — plik dopisywany na końcu przez wiele gałęzi jednocześnie; **n1-powiadomienia/notyfikacja-zywa kasują D-224** (sekcja 1) |
| `app/Http/Controllers/CollectionController.php`, `post-card.blade.php`, `resources/views/pages/collections/*.blade.php`, `SavePostToCollection.php` | **jedna-droga**, **zeszyty**, gpt-zeszyt-droga | Wysokie — trzy niezależne przebudowy tego samego kontrolera; `zeszyty` cofa #789 (sekcja 1) |
| `app/Http/Controllers/TagFollowController.php`, `TagFollowForm.php`, `UpdateTagFollows.php`, `TagSelection.php`, testy tagów, `docs/research/tagi-2026-09-20/*` | gpt-tagi, **naprawa-858**, **tagi-filtr** | Średnie/wysokie — naprawa-858 i tagi-filtr są niemal identyczne między sobą (mały diff), a oba mocno różnią się od gpt-tagi → **prawdopodobnie to samo zgłoszenie #858 naprawiane dwa razy równolegle (naprawa-858 i tagi-filtr), na bazie wcześniejszej refaktoryzacji gpt-tagi** — wymaga decyzji, którą z dwóch naprawczych wziąć |
| `docs/DECISIONS.md` + `docs/DATABASE.md` + `CookedEventController.php` + `RecipeController.php` + `NotificationController.php` + `Notification.php` + `resources/js/app.js` + `notifications.blade.php` + migracja `usun_zamrozone_wycinki_komentarzy` + 6 wspólnych testów | **gpt-n1-powiadomienia**, **notyfikacja-zywa** | Bardzo wysokie — te dwie gałęzie dotykają niemal identycznego zestawu plików (drzewa różne, ale bliskie: `git diff gpt-n1-powiadomienia..notyfikacja-zywa` = tylko 6 plików, 84+/321−) → **to dwa stanowiska robiące to samo zadanie (D-223, „powiadomienie śledzi treść komentarza”, #758)**, nie dwie różne funkcje |
| `app/Http/Controllers/NotificationController.php`, `Notification.php`, 6 testów `Powiadomienie*`/`Czas*` | **powiadomienia**, **straznik-format** | Wysokie — inny wątek powiadomień, też dwa stanowiska na tych samych plikach |
| `app/Moderacja/OcenaModelem.php`, `PrzeanalizujTresc.php`, `OznaczDoPrzegladu.php`, `docs/legal/SYGNALY_AUTOMATU.md` | **gpt-moderacja-ai**, **gpt-openai-granice** | Wysokie — obie gałęzie zmieniają moderację AI w tych samych plikach; `gpt-openai-granice` **nie została** przez recovera zgłoszona jako wątpliwa, ale koliduje wprost z 2a |
| `resources/views/pages/posts/create.blade.php`, `questions/create.blade.php` | gpt-tagi-miejsce, gpt-zdjecia-limity, gpt-zdjecia-publikacja | Średnie — trzy niezależne funkcje na tych samych widokach formularzy |
| `docs/infra/SONDA_WDROZENIA_805_808.md`, `scripts/sprawdz-wdrozenie.sh`, dowody `sonda805-808/*` | gpt-cloudflare-cache, gpt-harmonogram, gpt-sonda-wdrozenia | Średnie |
| `odwolanie-link`, `zaleglosci-zgloszen` | `ReportController.php`, `appeals/*.blade.php`, `NotifyReporterReceipt.php`, `ReportContent.php` | Średnie — ten sam obszar zgłoszeń/odwołań |
| `gpt-zdjecia-limity` / `gpt-zdjecia-publikacja` | `PublishPost.php`, `RecordCookedEvent.php`, `RecoveredFormPhotos.php`, testy zdjęć | Średnie — dwie gałęzie o zdjęciach na tych samych plikach domenowych |
| `resources/css/app.css` | gemini-794, gpt-zalegle, gpt-zeszyt-droga, naprawa-858, tagi-filtr | Niskie — same dodania, ale 5 gałęzi na jednym pliku = pewny konflikt tekstowy przy scalaniu (nie merge treści, tylko konflikt linii) |
| `package.json` | (już opisane przez inny zespół — wspólna linia `build`) | **Potwierdzone gdzie indziej, nie w tym skanie** — żadna z 56 gałęzi w moim zestawieniu nie dotyka `package.json` (sprawdzone: brak wpisu w żadnym numstat) |

**Uwaga o `package.json`:** żadna z 56 gałęzi z tego zadania nie zmienia `package.json` (potwierdzone —
brak w pełnym zestawieniu numstat). Zgłoszony wcześniej konflikt w linii `build` musiał powstać na innej
gałęzi/PR spoza tej puli 56 albo już po scaleniu części z nich — **nie do potwierdzenia z tego zestawu**.

---

## 4. Wyrywkowa kontrola commitów (5 gałęzi z największą liczbą zmian)

Ranking wg sumy `+/−` linii: **gpt-panel-moderacji-marka** (17 835), **gpt-obciazenie** (15 907),
**gpt-ai-piloty** (12 971), **naprawa-858** (9 274), **stan-sesji** (9 023).

Wszystkie 56 gałęzi mają **dokładnie jeden commit** o generycznej treści
„Odzyskaj prace stanowiska `<nazwa>` po awarii repozytorium” — to komunikat procesu odzysku, nie opis
merytoryczny pracy stanowiska, więc nie może „kłamać” w sensie niezgodności treści z deklaracją funkcji.
Sprawdzono więc **spójność tematyczną**: czy pliki w diffie pasują do nazwy gałęzi/stanowiska.

- **gpt-panel-moderacji-marka** → wyłącznie `docs/design/PANEL_MODERACJI_MARKA_581.md` i dowody floty
  (`docs/design/evidence/flota581-...`). Zgodne.
- **gpt-obciazenie** → wyłącznie `docs/infra/evidence/obciazenie605/**` (raporty, JIT, SQL). Zgodne.
- **gpt-ai-piloty** → `docs/research/ai-pilots/**`, `scripts/ai-pilots/*.php`. Zgodne.
- **naprawa-858** → `app/Domain/Tags/**`, `TagFollowController.php`, `docs/research/tagi-2026-09-20/**`
  (issue #858 = tagi). Zgodne, ale patrz sekcja 3 — treściowo prawie identyczna z `tagi-filtr`.
- **stan-sesji** → `docs/flota/STAN_SESJI*.md`, `docs/flota/prompty/**`. Zgodne.

**Werdykt: brak fałszywych deklaracji.** Treść każdej z pięciu gałęzi odpowiada nazwie stanowiska.
Zastrzeżenie: sam komunikat commita nie niesie żadnej informacji o zawartości, więc test „czy commit mówi
prawdę” sprowadza się tu do zgodności nazwa-gałęzi ↔ pliki, którą potwierdzono.

---

## Podsumowanie liczbowe

- Gałęzi z **potwierdzoną** cichą utratą cudzej pracy: **3** — `gpt-n1-powiadomienia`, `notyfikacja-zywa`
  (obie kasują D-224 w `docs/DECISIONS.md`) oraz `zeszyty` (cofa skutek D-224/#789 w `post-card.blade.php`).
- Zmian zgłoszonych jako wątpliwe: **4** — wszystkie 4 uznane za **prawdziwą pracę stanowiska** (2a, 2b, 2c, 2d).
- Par/klastrów gałęzi na tym samym pliku o istotnym ryzyku konfliktu: **9** (wypisane w sekcji 3),
  w tym jeden klaster bardzo wysokiego ryzyka duplikacji pracy (`gpt-n1-powiadomienia`/`notyfikacja-zywa`)
  i jeden duplikacji naprawy (`naprawa-858`/`tagi-filtr`).
- `package.json`: brak w tej puli 56 gałęzi — zgłoszony konflikt pochodzi spoza tego zestawu.
- Wyrywkowa kontrola 5 największych gałęzi: **0 rozbieżności** między nazwą stanowiska a treścią diffu.

## Rekomendacja dla automatu scalającego

Nie scalać `gpt-n1-powiadomienia`, `notyfikacja-zywa` i `zeszyty` bez ręcznego przeglądu — patrz wpis
dopisany na końcu `_wspolne/KOLEJNOSC_SCALANIA.md`. Przed scalaniem `naprawa-858`/`tagi-filtr` ustalić,
która z dwóch wersji naprawy #858 jest kanoniczna (są sobie bardzo bliskie, ale nie identyczne).

---

*Raport wygenerowany metodą czystego odczytu (`git diff`/`git show`/`git log`/`git merge-base`) na
`C:\Users\matma\Documents\Codex\kuking.pl`, bez modyfikacji jakiejkolwiek z 56 gałęzi.*
