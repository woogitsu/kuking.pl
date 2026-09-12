<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data wpisu w strumieniu gubi rok — ale tylko wtedy, gdy wolno.
 *
 * SKĄD TA ZMIANA
 * Decyzja właściciela z 12 września 2026, podjęta PO pomiarze główki karty
 * wpisu: „krótka data". Pomiar mówił, że całą robotę robi skrócenie przycisku
 * menu do trzech kropek, a data dokłada do tego 0–1 wiersza przy czcionce
 * 100% — i to właśnie te 0–1 wiersza są tu kupowane, świadomie.
 *
 * CO TEN TEST PILNUJE
 * Skrót jest prawdziwy tylko przy wpisie z BIEŻĄCEGO roku. „12 września"
 * przy wpisie sprzed dwóch lat nie jest skrótem, tylko nieprawdą podaną bez
 * ostrzeżenia — a to jest archiwum, do którego ludzie wracają.
 *
 * ZEGAR JEST PRZYMROŻONY W KAŻDYM TEŚCIE — i to nie jest ozdoba.
 * Ta klasa porównuje ROK momentu z ROKIEM „teraz", więc bez przymrożenia
 * przechodziłaby do 31 grudnia i zaczęła padać 1 stycznia, na sprawnym
 * kodzie (pułapka 9 z `docs/PULAPKI_TESTOW.md`, złapana w tym repozytorium
 * 12 września 2026 o 10:00).
 */
class DataWpisuGubiRokTylkoWTymRokuTest extends TestCase
{
    use RefreshDatabase;

    public function test_wpis_z_tego_roku_pokazuje_date_bez_roku(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 08:00:00', 'UTC'));

        $moment = CarbonImmutable::parse('2026-09-12 08:04:00', 'UTC');

        // 08:04 UTC to 10:04 czasu polskiego — strefa działa jak wszędzie
        // indziej w tej klasie (`Czas::lokalnie`).
        $this->assertSame('12 września, 10:04', Czas::dataWpisu($moment));
    }

    public function test_wpis_z_poprzedniego_roku_zostaje_z_rokiem(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 08:00:00', 'UTC'));

        $moment = CarbonImmutable::parse('2025-09-12 08:04:00', 'UTC');

        $this->assertSame('12 września 2025, 10:04', Czas::dataWpisu($moment));
    }

    public function test_prog_roku_liczy_sie_w_strefie_czlowieka_a_nie_w_utc(): void
    {
        /*
         * NAJWAŻNIEJSZY TEST W TYM PLIKU.
         *
         * „Teraz" to 31 grudnia 23:30 UTC, czyli w Polsce jest już
         * 1 stycznia 2027, 00:30. Wpis powstał godzinę wcześniej — 31 grudnia
         * 2026, 23:00 czasu polskiego, czyli w POPRZEDNIM roku człowieka.
         *
         * Gdyby próg liczył się w UTC, oba momenty byłyby z 2026 i data
         * straciłaby rok — przy wpisie z zeszłego roku. Przez pierwsze dwie
         * godziny polskiej doby 1 stycznia (jedną zimą) serwis kłamałby więc
         * o każdym wpisie z sylwestra.
         */
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC'));

        $moment = CarbonImmutable::parse('2026-12-31 22:00:00', 'UTC');

        $this->assertSame('31 grudnia 2026, 23:00', Czas::dataWpisu($moment));
    }

    public function test_brak_daty_nie_wywraca_karty(): void
    {
        // Wpis w przygotowaniu nie ma `published_at`. Karta ma wtedy nie
        // pokazywać nic, a nie wywrócić się na `null`.
        $this->assertSame('', Czas::dataWpisu(null));
    }

    public function test_karta_wpisu_pokazuje_krotka_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 08:00:00', 'UTC'));

        $autor = $this->user('kucharka', ['display_name' => 'Kucharka Testowa']);

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Rosół na niedzielę',
            'published_at' => CarbonImmutable::parse('2026-09-12 08:04:00', 'UTC'),
        ]);

        $odpowiedz = $this->actingAs($autor)->get(route('profile.show', 'kucharka'))->assertOk();

        // Asercja kontrolna: to naprawdę karta tego wpisu, a nie pusty ekran,
        // który przepuściłby oba sprawdzenia niżej (pułapka 2).
        $odpowiedz->assertSee('Rosół na niedzielę');

        $odpowiedz->assertSee('12 września, 10:04');
        $odpowiedz->assertDontSee('12 września 2026, 10:04');
    }
}
