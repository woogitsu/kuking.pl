<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Wynik `ZapamietanePorcje::wybor()`: wybór porcji do pokazania i to, skąd
 * pochodzi (#2602). Sam `WyborPorcji` zostaje taki, jaki był — ta klasa tylko
 * go opakowuje i niesie wiedzę o zapamiętanym ustawieniu widza.
 */
final readonly class WyborZapamietanychPorcji
{
    /** Liczba z zapamiętanego ustawienia (adres nie mówił o porcjach). */
    public const ZAPAMIETANE = 'zapamietane';

    /** Jawny `?porcje=N` w adresie. */
    public const ADRES = 'adres';

    /** Ilości autora: jawne `?porcje=autor` albo brak ustawienia. */
    public const AUTOR = 'autor';

    public function __construct(
        public WyborPorcji $wybor,
        /** Zapamiętana liczba tej osoby przy tym przepisie; `null` — nie ma. */
        public ?float $zapamietane,
        public string $zrodlo,
    ) {}

    public function maUstawienie(): bool
    {
        return $this->zapamietane !== null;
    }

    /** Widoczne ilości pochodzą z zapamiętanego ustawienia, nie z adresu. */
    public function zUstawienia(): bool
    {
        return $this->zrodlo === self::ZAPAMIETANE && $this->wybor->dostepny();
    }

    /**
     * Wartość `?porcje=` dla linku „Mniej”/„Więcej”/„Pokaż ilości z przepisu”
     * (`$ile === null` — ilości autora). Przy zapamiętanym ustawieniu adres
     * bez parametru oznacza „po mojemu”, więc ilości autora muszą być
     * jawne (`autor`), inaczej link wracałby do ustawienia, a nie do autora.
     */
    public function parametrDla(?float $ile): ?string
    {
        $parametr = $ile === null ? null : $this->wybor->doAdresu($ile);

        if ($parametr === null && $this->zapamietane !== null) {
            return ZapamietanePorcje::PARAMETR_AUTORA;
        }

        return $parametr;
    }

    /** Parametr, który odtwarza TO, co widać teraz (druk, kartka dla pomocnika). */
    public function parametrBiezacego(): ?string
    {
        if ($this->wybor->przeliczone()) {
            return $this->wybor->doAdresu((float) $this->wybor->wybrane);
        }

        return $this->zapamietane !== null && $this->wybor->dostepny() ? ZapamietanePorcje::PARAMETR_AUTORA : null;
    }
}
