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
use Illuminate\Support\Facades\Storage;

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
     * Publiczny URL wariantu zdjęcia.
     *
     * NIGDY nie wraca do oryginału.
     *
     * Wcześniejsza wersja miała `?? $this->object_key` jako zabezpieczenie
     * przed pustą ramką. To był wyciek: oryginał to plik przysłany przez
     * użytkownika, z nietkniętym EXIF-em — czyli z dokładną lokalizacją
     * kuchni, w której zrobiono zdjęcie. Wystarczyłoby dodać nowy wariant
     * do konfiguracji, żeby fallback uruchomił się dla wszystkich istniejących
     * zdjęć naraz.
     *
     * Kolejność: żądany wariant → dowolny wygenerowany → placeholder.
     * Pusta ramka jest gorsza niż nic, ale wyciek cudzego adresu jest gorszy
     * od pustej ramki.
     */
    public function url(string $variant = 'feed'): string
    {
        $variants = $this->warianty();

        $key = $variants[$variant]['key'] ?? null;

        if ($key === null && $variants !== []) {
            // Wariant nieznany, ale jakieś istnieją — bierzemy pierwszy lepszy.
            // To znaczy, że ktoś dodał wariant do konfiguracji i nie przetworzył
            // istniejących zdjęć; obraz będzie w złym rozmiarze, ale bezpieczny.
            $key = reset($variants)['key'] ?? null;
        }

        if ($key === null) {
            Log::warning('Zdjęcie bez wygenerowanych wariantów', [
                'media_id' => $this->getKey(),
                'status' => $this->status,
            ]);

            return asset('icons/kuking-mark.svg');
        }

        return Storage::disk($this->disk)->url($key);
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
