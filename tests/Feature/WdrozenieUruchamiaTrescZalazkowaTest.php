<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Treść zalążkowa (D-025) ma być na produkcji — i musi ją tam coś wwieźć.
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W RUNBOOKU
 * Bo przez dobę nie było jej na stronie i nikt tego nie zauważył. Decyzja
 * D-025 zapadła, `TrescZalazkowaSeeder` powstał 8 września z kompletem
 * własnych testów, wjechał na produkcję razem z resztą kodu — i nic się nie
 * stało. Ani `preDeployCommand` w `.railway/railway.ts`, ani
 * `docker/entrypoint.sh` nie wołały `db:seed` w ŻADNYM miejscu.
 *
 * Sprawdzone 9 września na żywej stronie: `/odkryj` nie pokazywał ani jednej
 * odznaki „konto przykładowe", mimo że produkcja stała na commicie z tym
 * seederem. Usterka ostatniego metra — funkcji nie ma nie dlatego, że jest
 * zepsuta, tylko dlatego, że nikt jej nie uruchamia.
 *
 * Ten test pilnuje dwóch rzeczy, których nie pilnował nikt:
 *   1. ścieżka wdrożenia NAPRAWDĘ woła `db:seed`, i to po migracjach;
 *   2. `db:seed` na produkcji wwozi treść zalążkową i NIE wwozi danych demo.
 *
 * Punkt 2 jest wykonywany, nie czytany ze źródeł: podmieniamy środowisko na
 * `production` i uruchamiamy prawdziwy `DatabaseSeeder`. Sam odczyt warunku
 * `if (! app()->environment('production'))` z pliku byłby sprawdzeniem, że
 * ktoś napisał kod, a nie że kod działa.
 *
 * Idempotencji seedera ten test NIE powtarza — pilnuje jej
 * `TrescZalazkowaSeederTest::test_drugi_przebieg_nic_nie_zmienia`.
 *
 * CZEGO TEN TEST NIE DOWODZI — DOPISANE 9 WRZEŚNIA WIECZOREM
 * Punkt 1 czyta `.railway/railway.ts`, czyli PLIK, a nie produkcję. Jest
 * zielony od 9 września — i przez cały ten czas produkcja **nie wołała
 * seedera**. Zmierzony connectorem Railway `preDeployCommand` serwisu
 * `kuking.pl` to dokładnie `php artisan migrate --force --no-interaction`
 * i nic więcej, bo **`railway config apply` nigdy nie zostało uruchomione**.
 *
 * Czyli: usterka ostatniego metra, którą ten test miał zamknąć, przesunęła
 * się o jeden metr dalej. Zielony test tutaj znaczy „ktoś to zapisał
 * w konfiguracji", nie „produkcja to robi". Tej drugiej rzeczy nie da się
 * sprawdzić z testu — dowodem jest wyłącznie odczyt z Railwaya albo
 * przykładowe konto widoczne na żywym `/odkryj` po wdrożeniu.
 *
 * Nie osłabiam przez to asercji niżej: pilnowanie, że plik konfiguracyjny
 * mówi właściwą rzecz, jest potrzebne. Chodzi o to, żeby nikt nie przeczytał
 * tej zieleni jako dowodu, którym ona nie jest. Kolejność kroków po stronie
 * właściciela opisuje `docs/OTWARCIE.md`, wiersz 14 tabeli stanu.
 */
class WdrozenieUruchamiaTrescZalazkowaTest extends TestCase
{
    use RefreshDatabase;

    private function konfiguracjaRailway(): string
    {
        $sciezka = base_path('.railway/railway.ts');

        $this->assertFileExists($sciezka, 'Nie ma .railway/railway.ts. Jeśli plik przeniesiono, popraw ścieżkę tutaj.');

        return (string) file_get_contents($sciezka);
    }

    #[Test]
    public function wdrozenie_wola_seeder_po_migracjach(): void
    {
        $konfiguracja = $this->konfiguracjaRailway();

        $od = mb_strpos($konfiguracja, 'preDeployCommand');
        $this->assertNotFalse($od, 'W .railway/railway.ts nie ma już pola preDeployCommand — to jest jedyne miejsce, w którym cokolwiek uruchamia się przy wdrożeniu.');

        $do = mb_strpos($konfiguracja, '],', $od);
        $this->assertNotFalse($do, 'Nie umiem odczytać listy komend pre-deploy. Jeśli zmienił się jej kształt, popraw ten test razem z nią.');

        $komendy = mb_substr($konfiguracja, $od, $do - $od);

        // Kontrola metody pomiaru: jeśli nie widzimy tu nawet migracji, to
        // znaczy, że czytamy nie ten fragment — i sprawdzenie seedera niżej
        // byłoby puste, nie zielone.
        $pozycjaMigracji = mb_strpos($komendy, 'artisan migrate');
        $this->assertNotFalse($pozycjaMigracji, 'W komendach pre-deploy nie ma migracji. Czytam zły fragment pliku albo wdrożenie przestało migrować — jedno i drugie trzeba sprawdzić ręcznie.');

        $pozycjaSeedera = mb_strpos($komendy, 'artisan db:seed');
        $this->assertNotFalse($pozycjaSeedera,
            'Wdrożenie nie uruchamia `db:seed`, więc treść zalążkowa z D-025 nigdy nie trafi na produkcję — '
            .'dokładnie tak było 8 i 9 września. Sam seeder działa i ma własne testy; brakowało tego, żeby ktoś go wywołał.');

        $this->assertLessThan($pozycjaSeedera, $pozycjaMigracji,
            'Seeder stoi PRZED migracjami. Pisze do kolumn, które dokłada migracja, więc w tej kolejności wywali wdrożenie.');
    }

    #[Test]
    public function seed_na_produkcji_wwozi_tresc_zalazkowa_i_nie_wwozi_danych_demo(): void
    {
        // `environment()` czyta kontener, nie config — podmieniamy więc to,
        // co naprawdę sprawdza `DatabaseSeeder`.
        $this->app->instance('env', 'production');
        $this->assertTrue($this->app->environment('production'), 'Nie udało się udawać produkcji — bez tego ten test nie bada niczego.');

        // `--force`, bo na produkcji artisan pyta o potwierdzenie i bez tego
        // komenda wisi na pytaniu — to zresztą DOKŁADNIE ten sam powód, dla
        // którego `--force` stoi w komendzie pre-deploy.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertSuccessful();

        $przykladowych = DB::table('users')->where('is_seeded', true)->count();
        $this->assertGreaterThan(0, $przykladowych,
            'Po `db:seed` na produkcji nie ma ani jednego konta przykładowego. D-025 mówi wprost, że treść zalążkowa ma tam wejść — pusty feed dla pierwszej zaproszonej osoby był powodem tej decyzji.');

        // DWIE PUŁAPKI, obie złapane przy pisaniu tego testu.
        //
        // Po samej domenie e-maila tych seederów NIE DA SIĘ odróżnić: oba
        // używają `example.test`. Pierwsza wersja liczyła adresy i wykazała
        // „12 kont demo na produkcji", choć była to treść zalążkowa, która
        // MA tam być.
        //
        // Druga: `basia` występuje w OBU seederach, więc `basia@example.test`
        // powstaje także z samej treści zalążkowej i nie świadczy o niczym.
        // Zostają trzy nazwy, które ma wyłącznie DemoSeeder — w tym konto
        // z rolą moderatora, czyli to, którego obecność na produkcji byłaby
        // najgorsza.
        $demo = DB::table('users')
            ->whereIn('email', [
                'marek@example.test',
                'ania@example.test',
                'moderacja@example.test',
            ])
            ->count();

        $this->assertSame(0, $demo,
            'Na produkcję weszły konta z DemoSeedera. Warunek `if (! app()->environment(\'production\'))` w DatabaseSeeder przestał działać — a to on jest jedyną rzeczą, która trzyma dane demo z dala od żywej bazy. Wśród nich jest konto z rolą moderatora.');

        $this->assertSame(0, DB::table('users')->where('is_seeded', false)->count(),
            'Po samym `db:seed` w bazie jest konto NIEoznaczone jako przykładowe. Wszystko, co wwozi wdrożenie, musi nosić odznakę „konto przykładowe" — D-025 zgadza się na treść zalążkową wyłącznie pod tym warunkiem.');
    }
}
