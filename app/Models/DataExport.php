<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /** Konto zostało usunięte, zanim job zdążył zbudować paczkę (`GenerateUserExport::handle()`). */
    public const REASON_ACCOUNT_MISSING = 'account_missing';

    /** Zapis gotowej paczki do magazynu plików się nie udał (`App\Exceptions\DataExportStorageFailure`). */
    public const REASON_STORAGE = 'storage';

    /** Budowa paczki przekroczyła limit czasu joba (15 minut, `GenerateUserExport::$timeout`). */
    public const REASON_TIMEOUT = 'timeout';

    /** Worek na resztę: każda inna awaria, której nie da się bezpiecznie pokazać wprost. */
    public const REASON_UNKNOWN = 'unknown';

    /**
     * Zamknięty zbiór PRAWDZIWYCH awarii `GenerateUserExport` (audyt W7-07,
     * migracja `2026_09_06_210000_convert_data_export_failure_reason_to_codes`)
     * — wyznaczony z jego `handle()`, `copyToTemp()` i `openZip()`, nie
     * z wyobraźni. Klucz idzie do kolumny `failure_reason`, wartość na ekran
     * (patrz `failureReasonLabel()`) — dokładnie jak `Report::REASONS`.
     *
     * `$e->getMessage()` (SQLSTATE, ścieżka na dysku tymczasowym, komunikat
     * biblioteki ZIP) NIGDY tu nie trafia — zostaje wyłącznie w logu
     * (`Log::warning` w `GenerateUserExport::handle()`).
     *
     * `{kontakt}` podmienia się w `failureReasonLabel()` na
     * `config('kuking.community.contact_email')` — adres ma jedno źródło
     * prawdy w konfiguracji, nie jest wpisany na sztywno w kilku miejscach.
     */
    public const REASONS = [
        self::REASON_ACCOUNT_MISSING => 'To konto już nie istnieje, więc nie mamy z czego przygotować paczki z danymi. '
            .'Jeśli uważasz, że to pomyłka, napisz do nas: {kontakt}.',
        self::REASON_STORAGE => 'Nie udało się zapisać paczki w naszym magazynie plików. '
            .'Spróbuj przygotować paczkę jeszcze raz za kilka minut. Jeśli to się powtórzy, napisz do nas: {kontakt}.',
        self::REASON_TIMEOUT => 'Przygotowanie paczki trwało za długo i zostało przerwane. '
            .'Spróbuj przygotować paczkę jeszcze raz. Jeśli to się powtórzy, napisz do nas: {kontakt}.',
        self::REASON_UNKNOWN => 'Nie udało się przygotować paczki z Twoimi danymi. '
            .'Spróbuj jeszcze raz, a jeśli to się powtórzy, napisz do nas: {kontakt}.',
    ];

    protected $fillable = [
        'user_id',
        'status',
        'disk',
        'object_key',
        'bytes',
        'completed_at',
        'expires_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Powód niepowodzenia po polsku, bezpieczny dla ekranu (patrz REASONS
     * powyżej). Nieznany, pusty albo sprzed W7-07 kod dostaje tekst spod
     * REASON_UNKNOWN — NIGDY `$this->failure_reason` wprost, inaczej stary
     * wolny tekst (albo literówka w przyszłym kodzie) i tak wylądowałby
     * na ekranie.
     */
    public function failureReasonLabel(): string
    {
        $text = self::REASONS[$this->failure_reason] ?? self::REASONS[self::REASON_UNKNOWN];

        return str_replace('{kontakt}', (string) config('kuking.community.contact_email'), $text);
    }
}
