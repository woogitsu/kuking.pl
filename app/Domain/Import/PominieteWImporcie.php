<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Recipe;

/**
 * Co import musiał pominąć albo uciąć, bo przepis przekracza granice formularza
 * (#2521). Osobny klucz ostrzezen parsera (#2546/#2548) nie oznacza
 * przekroczenia limitu. Bez treści: tylko liczby i zamknięte nazwy pól.
 *
 * Limity zostają, jakie były (`Recipe::MAX_INGREDIENTS`, `Recipe::MAX_STEPS`,
 * `LimityTekstuPrzepisu`) — ta klasa tylko zapamiętuje, że zadziałały, żeby
 * autor mógł to przeczytać przy każdym otwarciu szkicu (kolumna
 * `przepisy_z_importu.pominiete`, `docs/DATABASE.md`).
 *
 * Nazwy obciętych pól: `tytul`, `opis`, `porcje`, `skladnik:N`, `grupa:N`,
 * `krok:N` — N to numer wiersza liczony od 1 po pominięciu pustych, czyli tak,
 * jak człowiek widzi listę w szkicu.
 */
final class PominieteWImporcie
{
    /** Ile nazw pól wymieniamy w zdaniu; reszta idzie jako „i N innych”. */
    private const WYMIENIONYCH = 10;

    /** @var array{skladniki?: int, przygotowanie?: bool, gotowanie?: bool} */
    public readonly array $ostrzezeniaParsera;

    /**
     * @param  list<string>  $obciete
     * @param  array<string, mixed>  $ostrzezeniaParsera
     */
    public function __construct(
        public readonly int $skladniki = 0,
        public readonly int $kroki = 0,
        public readonly array $obciete = [],
        array $ostrzezeniaParsera = [],
    ) {
        $this->ostrzezeniaParsera = self::zamknieteOstrzezenia($ostrzezeniaParsera);
    }

    public function niepelny(): bool
    {
        return $this->skladniki > 0 || $this->kroki > 0 || $this->obciete !== [];
    }

    public function maOstrzezeniaParsera(): bool
    {
        return ($this->ostrzezeniaParsera['skladniki'] ?? 0) > 0
            || ($this->ostrzezeniaParsera['przygotowanie'] ?? false)
            || ($this->ostrzezeniaParsera['gotowanie'] ?? false);
    }

    /** @return ?array{skladniki: int, kroki: int, obciete: list<string>, ostrzezenia_parsera?: array{skladniki?: int, przygotowanie?: bool, gotowanie?: bool}} */
    public function doTablicy(): ?array
    {
        return $this->niepelny() || $this->maOstrzezeniaParsera()
            ? ['skladniki' => $this->skladniki, 'kroki' => $this->kroki, 'obciete' => $this->obciete]
                + ($this->maOstrzezeniaParsera() ? ['ostrzezenia_parsera' => $this->ostrzezeniaParsera] : [])
            : null;
    }

    public static function zTablicy(mixed $dane): ?self
    {
        if (! is_array($dane)) {
            return null;
        }

        $obciete = [];

        foreach (is_array($dane['obciete'] ?? null) ? $dane['obciete'] : [] as $pole) {
            if (is_string($pole) && preg_match('/^(tytul|opis|porcje|(skladnik|grupa|krok):\d{1,3})$/', $pole) === 1) {
                $obciete[] = $pole;
            }
        }

        $ostrzezenia = is_array($dane['ostrzezenia_parsera'] ?? null) ? $dane['ostrzezenia_parsera'] : [];

        $wynik = new self(
            max(0, (int) ($dane['skladniki'] ?? 0)),
            max(0, (int) ($dane['kroki'] ?? 0)),
            $obciete,
            $ostrzezenia,
        );

        return $wynik->doTablicy() !== null ? $wynik : null;
    }

    /**
     * @param  array<string, mixed>  $surowe
     * @return array{skladniki?: int, przygotowanie?: bool, gotowanie?: bool}
     */
    private static function zamknieteOstrzezenia(array $surowe): array
    {
        $wynik = [];
        if (is_int($surowe['skladniki'] ?? null) && $surowe['skladniki'] > 0) {
            $wynik['skladniki'] = $surowe['skladniki'];
        }
        foreach (['przygotowanie', 'gotowanie'] as $pole) {
            if (($surowe[$pole] ?? false) === true) {
                $wynik[$pole] = true;
            }
        }

        return $wynik;
    }

    public function zdanieOstrzezenParsera(): string
    {
        if (! $this->maOstrzezeniaParsera()) {
            return '';
        }

        $zdania = [];
        $ile = $this->ostrzezeniaParsera['skladniki'] ?? 0;
        if ($ile > 0) {
            $zdania[] = 'Nie odczytaliśmy '.$ile.' '.self::odmien($ile, 'składnika', 'składników', 'składników')
                .' ze źródła. Sprawdź listę składników i dopisz brakujące pozycje.';
        }
        foreach (['przygotowanie' => 'przygotowania', 'gotowanie' => 'gotowania lub pieczenia'] as $pole => $nazwa) {
            if ($this->ostrzezeniaParsera[$pole] ?? false) {
                $zdania[] = 'Nie mogliśmy wpisać czasu '.$nazwa.' ze źródła. Sprawdź go w źródle i w razie potrzeby uzupełnij pole.';
            }
        }

        return implode(' ', $zdania);
    }

    /** Pierwsza linia komunikatu: ile wierszy pominięto. Pusta, gdy nic nie pominięto. */
    public function zdaniePominietych(): string
    {
        $czesci = [];

        if ($this->skladniki > 0) {
            $czesci[] = $this->skladniki.' '.self::odmien($this->skladniki, 'składnik', 'składniki', 'składników');
        }

        if ($this->kroki > 0) {
            $czesci[] = $this->kroki.' '.self::odmien($this->kroki, 'krok', 'kroki', 'kroków');
        }

        if ($czesci === []) {
            return '';
        }

        return 'Pominęliśmy '.implode(' i ', $czesci).', bo przepis przekracza limit '
            .Recipe::MAX_INGREDIENTS.' składników i '.Recipe::MAX_STEPS.' kroków. Pominięte są ostatnie pozycje listy.';
    }

    /** Druga linia: które pola ucięte. Pusta, gdy nic nie ucięto. */
    public function zdanieObcietych(): string
    {
        if ($this->obciete === []) {
            return '';
        }

        $nazwy = array_map(self::nazwaPola(...), array_slice($this->obciete, 0, self::WYMIENIONYCH));
        $reszta = count($this->obciete) - count($nazwy);

        return 'Za długie pola zostały skrócone i kończą się znakiem „…”: '.implode(', ', $nazwy)
            .($reszta > 0 ? ' i '.$reszta.' '.self::odmien($reszta, 'inne', 'inne', 'innych') : '').'.';
    }

    /** Całość do pokazania autorowi, razem z tym, co zrobić. */
    public function komunikat(): string
    {
        return trim(implode(' ', array_filter([
            $this->niepelny() ? 'Ten import jest niepełny.' : '',
            $this->zdaniePominietych(),
            $this->zdanieObcietych(),
            $this->zdanieOstrzezenParsera(),
            $this->niepelny()
                ? 'Co zrobić: porównaj szkic ze źródłem, dopisz brakujące pozycje ręcznie albo podziel przepis na dwa.'
                : 'Porównaj szkic ze źródłem i uzupełnij wskazane pola przed publikacją.',
            'Brak takiego ostrzeżenia nie znaczy, że odczyt jest bezbłędny — zawsze porównaj go ze źródłem.',
        ])));
    }

    private static function nazwaPola(string $pole): string
    {
        return match (true) {
            $pole === 'tytul' => 'nazwa przepisu',
            $pole === 'opis' => 'opis',
            $pole === 'porcje' => 'porcje',
            str_starts_with($pole, 'skladnik:') => substr($pole, 9).'. składnik',
            str_starts_with($pole, 'grupa:') => 'nazwa części przy '.substr($pole, 6).'. składniku',
            default => substr($pole, 5).'. krok',
        };
    }

    private static function odmien(int $n, string $jeden, string $kilka, string $wiele): string
    {
        if ($n === 1) {
            return $jeden;
        }

        $reszta10 = $n % 10;
        $reszta100 = $n % 100;

        return $reszta10 >= 2 && $reszta10 <= 4 && ($reszta100 < 12 || $reszta100 > 14) ? $kilka : $wiele;
    }
}
