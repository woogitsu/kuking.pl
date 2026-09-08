<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\AktywniWTygodniu;
use App\Domain\Analytics\PowrotPoDniach;
use App\Domain\Analytics\ZasiegUgotowalem;
use Illuminate\Console\Command;

/**
 * Zamienia bramkę V1 z `docs/ROADMAP.md` — „Planner/groups/forks dopiero gdy
 * WAC i D30 pokazują powroty" — w liczby, które da się PRZECZYTAĆ, zamiast
 * decydować o dalszej pracy na wyczucie (issue #114/#115).
 *
 * DLA KOGO JEST TEN RAPORT
 * Dla człowieka w terminalu, nie dla skryptu. Stąd zdania po polsku
 * z podpisem, co liczba znaczy, a nie JSON ani tabela do sparsowania —
 * ten sam wybór co `kuking:wac` i `kuking:sprzataj-sygnaly`, tylko że tu
 * jest to jedyny cel istnienia komendy, nie efekt uboczny.
 *
 * SKĄD BIORĄ SIĘ TE LICZBY, KRÓTKO (pełne uzasadnienia w klasach domenowych)
 * - „Aktywni w ostatnich 7 dniach" i „powrót po 7/30 dniach" liczą się
 *   z `users.ostatnio_widziany_at` — nowego znacznika, zapisywanego
 *   throttlowanym middleware'em (`App\Domain\Analytics
 *   \ZanotujOstatniaWizyte`), NIE z `product_signals`. Powód w skrócie:
 *   `product_signals` ma retencję 90 dni (więcej niż 30 — to akurat NIE
 *   przesądzało sprawy), ale jest CELOWO wąska: dwa rzadkie zdarzenia
 *   techniczne, nie ogólny log odwiedzin. Pełne uzasadnienie stoi
 *   w komentarzu migracji `2026_09_08_200000_add_last_seen_to_users_table`.
 * - „Aktywni w ostatnich 7 dniach" to co innego niż `kuking:wac` — tamta
 *   liczy konta, które coś OPUBLIKOWAŁY; ta liczy konta, które PO PROSTU
 *   BYŁY (patrz `App\Domain\Analytics\AktywniWTygodniu`).
 * - „Powrót po 7/30 dniach" to DOLNA granica prawdziwego powrotu, nie jego
 *   dokładna wartość — pełne uzasadnienie w `App\Domain\Analytics
 *   \PowrotPoDniach`.
 * - „Ugotowałem" liczy się z `cooked_events` i `notifications`, bez
 *   wykluczeń gospodarza/kont testowych — to NIE jest metryka porównawcza
 *   w czasie jak WAC, tylko fakt o tym, czy najważniejszy mechanizm
 *   produktu (AGENTS.md, część 1) w ogóle działa. Pełne uzasadnienie
 *   w `App\Domain\Analytics\ZasiegUgotowalem`.
 *
 * PUSTA BAZA NIE WYWALA KOMENDY
 * Każda z trzech klas domenowych zwraca `0`, nie wyjątek, gdy nie ma
 * żadnych danych — `PowrotPoDniach` dodatkowo rozróżnia „kohorta pusta"
 * (0 kont wystarczająco starych) od „nikt nie wrócił" (kohorta niepusta,
 * zero powrotów), bo to są dwa różne fakty i pomylenie ich w tekście
 * raportu myliłoby czytelnika co do tego, czy liczba mówi cokolwiek.
 */
class RaportPowrotow extends Command
{
    protected $signature = 'kuking:raport';

    protected $description = 'Liczby z bramki V1 (docs/ROADMAP.md): ilu ludzi wraca i czy działa najważniejsze powiadomienie w serwisie.';

    public function handle(
        AktywniWTygodniu $aktywni,
        PowrotPoDniach $powroty,
        ZasiegUgotowalem $ugotowalem,
    ): int {
        $this->line('Raport powrotów — Kuking.pl');
        $this->line('Liczone teraz, na podstawie ostatniej znanej wizyty każdego konta.');
        $this->newLine();

        $liczbaAktywnych = $aktywni->liczba();
        $this->line(
            "Aktywni w ostatnich 7 dniach: {$liczbaAktywnych} — tylu ludzi realnie zajrzało "
            .'do Kuking w tym tygodniu (bez gospodarza i kont testowych/zalążkowych).',
        );

        $this->newLine();
        $this->wierszPowrotu($powroty->policz(7), 'Powrót po 7 dniach (D7)', 'tygodnia');
        $this->wierszPowrotu($powroty->policz(30), 'Powrót po 30 dniach (D30)', 'miesiąca');

        $this->newLine();
        $zasieg = $ugotowalem->policz();
        $this->line(
            "Przepisy z choć jednym „Ugotowałem”: {$zasieg['przepisy_z_ugotowalem']} — "
            .'tyle różnych przepisów ktoś naprawdę ugotował.',
        );
        $this->line(
            "Autorzy powiadomieni o „Ugotowałem”: {$zasieg['autorzy_powiadomieni']} — "
            .'tylu ludziom przyszedł najważniejszy komunikat w serwisie (AGENTS.md, część 1).',
        );

        return self::SUCCESS;
    }

    /** @param  array{kwalifikujacy_sie: int, wrocilo: int, procent: float|null}  $wynik */
    private function wierszPowrotu(array $wynik, string $etykieta, string $jednostka): void
    {
        if ($wynik['kwalifikujacy_sie'] === 0) {
            $this->line("{$etykieta}: brak kont sprzed co najmniej jednego {$jednostka} — jeszcze nie da się tego policzyć.");

            return;
        }

        $procent = number_format((float) $wynik['procent'], 1, ',', '');

        $this->line(
            "{$etykieta}: {$procent}% ({$wynik['wrocilo']} z {$wynik['kwalifikujacy_sie']} kont sprzed "
            ."co najmniej jednego {$jednostka} wróciło choć raz).",
        );
    }
}
