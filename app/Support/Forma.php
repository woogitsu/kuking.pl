<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Profile;
use App\Models\User;

/**
 * Tekst w formie, którą osoba SAMA wybrała (D-268, #1753).
 *
 * JEDYNA DROGA DO RODZAJU W TEKŚCIE SERWISU
 * Do D-268 reguła brzmiała „do czytelnika bez rodzaju” i pilnował jej
 * `TekstyNiePrzypisujaPlciTest`. Po D-268 rodzaj wolno pokazać — ale tylko
 * osobie, która odpowiedziała na „Jak mamy do Ciebie pisać?”, i tylko o niej
 * innym. Ten helper jest jedynym miejscem, które zamienia wybór z profilu na
 * wariant tekstu; test przepuszcza formę rodzajową WYŁĄCZNIE wewnątrz
 * wywołania `Forma::dla(...)`. Goły „ugotowałaś” w widoku nadal oblewa.
 *
 * TRZY WARIANTY, KAŻDY OBOWIĄZKOWY
 * Wariant neutralny nie ma wartości domyślnej i nie da się go pominąć
 * (sygnatura). To on trafia do każdego, kto nic nie wybrał — czyli dziś do
 * prawie wszystkich — więc musi być tekstem bez rodzaju. `FormaTekstyTest`
 * sprawdza to w każdym wywołaniu w repozytorium.
 *
 * NIE ZGADUJEMY. Brak profilu, konto wymazane, pole `NULL` albo wartość, której
 * helper nie zna — zawsze wariant neutralny. Imienia ani nazwy konta nie
 * czytamy (D-268 pkt 2, D-153).
 *
 * Gdyby kiedyś przyszło ICU (`{forma, select, …}`), przepina się to w jednym
 * miejscu — tutaj.
 */
final class Forma
{
    /**
     * @param  User|Profile|null  $osoba  do kogo albo o kim jest tekst
     * @param  string  $zenska  wariant dla formy żeńskiej — „ugotowałaś”
     * @param  string  $meska  wariant dla formy męskiej — „ugotowałeś”
     * @param  string  $neutralna  wariant bez rodzaju — „gotujesz”; trafia do każdego bez wyboru
     */
    public static function dla(User|Profile|null $osoba, string $zenska, string $meska, string $neutralna): string
    {
        $profil = $osoba instanceof User ? $osoba->profile : $osoba;

        return match ($profil?->form_of_address) {
            Profile::FORM_FEMININE => $zenska,
            Profile::FORM_MASCULINE => $meska,
            default => $neutralna,
        };
    }
}
