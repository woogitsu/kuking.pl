<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * „Powroty po N dniach" z bramki V1 (`docs/ROADMAP.md`: „Planner/groups/forks
 * dopiero gdy WAC i D30 pokazują powroty") — D7 i D30 tą samą klasą,
 * parametryzowaną liczbą dni.
 *
 * DEFINICJA, I DLACZEGO TA, A NIE INNA
 * Kohorta: konta założone co najmniej `$dni` dni temu (za młode konto nie
 * MIAŁO jeszcze szansy wrócić po tylu dniach — liczyłoby się jako
 * „nie wrócił" tylko dlatego, że jeszcze nie zdążył, co zaniżałoby procent
 * bez żadnego prawdziwego powodu).
 * Powrót: `ostatnio_widziany_at` istnieje i jest co najmniej `$dni` dni PO
 * `created_at` TEGO konta — czyli „wrócił choć raz po pierwszym tygodniu/
 * miesiącu", nie „był aktywny dokładnie w dniu N". To jest jedyna definicja
 * osiągalna z pojedynczej, NADPISYWANEJ kolumny: `ostatnio_widziany_at` nie
 * jest logiem, więc nie da się z niej odczytać, czy dane konto było widziane
 * W OKNIE wokół dnia 7 albo 30 — da się tylko sprawdzić, czy najnowszy znany
 * moment aktywności sięga już poza ten próg. Jest to jednostronnie
 * OSTROŻNE oszacowanie: konto, które wróciło raz między dniem 3 a 6, a potem
 * przestało — nie zaniesie się do D7 jako „powrót", mimo że realnie
 * wróciło. Procent D7/D30 z tej klasy jest więc DOLNĄ granicą prawdziwego
 * powrotu, nigdy zawyżeniem.
 *
 * DLACZEGO PORÓWNANIE PRZEZ `extract(epoch from …)`, NIE `created_at +
 * interval '$dni days'`
 * PostgreSQL liczy arytmetykę `timestamptz + interval` z polem `day` w
 * strefie SESJI (dokumentacja PG, sekcja 9.9.1) — dokładnie ten sam rodzaj
 * pułapki, który już raz kosztował ten projekt błąd w WAC
 * (`docs/research/ANALITYKA_STAN_WDROZENIA.md` §1.3: „te same dane dają inny
 * WAC na innym serwerze bazy"), bo to repozytorium NIGDZIE nie ustawia
 * strefy sesji Postgresa (`config/database.php` bez klucza `timezone`).
 * `extract(epoch from …)` na `timestamptz` liczy sekundy od 1970 UTC —
 * wielkość niezależną od strefy sesji z definicji — więc różnica dwóch
 * takich wartości jest tym samym CZASEM TRWANIA bez względu na to, gdzie
 * stoi baza. Cena tego wyboru: przy zmianie czasu (dwa dni w roku) próg
 * „30 dni" znaczy 30×24 godzin day-to-day, a nie „30 dat kalendarzowych
 * czasu polskiego" — różnica rzędu jednej godziny, nieistotna dla progu
 * liczonego w tygodniach/miesiącach, a za to zawsze ta sama, niezależnie od
 * serwera.
 *
 * TE SAME WYKLUCZENIA CO WAC/KOHORTA (`CookEligibility`) — z tego samego
 * powodu: gospodarz i konta testowe/zalążkowe nie mają psuć liczby czytanej
 * jako dowód, że SPOŁECZNOŚĆ wraca.
 */
final class PowrotPoDniach
{
    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    /**
     * @return array{kwalifikujacy_sie: int, wrocilo: int, procent: float|null}
     *              `procent` jest `null`, gdy kohorta jest pusta — dzielenie
     *              przez zero nie ma tu sensownego wyniku, a `0.0` sugerowałoby
     *              „nikt nie wrócił", nie „nie ma kogo liczyć".
     */
    public function policz(int $dni, ?CarbonInterface $teraz = null): array
    {
        $teraz ??= now();
        $wykluczeni = $this->eligibility->excludedUserIds();
        $prog = $teraz->copy()->subDays($dni);
        $progSekund = $dni * 86400;

        $kohorta = fn () => User::query()
            ->where('created_at', '<=', $prog)
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('id', $wykluczeni));

        $kwalifikujacySie = $kohorta()->count();

        $wrocilo = $kohorta()
            ->whereNotNull('ostatnio_widziany_at')
            ->whereRaw(
                'extract(epoch from ostatnio_widziany_at) - extract(epoch from created_at) >= ?',
                [$progSekund],
            )
            ->count();

        return [
            'kwalifikujacy_sie' => $kwalifikujacySie,
            'wrocilo' => $wrocilo,
            'procent' => $kwalifikujacySie > 0 ? round($wrocilo / $kwalifikujacySie * 100, 1) : null,
        ];
    }
}
