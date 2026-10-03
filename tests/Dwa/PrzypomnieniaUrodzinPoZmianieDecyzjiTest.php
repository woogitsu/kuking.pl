<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Users\Actions\UstawUrodziny;
use App\Domain\Users\Actions\ZapiszWyboryUrodzin;
use App\Models\Notification;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** #2880: decyzja jubilata po wstępnym SELECT, ale przed zapisem powiadomienia. */
#[Group('dwa-polaczenia')]
final class PrzypomnieniaUrodzinPoZmianieDecyzjiTest extends TestDwochPolaczen
{
    public function test_wylaczenie_widocznosci_przed_zapisem_nie_tworzy_powiadomienia(): void
    {
        [$solenizant, $odbiorca] = $this->para();
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1755, hashtext(?))', [(string) $odbiorca->getKey()]);
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);

        $this->ukryj($solenizant);
        $this->assertFalse((bool) $solenizant->fresh()?->birthday_visible_to_followers);
        $this->zwolnijBariere($bariera);

        $wynik = $komenda->wynik();
        $this->assertBezZakleszczenia($wynik, 'wyłączenie przed powiadomieniem');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(0, $this->ile($solenizant, $odbiorca), 'URODZINY_2880_OFF_NIE_TWORZY: decyzja wyłączenia była zatwierdzona przed zapisem.');
    }

    public function test_usuniecie_daty_przed_zapisem_nie_tworzy_powiadomienia(): void
    {
        [$solenizant, $odbiorca] = $this->para();
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1755, hashtext(?))', [(string) $odbiorca->getKey()]);
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);

        app(UstawUrodziny::class)->usun($solenizant);
        $this->assertNull($solenizant->fresh()?->birthday_day);
        $this->zwolnijBariere($bariera);

        $wynik = $komenda->wynik();
        $this->assertBezZakleszczenia($wynik, 'usunięcie daty przed powiadomieniem');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(0, $this->ile($solenizant, $odbiorca), 'URODZINY_2880_DATA_NIE_TWORZY: data została usunięta przed zapisem.');
    }

    public function test_zmiana_daty_na_inny_dzien_przed_zapisem_nie_tworzy_powiadomienia(): void
    {
        [$solenizant, $odbiorca] = $this->para();
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(1755, hashtext(?))', [(string) $odbiorca->getKey()]);
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);

        $jutro = Czas::lokalnie(Carbon::now())->addDay();
        app(UstawUrodziny::class)->zapisz($solenizant, $jutro->day, $jutro->month);
        $this->assertTrue((bool) $solenizant->fresh()?->birthday_visible_to_followers);
        $this->zwolnijBariere($bariera);

        $wynik = $komenda->wynik();
        $this->assertBezZakleszczenia($wynik, 'zmiana daty przed powiadomieniem');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(0, $this->ile($solenizant, $odbiorca), 'URODZINY_2880_INNY_DZIEN_NIE_TWORZY: data była już inna.');
    }

    public function test_zapis_przed_wylaczeniem_zostaje_a_wylaczenie_nie_zakleszcza_sie(): void
    {
        [$solenizant, $odbiorca] = $this->para();
        // Istniejący przyrząd #2318 zatrzymuje komendę po sprawdzeniu
        // powiadomienia: ma już SHARE na jubilacie, ale jeszcze nie INSERT.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2318, 1)', []);
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);
        $wylaczenie = $this->wTle('ukryj-urodziny', ['kto' => (string) $solenizant->getKey()]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach (['powiadomienie' => $komenda->wynik(), 'wyłączenie' => $wylaczenie->wynik()] as $krok => $wynik) {
            $this->assertBezZakleszczenia($wynik, $krok);
            $this->assertTrue($wynik['ok'], $krok.': '.$wynik['komunikat']);
        }

        $this->assertSame(1, $this->ile($solenizant, $odbiorca), 'URODZINY_2880_ZAPIS_PIERWSZY_ZOSTAJE');
        $this->assertFalse((bool) $solenizant->fresh()?->birthday_visible_to_followers);
    }

    public function test_aktualna_zgoda_tworzy_jedno_przypomnienie(): void
    {
        [$solenizant, $odbiorca] = $this->para();
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $wynik = $komenda->wynik();
        $this->assertBezZakleszczenia($wynik, 'aktualna zgoda');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(1, $this->ile($solenizant, $odbiorca), 'URODZINY_2880_ZGODA_TWORZY');
    }

    public function test_blokada_przy_mniejszym_odbiorcy_nie_zakleszcza_powiadomienia(): void
    {
        $this->przypomnienieKontraBlokada(mniejszyOdbiorca: true);
    }

    public function test_blokada_przy_wiekszym_odbiorcy_nie_zakleszcza_powiadomienia(): void
    {
        $this->przypomnienieKontraBlokada(mniejszyOdbiorca: false);
    }

    private function przypomnienieKontraBlokada(bool $mniejszyOdbiorca): void
    {
        [$mniejsze, $wieksze] = $this->paraPosortowana();
        [$solenizant, $odbiorca] = $mniejszyOdbiorca ? [$wieksze, $mniejsze] : [$mniejsze, $wieksze];
        $dzis = Czas::lokalnie(Carbon::now());
        $solenizant->forceFill([
            'birthday_day' => $dzis->day,
            'birthday_month' => $dzis->month,
            'birthday_visible_to_followers' => true,
        ])->save();
        $odbiorca->following()->attach($solenizant->getKey());

        // Komenda stoi po SHARE, przed INSERT powiadomienia. Prawdziwy
        // BlockUser bierze konta przez ZamekPary; FK powiadomienia bierze
        // KEY SHARE odbiorcy. Przy większym jubilacie sam SHARE jego konta
        // tworzył cykl z FOR UPDATE mniejszego odbiorcy, potem jubilata.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2318, 1)', []);
        $komenda = $this->wTle('przypomnij-o-urodzinach', []);
        $this->czekajNaZablokowane(1);
        $blokada = $this->wTle('zablokuj', [
            'kto' => (string) $odbiorca->getKey(),
            'kogo' => (string) $solenizant->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach (['powiadomienie' => $komenda->wynik(), 'blokada' => $blokada->wynik()] as $krok => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'URODZINY_2880_BLOKADA_PARY_NIE_ZAKLESZCZA '.$krok);
            $this->assertTrue($wynik['ok'], $krok.': '.$wynik['komunikat']);
        }
        $this->assertSame(1, $this->ile($solenizant, $odbiorca), 'Powiadomienie zapisane przed blokadą zostaje w historii.');
        $this->assertTrue($odbiorca->hasBlockRelationWith($solenizant), 'Blokada musi zostać zapisana po zakończeniu komendy.');
    }

    /** @return array{0: User, 1: User} */
    private function para(): array
    {
        $dzis = Czas::lokalnie(Carbon::now());
        $solenizant = $this->konto();
        $solenizant->forceFill([
            'birthday_day' => $dzis->day,
            'birthday_month' => $dzis->month,
            'birthday_visible_to_followers' => true,
        ])->save();
        $odbiorca = $this->konto();
        $odbiorca->following()->attach($solenizant->getKey());

        return [$solenizant, $odbiorca];
    }

    private function ukryj(User $solenizant): void
    {
        app(ZapiszWyboryUrodzin::class)->handle(
            $solenizant,
            (bool) $solenizant->birthday_wishes_enabled,
            (bool) $solenizant->wants_birthday_email,
            (bool) $solenizant->wants_birthday_email,
            (bool) $solenizant->birthday_visible_to_followers,
            false,
        );
    }

    private function ile(User $solenizant, User $odbiorca): int
    {
        return DB::table('notifications')
            ->where('user_id', $odbiorca->getKey())
            ->where('actor_id', $solenizant->getKey())
            ->where('type', Notification::TYPE_BIRTHDAY)
            ->count();
    }
}
