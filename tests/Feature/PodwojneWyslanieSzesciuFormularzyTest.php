<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audyt podwójnego wysłania formularza, 12 września 2026 — SZEŚĆ FORMULARZY
 * W JEDNYM MIEJSCU.
 *
 * PO CO TEN PLIK, SKORO KAŻDY Z SZEŚCIU MA WŁASNE TESTY
 * Bo pytanie „ile wierszy powstaje przy dwóch identycznych żądaniach" jest
 * jedno i zadaje się je wszystkim sześciu naraz. Osobne pliki pilnują
 * MECHANIZMÓW (klucz wysłania, indeks częściowy, blokada z rewalidacją);
 * ten pilnuje WYNIKU widzianego przez człowieka i jest tabelą z raportu
 * zapisaną w kodzie. Gdy ktoś za pół roku doda siódmy formularz albo zdejmie
 * ochronę z któregoś z tych sześciu, ten plik jest jednym miejscem, w którym
 * to widać.
 *
 * ZMIERZONE PRZED POPRAWKĄ (liczba wierszy przy dwóch identycznych żądaniach):
 *
 *   komentarz .............. 2   ← usterka, naprawiona w `PublishComment`
 *   przepis ................ 2   ← usterka, naprawiona kluczem wysłania
 *   zgłoszenie moderacyjne . 1
 *   odwołanie .............. 1
 *   „Ugotowałem" ........... 1
 *   zapis do zeszytu ....... 1
 *
 * ZAPIS DO ZESZYTU NIE DOSTAŁ ŻADNEGO NOWEGO OGRANICZENIA — świadomie.
 * `collection_items` ma klucz główny na parze (zeszyt, przepis), więc drugie
 * kliknięcie nie ma jak utworzyć drugiego wiersza. Dokładanie tam czegokolwiek
 * byłoby kosztem przy każdej migracji i przy każdym imporcie, bez ani jednego
 * wiersza mniej w bazie.
 *
 * CZEGO TEN PLIK NIE DOWODZI (pułapka 6 z `docs/PULAPKI_TESTOW.md`)
 * Zachowania przy DWÓCH POŁĄCZENIACH. `RefreshDatabase` trzyma dane
 * w niezatwierdzonej transakcji, więc oba żądania idą jednym połączeniem.
 * To jest pomiar podwójnego KLIKNIĘCIA (dwa żądania po kolei, jedna
 * przeglądarka), a nie przeplotu dwóch procesów. Przeplot mierzy grupa
 * `dwa-polaczenia` (`./scripts/testy-dwa-polaczenia.sh`).
 */
class PodwojneWyslanieSzesciuFormularzyTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function kluczZFormularza(string $html): ?string
    {
        return preg_match('/name="klucz_wyslania" value="([^"]+)"/', $html, $trafienia) === 1
            ? $trafienia[1]
            : null;
    }

    public function test_komentarz_dwa_zadania_jeden_wiersz(): void
    {
        $osoba = $this->user('komentujacaaudyt');
        $wpis = $this->wpis($this->user('autoraudyt'));
        $tresc = ['body' => 'Wygląda pysznie!'];

        $pierwsze = $this->actingAs($osoba)->post(route('posts.comment', $wpis), $tresc);
        $drugie = $this->actingAs($osoba)->post(route('posts.comment', $wpis), $tresc);

        $this->assertSame(1, Comment::query()->count());
        $drugie->assertStatus(302);
        $drugie->assertSessionHasNoErrors();
        $drugie->assertSessionHas('status', 'Komentarz dodany.');
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_przepis_dwa_zadania_jeden_wiersz(): void
    {
        $osoba = $this->user('autorkaaudyt');

        $klucz = $this->kluczZFormularza(
            (string) $this->actingAs($osoba)->get(route('recipes.create'))->getContent(),
        );

        $tresc = [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'skladniki_tekst' => "kura\nmarchew",
            'przygotowanie_tekst' => 'Zagotuj wodę.',
            'klucz_wyslania' => $klucz,
        ];

        $pierwsze = $this->actingAs($osoba)->post(route('recipes.store'), $tresc);
        $drugie = $this->actingAs($osoba)->post(route('recipes.store'), $tresc);

        $this->assertSame(1, Recipe::query()->count());
        $drugie->assertStatus(302);
        $drugie->assertSessionHasNoErrors();
        $drugie->assertSessionHas(
            'status',
            'Ten przepis już zapisaliśmy — to jest on. Drugie kliknięcie nie założyło drugiego przepisu.',
        );
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_zgloszenie_moderacyjne_dwa_zadania_jeden_wiersz(): void
    {
        $osoba = $this->user('zglaszajacaaudyt');
        $wpis = $this->wpis($this->user('zglaszanyaudyt'));
        $adres = route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]);
        $tresc = ['reason' => 'spam', 'details' => 'To jest reklama.'];

        $pierwsze = $this->actingAs($osoba)->post($adres, $tresc);
        $drugie = $this->actingAs($osoba)->post($adres, $tresc);

        $this->assertSame(1, Report::query()->count());
        $drugie->assertStatus(302);
        $drugie->assertSessionHasNoErrors();
        $this->assertSame(
            $pierwsze->headers->get('Location'),
            $drugie->headers->get('Location'),
            'Drugie zgłoszenie odesłało pod inny numer sprawy niż pierwsze.',
        );
    }

    public function test_odwolanie_dwa_zadania_jeden_wiersz(): void
    {
        $moderator = $this->moderator();
        $ukarany = $this->user('ukaranyaudyt');

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $ukarany->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        $tresc = ['body' => 'To nie była reklama, tylko przepis mojej mamy.'];

        $this->actingAs($ukarany)->post(route('appeals.store', $decyzja), $tresc);
        $drugie = $this->actingAs($ukarany)->post(route('appeals.store', $decyzja), $tresc);

        $this->assertSame(1, Appeal::query()->count());
        $drugie->assertStatus(302);

        // Tu człowiek widzi BŁĄD PRZY POLU, nie potwierdzenie — i to jest
        // zamierzone: odwołanie jest pismem z terminem, więc milczące
        // „przyjęliśmy" po drugim kliknięciu byłoby gorsze niż zdanie
        // mówiące, że pismo już do nas trafiło.
        $drugie->assertSessionHasErrors('body');
    }

    public function test_ugotowalem_dwa_zadania_jeden_wiersz(): void
    {
        $autor = $this->user('autorprzepisuaudyt');
        $kucharz = $this->user('kucharzaudyt');
        $przepis = $this->przepis($autor);

        $klucz = $this->kluczZFormularza(
            (string) $this->actingAs($kucharz)->get(route('cooked.create', $przepis->slug))->getContent(),
        );

        $tresc = ['note' => 'Wyszło pięknie.', 'klucz_wyslania' => $klucz];

        $pierwsze = $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), $tresc);
        $drugie = $this->actingAs($kucharz)->post(route('cooked.store', $przepis->slug), $tresc);

        $this->assertSame(1, CookedEvent::query()->count());
        $drugie->assertStatus(302);
        $drugie->assertSessionHasNoErrors();
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_zapis_do_zeszytu_dwa_zadania_jeden_wiersz(): void
    {
        // Bez żadnego nowego ograniczenia — pilnuje tego klucz główny
        // `collection_items (collection_id, recipe_id)`, który istnieje od
        // pierwszej migracji zeszytu.
        $osoba = $this->user('zapisujacaaudyt');
        $przepis = $this->przepis($this->user('autorzeszytaudyt'));

        $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));
        $drugie = $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));

        $this->assertSame(1, DB::table('collection_items')->count());
        $drugie->assertStatus(302);
        $drugie->assertSessionHasNoErrors();
    }
}
