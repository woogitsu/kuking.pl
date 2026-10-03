<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\PantryItem;
use Carbon\CarbonImmutable;

/**
 * Jedno opakowanie produktu z „Co mam w domu” — widok tylko do odczytu
 * (#2568, V2). Pierwsze opakowanie to kolumny `pantry_items`, drugie to
 * wiersz `pantry_second_packages`; reguły priorytetu, opisy stanu i widoki
 * pracują na jednym kształcie, więc nie wiedzą, skąd opakowanie pochodzi.
 *
 * `id` i `name` są produktu (dwa opakowania tej samej nazwy mają wspólny
 * `id`), a numer opakowania odróżnia je przy równym terminie.
 */
final class Opakowanie
{
    public const PIERWSZE = 'pierwsze';

    public const DRUGIE = 'drugie';

    public const ETYKIETY = [
        self::PIERWSZE => 'Pierwsze opakowanie',
        self::DRUGIE => 'Drugie opakowanie',
    ];

    public function __construct(
        public readonly PantryItem $produkt,
        public readonly string $numer,
        public readonly string $id,
        public readonly string $name,
        public readonly ?CarbonImmutable $expires_on,
        public readonly ?string $expiry_kind,
        public readonly ?string $quantity_note,
        public readonly bool $frozen,
        /** Identyfikator wiersza drugiego opakowania; `null` dla pierwszego. */
        public readonly ?string $opakowanieId = null,
        /** Czy produkt ma dwa opakowania (wtedy w widoku mówimy, które to). */
        public readonly bool $maDwa = false,
    ) {}

    public static function pierwsze(PantryItem $produkt, bool $maDwa = false): self
    {
        return new self(
            produkt: $produkt,
            numer: self::PIERWSZE,
            id: (string) $produkt->getKey(),
            name: (string) $produkt->name,
            expires_on: $produkt->expires_on,
            expiry_kind: $produkt->expiry_kind,
            quantity_note: $produkt->quantity_note,
            frozen: (bool) $produkt->frozen,
            maDwa: $maDwa,
        );
    }

    /**
     * Opakowania produktu w kolejności: pierwsze, drugie. Drugie jest brane
     * tylko z wczytanej relacji `secondPackage` — kto go nie wczytał, widzi
     * jedno opakowanie (tak działają testy na modelach składanych w pamięci).
     *
     * @return list<self>
     */
    public static function zProduktu(PantryItem $produkt): array
    {
        $drugie = $produkt->relationLoaded('secondPackage') ? $produkt->getRelation('secondPackage') : null;
        $maDwa = $drugie !== null;
        $wynik = [self::pierwsze($produkt, $maDwa)];

        if ($drugie !== null) {
            $wynik[] = new self(
                produkt: $produkt,
                numer: self::DRUGIE,
                id: (string) $produkt->getKey(),
                name: (string) $produkt->name,
                expires_on: $drugie->expires_on,
                expiry_kind: $drugie->expiry_kind,
                quantity_note: $drugie->quantity_note,
                frozen: (bool) $drugie->frozen,
                opakowanieId: (string) $drugie->getKey(),
                maDwa: true,
            );
        }

        return $wynik;
    }

    public function getKey(): string
    {
        return $this->id;
    }

    public function etykieta(): string
    {
        return self::ETYKIETY[$this->numer];
    }

    /**
     * Odcisk treści opakowania. Formularz usunięcia i edycji niesie go z
     * chwili otwarcia strony: gdy w międzyczasie drugie opakowanie awansowało
     * na pierwsze (po usunięciu pierwszego), stary formularz nie może
     * zadziałać na innym opakowaniu niż to, które człowiek widział.
     */
    public function odcisk(): string
    {
        return self::odciskTresci(
            $this->expires_on?->toDateString(),
            $this->expiry_kind,
            $this->quantity_note,
            $this->frozen,
            $this->numer === self::PIERWSZE ? ($this->produkt->first_package_id ?? $this->id) : null,
        );
    }

    public static function odciskTresci(?string $termin, ?string $rodzaj, ?string $ilosc, bool $mrozone, ?string $tozsamosc = null): string
    {
        return substr(sha1(implode('|', [$tozsamosc ?? '', $termin ?? '', $rodzaj ?? '', $ilosc ?? '', $mrozone ? '1' : '0'])), 0, 16);
    }
}
