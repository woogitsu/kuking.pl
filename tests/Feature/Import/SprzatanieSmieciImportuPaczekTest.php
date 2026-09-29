<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Users\Import\MagazynPaczek;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nocne sprzątanie porzuconych plików wczytywania paczki eksportu (issue #1985, etap 3).
 */
class SprzatanieSmieciImportuPaczekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_komenda_kasuje_tylko_przeterminowane_pliki_i_puste_katalogi(): void
    {
        $magazyn = app(MagazynPaczek::class);
        $porzucony = User::factory()->create();
        $tokenStary = $magazyn->zapisz($porzucony, UploadedFile::fake()->createWithContent('a.zip', 'x'));

        $this->travel(3)->hours();

        $swiezy = User::factory()->create();
        $tokenSwiezy = $magazyn->zapisz($swiezy, UploadedFile::fake()->createWithContent('b.zip', 'y'));

        // Zegar testu jest przesunięty, a mtime pliku bierze się z prawdziwego czasu.
        touch(Storage::disk('local')->path('import-paczek/'.$swiezy->getKey().'/'.$tokenSwiezy.'.zip'), now()->getTimestamp());

        // `zapisz` sam sprząta przy okazji, więc odtwarzamy porzucony plik ręcznie:
        // zadanie ma działać także wtedy, gdy nikt nie wchodzi w ekran wczytywania.
        Storage::disk('local')->put('import-paczek/'.$porzucony->getKey().'/'.$tokenStary.'.zip', 'x');
        touch(Storage::disk('local')->path('import-paczek/'.$porzucony->getKey().'/'.$tokenStary.'.zip'), now()->subHours(5)->getTimestamp());

        $this->artisan('kuking:sprzataj-paczki-importu')
            ->expectsOutput('Skasowano porzuconych plików wczytywania paczki: 1.')
            ->assertSuccessful();

        $dysk = Storage::disk('local');
        $this->assertFalse($dysk->exists('import-paczek/'.$porzucony->getKey().'/'.$tokenStary.'.zip'));
        $this->assertFalse($dysk->exists('import-paczek/'.$porzucony->getKey()), 'Pusty katalog osoby nie zostaje.');
        $this->assertTrue($dysk->exists('import-paczek/'.$swiezy->getKey().'/'.$tokenSwiezy.'.zip'), 'Świeża paczka czeka dalej.');
    }

    public function test_komenda_bez_niczego_do_sprzatania_nie_psuje_sie(): void
    {
        $this->artisan('kuking:sprzataj-paczki-importu')
            ->expectsOutput('Skasowano porzuconych plików wczytywania paczki: 0.')
            ->assertSuccessful();
    }

    public function test_zadanie_jest_w_harmonogramie_raz_na_dobe_na_jednym_serwerze(): void
    {
        $zdarzenie = collect(app(Schedule::class)->events())
            ->first(fn ($e) => ($e->description ?? null) === 'kuking:sprzataj-paczki-importu');

        $this->assertNotNull($zdarzenie, 'Brak zadania sprzątania paczek importu w harmonogramie.');
        $this->assertSame('10 3 * * *', $zdarzenie->expression);
        $this->assertTrue($zdarzenie->onOneServer);
        $this->assertTrue($zdarzenie->withoutOverlapping);
    }
}
