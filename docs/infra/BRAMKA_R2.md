# Bramka R2 przed wystawieniem `cdn.kuking.pl`

**Zgłoszenie:** issue #120 (P0, `typ: bezpieczeństwo`), audyt fali 2 — G-02 i G-11.
**Ostatnia aktualizacja tego pliku:** 2026-09-09.
**Stan:** strona aplikacyjna ZAMKNIĘTA · bramka na prawdziwym R2 **NIEPRZEJŚCIONA**.

> **Twarda zasada, dopóki tabela w §2 nie jest wypełniona na zielono:**
> nie wystawiaj produkcyjnego bucketu mediów pod `cdn.kuking.pl`.

Ten plik ma **datę w nagłówku i datę w każdym wierszu tabeli**, bo konfiguracja
bucketu w Cloudflare może się zmienić bez jednej linijki w tym repozytorium.
Dowód bez daty nie mówi nic o dzisiejszym stanie.

---

## 1. Co jest zamknięte w kodzie (i czego to NIE dowodzi)

### Zapis do R2 bez `x-amz-acl`

Cloudflare R2 nie implementuje S3-owych ACL na obiektach: `x-amz-acl` jest
w tabeli zgodności oznaczony jako **nieobsługiwany** dla `PutObject`,
a `GetObjectAcl` i `PutObjectAcl` nie istnieją tam wcale. Publiczność w R2 jest
cechą **bucketu**, nie obiektu.

Wbudowany sterownik Laravela `s3` wysyłał ACL i tak — przy **każdym** zapisie.
`League\Flysystem\AwsS3V3\AwsS3V3Adapter::upload()`:

```php
$acl = $options['params']['ACL'] ?? $this->determineAcl($config);
$this->client->upload($this->bucket, $key, $body, $acl, $options);
```

`determineAcl()` przy braku podanej widoczności zwraca `private`. Zdjęcie
trzeciego argumentu z `put()` (poprzedni krok, G-01) usunęło więc `public-read`,
ale **nie usunęło nagłówka**. Prywatność oryginałów — plików z pełnym EXIF-em,
czyli ze współrzędnymi GPS kuchni — zależała od tego, jak cudza implementacja
zareaguje na nagłówek, którego nie obsługuje. Cloudflare tego nie gwarantuje.

**Rozwiązanie:** własny sterownik dysku `r2`:

| plik | co robi |
|---|---|
| `app/Support/Storage/R2Adapter.php` | adapter Flysystem: `PutObject` (albo multipart) bez `ACL` i bez `Grant*`, `copy` bez `GetObjectAcl`, `setVisibility`/`visibility` rzucają wyjątek |
| `app/Support/Storage/DyskR2.php` | fabryka dysku — odpowiednik `FilesystemManager::createS3Driver()`, ten sam `S3Client` i ta sama klasa dysku, więc `url()` i `temporaryUrl()` działają jak dotąd |
| `app/Providers/AppServiceProvider.php` | `Storage::extend('r2', …)` |
| `config/filesystems.php` | `r2`, `r2_publiczne`, `r2_legacy`, `r2_eksporty` mają `driver => 'r2'` |
| `tests/Feature/ZapisDoR2BezAclTest.php` | test regresyjny na **prawdziwym, podpisanym żądaniu HTTP** |

Dlaczego adapter, a nie osobna wąska usługa magazynu (issue dopuszczało obie
drogi): `docs/MEDIA_PIPELINE.md` już rozstrzyga, że kod biznesowy korzysta
z Laravel Filesystem — dzięki temu dysk lokalny w testach i R2 na produkcji to
jedna ścieżka kodu. Własna usługa wokół `putObject/getObject` wymagałaby
przepisania każdego miejsca zapisu i odczytu (zdjęcia, warianty, paczki RODO,
`/health`, komenda przenosząca buckety), a przy okazji zabrałaby `Storage::fake()`
w testach i `temporaryUrl()` w `MediaController`. ACL trzeba wyciąć z jednego
miejsca — z żądania `PutObject`.

### Jak to jest sprawdzane

`Storage::fake()` ani mock na poziomie polecenia AWS **nie pokazują ani jednego
nagłówka**: nagłówki powstają dopiero przy serializacji żądania. Test podstawia
więc własny `handler` klienta S3 — ostatnie ogniwo stosu AWS SDK, już za
middleware podpisującym — i patrzy na kompletne żądanie HTTP. Nic nie wychodzi
do sieci.

Sprawdzone przez sam test: powrót ACL oblewa 6 z 12 przypadków, w tym cały
potok zdjęcia. Osobny przypadek (`test_wbudowany_sterownik_s3_wysyla_acl_…`)
dowodzi, że dawny sterownik **naprawdę** ten nagłówek wysyła — bez tego reszta
asercji przechodziłaby zawsze i nie pilnowałaby niczego.

### Czego kod nie dowodzi

Że prawdziwy bucket przyjmie takie żądanie, i że oryginał naprawdę nie jest
publiczny. **Tego z PHP nie widać.** Testy nie mają dostępu do panelu
Cloudflare, a to on stawia granicę: własna domena bucketu, `r2.dev`, klucze API.
Stąd §2.

---

## 2. Bramka na prawdziwym R2 — do wykonania po stronie właściciela

**Gdzie:** panel Cloudflare R2 (buckety `kuking-oryginaly` i `kuking-media`,
`r2.dev`, domena `cdn.kuking.pl`) plus jeden przebieg zapisu i odczytu na
środowisku staging podłączonym do prawdziwego R2.

**Czego potrzebujesz:** dostępu do konta Cloudflare, klucza API S3 do R2
(`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`) i `railway ssh` do serwisu.
Z kontenera agenta AI ani jeden z tych punktów nie jest wykonalny — nie ma tam
ani konta Cloudflare, ani bucketu.

Wypełnij kolumny **wynik** i **data**. Puste = nieprzejście.

| # | Co udowodnić | Jak | Wynik | Data |
|---|---|---|---|---|
| 1 | `GET https://cdn…/media/…_feed.webp` → **200** | `curl -sI` na adresie prawdziwego wariantu | | |
| 2 | `GET https://cdn…/incoming/….jpg` znanego oryginału → **403/404**, przez KAŻDĄ publiczną ścieżkę (własna domena, `r2.dev`, endpoint konta) | `curl -sI` po kolei na każdym z adresów | | |
| 3 | ten sam oryginał przez API S3 z serwera → **sukces** | `railway ssh -- php artisan tinker` → `Storage::disk('r2')->exists($klucz)` | | |
| 4 | `r2.dev` **wyłączone** na buckecie oryginałów | panel R2 → bucket → Settings → Public access | | |
| 5 | w publicznym buckecie **ani jednego** klucza `incoming/` | `aws s3api list-objects-v2 --bucket kuking-media --prefix incoming/ --endpoint-url …` → pusto | | |
| 6 | `PutObject` przechodzi **bez** `x-amz-acl` | wgraj zdjęcie przez formularz na stagingu i sprawdź, że wiersz `media` dostaje `ready` | | |
| 7 | plik bliski **15 MB** przechodzi | wgraj zdjęcie ~14,9 MB (limit `kuking.media.max_bytes`) | | |
| 8 | JPEG, PNG, WebP i AVIF — po jednej **prawdziwej** próbce | cztery wgrania z telefonu/aparatu, nie pliki generowane | | |
| 9 | worker wytwarza wszystkie **trzy** warianty | `metadata->variants` ma `thumb`, `feed`, `large` | | |
| 10 | oryginał ma EXIF, wariant **nie ma** | `exiftool` na pliku z bucketu oryginałów i na wariancie | | |
| 11 | skasowanie zabiera oryginał **i wszystkie** warianty | skasuj wpis, potem `list-objects-v2` na oba buckety | | |
| 12 | błąd zapisu do R2 daje bezpieczny komunikat i alert dla operatora | podmień sekret na błędny, spróbuj wgrać, sprawdź Sentry i to, co widzi człowiek | | |

Punkty 6–11 to jeden przebieg przez formularz — nie ma sensu robić ich osobno.

**Uwaga do punktu 2:** to jest jedyny punkt, którego nie wolno odhaczyć „bo
bucket nie ma domeny". Sprawdź adres, który **naprawdę istnieje** — weź klucz
oryginału z kolumny `media.object_key`. Odpowiedź 404 na wymyślony klucz nie
mówi nic o tym, czy bucket jest publiczny.

**Uwaga do punktu 12:** dyski R2 mają `throw => true`, więc nieudany zapis jest
wyjątkiem, nie cichym `false`. Człowiek ma zobaczyć polski komunikat, a nie
„opublikowano" i pustą ramkę.

---

## 3. Co pozostaje otwarte niezależnie od tej bramki

- **Domena `cdn.kuking.pl` przy buckecie wariantów.** Po W7-02 warianty nie mają
  publicznego adresu (adresem zdjęcia jest trasa `media.show`), ale zdjęcie
  klucza `url` z konfiguracji **nie zdejmuje domeny z bucketu** — dopóki
  `cdn.kuking.pl` tam wskazuje, stare adresy działają dalej. To czynność
  w panelu Cloudflare.
- **`ResponseCacheControl` na odpowiedzi R2.** Że AWS SDK potrafi zbudować taki
  podpisany adres, jest zmierzone offline
  (`ZdjecieObiektuDostajeTenSamNoStoreCoPrzekierowanieTest`). Że R2 ten parametr
  honoruje — nie jest zmierzone i wymaga prawdziwego bucketu.
- **Stary, jeden bucket (`r2_legacy`).** Publiczności nie zdejmujemy, dopóki
  `kuking:przenies-zdjecia` nie dojdzie do końca.
