<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Gotowanie\RoboczyDopisek;
use App\Domain\Users\Import\MagazynPaczek;
use App\Models\CookingNote;
use App\Models\CookingProgress;
use App\Models\Recipe;
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
