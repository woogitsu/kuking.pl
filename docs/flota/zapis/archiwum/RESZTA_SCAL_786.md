# Co zostaje w `flota/scal-786` poza kontraktem nazw baz

Sporządzone 21.09.2026 przez stanowisko `kontrakt-nazw-baz`.
Pomiar własny, chyba że zaznaczono inaczej.

---

## Krótka odpowiedź

**Nic.** Wobec dzisiejszego `origin/main` (`cd966aae`) gałąź `flota/scal-786`
nie niesie ani jednego pliku spoza tematu „nazywanie i rozłączanie baz
testowych". Nowa gałąź `flota/kontrakt-nazw-baz` (`c07d7701`) ma **identyczne
drzewo** co `flota/scal-786` (`git diff flota/scal-786 flota/kontrakt-nazw-baz`
jest puste). Rozdzielenie, o które prosił właściciel, jest dziś rozdzieleniem
na jedną część.

To nie znaczy, że decyzja właściciela była zbędna — znaczy, że **liczba, na
której ją oparto, opisywała inny stan repozytorium niż dzisiejszy.**

---

## Skąd się wzięło „117 plików, +12 962 / −907"

Dziś `flota/scal-786` wobec `origin/main` to **42 pliki, +2 046 / −364**.
Tę samą liczbę podaje GitHub dla PR-a #966 (`gh pr view 966`: `changedFiles: 42`,
`additions: 2046`, `deletions: 364`, baza `main`, szkic).

Mechanizm rozjazdu:

1. Autor gałęzi **wciągnął dzisiejszy `main` do gałęzi** o 07:54 —
   commit `ba69bf16` „Scal main do izolacji baz", którego drugim rodzicem
   jest dokładnie `cd966aae`.
2. Dopóki tego scalenia nie było, **każde porównanie z nieaktualnym `main`
   pokazywało pracę `main` jako pracę gałęzi**. Skala rośnie z wiekiem bazy
   porównania:

   | baza porównania (`main` z dnia) | wynik `git diff <baza> flota/scal-786` |
   |---|---|
   | 21.09 `cd966aae` (dziś) | **42 pliki, +2 046 / −364** |
   | 20.09 `4c811cc7` | 104 pliki, +8 871 / −829 |
   | 19.09 `61686213` | 156 plików, +14 993 / −1 268 |
   | 18.09 `f821b1ee` | 340 plików, +55 972 / −1 406 |

   Podana właścicielowi liczba 117 / +12 962 / −907 leży **między wierszem
   z 20.09 a wierszem z 19.09** i nie odtworzyła się dokładnie na żadnym
   commicie `main` z 19–21.09 — ale jej rząd wielkości i kierunek nie
   pozostawiają wątpliwości, co ją zrobiło.

---

## Cztery „niezwiązane tematy" — gdzie one naprawdę są

Właściciel wymienił je jako bagaż po awarii z 20.09. Żaden z nich nie jest
pracą tej gałęzi: **wszystkie cztery weszły na `main` własnymi commitami**
i gałąź ma je tylko dlatego, że wciągnęła `main`.

| Plik | Kto go wniósł na `main` | Kiedy |
|---|---|---|
| `tests/Feature/TagiWTresciWpisuTest.php` | `bd48fce3` „Tag w tresci wpisu prowadzi na strone tagu" | 20.09, 00:11 |
| `tests/Feature/WpisDaSieWyjacZZeszytuTest.php` | `4c811cc7` (#789) „Dodaj droge wyjecia wpisu z zeszytu (audyt L1)" | 20.09, 17:11 |
| `tests/Feature/SygnalyProduktoweTest.php` | `6052f699` (#916) „Wyszukiwarka: fraza przestaje być wzorcem…" | 21.09, 01:51 |
| `tests/Feature/StopkaOkruszkiIFiltrTrzymajaMinimaUxTest.php` | `cd966aae` (#918) „Cztery miejsca poniżej minimów UX 50+" | 21.09, 03:20 |

Sprawdzenie wprost: `git diff origin/main flota/scal-786 --name-only` **nie
wymienia żadnego z tych czterech plików** — bo po obu stronach są identyczne.

**Czy to praca warta osobnego PR-a?** Pytanie nie powstaje: to nie jest praca
leżąca na gałęzi, tylko `main` widziany przez nieaktualną szybę.

---

## Pełny zasięg tego, co zostało — pogrupowane

Dla porządku: tak rozkłada się te 42 pliki, gdyby ktoś chciał dzielić dalej.
**Każda z grup jest częścią tego samego kontraktu i nie da się jej wyjąć bez
czerwieni** — przy każdej stoi, co konkretnie zapali.

### Grupa 1 — reguła nazw (4 pliki)
`tests/nazwa-bazy.php`, `tests/bootstrap.php`, `tests/Unit/NazwaTestowejBazyTest.php`,
`.claude/hooks/session-start.sh`.
Sedno tematu. Hook miał do dziś DRUGĄ kopię reguły napisaną w bashu i to ona
się rozjechała; zostawiony z tyłu zakładałby inną bazę niż ta, na którą trafią
testy.

### Grupa 2 — konsumenci reguły (4 pliki)
`scripts/proba-wycofania.sh`, `scripts/testy-dwa-polaczenia.sh`,
`tests/skrypty/proba-odtworzenia.sh`, `tests/skrypty/izolacja-bazy-testowej.sh`.
Wszystkie wołały `require "tests/bootstrap.php"` po funkcje nazw. Po
przeniesieniu funkcji do `tests/nazwa-bazy.php` gołe zostawienie ich z tyłu
daje puste nazwy baz i skrypty padające bez zrozumiałego komunikatu.

### Grupa 3 — bezpiecznik i jego wpięcie (21 plików)
`scripts/bezpiecznik-bazy.mjs`, `scripts/bezpiecznik-bazy.test.mjs`,
`tests/Feature/BezpiecznikBazyPomiarowejTest.php` oraz **18 przyrządów**
`scripts/*.mjs` robiących `migrate:fresh`.
Wpięcie nie jest kosmetyką: skan w `bezpiecznik-bazy.test.mjs` wymaga, żeby
KAŻDY taki skrypt wołał `ustalBazePomiarowa()`. Sprawdzone kontrolą ujemną —
odpięcie jednego skryptu (`glowka-profilu.mjs`) zapala
`BezpiecznikBazyPomiarowejTest`.

### Grupa 4 — port bazy (4 pliki; suma grup = 42)
`scripts/port-bazy.sh`, `scripts/check.sh`, `phpunit.xml`,
`tests/Feature/SkryptyPytajaOWlasciwyPortTest.php`.
`SkryptyPytajaOWlasciwyPortTest` skanuje `scripts/`, `tests/skrypty/`,
`docker/` i `.claude/hooks/` na `pg_isready` bez `-p`. Zostawienie
którejkolwiek poprawki z tyłu zapala ten test z nazwą pliku i numerem linii.

### Grupa 5 — sprzątanie baz (3 pliki)
`scripts/cleanup-test-dbs.sh`, `tests/skrypty/sprzatanie-baz-testowych.sh`,
`tests/Feature/SprzatanieBazTestowychTest.php`.
Konsekwencja D-225: ze skrótu ścieżki nie da się odczytać, czyja jest baza,
więc sprzątacz musiał przejść na rejestr kopii i `pg_stat_activity`.

### Grupa 6 — CI (1 plik)
`.github/workflows/ci.yml`.
Job `dostepnosc` ustawiał `DB_DATABASE: kuking_test` na poziomie całego joba,
a kroki przeglądarkowe robiły na tym `migrate:fresh`. Bezpiecznik odmawia
teraz startu na `kuking_test*`, więc **bez tej zmiany CI staje**.

### Grupa 7 — dziennik decyzji i dokumentacja (5 plików)
`docs/DECISIONS.md` (D-225, D-226), `AGENTS.md`, `docs/AI_WORKFLOW.md`,
`docs/HANDOVER.md`, `docs/zlecenia/ZASADY_AGENTA.md`.
Te cztery ostatnie opisywały STARĄ regułę i odsyłały do `tests/bootstrap.php`.
Żaden test ich nie pilnuje — ale zostawione z tyłu byłyby instrukcją, która
mówi nieprawdę, a agenci floty czytają je jako źródło prawdy.

---

## Czego NIE ma, choć miało być

`docs/DATABASE.md` — gałąź go **nie dotyka**. Na `main` ten plik nie mówi nic
o nazewnictwie baz testowych (jedyne trafienie to nagłówek pomiaru
„baza `kuking_598_pomiar`"). Nie ma więc czego przenosić. To nie jest zmiana
schematu, więc wymóg z `AGENTS.md` („zmiana schematu = migracja + test +
`docs/DATABASE.md` + rollback") się tu nie uruchamia. **Do decyzji właściciela:**
czy kontrakt nazw baz ma mimo to dostać własny akapit w `docs/DATABASE.md`.

---

## Numery decyzji — sprawdzone, nie ma kolizji

`docs/DECISIONS.md` na `origin/main` (`cd966aae`) kończy się na **D-224**
i **nie ma D-223** — numer stoi pusty, zarezerwowany dla `flota/martwe-kaskady`
(pozycja 1 planu scalania). **D-225 i D-226 są wolne.** Zalecany rozdział
numerów z `KOLEJNOSC_SCALANIA.md` §2 pozostaje aktualny w tej części.
