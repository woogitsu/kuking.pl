## D-090 · `BlockUser` wchodzi przez `ZamekPary` — dokończenie D-080, bo dwie strony tej samej pary brały wiersze `users` w przeciwnych kolejnościach

**Data:** 10 września 2026 · Audyt kolejności blokad
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · Status: **obowiązuje**

### Co było złamane

D-080 §1 mówi: „**Obie** operacje na parze osób wchodzą przez jedno gardło —
`App\Domain\Social\ZamekPary`". W kodzie weszła **jedna**. Commit realizujący
D-080 (`ab5f4c6`, PR #290) ruszył `FollowUser.php` i `ZamekPary.php` — i tyle.
`BlockUser::handle()` został przy własnej `DB::transaction()` bez ani jednej
blokady wiersza, a `ZamekPary` był importowany wyłącznie w `FollowUser`.

To nie jest rozbieżność stylu. To dwie różne kolejności blokad na tej samej
parze wierszy, czyli dokładnie to, przed czym ostrzega D-079 („dwie różne
kolejności w jednym repozytorium to zakleszczenie, a nie zabezpieczenie").

### Co z tego NIE wynikało — podejrzenie zmierzone i OBALONE

Naturalny wniosek brzmi: skoro `BlockUser` nic nie blokuje, to wyścig
SOCIAL-01 jest nadal otwarty i po blokadzie zostaje obserwowanie. **Ten
wniosek jest nieprawdziwy** i został obalony pomiarem na dwóch połączeniach
do PostgreSQL (opis i skrypty: `docs/research/2026-09-10-kolejnosc-blokad.md`,
pomiar E8).

Powód: `INSERT INTO blocks` **i tak bierze blokady obu wierszy `users`** — bierze
je za niego sprawdzenie kluczy obcych, zapytaniem
`SELECT 1 FROM ONLY "public"."users" x WHERE "id" = $1 FOR KEY SHARE OF x`.
`FOR KEY SHARE` jest w konflikcie z `FOR UPDATE`, więc żądanie „Obserwuj"
ustawiało się w kolejce mimo wszystko. Zmierzony przeplot: przy
niezatwierdzonej transakcji `BlockUser` żądanie „Obserwuj" **nie weszło** na
żaden z dwóch wierszy, a stan końcowy to jedna blokada i zero obserwowań.

Zapisujemy to tak wyraźnie jak znalezisko, bo fałszywy alarm kosztuje tyle
samo co przeoczony błąd (D-064). Ale własność trzymała się na **kształcie
kluczy obcych**, czyli na czymś, czego nie widać w żadnej linijce PHP i czego
nie pilnuje żaden test — a to jest gwarancja przez przypadek, nie przez
projekt.

### Co z tego WYNIKAŁO — zakleszczenie, zmierzone

Blokady z kluczy obcych idą w kolejności **ról**, nie identyfikatorów:
`blocks_blocker_id_foreign` powstało przed `blocks_blocked_id_foreign`, więc
`INSERT` bierze najpierw wiersz blokującego, potem blokowanego. `ZamekPary`
bierze wiersze **rosnąco po identyfikatorze**. Gdy blokujący ma identyfikator
wyższy, obie strony idą pod prąd:

```text
„Obserwuj" (ZamekPary):  bierze wiersz NIŻSZY, czeka na WYŻSZY
„Zablokuj" (BlockUser):  bierze wiersz WYŻSZY, czeka na NIŻSZY
```

PostgreSQL wykrywa cykl i zabija jedną transakcję. W pomiarze (E3) ofiarą
padło **„Zablokuj"**:

```text
ERROR: deadlock detected
CONTEXT: while locking tuple (0,9) in relation "users"
  SQL statement "SELECT 1 FROM ONLY "public"."users" x WHERE "id" = $1 FOR KEY SHARE OF x"
```

Czyli człowiek dostawał błąd serwera zamiast założonej blokady — dokładnie
w sytuacji, dla której D-080 powstało, i wprost przeciw jego zdaniu „blokada
musi się udać zawsze". Ofiarę wybiera baza, więc równie dobrze mogło paść
„Obserwuj"; gorszy z tych dwóch wyników jest ten zmierzony.

### Decyzja

`BlockUser::handle()` wchodzi przez `ZamekPary::zablokuj()`, tak jak
`FollowUser`. Obie strony biorą te same dwa wiersze w tej samej, wyliczonej
z danych kolejności, więc jedna czeka na drugą zamiast zakleszczać się z nią
(kontrola dodatnia naprawy: pomiar E7 — cykl znika).

**Żadnego szóstego mechanizmu.** Nie powstaje nowa klasa, nie zmienia się
`ZamekPary`, nie zmienia się reguła kolejności. Zmienia się jedno: druga
akcja wchodzi przez istniejące gardło, zgodnie z tym, co D-080 już
postanowiło.

**Rewalidacja pod blokadą.** Zamek podaje świeże modele; `null` znaczy „konta
już nie ma" i kończy się `BladDlaCzlowieka` („To konto jest niedostępne."),
a nie naruszeniem klucza obcego i pięćsetką.

**Dziennik audytu zostaje POZA transakcją**, tak jak był. Wpis ma powstać
wtedy, gdy blokada naprawdę się zapisała; wciągnięty pod blokadę zniknąłby
razem z wycofaną transakcją, a jest osobnym śladem, nie częścią relacji.
Pilnuje tego osobny test.

**Tania odmowa „nie można zablokować samego siebie" zostaje przed zamkiem** —
nie ma po co otwierać transakcji, żeby odmówić. Gwarancję i tak trzyma
`blocks_no_self_check` w bazie.

### Czego ta decyzja NIE rozstrzyga

Nie usuwa pozostałych rozjazdów kolejności wykrytych w tym samym audycie
(kasowanie konta rusza `follows`/`blocks` bez `ZamekPary`; `LoginLinkController::
store()` bierze wiersz tokenu bez wiersza konta). Są opisane w raporcie
z naprawami **opisanymi, nie wdrożonymi** — każda jest osobną decyzją.

Nie da się jej też dowieść w istniejącym zestawie testów: `RefreshDatabase`
trzyma cały test w jednej niezatwierdzonej transakcji na jednym połączeniu,
więc drugiego uczestnika wyścigu po prostu nie ma. Testy pilnują
**kontraktu** (że akcja wchodzi przez zamek, w ustalonej kolejności, tej
samej co obserwowanie), a nie skutku. Skutek zmierzono poza zestawem, na
dwóch połączeniach; propozycja wprowadzenia takich testów do repozytorium
jest w raporcie.

**Zmiana wymaga:** rezygnacji z `ZamekPary` jako wspólnego gardła dla pary
osób — a wtedy razem z nią z D-080. Kolejność rosnąco po identyfikatorze nie
podlega zmianie inaczej niż we wszystkich miejscach naraz.

📄 `app/Domain/Social/Actions/BlockUser.php` ·
`app/Domain/Social/ZamekPary.php` ·
`tests/Feature/ZamekParyObejmujeBlokowanieTest.php` ·
`docs/research/2026-09-10-kolejnosc-blokad.md` ·
D-079 · D-080
