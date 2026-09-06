<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Grupa B z raportu: rzeczy, które dziś nie bolą, bo nie ma jeszcze ludzi.
 *
 * Przy stu kontach żadna z nich nie jest odczuwalna. Przy dwóch tysiącach
 * obserwujących u gospodarza i przy dwustu przepisach na pierogi każda
 * wygląda jak „serwis czasem źle działa" — i pojawi się dokładnie wtedy,
 * gdy zaczniemy rosnąć.
 */
class ListyIWyszukiwarkaTest extends TestCase
{
    use RefreshDatabase;

    private function obserwuj(User $kto, User $kogo): void
    {
        DB::table('follows')->insert([
            'follower_id' => $kto->getKey(),
            'followed_id' => $kogo->getKey(),
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // B6 — wielkość liter w adresie
    // ---------------------------------------------------------------

    public function test_listy_obserwujacych_dzialaja_przy_innej_wielkosci_liter(): void
    {
        $halina = $this->user('halina');

        // Basia dostaje SMS-a z linkiem. Klawiatura telefonu podniosła
        // pierwszą literę. Profil się otwierał (audyt A25 naprawił to
        // w JEDNYM miejscu z trzech), a „Obserwujący" dawało 404.
        $this->get('/@Halina')->assertOk();
        $this->get('/@Halina/obserwujacy')->assertOk();
        $this->get('/@Halina/obserwowani')->assertOk();
    }

    public function test_nieistniejacy_profil_dalej_daje_404(): void
    {
        // Druga strona reguły: „naprawa" polegająca na przepuszczaniu
        // wszystkiego też by przeszła test wyżej.
        $this->get('/@nie-ma-takiego-konta/obserwujacy')->assertNotFound();
    }

    // ---------------------------------------------------------------
    // B3 — blokady i liczniki na listach
    // ---------------------------------------------------------------

    public function test_zablokowana_osoba_znika_z_listy_i_z_licznika(): void
    {
        $gospodarz = $this->user('gospodarz');
        $basia = $this->user('basia');
        $nieprzyjemny = $this->user('nieprzyjemny');

        $this->obserwuj($basia, $gospodarz);
        $this->obserwuj($nieprzyjemny, $gospodarz);

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        $odpowiedz = $this->actingAs($basia)
            ->get(route('social.followers', ['username' => 'gospodarz']))
            ->assertOk();

        $lista = $odpowiedz->viewData('people');

        $this->assertSame(1, $lista->count());

        // TO JEST WŁAŚCIWY TEST TEJ NAPRAWY.
        //
        // Filtr działał wcześniej PO paginacji, w PHP. Lista wyglądała
        // poprawnie, ale `total()` liczył także osoby odfiltrowane: strona
        // pokazywała siedemnaście osób i mówiła, że jest ich dwadzieścia,
        // a ostatnia strona potrafiła wyjść pusta. Licznik, który nie zgadza
        // się z listą, wygląda jak zepsuty serwis.
        $this->assertSame(1, $lista->total(), 'Licznik liczy osoby, których na liście nie ma.');
    }

    public function test_lista_obserwujacych_nie_rosnie_liczba_zapytan_z_liczba_osob(): void
    {
        $gospodarz = $this->user('gospodarz');
        $basia = $this->user('basia');

        foreach (range(1, 12) as $numer) {
            $this->obserwuj($this->user('ktos'.$numer), $gospodarz);
        }

        $this->actingAs($basia);

        DB::enableQueryLog();
        $this->get(route('social.followers', ['username' => 'gospodarz']))->assertOk();
        $ile = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Wcześniej każda osoba na liście kosztowała dwa dodatkowe
        // `SELECT EXISTS`: jeden na blokadę, drugi na „czy widz ją obserwuje".
        // Dwanaście osób to były dwadzieścia cztery zapytania ponad resztę.
        //
        // Próg jest celowo luźny (liczba zapytań na stronę zmienia się przy
        // każdej zmianie w layoucie), ale rośnięcie LINIOWE z liczbą osób
        // złapie i tak.
        $this->assertLessThan(
            20,
            $ile,
            "Lista obserwujących wykonała {$ile} zapytań przy dwunastu osobach — ".
            'to wygląda na zapytanie na wiersz.',
        );
    }

    // ---------------------------------------------------------------
    // B5 — „Znaleziono 20 przepisów", gdy jest ich dwieście
    // ---------------------------------------------------------------

    public function test_wyszukiwarka_nie_klamie_o_liczbie_wynikow(): void
    {
        $autor = $this->user('autor');

        foreach (range(1, 25) as $numer) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => "Pierogi numer {$numer}",
                'status' => Recipe::STATUS_PUBLISHED,
                'visibility' => 'public',
                'published_at' => now(),
            ]);
        }

        $odpowiedz = $this->get(route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy']))->assertOk();

        // Ekran mówił „Znaleziono 20 przepisów", licząc POBRANE, a nie
        // ZNALEZIONE. Przy dwustu dopasowaniach to jest zdanie nieprawdziwe,
        // i to takie, na podstawie którego człowiek decyduje: „nie ma tego,
        // czego szukam, dodam własny".
        $odpowiedz->assertSee('Jest ich więcej', escape: false)
            ->assertDontSee('Znaleziono 20 przepisów', escape: false)
            ->assertSee('Pokaż więcej przepisów', escape: false);
    }

    public function test_pokaz_wiecej_dowozi_dalsze_wyniki_bez_javascriptu(): void
    {
        $autor = $this->user('autor');

        foreach (range(1, 25) as $numer) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => "Pierogi numer {$numer}",
                'status' => Recipe::STATUS_PUBLISHED,
                'visibility' => 'public',
                'published_at' => now(),
            ]);
        }

        $odpowiedz = $this->get(route('search', [
            'q' => 'pierogi', 'sekcja' => 'przepisy', 'ile' => 40,
        ]))->assertOk();

        $this->assertSame(25, $odpowiedz->viewData('recipes')->count());
        $odpowiedz->assertDontSee('Jest ich więcej', escape: false);
    }

    public function test_ile_z_adresu_nie_da_sie_uzyc_do_obciazenia_bazy(): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Pierogi ruskie',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // Bez górnej granicy ktoś wpisze `?ile=1000000` i zamieni
        // wyszukiwarkę w narzędzie do obciążania bazy.
        $this->get(route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy', 'ile' => 1000000]))
            ->assertOk();

        $this->get(route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy', 'ile' => -5]))
            ->assertOk();
    }

    public function test_liczebnik_odmienia_sie_po_polsku(): void
    {
        $autor = $this->user('autor');

        foreach (range(1, 3) as $numer) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => "Pierogi numer {$numer}",
                'status' => Recipe::STATUS_PUBLISHED,
                'visibility' => 'public',
                'published_at' => now(),
            ]);
        }

        // Odmiana była dwustanowa, więc dla trzech wyników ekran pisał
        // „Znaleziono 3 przepisów".
        $this->get(route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy']))
            ->assertOk()
            ->assertSee('Znaleziono 3 przepisy', escape: false)
            ->assertDontSee('Znaleziono 3 przepisów', escape: false);
    }
}
