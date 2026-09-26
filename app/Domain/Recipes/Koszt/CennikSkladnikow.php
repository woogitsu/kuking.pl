<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Koszt;

use App\Models\CenaSkladnika;
use Illuminate\Support\Collection;

/**
 * Dopasowanie tekstu składnika do wiersza cennika (D-286, część 2).
 *
 * Dopasowanie jest CAŁYMI SŁOWAMI, nie początkiem słowa: „maka" nie może
 * trafiać w „makaron". Wiersze są sprawdzane po kolei (`kolejnosc` z pliku
 * CSV), pierwszy pasujący wygrywa — dlatego „kiełbasa sucha" stoi przed
 * „kiełbasą". Pole `wyklucz` to początki słów, które odrzucają trafienie
 * („mąka ziemniaczana" nie jest mąką pszenną).
 *
 * Cennik wczytuje się raz na obiekt — strona przepisu pyta o kilkanaście
 * składników, a tabela ma kilkadziesiąt wierszy.
 */
final class CennikSkladnikow
{
    /** @var Collection<int, CenaSkladnika>|null */
    private ?Collection $wiersze = null;

    public function dopasuj(string $tekstSkladnika): ?CenaSkladnika
    {
        $tekst = ' '.IloscZTekstu::normalizuj($tekstSkladnika).' ';
        $slowa = preg_split('/[^a-z]+/', $tekst, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($this->wiersze() as $wiersz) {
            if (! $this->pasuje($wiersz->wzorce, $tekst)) {
                continue;
            }

            if ($this->wykluczone((string) $wiersz->wyklucz, $slowa)) {
                continue;
            }

            return $wiersz;
        }

        return null;
    }

    public function pusty(): bool
    {
        return $this->wiersze()->isEmpty();
    }

    /** @return Collection<int, CenaSkladnika> */
    private function wiersze(): Collection
    {
        return $this->wiersze ??= CenaSkladnika::query()->orderBy('kolejnosc')->get();
    }

    private function pasuje(string $wzorce, string $tekst): bool
    {
        foreach (explode('|', $wzorce) as $wzorzec) {
            $wzorzec = trim($wzorzec);

            if ($wzorzec !== '' && preg_match('/(?<![a-z])'.preg_quote($wzorzec, '/').'(?![a-z])/', $tekst) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $slowa
     */
    private function wykluczone(string $wyklucz, array $slowa): bool
    {
        foreach (explode('|', $wyklucz) as $poczatek) {
            $poczatek = trim($poczatek);

            if ($poczatek === '') {
                continue;
            }

            foreach ($slowa as $slowo) {
                if (str_starts_with($slowo, $poczatek)) {
                    return true;
                }
            }
        }

        return false;
    }
}
