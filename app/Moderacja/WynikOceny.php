<?php

declare(strict_types=1);

namespace App\Moderacja;

/**
 * ODPOWIEDŹ MODELU, SPROWADZONA DO TEGO, CO NAM POTRZEBNE (D-055).
 *
 * API oddaje kilkanaście kategorii z wynikami liczbowymi i własną flagę
 * `flagged`. My bierzemy z tego wyłącznie kategorie, które przekroczyły
 * NASZ próg (`moderation.model.prog`), i to jest świadome: `flagged` przy
 * polszczyźnie i kuchni bywa hojne — „zabiłam kurę na rosół", „krwisty
 * stek", „ubić pianę" — a każde trafienie kosztuje uwagę jedynego
 * moderatora.
 *
 * `pilne` znaczy: nie może czekać do jutrzejszego podsumowania. Wyłącznie
 * kategorie z `KategorieModeracji::PILNE`.
 */
final readonly class WynikOceny
{
    /**
     * @param  array<string, float>  $kategorie  kategoria → wynik, tylko te powyżej progu
     * @param  bool  $pilne  czy któraś z nich jest z listy `KategorieModeracji::PILNE`
     * @param  string  $czego  czego dotyczyła ocena, po polsku („tekst wpisu", „zdjęcie")
     */
    public function __construct(
        public array $kategorie,
        public bool $pilne,
        public string $czego,
    ) {}

    public function costamZnalazl(): bool
    {
        return $this->kategorie !== [];
    }

    /**
     * Powód dla moderatora — zdanie po polsku, z wynikiem, ale nie SAM wynik.
     *
     * „Model ocenił zdjęcie: treść seksualna (pewność 82%)." Kolejność
     * kategorii od najwyższego wyniku, bo pierwsza z nich nadaje sprawie
     * charakter.
     */
    public function powod(): string
    {
        $kategorie = $this->kategorie;
        arsort($kategorie);

        $opisy = [];

        foreach ($kategorie as $kategoria => $wynik) {
            $opisy[] = KategorieModeracji::opis($kategoria).' (pewność '.round($wynik * 100).'%)';
        }

        return 'Model ocenił '.$this->czego.': '.implode(', ', $opisy).'.';
    }
}
