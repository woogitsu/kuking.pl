<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jedna przeglądarka, której człowiek pozwolił pokazywać powiadomienia
 * Kuking (Web Push, issue #35, D-303).
 *
 * `$fillable` JEST PUSTE. Wiersz jest zgodą na przerywanie komuś dnia —
 * powstaje wyłącznie przez `ZapiszSubskrypcjePush`, który sprawdza host
 * adresu (SSRF) i przypina wiersz do zalogowanego konta. Żaden
 * `create($request->all())` nie ma prawa go założyć ani przepiąć.
 *
 * @property string $id
 * @property string $user_id
 * @property string $endpoint
 * @property string $klucz_p256dh
 * @property string $klucz_auth
 * @property string $kodowanie
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PushSubscription extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['klucz_p256dh', 'klucz_auth'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nazwa zrozumiała dla człowieka: po usłudze push poznaje się przeglądarkę. */
    public function nazwaPrzegladarki(): string
    {
        $host = $this->usluga();

        return match (true) {
            str_ends_with($host, 'fcm.googleapis.com') => 'Chrome, Edge albo inna przeglądarka oparta na Chrome',
            str_ends_with($host, 'push.services.mozilla.com') => 'Firefox',
            str_ends_with($host, 'push.apple.com') => 'Safari',
            str_ends_with($host, 'notify.windows.com') => 'Edge (Windows)',
            default => 'Przeglądarka',
        };
    }

    /** Sama nazwa usługi push, bez adresu — do ekranu i paczki RODO. */
    public function usluga(): string
    {
        return (string) parse_url($this->endpoint, PHP_URL_HOST);
    }
}
