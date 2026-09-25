## D-029 · Numer sprawy ma własną kolumnę z UNIQUE, nie jest wycinkiem UUID-a

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Numer sprawy pokazywany zgłaszającemu liczył się w **pięciu miejscach kodu
i dwóch widokach** jako osiem pierwszych znaków UUID-a v7 wiersza `reports`.
**Nie był przez to unikalny.** Zmierzone: w UUID-zie v7 pierwsze 48 bitów to
znacznik czasu w milisekundach, więc osiem znaków szesnastkowych to jego 32
GÓRNE bity — zmieniają się raz na 2^16 ms, czyli raz na 65,5 sekundy.

```text
Str::uuid7('2026-09-07 19:00:30') → 01a07d3e-4cb0-7099-…  → 01A07D3E
Str::uuid7('2026-09-07 19:01:10') → 01a07d3e-e8f0-739c-…  → 01A07D3E
```

Dwa różne wiersze, 40 sekund odstępu, jeden numer sprawy.

**Dlaczego to nie jest niezręczność.** Dla zgłaszającego **bez konta** ten
numer jest jedynym śladem sprawy: nie ma konta, nie ma listy zgłoszeń, a
poczty serwis dziś nie wysyła. Numer powtórzony znaczy, że ani on, ani
moderator nie umie powiedzieć, o którą z dwóch spraw chodzi — a każda ma
własny termin odpowiedzi z DSA art. 16.

**Co jest teraz:** kolumna `reports.numer_sprawy varchar(12) NOT NULL`
z indeksem UNIQUE i CHECK-iem na format. Numer nadaje MODEL (hak `creating`),
więc dostaje go każda droga powstania wiersza; `numer_sprawy` nie jest
w `$fillable`, bo to tożsamość nadana przez serwer, nie dana od człowieka.

Format `KU-XXXX-XXXX` z 30-znakowego alfabetu **bez `0`, `1`, `I`, `L`, `O`
i `U`**. Pięć pierwszych znika, bo numer jest przepisywany ręcznie z ekranu
i dyktowany przez telefon — w tej grupie odbiorców `0`/`O`, `1`/`I` i `1`/`L`
to ten sam znak. `U` znika, żeby z ośmiu losowych znaków nie ułożyło się
przypadkiem słowo; ten numer trafia do pisma.

Pierwsza wersja tej stałej miała `U` w alfabecie, mimo że komentarz obok
mówił, że go nie ma — wyszło to na wygenerowanym numerze `KU-F6XC-9U7Y`,
bo test sprawdzał WYLOSOWANY wynik i przechodził w około trzech na cztery
przebiegi. Sprawdza teraz sam alfabet. Zapisane tu, bo to trzeci raz w tym
repozytorium, gdy reguła stała w komentarzu, a nie w kodzie.

**Odrzucone: dłuższy wycinek UUID-a** (np. cztery znaki czasu plus osiem
losowych). Byłoby taniej — bez migracji — ale unikalność zostałaby
STATYSTYCZNA i niepilnowana przez nic. `AGENTS.md` §6 mówi o prawdziwych
ograniczeniach w bazie i tutaj to nie jest formalizm: przy kolumnie z UNIQUE
powtórzony numer jest niemożliwy, a nie tylko nieprawdopodobny.

**Backfill istniejących wierszy jest bezpieczny dokładnie dziś:** poczty nie
ma, więc żaden numer nie został jeszcze nikomu przekazany i nikt nie trzyma
starego w ręku. Po pierwszym wysłanym liście ta sama zmiana byłaby zmianą
numeru pod ręką zgłaszającego i wymagałaby innego planu.

**Zmiana wymaga:** zmierzonej liczby spraw zbliżającej się do rzędu, w którym
30^8 kombinacji przestaje wystarczać (~954 tys. spraw dla 50% szansy kolizji),
albo powodu, dla którego format ma wyglądać inaczej.

📄 `app/Support/NumerSprawy.php` ·
`database/migrations/2026_09_07_910000_add_numer_sprawy_to_reports.php` ·
`app/Models/Report.php` · `tests/Feature/NumerSprawyTest.php` ·
`docs/DATABASE.md`
