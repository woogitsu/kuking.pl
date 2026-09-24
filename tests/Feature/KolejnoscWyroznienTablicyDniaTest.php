<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Domain\Social\Actions\BlockUser;
use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testy zachowania kolejności `position` wyróżnień redakcyjnych na tablicy dnia (issue #866).
 */
class KolejnoscWyroznienTablicyDniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyroznienia_osob_i_wpisow_zachowuja_kolejnosc_wedlug_position_z_bazy(): void
    {
        $gospodarz = $this->moderator();

        // Tworzymy 4 autorów i ich wpisy
        $u1 = $this->user('autor1');
        $u2 = $this->user('autor2');
        $u3 = $this->user('autor3');
        $u4 = $this->user('autor4');

        $p1 = Post::factory()->create(['author_id' => $u1->getKey(), 'published_at' => now()->subDays(4)]);
        $p2 = Post::factory()->create(['author_id' => $u2->getKey(), 'published_at' => now()->subDays(3)]);
        $p3 = Post::factory()->create(['author_id' => $u3->getKey(), 'published_at' => now()->subDays(2)]);
        $p4 = Post::factory()->create(['author_id' => $u4->getKey(), 'published_at' => now()->subDays(1)]);

        // Wyróżniamy osoby w kolejności odwrotnej do utworzenia modeli: U4 (poz. 0), U3 (poz. 1), U2 (poz. 2), U1 (poz. 3)
        $kolejnoscOsob = [$u4, $u3, $u2, $u1];
        foreach ($kolejnoscOsob as $pozycja => $osoba) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $osoba->getKey(),
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        // Wyróżniamy wpisy w kolejności odwrotnej do utworzenia modeli: P4 (poz. 0), P3 (poz. 1), P2 (poz. 2), P1 (poz. 3)
        $kolejnoscWpisow = [$p4, $p3, $p2, $p1];
        foreach ($kolejnoscWpisow as $pozycja => $wpis) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $wpis->getKey(),
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        $tablica = app(DailyBoard::class)->forViewer(null);

        $oczekiwaneOsoby = array_map(fn ($u) => $u->getKey(), $kolejnoscOsob);
        $oczekiwaneWpisy = array_map(fn ($p) => $p->getKey(), $kolejnoscWpisow);

        // Sprawdzamy pierwsze 4 pozycje (gdyż automat może dopełnić do sufitu 6)
        $this->assertSame(
            $oczekiwaneOsoby,
            $tablica['people']->take(4)->pluck('id')->all(),
            'Kolejność osób z wyboru redakcyjnego nie zachowała position.',
        );

        $this->assertSame(
            $oczekiwaneWpisy,
            $tablica['posts']->take(4)->pluck('id')->all(),
            'Kolejność wpisów z wyboru redakcyjnego nie zachowała position.',
        );
    }

    public function test_skrot_strony_powitalnej_bierze_pierwsze_pozycje_wedlug_ustalonej_kolejnosci(): void
    {
        $gospodarz = $this->moderator();

        $osoby = [];
        $wpisy = [];
        for ($i = 1; $i <= 5; $i++) {
            $u = $this->user("gosc_autor_{$i}", ['display_name' => "Gosc Autor {$i}"]);
            $p = Post::factory()->create(['author_id' => $u->getKey(), 'published_at' => now()->subDays(6 - $i), 'body' => "Danie numer {$i}"]);
            $osoby[] = $u;
            $wpisy[] = $p;
        }

        // Zapisujemy pozycje w odwróconej kolejności: indeks 4 to pozycja 0, indeks 3 to pozycja 1 itd.
        $odwroconeOsoby = array_reverse($osoby);
        $odwroconeWpisy = array_reverse($wpisy);

        foreach ($odwroconeOsoby as $poz => $u) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $u->getKey(),
                'position' => $poz,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        foreach ($odwroconeWpisy as $poz => $p) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $p->getKey(),
                'position' => $poz,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        $response = $this->get('/')->assertOk();
        $this->assertSame(array_map(fn ($p) => $p->getKey(), array_slice($odwroconeWpisy, 0, 3)), $response->viewData('board')['posts']->modelKeys());
        $html = $response->getContent();

        // Strona powitalna bierze 3 pierwsze karty (FeedController::GUEST_BOARD_PEOPLE/POSTS = 3)
        // Pierwsze 3 to powinny być $odwroconeOsoby[0,1,2] i $odwroconeWpisy[0,1,2]
        // Natomiast osoby z pozycji 3 i 4 ($odwroconeOsoby[3,4]) NIE powinny się zmieścić na tablicy.
        // Wycinamy sekcję tablicy, aby nie mylić jej z feedem odkryj poniżej.
        $start = strpos($html, 'kuking-board');
        $this->assertIsInt($start, 'Nie znaleziono tablicy w dokumencie.');
        $koniec = strpos($html, 'kuking-board-footer', $start) ?: strpos($html, '</section>', $start);
        $sekcjaTablicy = substr($html, $start, $koniec ? $koniec - $start : strlen($html) - $start);

        $widoczneOsoby = array_slice($odwroconeOsoby, 0, 3);
        $niewidoczneOsoby = array_slice($odwroconeOsoby, 3);

        foreach ($widoczneOsoby as $u) {
            $this->assertStringContainsString($u->displayName(), $sekcjaTablicy);
        }
        foreach ($niewidoczneOsoby as $u) {
            $this->assertStringNotContainsString($u->displayName(), $sekcjaTablicy);
        }
        foreach (array_slice($odwroconeWpisy, 0, 3) as $post) {
            $this->assertStringContainsString($post->body, $sekcjaTablicy);
        }
        foreach (array_slice($odwroconeWpisy, 3) as $post) {
            $this->assertStringNotContainsString($post->body, $sekcjaTablicy);
        }
    }

    public function test_ukryta_pozycja_srodkowa_nie_psuje_kolejnosci_pozostalych_a_automat_uzupelnia_koniec(): void
    {
        $gospodarz = $this->moderator();
        $widz = $this->user('widz_test');

        $u0 = $this->user('autor_poz0');
        $u1 = $this->user('autor_poz1'); // zostanie zablokowany
        $u2 = $this->user('autor_poz2');
        $u3 = $this->user('autor_poz3');

        $p0 = Post::factory()->create(['author_id' => $u0->getKey(), 'published_at' => now()->subDays(4)]);
        $p1 = Post::factory()->create(['author_id' => $u1->getKey(), 'published_at' => now()->subDays(3)]);
        $p2 = Post::factory()->create(['author_id' => $u2->getKey(), 'published_at' => now()->subDays(2)]);
        $p3 = Post::factory()->create(['author_id' => $u3->getKey(), 'published_at' => now()->subDays(1)]);

        // Autor dopełnienia automatycznego
        $innyAutor = $this->user('inny_autor_auto');
        $innyWpis = Post::factory()->create(['author_id' => $innyAutor->getKey(), 'published_at' => now()]);

        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_USER, 'subject_id' => $u0->getKey(), 'position' => 0, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_USER, 'subject_id' => $u1->getKey(), 'position' => 1, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_USER, 'subject_id' => $u2->getKey(), 'position' => 2, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_USER, 'subject_id' => $u3->getKey(), 'position' => 3, 'curator_id' => $gospodarz->getKey()]);

        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_POST, 'subject_id' => $p0->getKey(), 'position' => 0, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_POST, 'subject_id' => $p1->getKey(), 'position' => 1, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_POST, 'subject_id' => $p2->getKey(), 'position' => 2, 'curator_id' => $gospodarz->getKey()]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => DailyPick::TYPE_POST, 'subject_id' => $p3->getKey(), 'position' => 3, 'curator_id' => $gospodarz->getKey()]);

        // Widz blokuje u1
        app(BlockUser::class)->handle($widz, $u1);

        $tablica = app(DailyBoard::class)->forViewer($widz->fresh());

        // Pozycja 1 wypadła. Pozostałe powinny zachować kolejność: u0, u2, u3
        $this->assertFalse($tablica['people']->contains('id', $u1->getKey()));
        $this->assertFalse($tablica['posts']->contains('id', $p1->getKey()));

        $oczekiwaneOsoby = [$u0->getKey(), $u2->getKey(), $u3->getKey()];
        $oczekiwaneWpisy = [$p0->getKey(), $p2->getKey(), $p3->getKey()];

        $this->assertSame(
            $oczekiwaneOsoby,
            $tablica['people']->take(3)->pluck('id')->all(),
            'Wzajemna kolejność pozostałych osób po wypadnięciu pozycji środkowej została naruszona.',
        );

        $this->assertSame(
            $oczekiwaneWpisy,
            $tablica['posts']->take(3)->pluck('id')->all(),
            'Wzajemna kolejność pozostałych wpisów po wypadnięciu pozycji środkowej została naruszona.',
        );

        // Automat uzupełnia koniec
        $this->assertTrue($tablica['posts']->contains('id', $innyWpis->getKey()));
    }

    public function test_remis_position_rozstrzyga_id_wyroznienia(): void
    {
        $moderator = $this->moderator();
        $people = [$this->user('remis_a'), $this->user('remis_b')];
        foreach ([1, 0] as $index) {
            $pick = new DailyPick;
            $pick->forceFill(['id' => '00000000-0000-4000-8000-00000000000'.($index + 1),
                'shown_on' => Czas::dzisiajData(), 'subject_type' => 'user', 'subject_id' => $people[$index]->id,
                'position' => 0, 'curator_id' => $moderator->id])->save();
        }
        $this->assertSame([$people[0]->id, $people[1]->id], app(DailyBoard::class)->forViewer(null)['people']->modelKeys(), 'Remis position nie ma stabilnego rozstrzygnięcia.');
    }
}
