<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/**
 * Wynik `ObserwujWybraneOsoby` wraz ze zdaniem dla człowieka.
 *
 * DWIE RÓŻNE RZECZY MOGŁY PÓJŚĆ NIE TAK NARAZ, więc komunikaty się zbiera,
 * zamiast wybierać jeden. Pominięte konto i nazwa, która zmieniła
 * właściciela, to osobne przypadki i każdy ma własne „co zrobić".
 */
final readonly class WynikObserwowaniaWybranych
{
    /**
     * @param  list<string>  $zmieniloWlasciciela
     */
    public function __construct(
        public int $zaobserwowano,
        public int $pominieto,
        public array $zmieniloWlasciciela,
    ) {}

    /** `null`, gdy wszystko poszło zgodnie z wyborem — wtedy nie ma czego dodawać. */
    public function komunikat(): ?string
    {
        $komunikaty = [];

        if ($this->pominieto > 0) {
            $komunikaty[] = ($this->zaobserwowano > 0 ? 'Nie udało się dodać wszystkich wybranych osób. ' : 'Nie udało się dodać wybranych osób. ')
                .'Możesz teraz wejść do serwisu i wybrać inne później.';
        }

        if ($this->zmieniloWlasciciela !== []) {
            // Komunikat mówi, CO ZROBIĆ, a nie tylko że coś poszło nie tak
            // (docs/UX_50_PLUS.md). Onboarding się NIE cofa i nie gubi reszty
            // zaznaczeń — pozostałe osoby są już zaobserwowane, a ta jedna
            // wymaga świadomego powtórzenia wyboru, bo to już ktoś inny.
            $komunikaty[] = count($this->zmieniloWlasciciela) === 1
                ? 'Nazwa „'.$this->zmieniloWlasciciela[0].'” należy teraz do innej osoby, więc jej nie zaobserwowaliśmy. Resztę zaznaczeń zapisaliśmy. Jeśli nadal chcesz obserwować tę osobę, znajdź ją w wyszukiwarce i kliknij „Obserwuj” na jej profilu.'
                : 'Te nazwy należą teraz do innych osób, więc ich nie zaobserwowaliśmy: '.implode(', ', $this->zmieniloWlasciciela).'. Resztę zaznaczeń zapisaliśmy. Jeśli nadal chcesz obserwować te osoby, znajdź je w wyszukiwarce i kliknij „Obserwuj” na ich profilach.';
        }

        return $komunikaty === [] ? null : implode(' ', $komunikaty);
    }
}
