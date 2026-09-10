<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POWIĄZANIE KONTA KUKING Z KONTEM U DOSTAWCY ZEWNĘTRZNEGO (D-098).
 *
 * Jeden wiersz = „to konto Kuking wchodzi także kontem u TEGO dostawcy,
 * o TYM identyfikatorze, od TEJ chwili".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO `$fillable` JEST PUSTE — najważniejsze zdanie w tym pliku
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wiersz w tej tabeli JEST DROGĄ WEJŚCIA NA KONTO, dokładnie tak samo jak
 * hasło. Kto go założy, ten wchodzi na to konto jednym kliknięciem. Gdyby
 * te pola stały na liście masowego przypisania, dowolny dzisiejszy
 * i przyszły `create($request->all())` — także taki, który o dostawcach
 * tożsamości w ogóle nie myśli — byłby przejęciem konta. Ta sama zasada co
 * `status`, `role` i `email` na `users` (AGENTS.md §7).
 *
 * Powiązania powstają WYŁĄCZNIE przez `User::connectGoogle()`, czyli przez
 * jawną, nazwaną metodę, którą da się znaleźć i której wejścia da się
 * policzyć.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TU NIE MA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ani tokenu dostępu, ani tokenu odświeżania, ani adresu e-mail
 * z dostawcy (D-069, rozstrzygnięcie 4). Token odświeżania w naszej bazie
 * byłby trwałym pełnomocnictwem do cudzego konta Google, leżącym
 * w serwisie, który go do niczego nie używa — dlatego żądanie idzie
 * z `access_type=online` i Google nam go nie wystawia. Adresu nie
 * kopiujemy, bo mamy go już na `users` i dwie kopie rozjechałyby się przy
 * pierwszej zmianie adresu; adres z dostawcy służy DOKŁADNIE RAZ, przy
 * pierwszym połączeniu.
 *
 * @property string $user_id
 * @property string $dostawca
 * @property string $identyfikator
 * @property Carbon $connected_at
 */
class TozsamoscZewnetrzna extends Model
{
    /**
     * Nazwy dostawców. Ta sama lista, co CHECK w bazie — rozszerza ją
     * MIGRACJA, nie stała w PHP, więc literówka nie założy nowego rodzaju
     * powiązania po cichu.
     */
    public const DOSTAWCA_GOOGLE = 'google';

    protected $table = 'tozsamosci_zewnetrzne';

    /** Patrz komentarz klasy: pusto, i to jest zabezpieczenie, nie przeoczenie. */
    protected $fillable = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
