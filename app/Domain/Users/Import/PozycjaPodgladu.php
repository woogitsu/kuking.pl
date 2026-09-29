<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

/**
 * Jedna pozycja z paczki widziana w podglądzie: przepis, wpis albo zeszyt.
 *
 * `odcisk` to skrót treści (SHA-256 z ujednoliconych pól), nie identyfikator
 * z paczki — kolejny etap zapisze go przy utworzonej treści, żeby ponowienie
 * tego samego importu niczego nie dublowało (issue #1985, pkt 3).
 */
final readonly class PozycjaPodgladu
{
    /** Można utworzyć: u tej osoby nie ma jeszcze takiej treści. */
    public const NOWA = 'nowa';

    /** Konflikt: u tej osoby jest już taka treść — o losie pozycji zdecyduje człowiek. */
    public const JUZ_JEST = 'juz_jest';

    /** Konflikt w samej paczce: ta sama treść występuje w niej więcej niż raz. */
    public const POWTORZONA_W_PACZCE = 'powtorzona_w_paczce';

    /** Nie do wczytania: brakuje pola albo wartość przekracza granice serwisu. */
    public const ODRZUCONA = 'odrzucona';

    /**
     * @param  list<string>  $uwagi  co się zmieni przy wczytaniu, np. „zostanie prywatny”
     */
    public function __construct(
        public string $rodzaj,
        public string $tytul,
        public string $stan,
        public ?string $powod,
        public array $uwagi,
        public string $odcisk,
    ) {}

    public function mozeBycUtworzona(): bool
    {
        return $this->stan === self::NOWA;
    }
}
