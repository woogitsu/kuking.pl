<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Models\MailFailure;
use App\Poczta\PowodOdmowy;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `listy` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaNieudanychListow implements Sonda
{
    public function nazwa(): string
    {
        return 'listy';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_SLAD_LISTOW_NIESPRAWDZALNY;
    }

    /**
     * Czy jakiś list przepadł, a właściciel jeszcze o tym nie wie
     * (issue #234, D-062).
     *
     * PO CO TO TU JEST
     * Bo do 10 września 2026 przepadnięcie listu wyglądało DOKŁADNIE jak
     * sukces: worker wyczerpywał trzy próby w sześć minut, zadanie lądowało
     * w `failed_jobs`, kolejka wracała do zera, a `/health` świecił na
     * zielono. Dotyczyło to potwierdzeń rejestracji i przypomnień hasła,
     * czyli listów, na które ktoś czeka przed ekranem. Ta sonda jest
     * pierwszym miejscem, w którym taka awaria mówi o sobie sama.
     *
     * BEZ OKNA CZASOWEGO — I TO JEST SEDNO
     * Nie pytamy „czy coś przepadło w ostatniej godzinie", tylko „czy
     * cokolwiek czeka na przeczytanie". Alarm z oknem czasowym gaśnie sam po
     * godzinie, czyli awaria z nocy jest o ósmej rano znowu niewidoczna —
     * a to jest ta sama cicha porażka, tylko o godzinę późniejsza. Gaśnie
     * dopiero wtedy, gdy człowiek odhaczy: `php artisan kuking:nieudane-listy
     * --odhacz`.
     *
     * KONSEKWENCJA, PRZYJĘTA ŚWIADOMIE: `/health` może stać w `degraded`
     * przez wiele godzin. Jest to cena za to, żeby jeden przepadły list nie
     * przeszedł niezauważony — a odhaczenie jest jedną komendą, po
     * przeczytaniu. `listy` nie są na liście `KRYTYCZNE`, więc trasa oddaje
     * dalej HTTP 200 i Railway nie restartuje z tego powodu niczego.
     *
     * DLACZEGO `Log::error` NIE ROBI TU SZUMU: sondy `/health` logują tylko
     * przy porażce, a monitoring odpytuje trasę co kilka minut — więc wpis
     * powstaje przy każdym odpytaniu, dopóki alarm trwa. To znaczy: dziennik
     * powie „od 02:14 do 08:30 listy przepadały", i taki zapis jest właśnie
     * tym, czego przy poszukiwaniu przyczyny brakuje najczęściej.
     */
    public function sprawdz(): void
    {
        $nieodhaczone = MailFailure::query()->nieodhaczone()->count();

        if ($nieodhaczone === 0) {
            return;
        }

        // Kategoria z NAJŚWIEŻSZEJ porażki: przy wyczerpanej puli wszystkie
        // wpisy z danej doby mają ten sam powód, a właściciela interesuje
        // to, co dzieje się TERAZ.
        $najswiezszy = MailFailure::query()
            ->nieodhaczone()
            ->orderByDesc('failed_at')
            ->first();

        $limit = $najswiezszy?->powod === PowodOdmowy::LIMIT_DOBOWY;

        throw new KontrolaZdrowiaNieprzeszla(
            $limit ? Powody::POWOD_LIMIT_POCZTY_WYCZERPANY : Powody::POWOD_LISTY_PRZEPADAJA,
            'Nieodhaczonych nieudanych listów: '.$nieodhaczone.'. '
            .'Najświeższy powód: '.($najswiezszy?->powod->value ?? 'nieznany').'. '
            .'Przeczytaj: php artisan kuking:nieudane-listy',
        );
    }
}
