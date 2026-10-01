## D-257 — Zdjęcia w R2: token per bucket w aplikacji, kopia jako datowane migawki poza jej zasięgiem (#617, 24 września 2026)

**Data:** 24 września 2026 · Status: **część w kodzie obowiązuje po scaleniu; strategia kopii czeka na decyzję właściciela** (runbook: `docs/infra/DR_ZDJEC_R2.md`)

**Co (w kodzie).**

1. Każdy bucket zdjęć i paczek może mieć własną parę tokenu R2:
   `AWS_ORIGINALS_*` (`r2`), `AWS_PUBLIC_*` (`r2_publiczne`), `AWS_LEGACY_*`
   (`r2_legacy`), `AWS_EXPORTS_*` (`r2_eksporty`). Bez pary bucket bierze
   wspólne `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, tak jak przed zmianą.
   Połowa pary to wyjątek przy ładowaniu konfiguracji z nazwą brakującej
   zmiennej, a nie cichy powrót do wspólnego tokenu.
2. Dysk `r2_kopia_zdjec` (sterownik `s3`, własna para
   `AWS_ZDJECIA_KOPIA_*`, token **tylko do odczytu**) i komenda
   `kuking:sprawdz-kopie-zdjec` porównująca wiersze `media` z migawką kopii.
   Komenda niczego nie zapisuje i odmawia pracy bez własnej pary tokenu.
   Pusty klucz s3 znaczy, że AWS SDK sięga po `AWS_ACCESS_KEY_ID` ze
   środowiska, czyli po token aplikacji.

**Dlaczego aplikacja NIE traci prawa kasowania.** `EraseAccountData` usuwa
zdjęcia natychmiast. Sprzątanie osieroconych i kompensacja nieudanego
wgrania też kasują. Kolejka z opóźnieniem albo „kosz” w tym samym buckecie
nie odbiera tokenowi prawa `DELETE` (przeniesienie to kopia plus `DELETE`),
a opóźnia usunięcie danych. Przed logicznym usunięciem chroni wyłącznie
kopia, do której aplikacja nie ma prawa zapisu.

**Co (rekomendacja, do decyzji właściciela).** Osobny bucket
`kuking-zdjecia-kopia` z jurysdykcją EU, bez domeny. Kopia to **datowane
migawki** `migawka-RRRR-MM-DD/{oryginaly,warianty}/`, robione przez proces
poza aplikacją. Rygiel dotyczy wieku (30 dni), lifecycle kasuje po 31 dniach.
To prostuje §6a `LOKALIZACJA_DANYCH_R2.md`: przy kopii lustrzanej ten sam
lifecycle wygasiłby każde zdjęcie starsze niż 31 dni, a rygiel chroniłby
tylko obiekty młodsze niż 30 dni. Zdjęcie usunięte przez użytkownika
znika z kopii najpóźniej po ok. 32 dniach i nigdy nie jest przywracane:
lista odtworzenia pochodzi z bazy.

**Co musiałoby się stać, żeby to zmienić:** inny dostawca kopii, prawo
„zapis bez kasowania” w tokenach R2 albo zmiana procedury usuwania danych.

Dowody: `tests/Feature/PoswiadczeniaBucketowR2Test.php`,
`tests/Feature/KopiaZdjecSprawdzanaTylkoOdczytemTest.php`.

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia. Zmienne `AWS_*_ACCESS_KEY_ID`
per bucket trzeba wtedy usunąć z Railway. Bez nich wszystkie buckety wracają
do wspólnego tokenu, który musi mieć dostęp do każdego z nich.
