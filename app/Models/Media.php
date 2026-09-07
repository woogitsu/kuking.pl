<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;

/**
 * Metadane zdjęcia. Sam plik żyje w object storage pod `object_key`.
 *
 * Widoki NIGDY nie pokazują zdjęcia, które nie jest `ready` — dzięki temu
 * niezweryfikowany plik (albo taki z jeszcze nieusuniętym GPS-em z EXIF)
 * nie wycieka na stronę.
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'media';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'owner_id',
        'disk',
        'variants_disk',
        'object_key',
        'mime_type',
        'bytes',
        'width',
        'height',
        'status',
        'alt_text',
        'checksum_sha256',
        'perceptual_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Wpisy, do ktorych to zdjecie jest przypiete.
     *
     * Potrzebne do bramki wlasnosci przy odzyskiwaniu zdjec po nieudanej
     * walidacji (audyt C1): zdjecie juz przypiete do wpisu nie moze zostac
     * podpiete pod drugi.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Adres wariantu zdjęcia — TRASA APLIKACJI, nie adres pliku w buckecie
     * (audyt W7-02).
     *
     * DLACZEGO NIE `Storage::url()`, SKORO TAK BYŁO
     * Bo adres pliku pod własną domeną CDN nikogo o nic nie pyta. Kto raz go
     * skopiował, otwierał zdjęcie także po zablokowaniu, po cofnięciu
     * obserwowania i po przełączeniu przepisu na prywatny — bez konta, bez
     * sesji, bez śladu. Przy `recipes.source_scan_media_id` (skan odręcznej
     * kartki z nazwiskami i adresami) to jest awaria prywatności, a nie
     * niedogodność.
     *
     * Teraz adres prowadzi do `MediaController`, który pyta Policy treści
     * NADRZĘDNEJ i przekierowuje (302) na krótko podpisany adres R2. Bajty
     * nie idą przez PHP — idzie przez nie wyłącznie decyzja.
     *
     * TA METODA JEST JEDYNYM MIEJSCEM GENERUJĄCYM ADRES ZDJĘCIA i to jest
     * warunek działania całej zmiany. Drugie miejsce, które zbuduje adres
     * pliku samo, obchodzi kontrolę dostępu i nie wywali przy tym żadnego
     * testu — pilnuje tego `ZdjeciaChronioneNieWyciekajaTest`.
     *
     * NIGDY nie wraca do oryginału. Wcześniejsza wersja miała
     * `?? $this->object_key` jako zabezpieczenie przed pustą ramką i to był
     * wyciek: oryginał to plik przysłany przez użytkownika, z nietkniętym
     * EXIF-em, czyli z dokładną lokalizacją kuchni.
     *
     * Kolejność: żądany wariant → dowolny wygenerowany → placeholder.
     * Pusta ramka jest gorsza niż nic, ale wyciek cudzego adresu jest gorszy
     * od pustej ramki.
     */
    public function url(string $variant = 'feed'): string
    {
        $wybrany = $this->wariantDoSerwowania($variant);

        if ($wybrany === null) {
            Log::warning('Zdjęcie bez wygenerowanych wariantów', [
                'media_id' => $this->getKey(),
                'status' => $this->status,
            ]);

            return asset('icons/kuking-mark.svg');
        }

        return route('media.show', [
            'media' => $this->getKey(),
            'wariant' => $wybrany['nazwa'],
        ]);
    }

    /**
     * Który wariant naprawdę pójdzie do przeglądarki i pod jakim kluczem.
     *
     * Rozstrzygnięcie „żądany wariant → dowolny wygenerowany → nic" musi być
     * JEDNO, wspólne dla `url()` (buduje adres) i dla `MediaController`
     * (serwuje bajty). Dwie kopie tej samej kolejności rozjechałyby się przy
     * pierwszej zmianie listy wariantów: adres wskazywałby `large`, a
     * kontroler oddawałby `feed` albo 404.
     *
     * @return array{nazwa: string, klucz: string}|null
     */
    public function wariantDoSerwowania(string $variant = 'feed'): ?array
    {
        $variants = $this->warianty();

        $klucz = $variants[$variant]['key'] ?? null;

        if ($klucz !== null) {
            return ['nazwa' => $variant, 'klucz' => $klucz];
        }

        // Wariant nieznany, ale jakieś istnieją — bierzemy pierwszy lepszy.
        // To znaczy, że ktoś dodał wariant do konfiguracji i nie przetworzył
        // istniejących zdjęć; obraz będzie w złym rozmiarze, ale bezpieczny.
        foreach ($variants as $nazwa => $dane) {
            if (isset($dane['key'])) {
                return ['nazwa' => $nazwa, 'klucz' => $dane['key']];
            }
        }

        return null;
    }

    public function width(string $variant = 'feed'): ?int
    {
        return $this->warianty()[$variant]['width'] ?? $this->width;
    }

    public function height(string $variant = 'feed'): ?int
    {
        return $this->warianty()[$variant]['height'] ?? $this->height;
    }

    /**
     * Dysk, na którym leżą PUBLICZNE WARIANTY tego zdjęcia.
     *
     * Oryginał (`disk`) i warianty mogą być w dwóch różnych bucketach —
     * oryginał w prywatnym, warianty w tym za `cdn.kuking.pl` (audyt G-01).
     * Na R2 publiczność jest cechą bucketu, nie obiektu, więc trzymanie obu
     * w jednym buckecie wystawiało oryginały z EXIF-em i GPS-em.
     *
     * `null` znaczy „tam, gdzie oryginał" i tak jest dla każdego zdjęcia
     * zapisanego przed rozdzieleniem bucketów. Nie backfillujemy tej kolumny:
     * wpisanie tam nazwy nowego dysku byłoby stwierdzeniem nieprawdy o tym,
     * gdzie te pliki fizycznie leżą, a `KasujZdjecie` szukałoby ich w złym
     * buckecie i zostawiało publiczne kopie na zawsze.
     */
    public function variantsDisk(): string
    {
        return $this->variants_disk ?? $this->disk;
    }

    /**
     * Warianty zdjęcia z metadanych, z JAWNIE OPISANYM KSZTAŁTEM.
     *
     * `metadata` to JSONB rzutowany na tablicę, więc dla analizy statycznej
     * jest tablicą o nieznanej zawartości — stąd trzy ostrzeżenia
     * „Offset 'variants' on array{} does not exist", które trzymały PHPStana
     * na poziomie 0 dla całego repozytorium.
     *
     * To nie jest cisza dla analizatora. `ProcessUploadedImage` zapisuje tu
     * strukturę, którą trzy metody niżej czytają na trzy różne sposoby, a
     * jedyny opis tej struktury żył w komentarzu w jobie. Teraz kształt stoi
     * w typie, obok kodu, który go czyta — i `is_array()` sprawdza go naprawdę,
     * bo w bazie mogą leżeć wiersze sprzed każdej zmiany tego formatu.
     *
     * @return array<string, array{key?: string, width?: int, height?: int}>
     */
    private function warianty(): array
    {
        $metadata = $this->metadata;

        if (! is_array($metadata) || ! is_array($metadata['variants'] ?? null)) {
            return [];
        }

        return $metadata['variants'];
    }
}
