<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

/**
 * Co się stało z prośbą „to nic takiego" dla grupy oznaczeń automatu.
 *
 * Kontroler tłumaczy rodzaj na zdanie dla moderatora; akcja
 * `Actions\ZamknijGrupeSygnalow` niczego nie mówi człowiekowi.
 */
final readonly class WynikZamknieciaGrupy
{
    /** Zamknięto `$ile` oznaczeń (co najmniej jedno). */
    public const ZAMKNIETO = 'zamknieto';

    /** Od wyświetlenia strony grupa urosła — nic nie zamknięto (#1059). */
    public const UROSLA = 'urosla';

    /** Nie było czego zamykać: ktoś inny zamknął grupę w międzyczasie. */
    public const PUSTA = 'pusta';

    /** Klucz grupy nie jest identyfikatorem konta ani `brak`. */
    public const NIEZNANA_GRUPA = 'nieznana_grupa';

    /** Grupa to oznaczenia własnych treści moderatora (audyt A5-11). */
    public const WLASNE = 'wlasne';

    public function __construct(
        public string $rodzaj,
        public int $ile = 0,
    ) {}
}
