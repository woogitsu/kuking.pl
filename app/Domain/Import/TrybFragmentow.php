<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Składanie szkicu z granic fragmentów wyznaczonych przez model (D-300,
 * wymaganie z pilota #814 w `docs/research/ai-pilots/RAPORT.md`).
 *
 * Model oddaje listę `[{do: numer_ostatniego_wiersza, etykieta}]`.
 * Odpowiedź jest PRZYJMOWANA tylko wtedy, gdy:
 *  - granice rosną ściśle i zaczynają się od wiersza 1,
 *  - ostatnia granica to ostatni wiersz (pełne pokrycie — nic nie ginie
 *    po cichu i nic nie jest „dopisane za końcem"),
 *  - każda etykieta jest z zamkniętej listy, a element nie ma obcych pól.
 * Inaczej `null` — i szkic nie powstaje z tej odpowiedzi.
 *
 * Tekst każdego pola to DOSŁOWNIE wiersze z oryginału: składnik = jeden
 * wiersz, krok = fragment sklejony spacją, tytuł = fragment sklejony spacją.
 */
final class TrybFragmentow
{
    public const ETYKIETY = ['tytul', 'opis', 'skladnik', 'krok', 'pomin'];

    /**
     * @param  list<string>  $wiersze
     * @param  mixed  $fragmenty  odpowiedź modelu, jeszcze niesprawdzona
     */
    public function zloz(array $wiersze, mixed $fragmenty): ?OdczytanyPrzepis
    {
        if (! is_array($fragmenty) || $fragmenty === [] || $wiersze === [] || ! array_is_list($fragmenty)) {
            return null;
        }

        $ostatni = 0;
        $tytul = [];
        $opis = [];
        $skladniki = [];
        $kroki = [];

        foreach ($fragmenty as $fragment) {
            if (! is_array($fragment) || array_diff(array_keys($fragment), ['do', 'etykieta']) !== []) {
                return null;
            }

            $do = $fragment['do'] ?? null;
            $etykieta = $fragment['etykieta'] ?? null;

            if (! is_int($do) || ! is_string($etykieta) || ! in_array($etykieta, self::ETYKIETY, true)) {
                return null;
            }

            if ($do <= $ostatni || $do > count($wiersze)) {
                return null;
            }

            $kawalek = array_slice($wiersze, $ostatni, $do - $ostatni);
            $ostatni = $do;

            match ($etykieta) {
                'tytul' => array_push($tytul, ...$kawalek),
                'opis' => array_push($opis, ...$kawalek),
                'skladnik' => array_push($skladniki, ...$kawalek),
                'krok' => $kroki[] = implode(' ', $kawalek),
                'pomin' => null,
            };
        }

        if ($ostatni !== count($wiersze)) {
            return null;
        }

        $przepis = new OdczytanyPrzepis(
            tytul: $tytul !== [] ? implode(' ', $tytul) : 'Przepis ze strony',
            opis: $opis !== [] ? implode(' ', $opis) : null,
            skladniki: $skladniki,
            kroki: $kroki,
        );

        return $przepis->pusty() ? null : $przepis;
    }
}
