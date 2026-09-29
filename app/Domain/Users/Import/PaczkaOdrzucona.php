<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

use RuntimeException;

/**
 * Cała paczka jest nie do wczytania — komunikat jest gotowym zdaniem dla człowieka.
 *
 * Wyjątek niesie tylko polskie zdanie mówiące, co zrobić (AGENTS.md §5, §11),
 * i krótki `kod` dla testów i dziennika. Nigdy nie niesie treści z pliku:
 * paczka pochodzi od użytkownika, więc jej zawartość nie trafia ani do komunikatu,
 * ani do logu (issue #1985: „audytowalny bez utrwalania całej zawartości”).
 */
final class PaczkaOdrzucona extends RuntimeException
{
    public const NIE_ZIP = 'nie_zip';

    public const ZA_DUZO_PLIKOW = 'za_duzo_plikow';

    public const NIEBEZPIECZNA_NAZWA = 'niebezpieczna_nazwa';

    public const BRAK_DANYCH = 'brak_danych';

    public const ZA_DUZE_DANE = 'za_duze_dane';

    public const NIE_JSON = 'nie_json';

    public const NIE_Z_KUKING = 'nie_z_kuking';

    public const NOWSZY_FORMAT = 'nowszy_format';

    public const ZLA_STRUKTURA = 'zla_struktura';

    public const ZA_DUZO_POZYCJI = 'za_duzo_pozycji';

    public function __construct(public readonly string $kod, string $komunikat)
    {
        parent::__construct($komunikat);
    }
}
