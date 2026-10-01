## D-255 — Adres magazynu R2 za strażnikiem hostów: tylko `<konto>.eu.r2.cloudflarestorage.com` (24 września 2026)

**Data:** 24 września 2026 · **Decyzja właściciela 24.09.2026** · Status: **obowiązuje**

**Co.** `AWS_ENDPOINT` — adres, pod który dyski R2/S3 wysyłają żądania
podpisane kluczami z `AWS_*` (zdjęcia `r2`/`r2_publiczne`/`r2_legacy`,
eksporty RODO `r2_eksporty`, kopie bazy `r2_kopie`, ogólny `s3`) — podlega
strażnikowi hostów, tak jak adresy API z kluczami (#991). Dozwolony jest
wyłącznie adres:

```text
https://<32 znaki hex>.eu.r2.cloudflarestorage.com
```

— wzór `^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$`, schemat `https`,
bez portu (także bez wpisanego `:443`), bez danych logowania, bez ścieżki
poza „/”, bez `?` i `#`.

**Dlaczego.** (1) Adres niósł sekrety i dane ludzi (oryginały z EXIF-em, paczki
RODO, zrzuty bazy), a nikt go nie sprawdzał: literówka albo podmieniona
zmienna wysyłała podpisane żądania pod obcy host, a pusty adres — do Amazona
(domyślny endpoint AWS SDK). (2) Segment `eu` przypina dane do jurysdykcji
UE: bucket z jurysdykcją jest osiągalny wyłącznie przez endpoint
jurysdykcyjny, a endpoint `eu` nie sięga bucketów spoza niej
(`docs/infra/LOKALIZACJA_DANYCH_R2.md` §2). Polityka prywatności składa
obietnicę o UE — strażnik sprawia, że aplikacja nie zapisze zdjęcia nigdzie
indziej.

**Co robi strażnik** (`App\Support\Storage\DozwolonyHostR2`):

- `DyskR2::utworz()` (sterownik `r2`) i `DyskR2::utworzS3()` (wbudowany `s3`,
  przepięty w `AppServiceProvider`) sprawdzają adres **przed** zbudowaniem
  `S3Client`. Zły adres → dysk się nie buduje, wyjątek po polsku nazywa
  zmienną i SAM host (bez klucza, userinfo i ścieżki); żadne żądanie nie
  wychodzi.
- `/health` ma sondę `magazyn`: zły adres któregoś dysku R2/S3 → `ok:false`,
  kod `magazyn_r2_zly_host`; host (z identyfikatorem konta) idzie tylko do
  logu. Dysk bez klucza i bez adresu (produkcja bez R2) jest pomijany.
- W `local`/`testing` dopuszczone są też adresy, które nie wychodzą z maszyny:
  pętla zwrotna (`localhost`, `127.0.0.1`, `::1` — MinIO z
  `PomiarOdcieciaDostepuDoPlikuTest`) i zarezerwowane domeny `.test`,
  `.invalid`, `.example`, `.localhost` (RFC 2606/6761), oraz pusty adres.
  Na każdym innym środowisku — wyłącznie wzór.
- Wzór jest w kodzie, nie w `.env`: kto może podmienić adres, nie może też
  dopisać wyjątku.

**Jak wymienić konto albo region.**

- *Inne konto Cloudflare, dalej jurysdykcja UE:* wystarczy nowa wartość
  `R2_ENDPOINT` na Railway (`https://<nowe konto>.eu.r2.cloudflarestorage.com`)
  — identyfikator konta pasuje do wzoru. Buckety na nowym koncie muszą być
  utworzone z jurysdykcją `eu` (inaczej endpoint `eu` ich nie zobaczy).
- *Inna jurysdykcja albo inny dostawca:* to jest zmiana tej decyzji —
  nowa decyzja właściciela, zmiana `DozwolonyHostR2::WZOR_HOSTA`,
  `BramkaR2::JURYSDYKCJA_Z_POLITYKI` i polityki prywatności w jednym PR,
  z testem. Jurysdykcji istniejącego bucketu nie da się zmienić — dane
  trzeba przenieść do nowego bucketu (`kuking:przenies-zdjecia`).

**Co musiałoby się stać, żeby to zmienić:** właściciel zmienia obietnicę
o lokalizacji danych albo dostawcę magazynu.

Dowody: `tests/Feature/StraznikHostaR2Test.php`, kontrola ujemna
„Strażnik R2 bez segmentu eu” w `scripts/kontrole-negatywne-alfa08.py`.

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia; danych nie trzeba cofać.
