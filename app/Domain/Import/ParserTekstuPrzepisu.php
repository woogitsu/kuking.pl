<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Lokalny odczyt przepisu z czystego tekstu (PDF z warstwą tekstu) — bez
 * modelu AI i bez kosztu (D-300: model tylko dla strony bez JSON-LD i dla
 * PDF-a bez warstwy tekstu).
 *
 * Reguła jest prosta i przewidywalna, bo człowiek i tak sprawdza szkic:
 *  - tytuł = pierwszy wiersz;
 *  - jeśli w tekście są nagłówki „Składniki" i „Przygotowanie" (albo
 *    „Sposób przygotowania", „Wykonanie", „Przygotowanie:"), wiersze między
 *    nimi to składniki, a akapity po drugim to kroki;
 *  - jeśli nagłówków nie ma, CAŁY tekst (bez tytułu) trafia do kroków,
 *    akapit na krok. Nic nie ginie — człowiek przeniesie składniki sam.
 *
 * Kroki numerowane („1.", „2)") są rozdzielane po numerze, nawet gdy stoją
 * bez pustej linii między sobą.
 */
final class ParserTekstuPrzepisu
{
    private const NAGLOWEK_SKLADNIKOW = '/^(sk[łl]adniki|potrzebne sk[łl]adniki|lista sk[łl]adnik[óo]w|potrzebujesz|ingredients)\s*:?\s*$/iu';

    private const NAGLOWEK_KROKOW = '/^(przygotowanie|spos[óo]b przygotowania|spos[óo]b wykonania|wykonanie|przyrz[aą]dzenie|instrukcja|instructions|method|directions)\s*:?\s*$/iu';

    public function odczytaj(string $tekst): ?OdczytanyPrzepis
    {
        $tekst = str_replace(["\r\n", "\r", "\f"], "\n", $tekst);
        $wiersze = array_map(
            static fn (string $w): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $w)),
            explode("\n", $tekst),
        );

        // Tytuł — pierwszy niepusty wiersz.
        $tytul = '';

        foreach ($wiersze as $i => $wiersz) {
            if ($wiersz !== '') {
                $tytul = $wiersz;
                $wiersze = array_slice($wiersze, $i + 1);

                break;
            }
        }

        if ($tytul === '') {
            return null;
        }

        $iSkladnikow = $this->indeks($wiersze, self::NAGLOWEK_SKLADNIKOW);
        $iKrokow = $this->indeks($wiersze, self::NAGLOWEK_KROKOW);

        if ($iSkladnikow !== null && $iKrokow !== null && $iSkladnikow < $iKrokow) {
            $opis = $this->akapity(array_slice($wiersze, 0, $iSkladnikow));
            $skladniki = array_values(array_filter(
                array_map(fn (string $w): string => $this->bezPunktora($w), array_slice($wiersze, $iSkladnikow + 1, $iKrokow - $iSkladnikow - 1)),
                static fn (string $w): bool => $w !== '',
            ));
            $kroki = $this->kroki(array_slice($wiersze, $iKrokow + 1));

            $przepis = new OdczytanyPrzepis(
                tytul: $tytul,
                opis: $opis !== [] ? implode("\n\n", $opis) : null,
                skladniki: $skladniki,
                kroki: $kroki,
            );
        } else {
            $przepis = new OdczytanyPrzepis(tytul: $tytul, kroki: $this->kroki($wiersze));
        }

        return $przepis->pusty() ? null : $przepis;
    }

    /**
     * @param  list<string>  $wiersze
     */
    private function indeks(array $wiersze, string $wzorzec): ?int
    {
        foreach ($wiersze as $i => $wiersz) {
            if (preg_match($wzorzec, $wiersz) === 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $wiersze
     * @return list<string>
     */
    private function kroki(array $wiersze): array
    {
        $kroki = [];
        $biezacy = [];

        foreach ($wiersze as $wiersz) {
            $numerowany = preg_match('/^(\d{1,2})[.)]\s+/u', $wiersz) === 1;

            if ($wiersz === '' || $numerowany) {
                if ($biezacy !== []) {
                    $kroki[] = implode(' ', $biezacy);
                    $biezacy = [];
                }

                if ($wiersz === '') {
                    continue;
                }
            }

            $biezacy[] = $numerowany ? (string) preg_replace('/^\d{1,2}[.)]\s+/u', '', $wiersz) : $wiersz;
        }

        if ($biezacy !== []) {
            $kroki[] = implode(' ', $biezacy);
        }

        return $kroki;
    }

    /**
     * @param  list<string>  $wiersze
     * @return list<string>
     */
    private function akapity(array $wiersze): array
    {
        $akapity = [];
        $biezacy = [];

        foreach ($wiersze as $wiersz) {
            if ($wiersz === '') {
                if ($biezacy !== []) {
                    $akapity[] = implode(' ', $biezacy);
                    $biezacy = [];
                }

                continue;
            }

            $biezacy[] = $wiersz;
        }

        if ($biezacy !== []) {
            $akapity[] = implode(' ', $biezacy);
        }

        return $akapity;
    }

    private function bezPunktora(string $wiersz): string
    {
        return trim((string) preg_replace('/^([-–—•*·▪●◦]|\d{1,2}[.)])\s*/u', '', $wiersz));
    }
}
