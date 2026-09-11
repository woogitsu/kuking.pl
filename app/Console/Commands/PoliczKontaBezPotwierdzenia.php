<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\KontaBezPotwierdzonegoAdresu;
use Illuminate\Console\Command;

/**
 * Pomiar do decyzji z issue #317 (pre-account-hijacking na logowaniu
 * linkiem): ile kont nie ma potwierdzonego adresu e-mail i ilu ludzi
 * naprawdę dotyczy zamiana linku na list z ustawieniem hasła.
 *
 * DECYZJA JUŻ ZAPADŁA, A KOMENDA ZOSTAJE. Powstała przed naprawą, żeby
 * policzyć jej koszt; po naprawie ta sama liczba mówi, ilu ludzi chodzi
 * dziś do serwisu drogą odzyskiwania konta zamiast linkiem — czyli ilu
 * z nich jeszcze nie kliknęło listu, który potwierdziłby im adres.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA KOMENDA TYLKO CZYTA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ani jednego `UPDATE`, `INSERT`, `DELETE` ani wysłanego listu — cztery
 * zapytania `SELECT count(*)` razy kilka. Wolno ją uruchomić na produkcji
 * (AGENTS.md §6 zabrania operacji DESTRUKCYJNYCH, a ta nie jest jedną
 * z nich), i po to powstała: liczba z bazy produkcyjnej jest jedyną, która
 * odpowiada na pytanie właściciela z issue #317.
 *
 * Cała reguła „kto się liczy" mieszka w
 * `App\Domain\Security\KontaBezPotwierdzonegoAdresu` — ta komenda tylko
 * formatuje wynik (ten sam wzorzec co `ReportWeeklyActiveCooks`
 * i `PoliczKukingow`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WYPISUJE TYLE WIERSZY, A NIE JEDNEJ LICZBY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo naiwne „ile kont ma puste `email_verified_at`" jest ZAWYŻONE: konto
 * po anonimizacji (D-022) samo ma tam `null`, a konta zamknięte, konta
 * obsługi serwisu i konta zalążkowe linku do logowania nie dostają już
 * dziś. Wypisujemy więc liczbę z pytania ORAZ każde odjęcie osobno, żeby
 * dało się przeczytać, skąd wzięła się liczba kosztu — zamiast prosić
 * o zaufanie do jednej wartości.
 */
class PoliczKontaBezPotwierdzenia extends Command
{
    protected $signature = 'kuking:konta-bez-potwierdzenia';

    protected $description = 'Liczy konta bez potwierdzonego adresu e-mail i to, ile z nich wchodzi dziś przez list z ustawieniem hasła zamiast przez link (issue #317). Tylko czyta.';

    public function handle(KontaBezPotwierdzonegoAdresu $pomiar): int
    {
        $wynik = $pomiar->policz();

        $this->info('Konta bez potwierdzonego adresu e-mail — pomiar do issue #317.');
        $this->newLine();

        $this->line("Wszystkich kont w bazie: {$wynik->kontaWszystkie}");
        $this->line(
            "Bez potwierdzonego adresu (samo `email_verified_at IS NULL`): {$wynik->bezPotwierdzeniaWszystkie}"
            ." — {$this->procent($wynik->procentBezPotwierdzenia())} wszystkich kont",
        );
        $this->newLine();

        $this->line('Z tej liczby ODPADA (bo te konta NIE DOSTAJĄ POCZTY Z FORMULARZA LOGOWANIA ANI DZIŚ, ANI PRZED NAPRAWĄ):');
        $this->line("  konta zamknięte — zablokowane, w trakcie usuwania, usunięte: {$wynik->bezPotwierdzeniaZamkniete}");
        $this->line("  konta zalążkowe z pliku (is_seeded): {$wynik->bezPotwierdzeniaZalazkowe}");
        $this->line("  konta obsługi serwisu — moderator, administrator: {$wynik->bezPotwierdzeniaObsluga}");
        $this->newLine();

        // NIE „ZABIERA WEJŚCIE" — bo naprawa #317 wejścia nie zabrała.
        // Te konta dostają z formularza logowania list z ustawieniem
        // hasła; po jego kliknięciu adres jest potwierdzony i następnym
        // razem wychodzi już zwykły link. Zdanie o zabranym wejściu było
        // prawdziwe, dopóki rozważaną naprawą była odmowa — zostawione
        // w tym miejscu mówiłoby właścicielowi nieprawdę o jego własnym
        // serwisie, i to akurat w wierszu, który ma być podstawą decyzji.
        $this->line(
            "KONTA, KTÓRE WCHODZĄ DZIŚ PRZEZ LIST Z USTAWIENIEM HASŁA, A NIE PRZEZ LINK: {$wynik->dotknieci}"
            ." — {$this->procent($wynik->procentDotknietych())} wszystkich kont",
        );
        $this->line("  z tego ŻYWE (mają wpis, przepis, komentarz albo „Ugotowałem”): {$wynik->dotknieciZywi}");
        $this->line("  z tego PUSTE REJESTRACJE (nie zrobiły nic): {$wynik->dotknieciPuscy()}");
        $this->newLine();

        $this->line('Rozbicie żywych po rodzaju wpisu (konto może być w kilku wierszach naraz):');
        $this->line("  mają wpis: {$wynik->dotknieciZWpisem}");
        $this->line("  mają przepis: {$wynik->dotknieciZPrzepisem}");
        $this->line("  mają komentarz: {$wynik->dotknieciZKomentarzem}");
        $this->line("  mają „Ugotowałem”: {$wynik->dotknieciZUgotowaniem}");
        $this->newLine();

        $this->line("Byli tu kiedykolwiek zalogowani (ostatnio_widziany_at): {$wynik->dotknieciWidzianiKiedykolwiek}");

        return self::SUCCESS;
    }

    /**
     * Procent z przecinkiem, nie z kropką — liczba idzie do człowieka,
     * a nie do arkusza (`AGENTS.md` §11: interfejs po polsku).
     */
    private function procent(float $wartosc): string
    {
        return str_replace('.', ',', (string) $wartosc).'%';
    }
}
