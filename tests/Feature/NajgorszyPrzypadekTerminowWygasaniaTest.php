<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Users\Import\MagazynPaczek;
use App\Models\CookingNote;
use App\Models\CookingProgress;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Najgorszy przypadek terminów „24 godziny” i „2 godziny” z polityki prywatności (#2708, pyt. 6).
 *
 * Polityka mówi: dane „przestają działać” po terminie, a zapis „co noc usuwamy”.
 * Przy sprzątaniu raz na dobę rekord założony tuż po przebiegu żyje w bazie
 * do dwóch przebiegów, czyli do terminu + doby. Te testy pilnują obu połówek
 * obietnicy: (1) po terminie odczyt już go nie widzi, mimo że sprzątanie jeszcze
 * nie przyszło; (2) sprzątanie nie biegnie rzadziej niż raz na dobę, więc zapis
 * znika najpóźniej po terminie + dobie.
 */
class NajgorszyPrzypadekTerminowWygasaniaTest extends TestCase
{
    use RefreshDatabase;

    /** Komendy sprzątające dane z terminem w godzinach, o których mówi polityka. */
    private const KOMENDY_SPRZATAJACE = [
        'kuking:sprzataj-postep-gotowania',
        'kuking:sprzataj-wspolne-gotowanie',
        'kuking:sprzataj-zmiany-adresu',
        'kuking:sprzataj-zaproszenia',
        'kuking:sprzataj-paczki-importu',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sprzatanie_terminow_z_polityki_biegnie_najrzadziej_raz_na_dobe(): void
    {
        $zadania = [];
        foreach (app(Schedule::class)->events() as $event) {
            $zadania[(string) ($event->description ?? '')] = $event->expression;
        }

        foreach (self::KOMENDY_SPRZATAJACE as $komenda) {
            $this->assertArrayHasKey($komenda, $zadania, "Brak zadania {$komenda} w harmonogramie.");
            // Stały dzień, miesiąc i dzień tygodnia (`* * *`): zadanie nie przeskakuje dni.
            $this->assertMatchesRegularExpression(
                '/^\S+ \S+ \* \* \*$/',
                $zadania[$komenda],
                "Zadanie {$komenda} biegnie rzadziej niż raz na dobę ({$zadania[$komenda]}), a polityka mówi „co noc”.",
            );
        }
    }

    public function test_trzy_nocne_przebiegi_z_rejestru_sa_o_zapisanych_godzinach_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));

        $zadania = [];
        foreach (app(Schedule::class)->events() as $event) {
            $zadania[(string) ($event->description ?? '')] = $event;
        }

        foreach ([
            'kuking:sprzataj-wspolne-gotowanie' => '30 2 * * *',
            'kuking:sprzataj-postep-gotowania' => '0 3 * * *',
            'kuking:sprzataj-paczki-importu' => '10 3 * * *',
        ] as $komenda => $wyrazenie) {
            $this->assertArrayHasKey($komenda, $zadania);
            $this->assertSame($wyrazenie, $zadania[$komenda]->expression);
            $this->assertContains($zadania[$komenda]->timezone, [null, 'UTC']);
        }
    }

    public function test_archiwum_polityki_z_30_09_jest_identyczne_z_biezaca(): void
    {
        $this->assertSame(
            file_get_contents(resource_path('legal/polityka-prywatnosci.md')),
            file_get_contents(resource_path('legal/archiwum/polityka-prywatnosci-2026-09-30.md')),
            'Bieżąca polityka i archiwum z 30.09 mają być identyczne (decyzja właściciela z 2.10.2026).',
        );
    }

    public function test_polityka_podaje_gorny_termin_usuniecia_zgodny_z_terminem_i_dobowym_sprzataniem(): void
    {
        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));

        // Sprzątanie biegnie raz na dobę, więc górny termin usunięcia = termin + 24 h.
        $wiersze = [
            'Zapamiętany postęp w trybie gotowania' => (int) config('kuking.cooking_progress.retention_hours'),
            'Wspólne gotowanie' => (int) config('kuking.wspolne_gotowanie.retencja_godziny'),
            'Prywatny dopisek z gotowania' => (int) config('kuking.cooking_note.retention_hours'),
            'Założenie i prowadzenie konta' => (int) config('kuking.account.email_change_ttl_hours'),
            'Zaproszenie do założenia konta' => (int) config('kuking.login_link.zaproszenia.waznosc_godzin'),
        ];
        foreach ($wiersze as $poczatek => $godzin) {
            $gorny = $godzin + 24;
            $this->assertMatchesRegularExpression(
                '/^\| '.preg_quote($poczatek, '/').'.*najpóźniej po około \*{0,2}'.$gorny.' godzin/mu',
                $polityka,
                "Polityka nie podaje górnego terminu usunięcia ({$gorny} godzin) w wierszu „{$poczatek}”.",
            );
        }

        $this->assertMatchesRegularExpression(
            '/^\| Wczytanie paczki z danymi z Kuking .*najpóźniej w nocy po tym terminie, czyli w ciągu doby/mu',
            $polityka,
            'Polityka nie podaje górnego terminu usunięcia paczki importu.',
        );
    }

    public function test_postep_gotowania_zalozony_tuz_po_przebiegu_nie_dziala_po_dobie_i_znika_przy_drugim_przebiegu(): void
    {
        $godzin = (int) config('kuking.cooking_progress.retention_hours');
        $osoba = User::factory()->create();
        $recipe = Recipe::factory()->create();

        // Przebieg sprzątania o 03:00; rekord powstaje sekundę później.
        Carbon::setTestNow(Carbon::parse('2026-10-03 03:00:01'));
        app(PostepGotowania::class)->wlacz($osoba, $recipe, [], []);
        $this->assertNotNull(app(PostepGotowania::class)->aktywny($osoba, $recipe));

        // Pierwszy kolejny przebieg (03:00 następnej doby): rekord jeszcze ważny i zostaje.
        Carbon::setTestNow(Carbon::parse('2026-10-04 03:00:00'));
        $this->artisan('kuking:sprzataj-postep-gotowania')->assertSuccessful();
        $this->assertSame(1, CookingProgress::query()->count());

        // Po terminie, a przed drugim przebiegiem: wiersz jeszcze fizycznie jest, ale nie działa.
        Carbon::setTestNow(Carbon::parse('2026-10-03 03:00:01')->addHours($godzin)->addSecond());
        $this->assertNull(app(PostepGotowania::class)->aktywny($osoba, $recipe), 'Po terminie postęp nadal działa.');

        // Drugi przebieg: najpóźniej termin + doba od założenia.
        Carbon::setTestNow(Carbon::parse('2026-10-05 03:00:00'));
        $this->artisan('kuking:sprzataj-postep-gotowania')->assertSuccessful();
        $this->assertSame(0, CookingProgress::query()->count(), 'Zapis nie zniknął w terminie + dobie.');
    }

    public function test_dopisek_z_gotowania_zalozony_tuz_po_przebiegu_nie_dziala_po_dobie_i_znika_przy_drugim_przebiegu(): void
    {
        $godzin = (int) config('kuking.cooking_note.retention_hours');
        $osoba = User::factory()->create();
        $recipe = Recipe::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-10-03 03:00:01'));
        app(RoboczyDopisek::class)->zapisz($osoba, $recipe, 'mniej soli', 0);
        $this->assertNotNull(app(RoboczyDopisek::class)->aktywny($osoba, $recipe));

        Carbon::setTestNow(Carbon::parse('2026-10-04 03:00:00'));
        $this->artisan('kuking:sprzataj-postep-gotowania')->assertSuccessful();
        $this->assertSame(1, CookingNote::query()->count());

        Carbon::setTestNow(Carbon::parse('2026-10-03 03:00:01')->addHours($godzin)->addSecond());
        $this->assertNull(app(RoboczyDopisek::class)->aktywny($osoba, $recipe), 'Po terminie dopisek nadal działa.');

        Carbon::setTestNow(Carbon::parse('2026-10-05 03:00:00'));
        $this->artisan('kuking:sprzataj-postep-gotowania')->assertSuccessful();
        $this->assertSame(0, CookingNote::query()->count(), 'Dopisek nie zniknął w terminie + dobie.');
    }

    public function test_wspolne_gotowanie_jest_niedostepne_na_granicy_terminu_i_znika_przy_nastepnym_przebiegu(): void
    {
        $godzin = (int) config('kuking.wspolne_gotowanie.retencja_godziny');
        Carbon::setTestNow(Carbon::parse('2026-10-03 02:30:00', 'UTC'));
        $osoba = User::factory()->create();
        $recipe = Recipe::factory()->create(['author_id' => $osoba->getKey()]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Ugotuj wodę.']);
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($osoba, $recipe);
        $termin = $sesja->expires_at;
        $this->assertSame($godzin, (int) Carbon::now()->diffInHours($termin));

        Carbon::setTestNow(Carbon::parse('2026-10-04 02:29:59', 'UTC'));
        $this->assertTrue($sesja->fresh()?->trwa() ?? false);

        Carbon::setTestNow($termin);
        $this->assertFalse($sesja->fresh()?->trwa() ?? true, 'RETENCJA_2708_SESJA_WIDOCZNA_NA_GRANICY');
        $this->assertSame(1, CookingSession::query()->count());

        // Fizyczna mutacja `<=` na `<` w sprzątaniu musi zostawić ten wiersz.
        $this->artisan('kuking:sprzataj-wspolne-gotowanie')->assertSuccessful();
        $this->assertSame(0, CookingSession::query()->count(), 'RETENCJA_2708_SESJA_NIEUSUNIETA_NA_GRANICY');
    }

    public function test_zip_na_dokladnej_granicy_dwoch_godzin_jeszcze_nie_jest_przeterminowany(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-10-03 01:10:00', 'UTC'));
        $osoba = User::factory()->create();
        $magazyn = app(MagazynPaczek::class);
        $token = $magazyn->zapisz($osoba, UploadedFile::fake()->createWithContent('a.zip', 'x'));
        $plik = 'import-paczek/'.$osoba->getKey().'/'.$token.'.zip';
        touch(Storage::disk('local')->path($plik), Carbon::now()->getTimestamp());

        Carbon::setTestNow(Carbon::parse('2026-10-03 03:10:00', 'UTC'));
        $this->artisan('kuking:sprzataj-paczki-importu')->assertSuccessful();
        $this->assertTrue(Storage::disk('local')->exists($plik), 'RETENCJA_2708_ZIP_ZNIKNAL_NA_GRANICY');

        Carbon::setTestNow(Carbon::parse('2026-10-03 03:10:01', 'UTC'));
        $this->assertNull($magazyn->sciezka($osoba, $token), 'RETENCJA_2708_ZIP_NADAL_DOSTEPNY_PO_TERMINIE');
    }

    public function test_paczka_importu_po_terminie_jest_niedostepna_a_porzucona_znika_przy_nocnym_przebiegu(): void
    {
        Storage::fake('local');
        $godzin = (int) config('kuking.import_paczki.przechowanie_godzin');
        $magazyn = app(MagazynPaczek::class);
        $osoba = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-10-03 03:10:01'));
        $token = $magazyn->zapisz($osoba, UploadedFile::fake()->createWithContent('a.zip', 'x'));
        $plik = 'import-paczek/'.$osoba->getKey().'/'.$token.'.zip';
        $utworzono = Carbon::now()->getTimestamp();
        touch(Storage::disk('local')->path($plik), $utworzono);
        $this->assertNotNull($magazyn->sciezka($osoba, $token));

        // Tuż po terminie, bez żadnego przebiegu sprzątania: odczyt odmawia, choć plik leży.
        Carbon::setTestNow(Carbon::now()->addHours($godzin)->addMinute());
        $this->assertTrue(Storage::disk('local')->exists($plik));
        $this->assertNull($magazyn->sciezka($osoba, $token), 'Po terminie paczkę nadal da się wczytać.');

        // Porzucony plik (nikt nie wszedł w ekran wczytywania) znika przy następnym nocnym przebiegu.
        Storage::disk('local')->put($plik, 'x');
        touch(Storage::disk('local')->path($plik), $utworzono);
        Carbon::setTestNow(Carbon::parse('2026-10-04 03:10:00'));
        $this->artisan('kuking:sprzataj-paczki-importu')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($plik), 'Porzucona paczka nie zniknęła przy nocnym przebiegu.');
    }
}
