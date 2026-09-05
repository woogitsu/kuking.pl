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
