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
