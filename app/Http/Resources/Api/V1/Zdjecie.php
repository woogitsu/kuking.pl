<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Media;

/**
 * Zdjęcie w odpowiedzi API (D-272) — WYŁĄCZNIE adresy trasy
 * `api.zdjecia.show`, nigdy klucz w buckecie ani podpisany adres R2.
 *
 * Adres nie jest pozwoleniem: tę trasę obsługuje ten sam `MediaController`
 * co WWW, a on pyta `DostepDoZdjecia`, czy ta osoba może zobaczyć ten plik
 * — przez Policy rodzica (wpis, przepis, profil). Zdjęcie bez wariantu do
 * pokazania nie trafia do odpowiedzi wcale (AGENTS.md §7: widok nie pokazuje
 * zdjęcia w stanie innym niż gotowy; oryginał z EXIF-em nie wychodzi nigdy).
 */
final class Zdjecie
{
    /** Warianty, które aplikacja dostaje w odpowiedzi — te same co WWW. */
    private const WARIANTY = ['thumb', 'feed', 'large'];

    /**
     * @return array{id: string, alt: string|null, warianty: array<string, string>}|null
     */
    public static function z(?Media $zdjecie): ?array
    {
        if ($zdjecie === null || ! $zdjecie->maWariantDoPokazania()) {
            return null;
        }

        $warianty = [];

        foreach (self::WARIANTY as $nazwa) {
            $wybrany = $zdjecie->wariantDoSerwowania($nazwa);

            if ($wybrany !== null) {
                $warianty[$nazwa] = route('api.zdjecia.show', [
                    'media' => $zdjecie->getKey(),
                    'wariant' => $wybrany['nazwa'],
                ]);
            }
        }

        return [
            'id' => (string) $zdjecie->getKey(),
            'alt' => $zdjecie->alt_text,
            'warianty' => $warianty,
        ];
    }

    /**
     * @param  iterable<Media>  $zdjecia
     * @return list<array{id: string, alt: string|null, warianty: array<string, string>}>
     */
    public static function lista(iterable $zdjecia): array
    {
        $wynik = [];

        foreach ($zdjecia as $zdjecie) {
            $jedno = self::z($zdjecie);

            if ($jedno !== null) {
                $wynik[] = $jedno;
            }
        }

        return $wynik;
    }
}
