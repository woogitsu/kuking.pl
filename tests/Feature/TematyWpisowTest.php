<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Topic;
use Database\Seeders\TopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tematy wpisów — część pierwsza issue #31 (SOUL.md 4.7).
 *
 * PO CO TEMATY ISTNIEJĄ
 * Onboarding pytał o zainteresowania i zapisywał odpowiedź DO SESJI, gdzie
 * ginęła po zakończeniu kroku. Marnowaliśmy najcenniejsze dane, jakie mamy
 * przy cold starcie — bo padają w jedynym momencie, w którym człowiek chętnie
 * odpowiada na pytania o siebie.
 *
 * Docelowo temat ratuje feed osoby, która nikogo nie obserwuje: nowe konto
 * widzi dziś pustą stronę, a pusty ekran dla kogoś po sześćdziesiątce znaczy
 * „to nie jest dla mnie" — i taka osoba nie wraca.
 *
 * TA CZĘŚĆ dokłada tematy do wpisów i stronę tematu. Obserwowanie tematów
 * i feed przychodzą osobno — bez oznaczonych wpisów nie byłoby czego
 * obserwować, więc kolejność nie jest przypadkowa.
 */
class TematyWpisowTest extends TestCase
{
    use RefreshDatabase;

    private function temat(string $slug = 'zupy', array $nadpisz = []): Topic
    {
        // `is_active` celowo nie jest w `$fillable` (patrz Topic), więc
        // przechodzi bokiem — inaczej test „wycofany temat znika z wyboru"
        // tworzyłby po cichu temat aktywny i przechodził z niewłaściwego
        // powodu. Dokładnie to się tu wydarzyło.
        $aktywny = $nadpisz['is_active'] ?? null;
        unset($nadpisz['is_active']);

        $temat = Topic::create(array_merge([
            'slug' => $slug,
            'name' => 'Zupy',
            'description' => 'Od rosołu po krem.',
            'position' => 1,
        ], $nadpisz));

        if ($aktywny !== null) {
            $temat->is_active = $aktywny;
            $temat->save();
        }

        return $temat;
    }

    public function test_nowy_temat_od_razu_zna_swoj_identyfikator(): void
    {
        $temat = $this->temat();

        // WYGLĄDA NA TEST O NICZYM, ALE NIE JEST.
        //
        // Migracja daje kolumnie `DEFAULT gen_random_uuid()`, więc wiersz
        // w bazie dostaje identyfikator nawet wtedy, gdy model go nie zna.
        // Bez `HasUuids` `getKey()` oddaje wtedy `null`, a builder zamienia
        // `where('topic_id', null)` na `topic_id IS NULL` — zapytanie nie
        // wybucha, tylko odpowiada na inne pytanie. Pięć testów w tym pliku
        // przechodziło z tego powodu, zanim ktokolwiek zobaczył stronę.
        $this->assertNotNull($temat->getKey(), 'Temat po zapisie nie zna własnego id.');
        $this->assertSame($temat->getKey(), $temat->fresh()->getKey());
    }

    private function opublikuj(array $dane = []): TestResponse
    {
        return $this->post(route('posts.store'), array_merge([
            'body' => 'Rosół jak u babci.',
            'visibility' => 'public',
        ], $dane));
    }

    public function test_wpis_da_sie_oznaczyc_tematem(): void
    {
        $temat = $this->temat();

        $this->actingAs($this->user('basia'))
            ->opublikuj(['topic_id' => $temat->getKey()])
            ->assertRedirect();

        $this->assertSame($temat->getKey(), Post::firstOrFail()->topic_id);
    }

    public function test_wpis_bez_tematu_dalej_da_sie_opublikowac(): void
    {
        // Cel produktowy to poniżej 60 sekund od wejścia do opublikowania.
        // Temat, który zatrzymuje publikację, jest gorszy niż brak tematu.
        $this->actingAs($this->user('basia'))->opublikuj()->assertRedirect();

        $this->assertNull(Post::firstOrFail()->topic_id);
        $this->assertSame(1, Post::count());
    }

    public function test_podstawiony_temat_nie_blokuje_publikacji(): void
    {
        $wycofany = $this->temat('stary', ['is_active' => false, 'name' => 'Wycofany']);

        $this->actingAs($this->user('basia'))
            ->opublikuj(['topic_id' => $wycofany->getKey()])
            ->assertRedirect();

        // Temat wycofany przez redakcję NIE trafia na nowe wpisy — ale wpis
        // i tak się publikuje, bez tematu. Odmowa publikacji z powodu decyzji
        // redakcyjnej, o której autor nic nie wie, byłaby najgorszą możliwą
        // odpowiedzią: człowiek traci wpis przez coś, czego nie zrobił.
        $this->assertNull(Post::firstOrFail()->topic_id);
    }

    public function test_strona_tematu_pokazuje_wpisy_z_tego_tematu(): void
    {
        $temat = $this->temat();
        $inny = $this->temat('ciasta', ['name' => 'Ciasta']);
        $autor = $this->user('basia');

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'topic_id' => $temat->getKey(),
            'body' => 'Rosół na niedzielę',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'topic_id' => $inny->getKey(),
            'body' => 'Sernik na sobotę',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $this->get(route('topics.show', $temat))
            ->assertOk()
            ->assertSee('Rosół na niedzielę')
            ->assertDontSee('Sernik na sobotę');
    }

    public function test_strona_tematu_jest_otwarta_dla_gosci(): void
    {
        $temat = $this->temat();

        Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'topic_id' => $temat->getKey(),
            'body' => 'Rosół na niedzielę',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // Strona tematu to jedno z niewielu miejsc, w które ma sens trafić
        // z wyszukiwarki — dlatego celowo poza `auth`.
        $this->get(route('topics.show', $temat))->assertOk()->assertSee('Rosół na niedzielę');
    }

    public function test_temat_nie_omija_ustawien_prywatnosci(): void
    {
        $temat = $this->temat();
        $autor = $this->user('basia');
        $obcy = $this->user('obcy');

        foreach (['public' => 'Widoczny dla wszystkich',
            'followers' => 'Tylko dla obserwujących',
            'private' => 'Tylko dla mnie'] as $widocznosc => $tresc) {
            Post::factory()->create([
                'author_id' => $autor->getKey(),
                'topic_id' => $temat->getKey(),
                'body' => $tresc,
                'status' => Post::STATUS_PUBLISHED,
                'visibility' => $widocznosc,
                'published_at' => now(),
            ]);
        }

        // TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU.
        //
        // Strona tematu zbiera wpisy WIELU autorów naraz, więc nie da się
        // użyć pomocnika z profilu, który liczy widoczność dla jednego
        // właściciela. Skopiowanie tamtego filtra przepuściłoby wpisy
        // „tylko dla obserwujących" od osób, których widz nie obserwuje —
        // czyli temat byłby obejściem ustawień prywatności.
        $this->actingAs($obcy)
            ->get(route('topics.show', $temat))
            ->assertOk()
            ->assertSee('Widoczny dla wszystkich')
            ->assertDontSee('Tylko dla obserwujących')
            ->assertDontSee('Tylko dla mnie');
    }

    public function test_obserwujacy_widzi_wpis_dla_obserwujacych(): void
    {
        $temat = $this->temat();
        $autor = $this->user('basia');
        $obserwujacy = $this->user('marek');

        DB::table('follows')->insert([
            'follower_id' => $obserwujacy->getKey(),
            'followed_id' => $autor->getKey(),
            'created_at' => now(),
        ]);

        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'topic_id' => $temat->getKey(),
            'body' => 'Tylko dla obserwujących',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'followers',
            'published_at' => now(),
        ]);

        // Druga strona tej samej reguły. Bez tego testu „naprawa" polegająca
        // na pokazywaniu wyłącznie treści publicznych też by przechodziła —
        // i po cichu odcięłaby obserwujących od tego, co im się należy.
        $this->actingAs($obserwujacy)
            ->get(route('topics.show', $temat))
            ->assertOk()
            ->assertSee('Tylko dla obserwujących');
    }

    public function test_zablokowana_osoba_nie_wyplywa_przez_temat(): void
    {
        $temat = $this->temat();
        $nieprzyjemny = $this->user('nieprzyjemny');
        $basia = $this->user('basia');

        Post::factory()->create([
            'author_id' => $nieprzyjemny->getKey(),
            'topic_id' => $temat->getKey(),
            'body' => 'Wpis od zablokowanego',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        // Blokada, która działa „w większości miejsc", nie działa. Temat jest
        // dokładnie tym miejscem, w którym ktoś odcięty wypłynąłby z powrotem.
        $this->actingAs($basia)
            ->get(route('topics.show', $temat))
            ->assertOk()
            ->assertDontSee('Wpis od zablokowanego');
    }

    public function test_skasowanie_tematu_nie_kasuje_wpisow(): void
    {
        $temat = $this->temat();

        $wpis = Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'topic_id' => $temat->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $temat->delete();

        // `nullOnDelete`, nie `cascade`. Decyzja redakcyjna o wycofaniu tematu
        // NIE MOŻE skasować czyjegoś wpisu — treść człowieka jest ważniejsza
        // niż porządek w słowniku.
        $this->assertNotNull($wpis->fresh(), 'Skasowanie tematu zabrało ze sobą wpis.');
        $this->assertNull($wpis->fresh()->topic_id);
    }

    public function test_lista_tematow_do_wyboru_pomija_wycofane(): void
    {
        $this->temat('zupy');
        $this->temat('stary', ['is_active' => false, 'name' => 'Wycofany temat']);

        $html = $this->actingAs($this->user('basia'))
            ->get(route('posts.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Zupy', $html);
        $this->assertStringNotContainsString('Wycofany temat', $html);
    }

    public function test_seeder_daje_pelna_liste_tematow(): void
    {
        $this->seed(TopicSeeder::class);

        // Zamknięta lista około 30 pozycji (SOUL.md 4.7). Wolne tagi
        // rozsypałyby się na „zakwas", „na zakwasie", „chleb zakwas" —
        // po miesiącu nie byłoby czego obserwować.
        $this->assertGreaterThanOrEqual(25, Topic::count());

        // Ponowne uruchomienie nie dubluje — seeder chodzi także
        // na istniejącej bazie.
        $ile = Topic::count();
        $this->seed(TopicSeeder::class);
        $this->assertSame($ile, Topic::count());
    }
}
