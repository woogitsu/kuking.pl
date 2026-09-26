<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelTablicyZachowujeWyborTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapis_pol_z_panelu_zachowuje_wpis_41_i_notatke_a_odznaczenie_go_usuwa(): void
    {
        $this->freezeTime();
        $this->actingAs($this->moderator());
        $author = $this->user();
        $older = Post::factory()->create(['author_id' => $author->id, 'published_at' => now()->subDays(2)]);
        $this->pick('post', $older->id, 'Notatka starszego wpisu');
        $newer = Post::factory()->count(40)->create(['author_id' => $author->id, 'published_at' => now()->subHour()])->first();
        $this->pick('post', $newer->id, 'Notatka nowego wpisu');

        $form = $this->form();
        $this->assertContains($older->id, $form['wpisy'] ?? [], 'Panel zgubił wyróżniony wpis poza czterdziestką.');
        $form['notatki'][$newer->id] = 'Zmieniona notatka';
        $this->put(route('admin.daily-board'), $form)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('daily_picks', ['subject_id' => $older->id, 'note' => 'Notatka starszego wpisu']);
        $this->assertDatabaseHas('daily_picks', ['subject_id' => $newer->id, 'note' => 'Zmieniona notatka']);

        $form = $this->form();
        $form['wpisy'] = array_values(array_diff($form['wpisy'], [$older->id]));
        $this->put(route('admin.daily-board'), $form)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('daily_picks', ['subject_id' => $older->id]);
    }

    public function test_wybrany_wpis_starszy_niz_tydzien_i_osoba_poza_kandydatami_maja_pola(): void
    {
        $this->actingAs($this->moderator());
        for ($i = 0; $i < 45; $i++) {
            $author = $this->user('kandydat'.$i);
            Post::factory()->create(['author_id' => $author->id]);
        }
        // Osoba bez wpisu nie jest nowym kandydatem, ale istniejący wybór
        // aktywnej osoby nadal ma dać się świadomie usunąć.
        $chosen = $this->user('wybrany_poza_lista');
        $old = Post::factory()->create(['author_id' => $author->id, 'published_at' => now()->subDays(8)]);
        $this->pick('user', $chosen->id, 'Wybrana osoba');
        $this->pick('post', $old->id, 'Starszy wpis');
        $form = $this->form();
        $this->assertContains($chosen->id, $form['osoby'] ?? [], 'Panel zgubił wybraną osobę.');
        $this->assertContains($old->id, $form['wpisy'] ?? [], 'Panel zgubił wyróżnienie starsze niż tydzień.');
    }

    public function test_niedostepny_wybor_ma_jawny_komunikat_bez_prywatnej_tresci(): void
    {
        $this->actingAs($this->moderator());
        $post = Post::factory()->create(['visibility' => Post::VISIBILITY_PRIVATE, 'body' => 'TAJNA TRESC']);
        $this->pick('post', $post->id, 'TAJNA NOTATKA');
        $response = $this->get(route('admin.daily-board'))->assertOk()
            ->assertSee('Niedostępne wyróżnienia zostaną pominięte przy zapisie.')
            ->assertDontSee('TAJNA TRESC')->assertDontSee('TAJNA NOTATKA');
        $form = $this->readForm($response->getContent());
        $this->assertNotContains($post->id, $form['wpisy'] ?? []);
        $this->put(route('admin.daily-board'), $form)->assertSessionHasNoErrors();
        $this->assertSame(Post::VISIBILITY_PRIVATE, $post->fresh()->visibility);
    }

    public function test_po_bledzie_odtwarza_nowe_zaznaczenia_notatki_i_swiadome_odznaczenia(): void
    {
        $this->actingAs($this->moderator());
        $a = Post::factory()->create();
        $b = Post::factory()->create();
        $this->pick('post', $a->id, 'Stara notatka');
        $people = [];
        for ($i = 0; $i < 7; $i++) {
            $person = $this->user('nadmiar'.$i);
            Post::factory()->create(['author_id' => $person->id]);
            $people[] = $person->id;
        }
        $form = $this->form();
        $form['wpisy'] = [$b->id];
        $form['osoby'] = $people;
        $form['notatki'][$b->id] = 'Nowa notatka <script>tekst</script>';
        $this->from(route('admin.daily-board'))->put(route('admin.daily-board'), $form)
            ->assertSessionHasErrors('osoby');
        $returned = $this->form();
        $this->assertSame([$b->id], $returned['wpisy'], 'Po błędzie wrócił zapis z bazy zamiast formularza.');
        $this->assertEqualsCanonicalizing($people, $returned['osoby']);
        $this->assertSame($form['notatki'][$b->id], $returned['notatki'][$b->id]);
        $this->assertDatabaseHas('daily_picks', ['subject_id' => $a->id, 'note' => 'Stara notatka']);

        unset($returned['wpisy']);
        $returned['notatki'][$a->id] = '';
        $this->from(route('admin.daily-board'))->put(route('admin.daily-board'), $returned)
            ->assertSessionHasErrors('osoby');
        $empty = $this->form();
        $this->assertEmpty($empty['wpisy'] ?? [], 'Niezaznaczona grupa wróciła do zapisu z bazy.');
        $this->assertSame('', $empty['notatki'][$a->id]);
    }

    public function test_nieprawidlowe_typy_danych_po_bledzie_nie_psuja_renderowania(): void
    {
        $this->actingAs($this->moderator());
        $post = Post::factory()->create();
        $this->from(route('admin.daily-board'))->put(route('admin.daily-board'), [
            '_board_form' => '1', 'osoby' => 'tekst', 'wpisy' => [['nie uuid']],
            'notatki' => [$post->id => ['zly typ']],
        ])->assertSessionHasErrors();
        $this->get(route('admin.daily-board'))->assertOk();
    }

    public function test_szukaj_osob_dociera_poza_40_i_przenosi_wybor_z_notatka_bez_zapisu(): void
    {
        $this->actingAs($this->moderator());
        $all = [];
        for ($i = 0; $i < 45; $i++) {
            $person = $this->user('kuchnia'.$i, ['display_name' => 'Kuchnia '.$i]);
            Post::factory()->create(['author_id' => $person->id, 'published_at' => now()->subMinutes($i)]);
            $all[] = $person;
        }
        $response = $this->get(route('admin.daily-board'))->assertOk();
        $doc = new DOMDocument;
        @$doc->loadHTML($response->getContent());
        $xpath = new DOMXPath($doc);
        $button = $xpath->query('//form//button[@name="przegladaj"]');
        $this->assertSame(1, $button->length, 'Brak drogi do dalszych kandydatów w formularzu.');
        $form = $this->readForm($response->getContent());
        $this->assertArrayHasKey('szukaj', $form);
        $a = $all[0];
        $b = $all[44];
        $form['osoby'] = [$a->id];
        $form['notatki'][$a->id] = 'Pierwsza notatka';
        $form['szukaj'] = $b->profile->username;
        $form['przegladaj'] = self::elementDom($button->item(0))->getAttribute('value');
        $browse = $this->from(route('admin.daily-board'))->put(route('admin.daily-board'), $form)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, DailyPick::count(), 'Przeglądanie zapisało niezatwierdzony wybór.');
        $next = $this->get($browse->headers->get('Location'))->assertOk();
        $this->assertTrue($next->viewData('osoby')->contains('id', $b->id), 'Wyszukiwarka pomija osobę spoza czterdziestki.');
        $next->assertSee('value="'.$b->id.'"', false);
        $form = $this->readForm($next->getContent());
        $this->assertSame([$a->id], $form['osoby']);
        $this->assertSame('Pierwsza notatka', $form['notatki'][$a->id]);
        $form['osoby'][] = $b->id;
        $this->put(route('admin.daily-board'), $form)->assertSessionHasNoErrors();
        $this->assertSame(2, DailyPick::count());
        $this->assertDatabaseHas('daily_picks', ['subject_id' => $a->id, 'note' => 'Pierwsza notatka']);
    }

    public function test_nie_mozna_wyroznic_niedostepnej_osoby_przez_podanie_uuid(): void
    {
        $this->actingAs($this->moderator());
        $person = $this->user();
        $person->ban();
        $this->put(route('admin.daily-board'), ['osoby' => [$person->id]])->assertSessionHasErrors('osoby');
        $this->assertSame(0, DailyPick::count());
    }

    public function test_komunikat_mowi_o_wyborze_a_audyt_liczy_unikalne_wyroznienia(): void
    {
        $this->actingAs($this->moderator());
        $post = Post::factory()->create();
        Post::factory()->count(7)->create();
        $this->from(route('admin.daily-board'))->put(route('admin.daily-board'), ['wpisy' => [$post->id, $post->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DailyPick::count());
        $this->get(route('admin.daily-board'))->assertOk()
            ->assertSee('Zapisaliśmy wyróżnienia. Pozostałe miejsca tablica uzupełni automatycznie.')
            ->assertDontSee('Na tablicy jest dziś');
        $metadata = AuditLogEntry::where('action', 'daily_board.updated')->first()->metadata;
        $this->assertSame(1, $metadata['wpisy']);
        $this->assertSame(2, $metadata['przeslane_wpisy']);
        $this->assertCount(6, app(DailyBoard::class)->forViewer(null)['posts']);
        $this->put(route('admin.daily-board'), [])->assertSessionHas('status', 'Wyczyszczone. Tablica dobierze treści sama.');
    }

    private function pick(string $type, string $id, string $note): void
    {
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => $type,
            'subject_id' => $id, 'position' => DailyPick::count(), 'curator_id' => auth()->id(), 'note' => $note]);
    }

    private function form(): array
    {
        return $this->readForm($this->get(route('admin.daily-board'))->assertOk()->getContent());
    }

    /** Wysyłamy rzeczywiste pola formularza, nie znane testowi UUID-y. */
    private function readForm(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $parts = [];
        foreach (self::elementyDom($xpath->query('//form[.//input[@name="_method" and @value="PUT"]]//input[@name]')) as $input) {
            if ($input->getAttribute('type') === 'checkbox' && ! $input->hasAttribute('checked')) {
                continue;
            }
            $parts[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }
        $this->assertNotEmpty($parts, 'Nie znaleziono pól formularza.');
        parse_str(implode('&', $parts), $data);

        return $data;
    }
}
