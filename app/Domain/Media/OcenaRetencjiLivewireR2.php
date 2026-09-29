<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Ocena reguł lifecycle bucketu R2 pod kątem katalogu uploadów Livewire (#2051).
 *
 * Czysta funkcja na tablicy reguł w kształcie odpowiedzi
 * `GetBucketLifecycleConfiguration` — bez sieci, bez dysku, bez logów. Klucza
 * obiektu ani nazwy bucketu tu nie ma i nie będzie: wynik trafia do zgłoszeń.
 *
 * Co uznajemy za DOBRĄ regułę: włączona, wygasza obiekty po co najwyżej
 * jednym dniu i jej prefiks jest DOKŁADNIE katalogiem Livewire (z ukośnikiem).
 *
 * Co uznajemy za GROŹNĄ regułę: włączone wygasanie, którego prefiks obejmuje
 * także `incoming/` (pusty prefiks = cały bucket, ale też `in` albo
 * `incoming/`). Oczyszczone oryginały są potrzebne do wariantów i do eksportu
 * RODO, a kasowanie ich jest nieodwracalne.
 *
 * Czego ta klasa NIE widzi: Bucket Lock (R2 nie oddaje go przez API S3)
 * i tego, czy R2 faktycznie kasuje obiekty. To zostaje w runbooku.
 */
final class OcenaRetencjiLivewireR2
{
    /** Prefiks oczyszczonych oryginałów, którego reguła nigdy nie może objąć. */
    public const PREFIKS_ORYGINALOW = 'incoming/';

    /** Najdłuższy czas życia, jaki jeszcze zgadza się z obietnicą „po jednym dniu”. */
    public const MAKS_DNI = 1;

    /**
     * @param  list<array<string, mixed>>  $reguly  `Rules` z odpowiedzi S3
     * @param  string  $prefiks  katalog Livewire z ukośnikiem na końcu
     * @return array{dobra: bool, alarm: list<string>, uwagi: list<string>}
     */
    public static function ocen(array $reguly, string $prefiks): array
    {
        $dobra = false;
        $alarm = [];
        $uwagi = [];

        foreach ($reguly as $regula) {
            if (($regula['Status'] ?? '') !== 'Enabled' || ! isset($regula['Expiration'])) {
                continue;
            }

            $regulaPrefiks = self::prefiks($regula);
            $dni = isset($regula['Expiration']['Days']) ? (int) $regula['Expiration']['Days'] : null;

            if (str_starts_with(self::PREFIKS_ORYGINALOW, $regulaPrefiks)) {
                $alarm[] = $regulaPrefiks === ''
                    ? 'Włączona reguła wygasania ma PUSTY prefiks — obejmuje cały bucket, także `incoming/`.'
                    : "Włączona reguła wygasania z prefiksem `{$regulaPrefiks}` obejmuje także `incoming/`.";

                continue;
            }

            if ($regulaPrefiks !== $prefiks) {
                continue;
            }

            if ($dni !== null && $dni >= 1 && $dni <= self::MAKS_DNI) {
                $dobra = true;
            } else {
                $uwagi[] = $dni === null
                    ? "Reguła dla `{$prefiks}` wygasza po dacie, nie po liczbie dni."
                    : "Reguła dla `{$prefiks}` wygasza po {$dni} dniach, a obietnica to najwyżej ".self::MAKS_DNI.'.';
            }
        }

        if (! $dobra) {
            $uwagi[] = "Brak włączonej reguły wygasania dla prefiksu `{$prefiks}` z czasem życia do ".self::MAKS_DNI.' dnia.';
        }

        return ['dobra' => $dobra && $alarm === [], 'alarm' => $alarm, 'uwagi' => $uwagi];
    }

    /**
     * Reguły starszego kształtu mają `Prefix` na wierzchu, nowsze — w `Filter`.
     *
     * @param  array<string, mixed>  $regula
     */
    private static function prefiks(array $regula): string
    {
        return (string) ($regula['Filter']['Prefix'] ?? $regula['Prefix'] ?? '');
    }
}
