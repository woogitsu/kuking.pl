<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedno zlecenie odczytu przepisu (V2, D-298) — tabela `importy_przepisow`.
 *
 * WIERSZ NIE NIESIE TREŚCI PRZEPISU. Treść od pierwszej chwili stoi w zwykłym
 * szkicu (`recipe_id`), a zdjęcie kartki w `recipes.source_scan_media_id`.
 * Tu jest tylko ślad: kto, kiedy, jaki stan, ile kosztowało.
 *
 * `$fillable` JEST PUSTE CELOWO. `status`, `kod_bledu`, klucz właściciela
 * i kwoty budżetu to pola sterujące (AGENTS.md §7, D-006) — zapisują je
 * wyłącznie nazwane akcje z `App\Domain\Import` przez `forceFill()`.
 *
 * @property string $id
 * @property string $user_id
 * @property ?string $recipe_id
 * @property string $zrodlo
 * @property string $status
 * @property ?string $kod_bledu
 * @property int $proby
 * @property ?int $rezerwacja_mikrousd
 * @property ?string $rezerwacja_dzien
 * @property ?int $koszt_mikrousd
 * @property ?array<string, mixed> $odpowiedz_modelu
 */
class ImportPrzepisu extends Model
{
    use HasUuids;

    protected $table = 'importy_przepisow';

    protected $fillable = [];

    public const ZRODLO_ZDJECIE = 'zdjecie';

    public const ZRODLO_URL = 'url';

    public const ZRODLO_PDF = 'pdf';

    public const STATUS_OCZEKUJE = 'oczekuje';

    public const STATUS_W_TOKU = 'w_toku';

    public const STATUS_GOTOWY = 'gotowy';

    public const STATUS_NIEUDANY = 'nieudany';

    public const STATUS_WSTRZYMANY_LIMITEM = 'wstrzymany_limitem';

    /** Stany, po których nic się już samo nie zmieni. */
    public const STATUSY_KONCOWE = [self::STATUS_GOTOWY, self::STATUS_NIEUDANY, self::STATUS_WSTRZYMANY_LIMITEM];

    // Zamknięta lista kodów błędu — CHECK `importy_przepisow_kod_bledu_check`.
    public const KOD_LIMIT_OSOBY = 'limit_osoby';

    public const KOD_BUDZET_DZIENNY = 'budzet_dzienny';

    public const KOD_BUDZET_MIESIECZNY = 'budzet_miesieczny';

    public const KOD_BRAK_ZGODY = 'brak_zgody';

    public const KOD_WYLACZONY = 'wylaczony';

    public const KOD_MODEL_NIEDOSTEPNY = 'model_niedostepny';

    public const KOD_NIECZYTELNE = 'nieczytelne';

    public const KOD_ODPOWIEDZ_BLEDNA = 'odpowiedz_bledna';

    public const KOD_ZDJECIE_NIEDOSTEPNE = 'zdjecie_niedostepne';

    public const KOD_SZKIC_ZMIENIONY = 'szkic_zmieniony';

    public const KOD_BLAD_WEWNETRZNY = 'blad_wewnetrzny';

    protected function casts(): array
    {
        return [
            'odpowiedz_modelu' => 'array',
            'proby' => 'integer',
            'rezerwacja_mikrousd' => 'integer',
            'koszt_mikrousd' => 'integer',
            'tokeny_wejscia' => 'integer',
            'tokeny_wyjscia' => 'integer',
            'rozpoczeto_at' => 'datetime',
            'zakonczono_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function jestKoncowy(): bool
    {
        return in_array($this->status, self::STATUSY_KONCOWE, true);
    }

    /** Czy człowiek może kliknąć „Spróbuj jeszcze raz” — zlecenie skończyło się bez szkicu z tekstem. */
    public function moznaPonowic(): bool
    {
        return in_array($this->status, [self::STATUS_NIEUDANY, self::STATUS_WSTRZYMANY_LIMITEM], true)
            && $this->kod_bledu !== self::KOD_SZKIC_ZMIENIONY
            && $this->recipe_id !== null;
    }
}
