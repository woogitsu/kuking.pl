<?php

declare(strict_types=1);

namespace App\Models;

use App\Poczta\PowodOdmowy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * List, którego dostawca nie przyjął i który już nie wyjdzie (issue #234,
 * D-062). Wiersz w `mail_failures`.
 *
 * TO NIE JEST ENCJA Z ŻYCIORYSEM, TYLKO ZDARZENIE. Powstaje raz, w chwili
 * porażki, i zmienia się dokładnie jeden raz: gdy właściciel odhaczy, że już
 * o nim wie (`zauwazony_at`). Dlatego `$timestamps = false` — nie ma czego
 * aktualizować, a `updated_at` sugerowałby, że wiersz się zmienia.
 *
 * `$fillable` NIE ISTNIEJE i to jest celowe — ta sama zasada co
 * w `LoginLinkToken` i `PendingEmailChange`: wiersz zapisuje wyłącznie
 * `App\Poczta\ZapiszNieudanyList`, jawnie, pole po polu, a domyślny
 * `$guarded = ['*']` Eloquenta odrzuca tu każde masowe przypisanie. Do tej
 * tabeli nie prowadzi żaden formularz i nie ma prowadzić.
 *
 * @property string $id
 * @property string|null $failed_job_uuid
 * @property PowodOdmowy $powod
 * @property int|null $status_http
 * @property string $rodzaj
 * @property string|null $kolejka
 * @property int $prob
 * @property string|null $user_id
 * @property string|null $komunikat
 * @property Carbon $failed_at
 * @property Carbon|null $zauwazony_at
 * @property-read User|null $user
 */
class MailFailure extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'mail_failures';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'powod' => PowodOdmowy::class,
            'status_http' => 'integer',
            'prob' => 'integer',
            'failed_at' => 'datetime',
            'zauwazony_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Listy, o których właściciel jeszcze nie wie.
     *
     * To jest JEDYNE pytanie, które zadaje `/health`, i dlatego indeks pod nim
     * jest częściowy (`WHERE zauwazony_at IS NULL`). Świadomie BEZ okna
     * czasowego: alarm o liście, który przepadł trzy godziny temu, nie ma
     * prawa zgasnąć sam z siebie, bo wtedy `/health` znowu zaczyna wyglądać
     * jak sukces — czyli wracamy do usterki z issue #234.
     */
    public function scopeNieodhaczone(Builder $zapytanie): Builder
    {
        return $zapytanie->whereNull('zauwazony_at');
    }

    /**
     * Czy TEMU człowiekowi przepadł TAKI list w ostatnich `$godzin` godzinach.
     *
     * Używa tego ekran „Potwierdź adres e-mail", żeby nie obiecywać listu,
     * który nie wyszedł (D-062 §4). Okno czasowe jest tu na miejscu — inaczej
     * niż w `nieodhaczone()` — bo to jest zdanie DLA CZŁOWIEKA o tym, co
     * dzieje się teraz, a nie alarm dla właściciela. Po dobie człowiek i tak
     * ma na ekranie przycisk „Wyślij wiadomość jeszcze raz".
     */
    public static function przepadlListDo(string $userId, string $rodzaj, int $godzin): ?self
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('rodzaj', $rodzaj)
            ->where('failed_at', '>=', now()->subHours(max(1, $godzin)))
            ->orderByDesc('failed_at')
            ->first();
    }
}
