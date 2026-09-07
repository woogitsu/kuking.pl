<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Zdjęcie ukrycia (issue #65).
     *
     * ŚWIADOMIE NIE MA GO W `DOZWOLONE`. Ta macierz opisuje decyzje możliwe
     * przy ROZPATRYWANIU ZGŁOSZENIA — a przywrócenie nie jest odpowiedzią na
     * zgłoszenie, tylko cofnięciem wcześniejszej decyzji. Zgłoszenie jest
     * wtedy dawno rozstrzygnięte i `decide()` słusznie nie przyjmuje drugiej
     * decyzji. Przywracanie ma własny endpoint i własny przycisk.
     */
    public const ACTION_UNHIDE = 'unhide';

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

        // ŚWIADOMIE BRAK `hide` PRZY `cooked_event`. „Ugotowałem" nie ma
        // kolumny `status` — nie ma czego ustawić na `hidden`. Przycisk
        // istniał i nie robił NIC: zgłoszenie dostawało status
        // „rozstrzygnięte", autor dostawał powiadomienie „ukryliśmy Twoją
        // treść", a wykonanie stało w serwisie dalej. Moderator był
        // przekonany, że coś zrobił (znalezione przy #65).
        //
        // Wykonanie zdejmuje się z widoku przez `remove` (soft delete) —
        // i ono działa naprawdę.
        'cooked_event' => [self::ACTION_NONE, self::ACTION_WARN, self::ACTION_REMOVE, self::ACTION_SUSPEND, self::ACTION_BAN],
    ];

    /**
     * Decyzje, od których wolno się odwołać (issue #10, DSA art. 20).
     *
     * Brakuje tu `no_action` — zgłoszenie odrzucone nie dotknęło nikogo, więc
     * nie ma się od czego odwoływać (zgłaszający ma osobną drogę: dopisać
     * nowe fakty do zgłoszenia). Brakuje też `unhide` — nikt nie odwołuje się
     * od dobrej wiadomości.
     *
     * @var list<string>
     */
    public const ODWOLYWALNE = [
        self::ACTION_HIDE,
        self::ACTION_REMOVE,
        self::ACTION_WARN,
        self::ACTION_SUSPEND,
        self::ACTION_BAN,
    ];

    /** Etykiety po polsku — jedno źródło dla formularza i dla komunikatów. */
    public const ETYKIETY = [
        self::ACTION_NONE => 'Bez działania',
        self::ACTION_HIDE => 'Ukryj treść',
        self::ACTION_UNHIDE => 'Przywróć treść',
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
    public static function dozwoloneDla(?string $typCelu): array
    {
        // Nieznany typ (np. `unknown` przy zgłoszeniu z nierozpoznanym
        // adresem) daje samo `none`: sprawę można zamknąć i odpowiedzieć
        // zgłaszającemu, ale nie da się ukryć treści, której nie wskazano.
        $akcje = self::DOZWOLONE[$typCelu] ?? [self::ACTION_NONE];

        return array_intersect_key(self::ETYKIETY, array_flip($akcje));
    }

    protected $fillable = [
        'moderator_id',
        'report_id',
        'target_type',
        'target_id',
        'subject_user_id',
        'action',
        'previous_status',
        'reason_code',
        'note',
        'user_message',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** Osoba, której ta decyzja dotyczy — autor treści albo zgłoszone konto. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Odwołanie od tej decyzji. Najwyżej jedno — `UNIQUE` w bazie. */
    public function appeal(): HasOne
    {
        return $this->hasOne(Appeal::class);
    }

    public function label(): string
    {
        return self::ETYKIETY[$this->action] ?? $this->action;
    }

    /**
     * Do kiedy można się odwołać.
     *
     * SZEŚĆ MIESIĘCY, bo tyle wymaga art. 20 ust. 1 DSA („co najmniej sześć
     * miesięcy od decyzji"). Wcześniej było 14 dni — liczba wzięta
     * z `docs/legal/MODERATION_PLAYBOOK.md`, gdzie powstała z rozsądku
     * operacyjnego, nie z przepisu (pomiar: `docs/decyzje/DSA_POMIAR.md`).
     *
     * Liczone od DECYZJI, nie od przeczytania jej przez użytkownika —
     * inaczej termin nigdy by nie mijał komuś, kto nie zagląda do serwisu.
     *
     * `addMonths(6)`, a nie `addDays(180)`: regulamin mówi ludziom „6
     * miesięcy", a sześć miesięcy kalendarzowych jest zawsze DŁUŻSZE niż 180
     * dni, więc liczenie w miesiącach nie potrafi wypaść na niekorzyść osoby,
     * która policzyła termin z kalendarza. `appeal_days` z konfiguracji
     * zostaje jako DOLNA GRANICA, poniżej której termin nie ma prawa zejść —
     * gdyby ktoś ustawił ją niżej niż wymaga DSA, bierzemy sześć miesięcy.
     */
    public function appealDeadline(): CarbonInterface
    {
        $zKonfiguracji = $this->created_at->copy()->addDays((int) config('kuking.moderation.appeal_days'));
        $szescMiesiecy = $this->created_at->copy()->addMonths(6);

        return $zKonfiguracji->greaterThan($szescMiesiecy) ? $zKonfiguracji : $szescMiesiecy;
    }

    /** Czy od tej decyzji da się jeszcze złożyć odwołanie. */
    public function isAppealable(): bool
    {
        return in_array($this->action, self::ODWOLYWALNE, true)
            && $this->appealDeadline()->isFuture();
    }
}
