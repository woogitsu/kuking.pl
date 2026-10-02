<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\MojeRozmowy;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Moje rozmowy” w „Moje” (#2432): prywatna lista wątków, w których osoba
 * pisze. Pilnuje: jeden wpis na wątek (ostatnia własna wypowiedź), adres z
 * numerem strony i kotwicą, brak treści niedostępnych dla osoby (Policy celu,
 * blokada, moderacja, usunięcie), porcje po kursorze i brak nowych skutków.
 */
class MojeRozmowyTest extends TestCase
{
    use RefreshDatabase;

    private function komentarz(User $autor, Post|Recipe|CookedEvent $cel, array $atrybuty = []): Comment
    {
        $klucz = match (true) {
            $cel instanceof Post => 'post_id',
            $cel instanceof Recipe => 'recipe_id',
            default => 'cooked_event_id',
        };

        $cele = ['post_id' => null, 'recipe_id' => null, 'cooked_event_id' => null];
        $cele[$klucz] = $cel->getKey();

        return Comment::factory()->create(['author_id' => $autor->getKey()] + $cele + $atrybuty);
    }

    private function wpis(array $atrybuty = []): Post
    {
        return Post::factory()->create([
            'author_id' => $this->user()->getKey(),
        ] + $atrybuty);
    }

    private function lista(User $osoba): TestResponse
    {
        return $this->actingAs($osoba)->get(route('collections.own-conversations'));
    }

    public function test_gosc_trafia_do_logowania(): void
    {
        $this->get(route('collections.own-conversations'))->assertRedirect(route('login'));
    }

    public function test_komentarz_bez_odpowiedzi_jest_na_liscie_i_odnosnik_prowadzi_do_niego(): void
    {
        $ja = $this->user('pytajaca');
        $wpis = $this->wpis(['body' => 'Chleb na zakwasie']);
        $komentarz = $this->komentarz($ja, $wpis, ['body' => 'Jaka temperatura pieczenia?']);

        $odpowiedz = $this->lista($ja)->assertOk();

        $odpowiedz->assertSee('Jaka temperatura pieczenia?');
        $odpowiedz->assertSee('Twój komentarz');
        $odpowiedz->assertSee($wpis->url().'#komentarz-'.$komentarz->getKey(), false);

        // Odnośnik naprawdę otwiera rozmowę z tą wypowiedzią.
        $this->actingAs($ja)->get($wpis->url())->assertOk()->assertSee('Jaka temperatura pieczenia?');
    }

    public function test_wiele_wlasnych_wypowiedzi_w_jednym_watku_to_jedna_pozycja_pod_ostatnia(): void
    {
        $ja = $this->user('gadula');
        $inna = $this->user('rozmowczyni');
        $wpis = $this->wpis();

        $korzen = $this->komentarz($ja, $wpis, ['body' => 'Pierwsze pytanie', 'created_at' => now()->subHours(3)]);
        $this->komentarz($inna, $wpis, ['body' => 'Cudza odpowiedź', 'parent_id' => $korzen->getKey(), 'created_at' => now()->subHours(2)]);
        $ostatnia = $this->komentarz($ja, $wpis, ['body' => 'Dziękuję, wyszło', 'parent_id' => $korzen->getKey(), 'created_at' => now()->subHour()]);

        $pozycje = $this->lista($ja)->assertOk()->viewData('rozmowy')->items();

        $this->assertCount(1, $pozycje);
        $this->assertSame((string) $ostatnia->getKey(), $pozycje[0]->idKomentarza);
        $this->assertTrue($pozycje[0]->jestOdpowiedzia);
        $this->lista($ja)->assertSee('Twoja odpowiedź')->assertSee('Dziękuję, wyszło')->assertDontSee('Pierwsze pytanie')->assertDontSee('Cudza odpowiedź');
    }

    public function test_rozne_korzenie_na_tej_samej_tresci_to_osobne_pozycje_od_najnowszej_z_remisem_po_id(): void
    {
        $ja = $this->user('wielokrotna');
        $wpis = $this->wpis();
        $t = now()->subDay()->startOfSecond();

        $a = $this->komentarz($ja, $wpis, ['body' => 'Stary', 'created_at' => now()->subDays(3)]);
        $b = $this->komentarz($ja, $wpis, ['body' => 'Remis jeden', 'created_at' => $t]);
        $c = $this->komentarz($ja, $wpis, ['body' => 'Remis dwa', 'created_at' => $t]);

        $id = array_map(fn ($p) => $p->idKomentarza, $this->lista($ja)->viewData('rozmowy')->items());

        $remisy = collect([$b, $c])->sortByDesc(fn (Comment $k) => (string) $k->getKey())->map(fn (Comment $k) => (string) $k->getKey())->values()->all();
        $this->assertSame([...$remisy, (string) $a->getKey()], $id);
    }

    public function test_cudze_wypowiedzi_i_cudza_lista_nie_wchodza_do_mojej(): void
    {
        $ja = $this->user('ja_rozmowy');
        $obca = $this->user('obca_rozmowy');
        $wpis = $this->wpis();
        $this->komentarz($ja, $wpis, ['body' => 'Moja wypowiedź']);
        $this->komentarz($obca, $wpis, ['body' => 'Wypowiedź obcej']);

        $this->lista($ja)->assertSee('Moja wypowiedź')->assertDontSee('Wypowiedź obcej');
        $this->actingAs($ja)->get(route('collections.own-conversations', ['user' => $obca->getKey(), 'author_id' => $obca->getKey()]))
            ->assertOk()->assertDontSee('Wypowiedź obcej');
    }

    public function test_usuniete_ukryte_i_zastapione_sladem_wypowiedzi_nie_obiecuja_rozmowy(): void
    {
        $ja = $this->user('porzadkujaca');
        $wpis = $this->wpis();

        $this->komentarz($ja, $wpis, ['body' => 'Usunięta miękko'])->delete();
        $this->komentarz($ja, $wpis, ['body' => 'Ukryta przez moderację', 'status' => Comment::STATUS_HIDDEN]);
        $this->komentarz($ja, $wpis, ['body' => Comment::DELETED_PLACEHOLDER, 'body_removed_at' => now()]);
        $this->komentarz($ja, $wpis, ['body' => 'Zostaje']);

        $odpowiedz = $this->lista($ja)->assertOk();

        $this->assertCount(1, $odpowiedz->viewData('rozmowy')->items());
        $odpowiedz->assertSee('Zostaje')->assertDontSee('Usunięta miękko')->assertDontSee('Ukryta przez moderację')->assertDontSee(Comment::DELETED_PLACEHOLDER);
    }

    public function test_ukryta_ostatnia_wypowiedz_ustepuje_wczesniejszej_widocznej_w_tym_samym_watku(): void
    {
        $ja = $this->user('wycofana');
        $wpis = $this->wpis();
        $korzen = $this->komentarz($ja, $wpis, ['body' => 'Widoczny korzeń', 'created_at' => now()->subHours(2)]);
        $this->komentarz($ja, $wpis, ['body' => 'Ukryta odpowiedź', 'parent_id' => $korzen->getKey(), 'status' => Comment::STATUS_HIDDEN, 'created_at' => now()->subHour()]);

        $pozycje = $this->lista($ja)->viewData('rozmowy')->items();

        $this->assertCount(1, $pozycje);
        $this->assertSame((string) $korzen->getKey(), $pozycje[0]->idKomentarza);
    }

    public function test_watek_pod_niewidocznym_korzeniem_znika_przy_blokadzie_w_obie_strony_i_zbanowanym_autorze_korzenia(): void
    {
        $ja = $this->user('blokujaca');
        $wpis = $this->wpis(['body' => 'Treść publiczna']);

        $blokowany = $this->user('blokowany_autor');
        $blokujacy = $this->user('blokujacy_mnie');
        $zbanowany = $this->user('zbanowany_autor');

        foreach (['blokowanym' => $blokowany, 'blokujacym' => $blokujacy, 'zbanowanym' => $zbanowany] as $etykieta => $autorKorzenia) {
            $korzen = $this->komentarz($autorKorzenia, $wpis, ['body' => 'Korzeń '.$etykieta]);
            $this->komentarz($ja, $wpis, ['body' => 'Moja odp. pod '.$etykieta, 'parent_id' => $korzen->getKey()]);
        }
        $this->komentarz($ja, $wpis, ['body' => 'Moja wolna wypowiedź']);

        // Kontrola dodatnia: przed blokadami wszystkie cztery wątki są na liście.
        $this->assertCount(4, $this->lista($ja)->viewData('rozmowy')->items());

        DB::table('blocks')->insert(['blocker_id' => $ja->getKey(), 'blocked_id' => $blokowany->getKey(), 'created_at' => now()]);
        DB::table('blocks')->insert(['blocker_id' => $blokujacy->getKey(), 'blocked_id' => $ja->getKey(), 'created_at' => now()]);
        $zbanowany->ban();

        $odpowiedz = $this->lista($ja);
        $this->assertCount(1, $odpowiedz->viewData('rozmowy')->items());
        $odpowiedz->assertSee('Moja wolna wypowiedź')->assertDontSee('Moja odp. pod');
    }

    public function test_tresc_niedostepna_dla_osoby_znika_bez_tytulu_fragmentu_i_adresu(): void
    {
        config(['kuking.questions.enabled' => true]);
        $ja = $this->user('straconydostep');
        $autor = $this->user('autor_tresci');

        $prywatny = $this->wpis(['author_id' => $autor->getKey(), 'body' => 'Tajny wpis autora']);
        $this->komentarz($ja, $prywatny, ['body' => 'Fragment pod prywatnym']);

        $pytanie = Post::factory()->question()->create(['author_id' => $autor->getKey(), 'title' => 'Tytuł ukrytego pytania']);
        $this->komentarz($ja, $pytanie, ['body' => 'Fragment pod pytaniem']);

        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public', 'title' => 'Tytuł zawężonego przepisu']);
        $this->komentarz($ja, $przepis, ['body' => 'Fragment pod przepisem']);

        $wpis = $this->wpis(['body' => 'Zostaje publiczny']);
        $this->komentarz($ja, $wpis, ['body' => 'Fragment pod publicznym']);

        // Kontrola dodatnia: przed zmianą widoczności cztery pozycje i tytuły.
        $przed = $this->lista($ja)->assertOk();
        $this->assertCount(4, $przed->viewData('rozmowy')->items());
        $przed->assertSee('Pytanie: Tytuł ukrytego pytania')->assertSee('Przepis: Tytuł zawężonego przepisu');

        $prywatny->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();
        $pytanie->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();
        $przepis->forceFill(['visibility' => 'private'])->save();

        $po = $this->lista($ja)->assertOk();
        $this->assertCount(1, $po->viewData('rozmowy')->items());
        $po->assertSee('Fragment pod publicznym')
            ->assertDontSee('Fragment pod prywatnym')
            ->assertDontSee('Fragment pod pytaniem')
            ->assertDontSee('Fragment pod przepisem')
            ->assertDontSee('Tytuł ukrytego pytania')
            ->assertDontSee('Tytuł zawężonego przepisu');

        // Wyłączony dział pytań zamyka też pytanie publiczne.
        $pytanie->forceFill(['visibility' => Post::VISIBILITY_PUBLIC])->save();
        $this->assertCount(2, $this->lista($ja)->viewData('rozmowy')->items());
        config(['kuking.questions.enabled' => false]);
        $this->assertCount(1, $this->lista($ja)->viewData('rozmowy')->items());
    }

    public function test_utrata_dostepu_miedzy_lista_a_kliknieciem_zamyka_rozmowe(): void
    {
        $ja = $this->user('klikajaca');
        $autor = $this->user('autor_zmienia');
        $wpis = $this->wpis(['author_id' => $autor->getKey()]);
        $this->komentarz($ja, $wpis, ['body' => 'Wrócę tu jutro']);

        $adres = $this->lista($ja)->viewData('rozmowy')->items()[0]->adres;
        $this->actingAs($ja)->get($adres)->assertOk();

        $wpis->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();

        $this->actingAs($ja)->get($adres)->assertForbidden();
    }

    public function test_wykonanie_pokazuje_kucharza_a_tytul_przepisu_tylko_gdy_osoba_moze_go_zobaczyc(): void
    {
        $ja = $this->user('komentujaca_wykonanie');
        $autorPrzepisu = $this->user('autor_przepisu_wyk');
        $kucharz = $this->user('kucharz_wyk');
        $przepis = Recipe::factory()->create(['author_id' => $autorPrzepisu->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public', 'title' => 'Zupa tytułowa']);
        $wykonanie = CookedEvent::factory()->create(['user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey()]);
        $this->komentarz($ja, $wykonanie, ['body' => 'Pięknie wyszło']);

        $this->lista($ja)->assertOk()->assertSee('Ugotowane przez')->assertSee('Zupa tytułowa')->assertSee('Pięknie wyszło');

        // Blokada z autorem przepisu: wykonanie jest zamknięte, więc rozmowa znika razem z tytułem.
        DB::table('blocks')->insert(['blocker_id' => $ja->getKey(), 'blocked_id' => $autorPrzepisu->getKey(), 'created_at' => now()]);

        $this->lista($ja)->assertOk()->assertDontSee('Zupa tytułowa')->assertDontSee('Pięknie wyszło');
    }

    public function test_adres_uwzglednia_dalsza_strone_korzeni_i_porcje_odpowiedzi(): void
    {
        config(['kuking.comments.page_size' => 2, 'kuking.comments.replies_per_thread' => 2]);
        $ja = $this->user('daleko');
        $inni = $this->user('tlum');
        $wpis = $this->wpis();

        $stare = [];
        for ($i = 0; $i < 4; $i++) {
            $stare[] = $this->komentarz($inni, $wpis, ['body' => 'Cudzy korzeń '.$i, 'created_at' => now()->subDays(10 - $i)]);
        }
        $moj = $this->komentarz($ja, $wpis, ['body' => 'Mój korzeń na trzeciej stronie', 'created_at' => now()->subDays(2)]);

        $adres = $this->lista($ja)->viewData('rozmowy')->items()[0]->adres;
        $this->assertStringContainsString('komentarze=3', $adres);
        $this->assertStringEndsWith('#komentarz-'.$moj->getKey(), $adres);
        $this->actingAs($ja)->get($adres)->assertOk()->assertSee('Mój korzeń na trzeciej stronie');

        // Odpowiedź w dalszej porcji odpowiedzi tego samego wątku.
        $korzen = $stare[0];
        foreach ([1, 2, 3] as $i) {
            $this->komentarz($inni, $wpis, ['body' => 'Cudza odp. '.$i, 'parent_id' => $korzen->getKey(), 'created_at' => now()->subDays(9)->addMinutes($i)]);
        }
        $moja = $this->komentarz($ja, $wpis, ['body' => 'Moja dalsza odpowiedź', 'parent_id' => $korzen->getKey(), 'created_at' => now()->subDays(9)->addMinutes(10)]);

        $pozycje = $this->lista($ja)->viewData('rozmowy')->items();
        $odp = collect($pozycje)->first(fn ($p) => $p->idKomentarza === (string) $moja->getKey());
        $this->assertNotNull($odp);
        $this->assertStringContainsString('watek='.$korzen->getKey(), $odp->adres);
        $this->assertStringContainsString('odpowiedzi=2', $odp->adres);
        $this->actingAs($ja)->get($odp->adres)->assertOk()->assertSee('Moja dalsza odpowiedź');
    }

    public function test_lista_idzie_porcjami_po_kursorze_bez_powtorzen_i_zly_kursor_to_pierwsza_porcja(): void
    {
        $ja = $this->user('plodna');
        for ($i = 0; $i < MojeRozmowy::NA_STRONE + 5; $i++) {
            $this->komentarz($ja, $this->wpis(), ['body' => 'Rozmowa '.$i, 'created_at' => now()->subMinutes($i)]);
        }

        $pierwsza = $this->lista($ja)->assertOk();
        $strona1 = $pierwsza->viewData('rozmowy');
        $this->assertCount(MojeRozmowy::NA_STRONE, $strona1->items());
        $this->assertTrue($strona1->hasMorePages());
        $pierwsza->assertSee('Następna strona rozmów');

        $druga = $this->actingAs($ja)->get($strona1->nextPageUrl())->assertOk()->viewData('rozmowy');
        $this->assertCount(5, $druga->items());
        $this->assertFalse($druga->hasMorePages());

        $wszystkie = array_merge(
            array_map(fn ($p) => $p->idKomentarza, $strona1->items()),
            array_map(fn ($p) => $p->idKomentarza, $druga->items()),
        );
        $this->assertCount(MojeRozmowy::NA_STRONE + 5, array_unique($wszystkie));

        $zly = $this->actingAs($ja)->get(route('collections.own-conversations', ['po' => '; drop table comments']))->assertOk()->viewData('rozmowy');
        $this->assertCount(MojeRozmowy::NA_STRONE, $zly->items());
    }

    public function test_porcja_jest_pelna_mimo_odrzuconych_przez_policy_wierszy(): void
    {
        $ja = $this->user('przebierajaca');
        $autor = $this->user('autor_prywatnych');

        // 45 najnowszych rozmów jest pod treścią prywatną cudzego autora (sprawdzenie Policy), 3 starsze są dostępne.
        for ($i = 0; $i < 45; $i++) {
            $prywatny = $this->wpis(['author_id' => $autor->getKey(), 'visibility' => Post::VISIBILITY_PRIVATE]);
            $this->komentarz($ja, $prywatny, ['body' => 'Zamknięta '.$i, 'created_at' => now()->subMinutes($i)]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->komentarz($ja, $this->wpis(), ['body' => 'Dostępna '.$i, 'created_at' => now()->subDays(5)->subMinutes($i)]);
        }

        $strona = $this->lista($ja)->assertOk()->viewData('rozmowy');

        $this->assertCount(3, $strona->items());
        $this->assertFalse($strona->hasMorePages());
    }

    public function test_liczba_zapytan_rosnie_co_najwyzej_o_jedno_na_rozmowe(): void
    {
        $ja = $this->user('zapytania');
        $inna = $this->user('autor_publiczny');
        $przepis = Recipe::factory()->create(['author_id' => $inna->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $dodaj = function (int $ile) use ($ja, $inna, $przepis): void {
            for ($i = 0; $i < $ile; $i++) {
                $this->komentarz($ja, $this->wpis(['author_id' => $inna->getKey()]));
                $this->komentarz($ja, $przepis);
            }
        };

        $zlicz = function () use ($ja): int {
            $this->actingAs($ja);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('collections.own-conversations'))->assertOk();
            $ile = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $ile;
        };

        $dodaj(2);
        $malo = $zlicz();
        $dodaj(6);
        $duzo = $zlicz();

        // Treści i konteksty idą po jednym zapytaniu na rodzaj (leniwe ładowanie
        // rzuca wyjątek w testach). Policy `view` pyta bazę o blokadę raz na
        // treść — to jedyny wzrost: co najwyżej jedno zapytanie na pozycję.
        $dodatkowe = 12;
        $this->assertLessThanOrEqual($malo + $dodatkowe, $duzo, 'Więcej niż jedno zapytanie na dodaną rozmowę.');
    }

    public function test_samo_ogladanie_listy_nie_tworzy_powiadomien_ani_zmian_w_komentarzach(): void
    {
        $ja = $this->user('cicha');
        $this->komentarz($ja, $this->wpis(), ['body' => 'Bez skutków']);
        $powiadomien = Notification::query()->count();
        $komentarze = Comment::query()->count();

        $this->lista($ja)->assertOk();
        $this->lista($ja)->assertOk();

        $this->assertSame($powiadomien, Notification::query()->count());
        $this->assertSame($komentarze, Comment::query()->count());
    }

    public function test_pusty_stan_mowi_co_zrobic_a_zeszyt_ma_odnosnik(): void
    {
        $ja = $this->user('pusta_lista');

        $this->lista($ja)->assertOk()->assertSee('Nie ma tu jeszcze żadnej rozmowy');
        $this->actingAs($ja)->get(route('collections.index'))->assertOk()
            ->assertSee(route('collections.own-conversations'), false)->assertSee('Moje rozmowy');
    }
}
