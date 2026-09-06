# Media pipeline

Zdjęcia są kluczową częścią Kuking i jednym z głównych kosztów.

## Flow

```text
client
→ backend: prepare upload
→ signed URL
→ object storage
→ complete
→ background job
→ validation
→ EXIF/GPS strip
→ resize
→ variants
→ moderation
→ ready
```

## Walidacja

Sprawdzić:
- magic bytes;
- realny MIME;
- rozmiar;
- wymiary;
- limit megapikseli;
- możliwość dekodowania;
- checksum.

Nie ufać rozszerzeniu.

### Lista formatów to lista tego, co UMIEMY OTWORZYĆ

`config/kuking.php` → `media.accepted_mime_types` może zawierać wyłącznie
formaty, które to środowisko naprawdę przetworzy — nie te, które umie nazwać.
To rozróżnienie już raz kosztowało: HEIC i HEIF stały na liście, bo rozpoznaje
je `mime_content_type()`, ale PHP 8.4 nie ma stałej `IMAGETYPE_HEIC`
(`getimagesize()` zwraca `false`), a GD ich nie dekoduje. Siedem formularzy
podpowiadało więc format, po którym serwis odpowiadał „ten plik nie wygląda
na zdjęcie" — komuś, kto właśnie zrobił zdjęcie telefonem.

Pilnuje tego `ObiecujemyTylkoFormatyKtoreUmiemyTest`. Wartość atrybutu `accept`
w formularzach bierze się z tej samej listy, przez `LimityZdjec::atrybutAccept()`.

### Ile to kosztuje pamięci

Zmierzone: szczyt RSS procesu przy przetworzeniu jednego zdjęcia razem z trzema
wariantami (gd, PHP 8.4, `ProcessUploadedImage`).

| wymiary | szczyt RSS | czas |
|---|---:|---:|
| 12 Mpx (4000×3000) | 161 MB | 1,3 s |
| 24 Mpx (5657×4243) | 254 MB | 2,4 s |
| 50 Mpx (8165×6124) | 452 MB | 4,6 s |

50 Mpx to limit z `media.max_megapixels`, czyli najgorszy dozwolony przypadek.
Worker ma 1024 MB (`.railway/railway.ts`), więc zapas jest ponad dwukrotny.

**`memory_limit` PHP tych liczb NIE WIDZI.** Przy 50 Mpx licznik PHP pokazuje
28 MB, a RSS 452 MB — libgd alokuje bitmapę poza licznikiem PHP. Wynikają z tego
dwie rzeczy, obie wcześniej zapisane w komentarzach odwrotnie:

- podnoszenie `PHP_WORKER_MEMORY_LIMIT` nie pomoże na brak pamięci przy
  dekodowaniu obrazu;
- taka awaria nie zgłosi „Allowed memory size exhausted" — proces zniknie,
  zabity przez OOM kontenera, bez śladu w Sentry. Objawem będzie zdjęcie
  w statusie `processing` (łapie to `ProcessUploadedImage::failed()`).

Podniesienie `max_megapixels` wymaga sprawdzenia tej tabeli wobec pamięci
kontenera i wobec `--memory` w `docker/entrypoint.sh`; pilnuje tego
`BudzetPamieciZdjecTest`.

## Prywatność

Usuwać:
- GPS;
- zbędny EXIF;
- dane urządzenia.

## Warianty

Propozycja:
- thumb 320 px;
- feed 960 px;
- large 1600 px.

WebP/AVIF, z fallbackiem zgodnym z support matrix.

## Oryginał

Nie musi być publicznie serwowany. Można go trzymać krótko do reprocessingu zgodnie z retention policy.

## Moderacja

Automatyka może flagować, ale nie powinna samodzielnie permanentnie banować bez odpowiedniej polityki.

## Storage

Kod biznesowy korzysta z Laravel Filesystem.

Dzięki temu:
```text
Railway/S3-compatible storage
→ Cloudflare R2
```
jest migracją infrastruktury, a nie przepisywaniem domeny.
