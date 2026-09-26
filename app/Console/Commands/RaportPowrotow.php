<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\AktywniWTygodniu;
use App\Domain\Analytics\CookRetentionCohorts;
use App\Domain\Analytics\DrugiWpisW7Dni;
use App\Domain\Analytics\PowrotPoDniach;
use App\Domain\Analytics\ZasiegUgotowalem;
use App\Domain\Analytics\ZrobiePonownie;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
 * - „Zrobię ponownie" liczy się w trzech stanach — tak / nie / brak
 *   odpowiedzi — osobno dla cudzych i własnych przepisów; brak odpowiedzi
 *   nie jest „nie" (issue #1509, `App\Domain\Analytics\ZrobiePonownie`).
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
        CookRetentionCohorts $kohorty,
        ZrobiePonownie $zrobiePonownie,
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
        $this->drugiWpis();

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

        $this->newLine();
        $this->zrobiePonownie($zrobiePonownie);

        $this->newLine();
        $this->kohorty($kohorty);

        return self::SUCCESS;
    }

    /**
     * Kohorty retencji — MOCNIEJSZY pomiar niż D7/D30 wyżej, i dlatego stoi
     * w tym samym raporcie, a nie w osobnej komendzie.
     *
     * D7/D30 wyżej czyta `ostatnio_widziany_at` — jedną, NADPISYWANĄ kolumnę.
     * Odpowiada więc na pytanie „czy ten człowiek zajrzał jeszcze po N
     * dniach", i sam `PowrotPoDniach` opisuje siebie jako oszacowanie
     * jednostronnie ostrożne.
     *
     * Kohorta liczy co innego: bierze PRAWDZIWĄ aktywność (`CookActivity`)
     * i pyta, w którym tygodniu po rejestracji dana osoba coś zrobiła.
     * „Zajrzał" i „coś zrobił" to nie to samo zdanie o produkcie, a bramka
     * V1 z `docs/ROADMAP.md` („Planner/groups/forks dopiero gdy WAC i D30
     * pokazują powroty") pyta o to drugie.
     *
     * DLACZEGO TO TU W OGÓLE TRAFIA
     * `CookRetentionCohorts` istniało, było przetestowane — i nie było
     * wołane z ŻADNEJ komendy ani kontrolera. Liczba, której nikt nie umie
     * wyświetlić, nie jest pomiarem, tylko kodem czekającym na usunięcie.
     */
    private function kohorty(CookRetentionCohorts $kohorty): void
    {
        $rozmiary = $kohorty->rozmiaryKohort();
        $wiersze = $kohorty->weekly()->groupBy('signup_week');

        $this->line('Kohorty powrotów — z prawdziwej aktywności, nie z ostatniej wizyty.');

        if ($rozmiary->isEmpty()) {
            $this->line('Brak danych — nikt jeszcze się nie zarejestrował.');

            return;
        }

        // Początek BIEŻĄCEGO tygodnia w strefie człowieka — ta sama strefa,
        // w której `CookRetentionCohorts` tnie tygodnie. Bez tego progu nie
        // dałoby się odróżnić „nikt nie wrócił" od „jeszcze nie minęło tyle
        // czasu, żeby ktokolwiek mógł wrócić" — a to są dwa różne fakty
        // i pomylenie ich myliłoby czytelnika (ta sama zasada, co przy
        // pustej kohorcie D7/D30 wyżej).
        $biezacyTydzien = CarbonImmutable::now(Czas::strefa())->startOfWeek()->startOfDay();

        // Iterujemy po ROZMIARACH, nie po wierszach aktywności — inaczej
        // kohorta, w której nie odezwał się nikt, nie miałaby ani jednego
        // wiersza i po prostu zniknęłaby z raportu. A to jest dokładnie ta
        // kohorta, o której bramka V1 musi wiedzieć.
        foreach ($rozmiary as $tydzien => $wKohorcie) {
            /** @var Collection<int, object> $kohorta */
            $kohorta = $wiersze[$tydzien] ?? collect();
            $wOfsecie = $kohorta->keyBy(fn (object $w): int => (int) $w->week_offset);

            $pierwszy = $this->ofset($wOfsecie, 0, $wKohorcie, (string) $tydzien, $biezacyTydzien);
            $poTygodniu = $this->ofset($wOfsecie, 1, $wKohorcie, (string) $tydzien, $biezacyTydzien);
            $poMiesiacu = $this->ofset($wOfsecie, 4, $wKohorcie, (string) $tydzien, $biezacyTydzien);

            $this->line(
                "  tydzień {$tydzien}: {$wKohorcie} w kohorcie · w pierwszym tygodniu {$pierwszy}"
                ." · po tygodniu {$poTygodniu} · po miesiącu {$poMiesiacu}",
            );
        }
    }

    /**
     * Jedna komórka kohorty: liczba i procent, albo „za wcześnie".
     *
     * MIANOWNIKIEM JEST ROZMIAR KOHORTY, nie liczba aktywnych w tygodniu
     * zerowym (audyt G07). Poprzednia wersja dzieliła przez `week_offset = 0`
     * i przy trzech osobach — jednej aktywnej na starcie, dwóch innych po
     * miesiącu — wypisywała „po miesiącu 2 (200,0%)". Zbiór aktywnych
     * w tygodniu 4 nie zawiera się w zbiorze aktywnych w tygodniu 0.
     *
     * @param  Collection<int, object>  $wOfsecie
     * @param  int  $wKohorcie  ilu ludzi zarejestrowało się w tym tygodniu
     */
    private function ofset(
        Collection $wOfsecie,
        int $ofset,
        int $wKohorcie,
        string $tydzienRejestracji,
        CarbonImmutable $biezacyTydzien,
    ): string {
        $tydzienDocelowy = CarbonImmutable::parse($tydzienRejestracji, Czas::strefa())
            ->addWeeks($ofset)
            ->startOfDay();

        // Tydzień, który jeszcze się nie skończył, nie jest zerem — jest
        // niewiadomą. Bieżący tydzień liczy się jako niedomknięty.
        if ($tydzienDocelowy >= $biezacyTydzien) {
            return 'za wcześnie';
        }

        $ilu = (int) ($wOfsecie[$ofset]->active_users ?? 0);
        $procent = number_format($ilu / $wKohorcie * 100, 1, ',', '');

        return "{$ilu} ({$procent}%)";
    }

    /**
     * „Zrobię ponownie" w trzech stanach (issue #1509). Brak odpowiedzi ma
     * własny wiersz i nie wchodzi do mianownika odsetka „tak" — pełne
     * uzasadnienie w `App\Domain\Analytics\ZrobiePonownie`.
     */
    private function zrobiePonownie(ZrobiePonownie $zrobiePonownie): void
    {
        $wynik = $zrobiePonownie->policz();

        $this->line('„Zrobię ponownie” po gotowaniu — ostatnie '.ZrobiePonownie::DNI.' dni, każde wykonanie osobno.');
        $this->wierszZrobiePonownie('Cudze przepisy', $wynik['cudze']);
        $this->wierszZrobiePonownie('Własne przepisy autora', $wynik['wlasne']);
    }

    /** @param  array{tak: int, nie: int, brak: int, wszystkie: int, odsetek_odpowiedzi: float|null, odsetek_tak: float|null}  $w */
    private function wierszZrobiePonownie(string $etykieta, array $w): void
    {
        if ($w['wszystkie'] === 0) {
            $this->line("  {$etykieta}: brak wykonań w tym okresie.");

            return;
        }

        $odpowiedzi = number_format((float) $w['odsetek_odpowiedzi'], 1, ',', '');
        $odsetekTak = $w['odsetek_tak'] === null
            ? 'za mało danych (mniej niż '.ZrobiePonownie::MINIMUM_ODPOWIEDZI.' odpowiedzi)'
            : number_format($w['odsetek_tak'], 1, ',', '').'% odpowiedzi to „tak”';

        $this->line(
            "  {$etykieta}: tak {$w['tak']} · nie {$w['nie']} · brak odpowiedzi {$w['brak']}"
            ." · odpowiedziało {$odpowiedzi}% z {$w['wszystkie']} wykonań · {$odsetekTak}",
        );
    }

    /**
     * „Drugi wpis w 7 dni” (issue #29): czy pierwszy wpis zamienia się
     * w nawyk. Same liczby; poniżej progu próby bez procentu. Definicja
     * w `App\Domain\Analytics\DrugiWpisW7Dni`. Klasa brana z kontenera
     * tutaj, nie z sygnatury `handle()`, żeby ta sekcja nie kolidowała
     * z innymi dopisywanymi równolegle.
     */
    private function drugiWpis(): void
    {
        $w = app(DrugiWpisW7Dni::class)->policz();
        $etykieta = 'Drugi wpis w 7 dni od pierwszego (pierwsze wpisy z ostatnich '
            .DrugiWpisW7Dni::KOHORTA_DNI.' dni, najmłodsze '.DrugiWpisW7Dni::OKNO_DNI.' dni pominięte)';

        if ($w['kohorta'] === 0) {
            $this->line("{$etykieta}: brak osób z pierwszym wpisem w tym okresie — jeszcze nie da się tego policzyć.");

            return;
        }

        if ($w['procent'] === null) {
            $this->line(
                "{$etykieta}: {$w['z_drugim']} z {$w['kohorta']} — za mało danych na procent (mniej niż "
                .DrugiWpisW7Dni::MINIMUM_OSOB.' osób), to liczba, nie wniosek.',
            );

            return;
        }

        $this->line("{$etykieta}: {$w['z_drugim']} z {$w['kohorta']} (".number_format($w['procent'], 1, ',', '').'%).');
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
