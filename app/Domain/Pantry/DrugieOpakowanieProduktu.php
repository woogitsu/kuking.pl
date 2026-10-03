<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\PantryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Drugie opakowanie tego samego produktu z osobnym terminem (#2568, V2).
 *
 * NAZWA PRODUKTU ZOSTAJE JEDNA (D-285, `UNIQUE (user_id, klucz)`), a zwykłe
 * dodanie tej samej nazwy nadal niczego nie tworzy (`CoMamWDomu::dodaj()`).
 * Drugie opakowanie powstaje WYŁĄCZNIE tą akcją, z jawnego formularza
 * „Dodaj drugie opakowanie”. Największa liczba opakowań to dwa: pilnuje tego
 * `UNIQUE (pantry_item_id)` w bazie, więc równoległe zapisy nie dadzą trzeciego.
 *
 * KAŻDE OPAKOWANIE MA WŁASNE: termin z rodzajem, ilość (wolny tekst)
 * i „mrożone”. Walidację i komunikaty błędów dzieli z `ZmienTerminProduktu`
 * (`kolumny()`), więc oba formularze mówią po polsku to samo.
 *
 * SERIALIZACJA: każda operacja trzyma blokadę wiersza PRODUKTU
 * (`FOR UPDATE`), tak jak `ZmienTerminProduktu`. Edycje i usunięcia dwóch
 * opakowań tego samego produktu ustawiają się więc w kolejce i żadna nie
 * nadpisuje drugiej.
 *
 * USUNIĘCIE JEDNEGO OPAKOWANIA nie rusza drugiego. Usunięcie pierwszego, gdy
 * drugie istnieje, przenosi treść drugiego na miejsce pierwszego (pierwsze
 * opakowanie to kolumny `pantry_items`) i kasuje wiersz drugiego — nic się
 * nie scala, nic nie ginie. Formularz niesie odcisk treści z chwili otwarcia
 * strony, więc stary formularz nie zadziała na opakowaniu, którego człowiek
 * nie widział. Usunięcie całego produktu to osobna akcja (`PantryController::destroy`).
 */
final class DrugieOpakowanieProduktu
{
    public const USUNIETO = 'usunieto';

    public const JUZ_NIE_MA = 'juz_nie_ma';

    public const ZMIENILO_SIE = 'zmienilo_sie';

    /** Pierwsze opakowanie jest jedynym — „usuń to opakowanie” = „usuń produkt”. */
    public const JEDYNE = 'jedyne';

    public const BLAD_ZMIENILO_SIE = 'Opakowania tego produktu zmieniły się od otwarcia tej strony (zmiana w innym oknie albo na innym urządzeniu). '
        .'Niczego nie zapisaliśmy ani nie usunęliśmy. Wróć do listy „Co mam w domu” i otwórz opakowanie jeszcze raz.';

    public function __construct(private readonly ZmienTerminProduktu $termin) {}

    /**
     * Dodaje drugie opakowanie albo zmienia istniejące.
     *
     * `$opakowanieId` pusty = formularz „Dodaj drugie opakowanie”. Retry tego
     * samego formularza (podwójne kliknięcie, ponowne wysłanie) jest
     * idempotentny: ta sama treść nie tworzy niczego nowego. Inna treść przy
     * już istniejącym drugim opakowaniu jest odrzucana, a nie nadpisywana —
     * ktoś dodał je w innym oknie. `$opakowanieId` wypełniony = edycja
     * konkretnego, widzianego opakowania.
     *
     * @param  array<string, mixed>  $dane  surowe pola formularza
     * @return bool `false`, gdy produktu już nie ma (usunięty równolegle)
     *
     * @throws ValidationException
     */
    public function zapisz(PantryItem $produkt, array $dane, ?string $opakowanieId = null, ?string $dzis = null): bool
    {
        $dzis ??= PriorytetZuzycia::dzis();
        $opakowanieId = $opakowanieId === null || $opakowanieId === '' ? null : $opakowanieId;

        return DB::transaction(function () use ($produkt, $dane, $opakowanieId, $dzis): bool {
            if (! $this->zablokujProdukt($produkt)) {
                return false;
            }

            $drugie = DB::table('pantry_second_packages')
                ->where('pantry_item_id', $produkt->getKey())
                ->first();

            if ($opakowanieId !== null && ($drugie === null || $drugie->id !== $opakowanieId)) {
                throw ValidationException::withMessages(['opakowanie' => self::BLAD_ZMIENILO_SIE]);
            }

            $kolumny = $this->termin->kolumny(
                $drugie ?? (object) ['expires_on' => null, 'expiry_kind' => null],
                $dane,
                $dzis,
            );

            if ($drugie === null) {
                DB::table('pantry_second_packages')->insert(['pantry_item_id' => $produkt->getKey(), ...$kolumny]);

                return true;
            }

            if ($opakowanieId === null) {
                // Formularz „Dodaj” przy istniejącym drugim opakowaniu.
                if ($this->odcisk($kolumny) === $this->odcisk((array) $drugie)) {
                    return true;
                }

                throw ValidationException::withMessages(['opakowanie' => 'Drugie opakowanie tego produktu jest już zapisane. '
                    .'Niczego nie zmieniliśmy. Wróć do listy „Co mam w domu” i użyj przy nim „Zmień termin”.']);
            }

            DB::table('pantry_second_packages')->where('id', $drugie->id)->update($kolumny);

            return true;
        });
    }

    /**
     * Usuwa JEDNO opakowanie, nie ruszając drugiego.
     *
     * @param  string  $cel  `Opakowanie::PIERWSZE` albo identyfikator drugiego opakowania
     * @param  string|null  $odcisk  odcisk pierwszego opakowania z chwili otwarcia strony (przy `pierwsze`)
     * @return string jedna ze stałych `USUNIETO`, `JUZ_NIE_MA`, `ZMIENILO_SIE`, `JEDYNE`
     */
    public function usun(PantryItem $produkt, string $cel, ?string $odcisk = null): string
    {
        return DB::transaction(function () use ($produkt, $cel, $odcisk): string {
            $pierwsze = DB::table('pantry_items')
                ->where('id', $produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->lockForUpdate()
                ->first(['id', 'first_package_id', 'expires_on', 'expiry_kind', 'quantity_note', 'frozen']);

            if ($pierwsze === null) {
                return self::JUZ_NIE_MA;
            }

            $drugie = DB::table('pantry_second_packages')->where('pantry_item_id', $produkt->getKey())->first();

            if ($cel !== Opakowanie::PIERWSZE) {
                if (! Str::isUuid($cel) || $drugie === null || $drugie->id !== $cel) {
                    return self::JUZ_NIE_MA;
                }

                DB::table('pantry_second_packages')->where('id', $drugie->id)->delete();

                return self::USUNIETO;
            }

            if ($drugie === null) {
                return self::JEDYNE;
            }

            if (($pierwsze->first_package_id !== null && ($odcisk === null || $odcisk === ''))
                || ($odcisk !== null && $odcisk !== '' && $odcisk !== $this->odcisk((array) $pierwsze, (string) ($pierwsze->first_package_id ?? $pierwsze->id)))) {
                return self::ZMIENILO_SIE;
            }

            DB::table('pantry_items')->where('id', $produkt->getKey())->update([
                'expires_on' => $drugie->expires_on,
                'expiry_kind' => $drugie->expiry_kind,
                'quantity_note' => $drugie->quantity_note,
                'frozen' => $drugie->frozen,
                'first_package_id' => $drugie->id,
            ]);
            DB::table('pantry_second_packages')->where('id', $drugie->id)->delete();

            return self::USUNIETO;
        });
    }

    private function zablokujProdukt(PantryItem $produkt): bool
    {
        return DB::table('pantry_items')
            ->where('id', $produkt->getKey())
            ->where('user_id', $produkt->user_id)
            ->lockForUpdate()
            ->exists();
    }

    /** @param  array<string, mixed>  $tresc */
    private function odcisk(array $tresc, ?string $tozsamosc = null): string
    {
        return Opakowanie::odciskTresci(
            isset($tresc['expires_on']) ? (string) $tresc['expires_on'] : null,
            isset($tresc['expiry_kind']) ? (string) $tresc['expiry_kind'] : null,
            isset($tresc['quantity_note']) ? (string) $tresc['quantity_note'] : null,
            (bool) ($tresc['frozen'] ?? false),
            $tozsamosc,
        );
    }
}
