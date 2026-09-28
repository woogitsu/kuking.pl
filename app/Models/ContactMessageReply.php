<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna odpowiedź operatora na wiadomość z „Napisz do nas" (D-058).
 *
 * To jest ślad po LIŚCIE, który naprawdę wyszedł — albo nie wyszedł. Do tej
 * pory jedynym śladem odpowiedzi była notatka, którą moderator sam sobie
 * zapisał („odpisane z Gmaila"), czyli zdanie o niesprawdzalnej treści.
 *
 * `status` NIE MA W `$fillable` — ta sama zasada, co przy `ContactMessage`
 * i `User` (AGENTS.md §7). Stan wysyłki nie jest polem formularza: ustawia go
 * wyłącznie `App\Domain\Contact\WyslijOdpowiedzNaWiadomosc`, po tym jak
 * dostawca poczty coś powiedział. Gdyby `status` był fillable, wystarczyłoby
 * dopisać `status=wyslana` do żądania POST, żeby panel pokazał „wysłano" nad
 * listem, którego nikt nigdy nie wysłał.
 */
class ContactMessageReply extends Model
{
    use HasUuids;

    /** Zapisana, wysyłka jeszcze nierozstrzygnięta. */
    public const STATUS_W_TOKU = 'w_toku';

    /** Dostawca poczty potwierdził przyjęcie listu. */
    public const STATUS_WYSLANA = 'wyslana';

    /** Nie wyszedł i wiemy o tym. */
    public const STATUS_NIEUDANA = 'nieudana';

    /**
     * Etykiety na ekran. Mówią o LIŚCIE, nie o wierszu w bazie — „W trakcie
     * wysyłania" zamiast „w_toku", bo to jest zdanie, które moderator czyta
     * przy sprawie człowieka czekającego na odpowiedź.
     */
    public const STATUSY = [
        self::STATUS_W_TOKU => 'Wysyłka w toku',
        self::STATUS_WYSLANA => 'Wysłana',
        self::STATUS_NIEUDANA => 'Nie udało się wysłać',
    ];

    protected $fillable = [
        'contact_message_id',
        'author_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContactMessage, $this>
     */
    public function wiadomosc(): BelongsTo
    {
        return $this->belongsTo(ContactMessage::class, 'contact_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSY[$this->status] ?? $this->status;
    }

    public function wyszla(): bool
    {
        return $this->status === self::STATUS_WYSLANA;
    }

    /**
     * Jedyna droga do stanu „wysłana".
     *
     * Komplet naraz (`status` + `sent_at`), bo CHECK
     * `contact_message_replies_sent_complete` nie przyjmie połowy — a i bez
     * niego „wysłana" bez godziny nie odpowiadałaby na pytanie, po które
     * ktokolwiek na ten ekran wchodzi.
     */
    public function oznaczWyslana(): void
    {
        $this->forceFill([
            'status' => self::STATUS_WYSLANA,
            'sent_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * Jedyna droga do stanu „nieudana".
     *
     * `sent_at` ZERUJEMY, zamiast zostawić — inaczej ponowiona i znów
     * nieudana próba mogłaby zostawić godzinę wysłania przy liście, który
     * nie wyszedł. CHECK odrzuciłby to i tak, ale komunikat bazy jest
     * gorszym miejscem na naukę niż ta metoda.
     */
    public function oznaczNieudana(string $powod): void
    {
        $this->forceFill([
            'status' => self::STATUS_NIEUDANA,
            'sent_at' => null,
            'error' => $powod,
        ])->save();
    }
}
