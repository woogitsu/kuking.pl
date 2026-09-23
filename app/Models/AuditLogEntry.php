<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Skrot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

/**
 * Wpis w dzienniku audytu.
 *
 * Zasady: logujemy FAKT i AKTORA, nigdy treści ani tokenów. IP wyłącznie jako
 * hash — do wykrywania nadużyć wystarcza, a nie tworzy zbędnego zbioru danych
 * osobowych (docs/SECURITY_PRIVACY_LEGAL.md).
 */
class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    /**
     * Kategorie zdarzeń, których RETENCJA (issue #19,
     * docs/decyzje/ADR_RETENCJE.md §3.1, §5.1) NIGDY nie kasuje — niezależnie
     * od wieku wiersza. Zamknięta stała, nie config: zmiana tej listy ma
     * przechodzić przez code review, nie przez zmienną środowiskową ani plik
     * konfiguracyjny edytowalny bez recenzji (patrz `config/kuking.php` →
     * `audit_log.retention_months`, gdzie ta decyzja jest wyjaśniona z drugiej
     * strony).
     *
     * `App\Domain\Compliance\PrzedawnioneWpisyAudytu::posprzataj()` filtruje
     * właśnie po tej stałej — `AuditLogEntryNigdyNieKasujTest` pilnuje, że
     * żaden wiersz o `action` z tej listy nie znika, niezależnie od tego, jak
     * bardzo jest stary.
     *
     * `account.data_erased` — JEDYNY dowód, że prawo do usunięcia konta
     * (RODO art. 17) zostało FAKTYCZNIE wykonane. Po wykonaniu wiersz `users`
     * jest zanonimizowany (`EraseAccountData`), nie skasowany — nie ma więc
     * żadnego innego miejsca w bazie, które odpowie na pytanie "czy i kiedy
     * to konto zostało usunięte".
     *
     * `account.delete_requested` i `account.delete_cancelled` —
     * `User::cancelDeletion()` ZERUJE `delete_requested_at` na wierszu
     * `users` (`forceFill(['delete_requested_at' => null, ...])`). Po
     * cofnięciu jedynym miejscem w CAŁEJ bazie, które mówi, że ktoś w ogóle
     * zgłosił usunięcie konta i potem zmienił zdanie, są te dwa wpisy tutaj.
     * Skasowanie ich po ogólnym okresie retencji usuwałoby jedyny ślad
     * własnej decyzji użytkownika — dowód, o który zapyta regulator albo sam
     * użytkownik przy sporze ("nigdy nie prosiłem o usunięcie konta").
     *
     * LISTA JEST ZAMKNIĘTA. Rozszerzenie wymaga tego samego zmierzonego
     * powodu co powyższe trzy pozycje ("ten wiersz jest jedynym dowodem
     * czegoś, co RODO/DSA wymaga umieć wykazać") — nie samej ostrożności.
     * `moderation.decided`, `content.reported` i `appeal.*` ŚWIADOMIE tu nie
     * są — ich pełny, autorytatywny zapis żyje w `reports`/`moderation_actions`/
     * `appeals`, którym retencja daje dłuższy okres niż domyślny `audit_log`
     * (`config('kuking.moderation.case_retention_months')` vs
     * `config('kuking.audit_log.retention_months')`) — wpis tutaj jest
     * cieńszą kopią, która może wygasnąć wcześniej bez utraty dowodu.
     *
     * @var list<string>
     */
    public const NIGDY_NIE_KASUJ = [
        'account.data_erased',
        'account.delete_requested',
        'account.delete_cancelled',
    ];

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'ip_hash',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Zapisz zdarzenie w dzienniku.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ip = null,
    ): self {
        return self::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject?->getKey(),
            'ip_hash' => $ip === null ? null : Skrot::hmac($ip),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Zapisz zdarzenie PO ZATWIERDZONEJ zmianie — tak, żeby awaria dziennika
     * nie zamieniła udanej zmiany w błąd dla człowieka (#1373, #1343, #1363).
     *
     * DLA KOGO: wpis, który stoi ZA transakcją zmiany (D-088: „dziennik
     * audytu zostaje POZA transakcją… jest osobnym śladem, nie częścią
     * relacji"). W tym miejscu zmiana jest już trwała i nic jej nie cofnie,
     * więc wyjątek z `record()` dawał tylko jedno: odpowiedź „nie udało
     * się" przy koncie, zgłoszeniu albo decyzji, które istnieją. Ponowienie
     * odbijało się wtedy od nich („adres zajęty", „już zamknięte") i też
     * nie uzupełniało wpisu.
     *
     * CO ROBI Z AWARIĄ: nie połyka jej. `report()` oddaje ją do obsługi
     * wyjątków (log, zewnętrzny monitoring) z nazwą zdarzenia i podmiotu,
     * więc operator widzi, KTÓREGO wpisu brakuje — tak samo jak przy
     * `NotifyReporterReceipt::potwierdzBezWywracaniaSprawy()`. Autorytatywny
     * zapis tych zdarzeń żyje w tabelach zmiany (`users`, `reports`,
     * `moderation_actions`) — patrz komentarz przy `NIGDY_NIE_KASUJ`.
     *
     * PUNKT ZAPISU, a nie gołe `create()`: wołana wewnątrz CUDZEJ transakcji
     * (komenda, test, przyszły endpoint) nieudany `INSERT` zerwałby ją
     * w PostgreSQL (25P02) — wycofanie do punktu zapisu zostawia połączenie
     * zdatne do dalszej pracy.
     *
     * NIE DLA wpisów, które są częścią zmiany i mają z nią stać albo paść
     * razem (`moderation.decided`, `user.role_changed`, `post.published`):
     * te wołają `record()` WEWNĄTRZ transakcji zmiany.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function recordBezWywracania(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $metadata = [],
        ?string $ip = null,
    ): ?self {
        try {
            return DB::transaction(fn (): self => self::record($action, $actor, $subject, $metadata, $ip));
        } catch (Throwable $awaria) {
            report(new RuntimeException(
                'Nie zapisał się wpis dziennika audytu „'.$action.'"'
                .($subject === null ? '' : ' dla '.class_basename($subject).' '.$subject->getKey())
                .' — zmiana, którą opisuje, jest już zatwierdzona.',
                previous: $awaria,
            ));

            return null;
        }
    }
}
