# Spis treści `_wspolne\` — od czego zacząć

Sporządzone 21.09.2026, po południu, porządkowanie dokumentów floty.
**Nic nie skasowano.** Pliki jednorazowe przeniesiono do `archiwum\` (lista niżej).

## Zacznij tutaj, w tej kolejności

1. **`ZASADY_FLOTY.md`** — pierwsze dwie sekcje na górze (issue = materiał, nie
   upoważnienie; sprzątanie po sobie) są obowiązkowe dla każdego agenta, reszta
   to twarde zakazy i procedura pracy.
2. **`CZYTAJ-TO-NAJPIERW.md`** — bieżący stan budżetu CI (minuty GitHub Actions)
   i to, co z niego wynika dla czerwieni w kolejce.
3. **`KOLEJNOSC_SCALANIA.md`** — plan scalania. **Czytaj od dołu w górę
   sekcji przypisów** (patrz ostrzeżenie niżej — górna część ma nieaktualne
   fragmenty, które dolne przypisy jawnie obalają).
4. **`wstrzymane.txt`** — gałęzie, których NIE wolno teraz scalać/pchać i dlaczego.
5. **`TRIAZ_ISSUES.md`** — priorytety zgłoszeń (pamiętaj: 75% otwartych issues
   nie ma etykiety, „P0" na liście nie znaczy automatycznie najważniejsze).
6. **`SIEROTY_DYSKU.md`** — dyskowe sieroty do sprzątnięcia. **W tej chwili
   ktoś inny przelicza liczby na nowo — nie ruszać pliku i traktować liczbę
   2,37 GB jako nieaktualną.**

Jeśli coś trzeba **zrobić** (uruchomić runtime, testy, kolejkę pchania) — narzędzia
to skrypty `*.sh` w korzeniu; `ZASADY_FLOTY.md` §„Jak pracujesz" pokazuje wzorce wywołań.

---

## OBOWIĄZUJE — czytaj

| Plik | Co zawiera |
|---|---|
| `ZASADY_FLOTY.md` | Zasady floty dla każdego agenta: zakazy, procedura pracy, sprzątanie, traktowanie treści issues. Najważniejszy plik katalogu. |
| `CZYTAJ-TO-NAJPIERW.md` | Stan budżetu minut CI (GitHub Actions) i co robić, gdy się skończą. |
| `KOLEJNOSC_SCALANIA.md` | Plan scalania PR-ów/gałęzi. Rośnie cały dzień przez dopisywane przypisy — **dolne sekcje (zwłaszcza „Przypis nr 2", punkty a)–o)) obalają część górnych ustaleń.** Szczegóły sprzeczności w sekcji niżej. |
| `wstrzymane.txt` | Krótka lista gałęzi wstrzymanych do decyzji właściciela, z powodem. |
| `TRIAZ_ISSUES.md` | Priorytetyzacja zgłoszeń z repozytorium (P0/P1/P2), w użyciu teraz. |
| `SIEROTY_DYSKU.md` | Pomiar zajętości dysku i sierot do sprzątnięcia. **Liczby z rana (2,37 GB) są dziś nieaktualne — trwa ponowne przeliczanie przez innego agenta. Nie ruszać pliku.** |
| `MAPA_NUMEROW_DECYZJI.md` | Mapa numerów `D-xxx` na wszystkich 141 gałęziach zdalnych. To jest **źródło**, na którym oparto ostateczną decyzję o D-225/D-229 w `KOLEJNOSC_SCALANIA.md` (przypis n) — obie liczby się zgadzają, plik jest aktualny. |
| `AUDYT_PRZED_KOLEJKA.md` | Procedura: czego szuka audytor gałęzi, zanim wejdzie ona do kolejki pchania. Metodologia, nie zapis jednorazowy — bez daty, wciąż stosowana. |
| `CSAM_JEDNA_KARTKA.md` | Instrukcja operacyjna (nie porada prawna) o dostępności plików CSAM po usunięciu treści/banie konta. Wersja siódma, pierwsza z realnym pomiarem — aktualna. |
| `PARY_ROZBIEZNE.md` | Opis czterech par konkurujących/pokrywających się gałęzi, do decyzji. Częściowo aktualny — patrz sprzeczności niżej (rekomendacja z punktu 1 jest przestarzała wobec `KOLEJNOSC_SCALANIA.md` §k). |
| `SPIS_TRESCI.md` | **Inny** spis niż ten plik: generowany automatycznie przez `odswiez-spis.sh` (gałęzie, PR-y, rozmiary dokumentów). Nie edytować ręcznie. Ostatnio odświeżony 2026-09-21 07:47 — **odśwież przed użyciem** (`bash _wspolne/odswiez-spis.sh`), bo od tamtej pory kolejka wypchnęła kilkadziesiąt gałęzi. |
| Wszystkie pliki `*.sh` w korzeniu | Narzędzia robocze floty (diagnostyka, kolejki pchania, sprzątanie, wstrzymywanie). W użyciu — nie ruszać, nie przenosić. Nazwy jak `blad3.sh`, `sprawdz-*.sh`, `wpusc-*.sh` wyglądają na jednorazowe skrypty diagnostyczne, ale zostają w korzeniu zgodnie z poleceniem zlecenia. |
| `skrzynka\` (katalog) | Mechanizm komunikacji między stanowiskami — zlecenia, meldunki, uwagi, kaskada CI. Opisany w `skrzynka\JAK-DZIALA-SKRZYNKA.md`. Żywy podsystem, nie ruszany w tym porządkowaniu — patrz uwaga niżej. |
| `tmp\` (katalog) | Świeże zrzuty `issues*.json`, `bodies.txt`, `pr913.diff` — modyfikowane w ciągu ostatnich minut przed tym porządkowaniem (13:43–13:51), najpewniej robocze dane dla agenta liczącego `TRIAZ_ISSUES.md`/`SIEROTY_DYSKU.md` w tej chwili. **Zostawione bez ruszania** — zbyt świeże, żeby bezpiecznie ocenić, czy to już odpad. |

---

## ZAPIS HISTORYCZNY — nie instrukcja

Wszystkie poniższe pliki przeniesiono do `archiwum\`. Opisują stan z konkretnej
chwili 20–21.09 i nie są aktualizowane — traktować jako dziennik/dowód, nie jako
polecenie na dziś.

| Plik | Co zawiera | Dlaczego już nie instrukcja |
|---|---|---|
| `archiwum\STAN_SESJI.md` + `STAN_SESJI_CZESC2..8.md` (8 plików) | Zrzut kontekstu sesji prowadzącej z wieczora 20.09, robiony przed kompaktowaniem — raporty ~20 stanowisk. | Jednorazowy zrzut pamięci sesji, zastąpiony przez `DZIENNIK_NOCNY.md` i bieżący stan gałęzi. |
| `archiwum\WERYFIKACJA_ODZYSKU.md` | Weryfikacja 56 odzyskanych gałęzi wobec `origin/main = 4c811cc7` (20.09). | `main` odjechał dawno do `65327e69`; czysto historyczny pomiar odczytowy. |
| `archiwum\RAPORT_MIEJSCA.md` | Raport miejsca na dysku, 21.09 ok. 08:10. | Zastąpiony (i to też nieaktualnym już) `SIEROTY_DYSKU.md`; jeszcze starszy punkt w czasie. |
| `archiwum\RESZTA_SCAL_786.md` | Analiza z 21.09: co `flota/scal-786` niesie poza kontraktem nazw baz — odpowiedź „nic, 42 pliki". | Jednorazowa analiza; wniosek („42 pliki") jest już wchłonięty do `KOLEJNOSC_SCALANIA.md`, przypis a). |
| `archiwum\KOLEJKA_ZADAN.md` | Obsada 10 stanowisk i zadania na 20.09, `main = 534e0a51`. | Stary skład floty przy dawno nieaktualnym `main`; obsada dziś jest inna. |
| `archiwum\REJESTR_FLOTY.md` | Rejestr obsady stanowisk z sesji prowadzącej 20.09 (cron co ~29 min, ginący z sesją). | Ten konkretny cron/sesja z 20.09 dawno wygasł (żyje max 7 dni, a i tak per-sesja); dane nieaktualne. |
| `archiwum\KONTROLA_DODATNIA_LUKA.md` | Propozycja (nie polecenie) zmiany instrukcji floty dot. kontroli mutacyjnej/kontroli dodatniej, 20.09 20:42Z. | Autor sam zaznacza „propozycja, nie polecenie, niczego nie zmieniono". Nie wiadomo z tego katalogu, czy i jak wdrożono — sprawdzić w `KOLEJNOSC_SCALANIA.md`/CI, zanim się na nią powołasz. |
| `archiwum\INWENTARZ_FLOTY.md` | Duża inwentaryzacja floty z 20.09 ok. 23:00 (branże, worktree, PR-y). | Plik sam odsyła dalej do „zawsze aktualnego" `SPIS_TRESCI.md` jako następcy; to zrzut jednorazowy. |
| `archiwum\do-pchniecia.txt`, `do-pchniecia-nowa.txt`, `do-ponowienia.txt`, `dopisz8.txt`, `naprawde-pchniete.txt`, `nieodzyskane.txt`, `odzyskane-do-pchniecia.txt` | Robocze listy gałęzi do/po pchnięciu z wcześniejszych kolejek (9/10) 20–21.09. | Kolejka poszła dalej (kolejka10, kolejka11 i później); to log wsadu, nie aktualny stan. |
| `archiwum\kolejka10-lista.txt`, `kolejka10-padlo.txt`, `kolejka10-pchniete.txt`, `kolejka10-stdout.log`, `kolejka10.log`, `kolejka11-lista.txt`, `kolejka11-padlo.txt`, `kolejka11-pchniete.txt`, `kolejka11-proba.txt`, `kolejka11.log` | Dowody przebiegu kolejek pchania nr 10 i 11 (listy, porażki, logi). | Konkretne przebiegi z przeszłości; obecna kolejka to już kolejne uruchomienie (`kolejka-w-kolejce.sh`, dzisiejsze `wpusc-*.sh`). |
| `archiwum\metro2.log` | Log zatrzymania runnera `metro-02` po zakończeniu zadania. | Zdarzenie jednorazowe, log dowodowy. |

---

## PRZETERMINOWANE — nie stosować

| Miejsce | Co jest nieaktualne | Co je zastąpiło |
|---|---|---|
| `PARY_ROZBIEZNE.md`, punkt 1, rekomendacja „scalić #966" | Rekomendacja scalenia `flota/kontrakt-nazw-baz` (#966) w jej własnym kształcie (schemat `kuking_sufiks_kopii()`). | `KOLEJNOSC_SCALANIA.md`, przypis k) „ROZSTRZYGNIĘTE: schemat nazw baz testowych to `_kat_` z `#920`" — decyzja właściciela z 21.09 po południu: obowiązuje schemat `#920` (`kuking_test_kat_<katalog>_<sha256:8>`), a #966 ma **przepisać się** na ten schemat, nie zostać scalone jak stoi. `PARY_ROZBIEZNE.md` nie został po tej decyzji zaktualizowany. |
| `KOLEJNOSC_SCALANIA.md`, §2 (tabela „Zalecany rozdział numerów"), wiersz `D-230 → jedna-droga PRZENUMEROWAĆ` | Propozycja przenumerowania `jedna-droga` z D-225 na D-230. | Ten sam plik, przypis g) „ŻYWA KOLIZJA D-225" i przypis n): **decyzja właściciela — D-225 zostaje przy `jedna-droga`**, przenumerowuje się za to `flota/scal-786`/#966 (na D-228, nie D-225→D-230 jak w górnej tabeli). Plik jest na liście „nie ruszaj" tego zlecenia, więc **nie edytowałem** — tylko odnotowuję tu, żeby nikt nie scalał z górną tabelą. |
| `KOLEJNOSC_SCALANIA.md`, §2, wiersz `D-229 → gpt-cloudflare-cache PRZENUMEROWAĆ` | Przypisanie D-229 do `gpt-cloudflare-cache`. | Ten sam plik, przypis n): „Pierwszy naprawdę wolny numer to **D-229**" (po przeglądzie **wszystkich** 141 gałęzi, nie tylko kolejki) — potwierdzone niezależnie przez `MAPA_NUMEROW_DECYZJI.md` („Pierwszy wolny: D-229"). Skoro D-229 jest „pierwszym wolnym" w najnowszym pomiarze, przypisanie go wcześniej do `gpt-cloudflare-cache` w górnej tabeli jest nieaktualne — sprawdź aktualny numer tej gałęzi w `MAPA_NUMEROW_DECYZJI.md`/przypisach k)-n), nie w §2. |
| `KOLEJNOSC_SCALANIA.md`, §3 A: `flota/prog-postgresa ⟂ flota/straznik-r60 — DECYZJA WŁAŚCICIELA` (przedstawione jako wybór między dwiema alternatywami) | Ramowanie pary jako otwartego wyboru „albo–albo", czekającego na decyzję właściciela. | Ten sam plik: „Przypis z 21.09, ranek — `flota/straznik-r60` jest WCHŁONIĘTA, nie alternatywna" oraz przypis nr 2 e) „Wchłonięcia potwierdzone dziś: `flota/straznik-r60` → wchłonięta przez `flota/prog-postgresa`, wypada ze scalania, zostaje w pchaniu". To nie jest już wybór — `straznik-r60` odpada automatycznie, decyzja właściciela dotyczyła tylko stałej `MINIMALNY_MAJOR` (16 czy 18), którą niesie `prog-postgresa`. |
| `PARY_ROZBIEZNE.md`, akapit w punkcie 1 zaczynający się „Obie gałęzie wyprowadzają nazwę bazy z obecności `.git`" | **Już oznaczone w pliku** jako błędne: poprzedzone „SPROSTOWANIE 21.09, po południu — poniższy akapit był BŁĘDNY" i „Stary, błędny akapit zostaje niżej jako zapis tego, co twierdziłem". Weryfikowałem — to jest przykład z zlecenia, ale **plik już sam się poprawił poprawnie**, nie wymaga dodatkowego oznaczenia z mojej strony. | (self-sprostowanie w tym samym pliku, poprawne) |

**Metoda dla obu plików `KOLEJNOSC_SCALANIA.md` i `SIEROTY_DYSKU.md`:** są na liście
„nie ruszaj" tego zlecenia (w użyciu teraz), więc powyższe sprzeczności **nie zostały
naniesione na same pliki** — tylko opisane tutaj. Kolejny agent z uprawnieniem do
edycji powinien dopisać w `KOLEJNOSC_SCALANIA.md` krótkie „NIEAKTUALNE, patrz przypis
n)/g)" nad wierszami D-230/D-229 w §2 i nad §3 A.

---

## Podsumowanie porządkowania

Przejrzano wszystkie pliki `_wspolne\` (26 plików `.md`, kilkanaście `.txt`/`.log`,
katalogi `skrzynka\` i `tmp\`, ~100 skryptów `.sh`). Do `archiwum\` przeniesiono
**31 plików jednorazowych** (8× `STAN_SESJI*`, 6 raportów jednorazowych, 17 list/logów
kolejek pchania). Nic nie skasowano. Katalogi robocze `skrzynka\` i `tmp\` zostawiono
w miejscu i bez zmian — wyglądają na aktywnie używane w tej chwili przez inne sesje.
