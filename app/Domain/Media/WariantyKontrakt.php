<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;

/**
 * JEDEN walidator kontraktu `metadata.variants`, wspólny dla bramek migracji
 * zdjęć (issue #1905).
 *
 * PO CO TO ISTNIEJE
 * `kuking:przenies-zdjecia` i `kuking:sprawdz-zdjecia-po-przenosinach`
 * iterowały `metadata['variants'] ?? []` z gołym `foreach` i pomijały każdy
 * wpis, który nie wyglądał jak `['key' => '...']` — CICHO, przez `continue`.
 * Pusta tablica, `null` albo wpis bez `key` dawały wtedy dokładnie taki sam
 * wynik jak komplet poprawnych wariantów: „nie ma czego sprawdzić" == „nic
 * nie brakuje". Migrator mógł przestawić `disk` rekordu, którego wariantów
 * nigdy nie potwierdził, a raport zależności twierdził, że każdy sprawdzony
 * wiersz ma swoje pliki, choć nie miał czego sprawdzić.
 *
 * KONTRAKT (status `ready`)
 * `metadata.variants` musi być NIEPUSTĄ tablicą, każdy klucz tej tablicy
 * musi być nazwą wariantu (tekst, nie liczba — inaczej to jest lista, nie
 * mapa), a każda wartość musi być tablicą z polem `key`, które jest
 * niepustym tekstem. Wszystko inne jest błędem DANYCH, nie brakiem pliku —
 * i to jest cała różnica względem `skopiuj()`/`Storage::exists()` niżej
 * w tych komendach: TAM nie ma czego kopiować, TU nie wiadomo, co sprawdzić.
 *
 * Statusy inne niż `ready` (`pending`, `processing`, `deleted`, `rejected`)
 * z definicji mogą nie mieć kompletu wariantów — dla nich brak, pustka albo
 * pojedynczy uszkodzony wpis NIE JEST błędem: taki wpis jest po prostu
 * pomijany, dokładnie jak w kodzie sprzed tej poprawki. Bramka migracji
 * pyta wyłącznie o zdjęcia `ready` (`gdzie disk = r2_legacy`, do których
 * migrator w ogóle sięga) — zaostrzanie kontraktu dla statusów, których ta
 * bramka nie rusza, nie miałoby komu służyć i psułoby dane, które i tak nie
 * są jeszcze gotowe.
 *
 * NIE ZGADUJEMY KLUCZA Z NAZWY. Rekord `ready` z uszkodzonym wariantem
 * zostaje nietknięty — decyzję, co z nim zrobić, podejmuje operator po
 * przeczytaniu bezpiecznego powodu w raporcie, nie ten walidator.
 */
final class WariantyKontrakt
{
    /**
     * @return array<string, string> mapa nazwa wariantu → klucz pliku
     *
     * @throws WariantyMetadanychNiepelne gdy kontrakt jest złamany dla `ready`
     */
    public static function wyciagnij(Media $media): array
    {
        $metadata = $media->metadata;
        $variants = is_array($metadata) ? ($metadata['variants'] ?? null) : null;

        if ($media->status !== Media::STATUS_READY) {
            return is_array($variants) ? self::poprawneWpisy($variants) : [];
        }

        if (! is_array($variants) || $variants === []) {
            throw new WariantyMetadanychNiepelne(
                'metadata.variants jest puste albo go nie ma, mimo statusu `ready`.',
            );
        }

        $poprawne = [];

        foreach ($variants as $nazwa => $wariant) {
            if (! is_string($nazwa) || $nazwa === '') {
                throw new WariantyMetadanychNiepelne(
                    'Wariant bez nazwy — metadata.variants jest listą, nie mapą nazwa → wariant.',
                );
            }

            if (! is_array($wariant) || ! isset($wariant['key']) || ! is_string($wariant['key']) || $wariant['key'] === '') {
                throw new WariantyMetadanychNiepelne("Wariant `{$nazwa}` bez poprawnego, niepustego klucza pliku.");
            }

            $poprawne[$nazwa] = $wariant['key'];
        }

        return $poprawne;
    }

    /** @param array<mixed, mixed> $variants
     * @return array<string, string> */
    private static function poprawneWpisy(array $variants): array
    {
        $poprawne = [];

        foreach ($variants as $nazwa => $wariant) {
            if (is_string($nazwa) && $nazwa !== ''
                && is_array($wariant) && isset($wariant['key'])
                && is_string($wariant['key']) && $wariant['key'] !== '') {
                $poprawne[$nazwa] = $wariant['key'];
            }
        }

        return $poprawne;
    }
}
