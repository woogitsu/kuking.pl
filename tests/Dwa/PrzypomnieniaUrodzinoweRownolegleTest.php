<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Notification;
use App\Support\Czas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dwa równoległe `kuking:przypomnij-o-urodzinach` (#2318).
 *
 * Komenda sprawdzała „czy ta para dostała już dziś przypomnienie" i dobowy
 * limit odbiorcy zwykłym SELECT-em, a zapis robiła osobno. Dwa procesy
 * (ręczne uruchomienie obok harmonogramu albo dwie instancje po utracie
 * blokady `onOneServer`) oba czytały „brak" i oba tworzyły `birthday.today`.
 *
 * Przeplot wymuszony barierą PO rzeczywistym sprawdzeniu, a PRZED zapisem,
 * w obu procesach naraz. Z poprawką drugi proces stoi na blokadzie
 * odbiorcy, zanim w ogóle sprawdzi, i po zwolnieniu widzi wiersz pierwszego.
 *
 * Czego nie dowodzi: osobnego przeplotu dla dobowego limitu (dwa procesy
 * przy RÓŻNYCH solenizantach). Limit i „już było" stoją w tej samej sekcji
 * krytycznej na odbiorcę, więc ta sama blokada obejmuje oba warunki.
 */
#[Group('dwa-polaczenia')]
final class PrzypomnieniaUrodzinoweRownolegleTest extends TestDwochPolaczen
{
    public function test_dwa_rownolegle_uruchomienia_daja_jedno_przypomnienie_na_pare(): void
    {
        $dzis = Czas::lokalnie(Carbon::now());
        $solenizantka = $this->konto();
        $solenizantka->forceFill([
            'birthday_day' => $dzis->day,
            'birthday_month' => $dzis->month,
            'birthday_visible_to_followers' => true,
        ])->save();
        $obserwujacy = $this->konto();
        $obserwujacy->following()->attach($solenizantka->getKey());

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2318, 1)', []);
        $pierwszy = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'uruchomienie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Uruchomienie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $ile = DB::table('notifications')
            ->where('user_id', $obserwujacy->getKey())
            ->where('actor_id', $solenizantka->getKey())
            ->where('type', Notification::TYPE_BIRTHDAY)
            ->count();

        // Kontrola dodatnia: 0 znaczyłoby, że komenda nie zadziałała wcale
        // (np. cisza nocna), a nie że wyścig jest zamknięty.
        $this->assertSame(1, $ile, 'Para (odbiorca, solenizantka) dostała '.$ile.' przypomnień w jednej dobie.');
    }
}
