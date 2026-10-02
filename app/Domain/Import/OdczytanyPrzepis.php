<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Recipe;
use App\Support\LimityTekstuPrzepisu;

/**
 * Przepis odczytany ze źródła — zanim stanie się szkicem.
 *
 * Wszystkie pola są TEKSTEM ZE ŹRÓDŁA: nic tu nie jest przeliczane,
 * uzupełniane ani zgadywane (projekt §2.2 pkt 4 — „brak ilości zostaje
 * brakiem"). Liczby porcji i minut wchodzą tylko wtedy, gdy źródło podaje
 * je jako jedną liczbę; „4–6 porcji" daje `null`, bo zgadywanie jest
 * gorsze niż puste pole.
 *
 * Konstruktor przycina pola do granic formularza (`LimityTekstuPrzepisu`),
 * żeby szkic dało się zapisać w kreatorze bez błędu walidacji na polu,
 * którego człowiek nie wpisywał.
 */
final class OdczytanyPrzepis
{
    public readonly string $tytul;

    public readonly ?string $opis;

    /** @var list<string> */
    public readonly array $skladniki;

    /** @var list<string> */
    public readonly array $kroki;

    /** Co pominięto albo ucięto przez granice formularza (#2521) — nic nie znika po cichu. */
    public readonly PominieteWImporcie $pominiete;

    /**
     * @param  list<string>  $skladniki
     * @param  list<string>  $kroki
     */
    public function __construct(
        string $tytul,
        ?string $opis = null,
        public readonly ?float $porcje = null,
        public readonly ?int $przygotowanieMinut = null,
        public readonly ?int $gotowanieMinut = null,
        array $skladniki = [],
        array $kroki = [],
        /** `totalTime` ze źródła — zachowany osobno, nigdy dzielony na prep/cook (#2572). */
        public readonly ?int $lacznieMinut = null,
    ) {
        $obciete = [];

        $tytul = trim($tytul);
        $this->tytul = self::przytnij($tytul, LimityTekstuPrzepisu::POLA['title']);
        if ($tytul !== $this->tytul) {
            $obciete[] = 'tytul';
        }

        $opis = $opis === null ? null : trim($opis);
        $this->opis = $opis === null || $opis === '' ? null : self::przytnij($opis, LimityTekstuPrzepisu::POLA['summary']);
        if ($opis !== null && $this->opis !== $opis) {
            $obciete[] = 'opis';
        }

        [$czysteSkladniki, $obcieteSkladniki] = self::czyste($skladniki, LimityTekstuPrzepisu::POLA['ingredients.*.text']);
        [$czysteKroki, $obcieteKroki] = self::czyste($kroki, LimityTekstuPrzepisu::POLA['steps.*.instruction']);

        $this->skladniki = array_slice($czysteSkladniki, 0, Recipe::MAX_INGREDIENTS);
        $this->kroki = array_slice($czysteKroki, 0, Recipe::MAX_STEPS);

        // Numery obciętych wierszy dotyczą tylko wierszy, które zostały.
        foreach ($obcieteSkladniki as $nr) {
            if ($nr <= Recipe::MAX_INGREDIENTS) {
                $obciete[] = 'skladnik:'.$nr;
            }
        }

        foreach ($obcieteKroki as $nr) {
            if ($nr <= Recipe::MAX_STEPS) {
                $obciete[] = 'krok:'.$nr;
            }
        }

        $this->pominiete = new PominieteWImporcie(
            max(0, count($czysteSkladniki) - Recipe::MAX_INGREDIENTS),
            max(0, count($czysteKroki) - Recipe::MAX_STEPS),
            $obciete,
        );
    }

    public function pusty(): bool
    {
        return $this->skladniki === [] && $this->kroki === [];
    }

    /** Cały tekst kroków — do porównania z opisem przy publikacji (D-300). */
    public function tekstKrokow(): string
    {
        return implode("\n", $this->kroki);
    }

    /**
     * @param  list<string>  $wiersze
     * @return array{0: list<string>, 1: list<int>} wiersze bez pustych oraz numery (od 1) wierszy skróconych
     */
    private static function czyste(array $wiersze, int $limit): array
    {
        $wynik = [];
        $obciete = [];

        foreach ($wiersze as $wiersz) {
            $wiersz = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $wiersz));

            if ($wiersz !== '') {
                $wynik[] = self::przytnij($wiersz, $limit);

                if (mb_strlen($wiersz) > $limit) {
                    $obciete[] = count($wynik);
                }
            }
        }

        return [$wynik, $obciete];
    }

    private static function przytnij(string $tekst, int $limit): string
    {
        return mb_strlen($tekst) > $limit ? rtrim(mb_substr($tekst, 0, $limit - 1)).'…' : $tekst;
    }
}
