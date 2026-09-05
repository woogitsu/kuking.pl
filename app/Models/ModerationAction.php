<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decyzja moderatora wraz z uzasadnieniem.
 *
 * `user_message` to treść, którą realnie zobaczył użytkownik. Trzymamy ją,
 * bo przy odwołaniu musimy wiedzieć, co mu powiedzieliśmy (DSA art. 17).
 */
class ModerationAction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const ACTION_NONE = 'no_action';

    public const ACTION_HIDE = 'hide';

    public const ACTION_REMOVE = 'remove';

    public const ACTION_WARN = 'warn';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_BAN = 'ban';

    /**
     * Które decyzje wolno podjąć dla którego typu zgłoszenia.
     *
     * DLACZEGO TO JEST JAWNA MACIERZ, A NIE ŁAŃCUCH `if`-ów
     *
     * Wcześniej każda akcja „coś robiła" dla każdego celu, przez `match` z
     * gałęzią `default`. Dwa skutki, oba groźne i oba ciche:
     *
     *   1. `remove` na zgłoszeniu OSOBY wywoływało `$user->delete()`.
     *      `User` nie ma SoftDeletes, a klucze obce mają `cascadeOnDelete` —
     *      więc jeden klik w przycisk podpisany „Usuń treść" kasował konto,
     *      profil, wszystkie wpisy, przepisy, zdjęcia, komentarze, zeszyty
     *      i wykonania. Bezpowrotnie, bez potwierdzenia, bez logu treści.
     *
     *   2. `suspend` na zgłoszonym WPISIE tylko ukrywało wpis. Konto autora
     *      zostawało aktywne, a zgłoszenie dostawało status „rozstrzygnięte".
     *      Moderator był przekonany, że zawiesił kogoś, kogo nie zawiesił.
     *
     * Macierz zamyka obie drogi u źródła: kombinacja spoza listy jest błędem
     * walidacji, a nie cichym „zrób coś innego". Formularz też pokazuje
     * wyłącznie te decyzje, które dla danego zgłoszenia mają sens.
     *
     * ŚWIADOMIE BRAK `remove` PRZY `user`. Usunięcie konta to nie jest decyzja
     * moderacyjna przy zgłoszeniu — to osobny proces z terminem na zmianę
     * zdania (RODO art. 17, `User::markForDeletion()`). Moderator, który chce
     * odciąć osobę od serwisu, ma `ban`.
     *
     * @var array<string, list<string>>
     */
    public const DOZWOLONE = [
        'user' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_SUSPEND, self::ACTION_BAN],
        'post' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_HIDE, self::ACTION_REMOVE, self::ACTION_SUSPEND, self::ACTION_BAN],
        'recipe' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_HIDE, self::ACTION_REMOVE, self::ACTION_SUSPEND, self::ACTION_BAN],
        'comment' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_HIDE, self::ACTION_REMOVE, self::ACTION_SUSPEND, self::ACTION_BAN],
        'cooked_event' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_HIDE, self::ACTION_REMOVE, self::ACTION_SUSPEND, self::ACTION_BAN],
    ];

    /** Etykiety po polsku — jedno źródło dla formularza i dla komunikatów. */
    public const ETYKIETY = [
        self::ACTION_NONE => 'Bez działania',
        self::ACTION_HIDE => 'Ukryj treść',
        self::ACTION_REMOVE => 'Usuń treść',
        self::ACTION_WARN => 'Ostrzeżenie dla autora',
        self::ACTION_SUSPEND => 'Zawieś konto autora',
        self::ACTION_BAN => 'Zablokuj konto autora na stałe',
    ];

    /**
     * Decyzje możliwe dla tego typu zgłoszenia.
     *
     * @return array<string, string> akcja => etykieta
     */
    public static function dozwoloneDla(string $typCelu): array
    {
        $akcje = self::DOZWOLONE[$typCelu] ?? [self::ACTION_NONE];

        return array_intersect_key(self::ETYKIETY, array_flip($akcje));
    }

    protected $fillable = [
        'moderator_id',
        'report_id',
        'target_type',
        'target_id',
        'action',
        'reason_code',
        'note',
        'user_message',
    ];

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
