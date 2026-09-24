<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresja #1296: najwyżej jedno danie od osoby także wśród pozycji, które
 * wybrał gospodarz — przy zapisie w panelu i przy odczycie zastanego wyboru.
 */
class TablicaDniaJednoDanieOdAutoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_odrzuca_dwa_dania_jednej_osoby_i_zachowuje_zaznaczenia(): void
    {
        $gospodarz = $this->moderator();
        $ania = $this->user('ania', ['display_name' => 'Ania Nowak']);
        [$pierwsze, $drugie] = Post::factory()->count(2)->create(['author_id' => $ania->id]);
        $inne = Post::factory()->create();

        $this->actingAs($gospodarz)->from(route('admin.daily-board'))
            ->put(route('admin.daily-board'), [
                'wpisy' => [$pierwsze->id, $inne->id, $drugie->id],
                'notatki' => [$inne->id => 'Piękny chleb'],
            ])
            ->assertRedirect(route('admin.daily-board'))
            ->assertSessionHasErrors(['wpisy' => 'Na tablicy może stać najwyżej jedno danie od osoby. Zostaw zaznaczone tylko jedno danie od: Ania Nowak.']);

        // Nic nie zapisaliśmy częściowo — cały wybór czeka na decyzję.
        $this->assertSame(0, DailyPick::query()->count());

        // Poprawne dane nie znikają: formularz wraca z zaznaczeniami i notatką.
        $html = $this->get(route('admin.daily-board'))->assertOk()->getContent();
        foreach ([$pierwsze, $drugie, $inne] as $wpis) {
            $this->assertMatchesRegularExpression('/value="'.$wpis->id.'"\s+checked/', $html);
        }
        $this->assertStringContainsString('Piękny chleb', $html);
    }

    /** Kontrola dodatnia: dania RÓŻNYCH osób przechodzą w tym samym formularzu. */
    public function test_panel_przyjmuje_po_jednym_daniu_od_roznych_osob(): void
    {
        $gospodarz = $this->moderator();
        [$pierwsze, $drugie] = Post::factory()->count(2)->create();

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), ['wpisy' => [$pierwsze->id, $drugie->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$pierwsze->id, $drugie->id], DailyPick::query()->forDate()->pluck('subject_id')->all());
    }

    /**
     * Zastane dwa wybory jednej osoby (sprzed reguły albo wstawione ręcznie):
     * tablica pokazuje pierwsze według pozycji gospodarza, a wolne miejsce
     * dobiera automat — dla innej osoby, nie dla drugiego dania tej samej.
     */
    public function test_tablica_z_zastanym_podwojnym_wyborem_pokazuje_jedno_danie_osoby(): void
    {
        $gospodarz = $this->moderator();
        $ania = $this->user('ania');
        $basia = $this->user('basia');
        $pierwsze = Post::factory()->create(['author_id' => $ania->id, 'published_at' => now()->subDays(2)]);
        $drugie = Post::factory()->create(['author_id' => $ania->id, 'published_at' => now()->subDay()]);
        $basi = Post::factory()->create(['author_id' => $basia->id, 'published_at' => now()->subDays(3)]);

        foreach ([$pierwsze, $drugie] as $pozycja => $wpis) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => DailyPick::TYPE_POST,
                'subject_id' => $wpis->id,
                'position' => $pozycja,
                'curator_id' => $gospodarz->id,
            ]);
        }

        $dania = app(DailyBoard::class)->forViewer(null)['posts'];

        $this->assertSame($pierwsze->id, $dania->first()->id, 'Wybór gospodarza przestał stać pierwszy.');
        $this->assertSame(1, $dania->where('author_id', $ania->id)->count(), 'Tablica pokazuje dwa dania jednej osoby.');
        $this->assertTrue($dania->contains('id', $basi->id), 'Zwolnionego miejsca nie uzupełnił automat.');
        $this->assertLessThanOrEqual(6, $dania->count());
    }
}
