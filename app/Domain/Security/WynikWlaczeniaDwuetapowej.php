<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Rozstrzygnięcie `WlaczDwuetapowa` — kontroler wybiera po nim odpowiedź (#970).
 *
 * `kodyJawne` jest wypełnione tylko przy `WLACZONO`: to jedyny moment,
 * w którym serwis zna jawną treść kodów zapasowych.
 */
final readonly class WynikWlaczeniaDwuetapowej
{
    /** Konto nie zaczęło ustawiania (brak sekretu): wróć na ekran włączenia. */
    public const BRAK_SEKRETU = 'brak_sekretu';

    /** 2FA już potwierdzona (stary formularz, druga karta): nic nie zużyto. */
    public const JUZ_WLACZONE = 'juz_wlaczone';

    /** Hasło nie pasuje: kodu nie sprawdzono ani nie zużyto. */
    public const ZLE_HASLO = 'zle_haslo';

    /** Kod z aplikacji nie pasuje do sekretu. */
    public const ZLY_KOD = 'zly_kod';

    /** Zapis przegrał z drugą kartą: sekret się zmienił albo 2FA potwierdzono w międzyczasie. */
    public const PRZEGRANA_Z_DRUGA_KARTA = 'przegrana';

    /** 2FA włączona tym żądaniem. */
    public const WLACZONO = 'wlaczono';

    /** @param  list<string>  $kodyJawne */
    public function __construct(
        public string $status,
        public array $kodyJawne = [],
    ) {}
}
