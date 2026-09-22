<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blokada działa też przy ODPOWIADANIU na komentarz (audyt W7-06, SEC-06).
 *
 * Blokada obowiązywała dotąd na trzech powierzchniach: renderowanie
 * (`Comment::scopeWidoczneDla`), powiadomienia (`NotifyUser`) i tworzenie
 * komentarza — ale to ostatnie tylko wobec WŁAŚCICIELA treści. Wobec autora
 * komentarza, pod którym się odpowiada, nie działało nigdzie.
 *
 * SCENARIUSZ
 * A i B są w relacji blokady, ale oboje mogą komentować u C. B pisze
 * komentarz X. A go nie widzi, bo filtr go ukrywa — ale znając UUID
 * komentarza X (od wspólnego znajomego, ze zrzutu ekranu, sprzed blokady)
 * A mógł wysłać `parent_id = X` i utworzyć odpowiedź strukturalnie podpiętą
 * pod wątek B.
 *
 * Powiadomienie do B i tak nie szło, więc nękania z tego nie było — ale zapis
 * przekraczał granicę, o której interfejs mówi, że jej nie da się przekroczyć,
 * a wątek B rósł o cudzą odpowiedź. To jest ten sam wzorzec, który wraca
 * w audytach: reguła żyje w jednej warstwie, a druga ją omija.
 */
class BlokadaObowiazujeTakzePrzyOdpowiadaniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zablokowany_nie_podepnie_sie_pod_watek_osoby_ktora_go_blokuje(): void
    {
        $c = $this->user('gospodarz');
        $b = $this->user('zablokowanyb');
        $a = $this->user('blokujacya');

        $a->blocking()->attach($b->getKey());

        $wpis = Post::factory()->for($c, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        $komentarzB = Comment::create([
            'author_id' => $b->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Robiłam podobnie, tylko z majerankiem.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $liczbaKomentarzyPrzed = Comment::count();

        // A zna UUID komentarza B, choć go nie widzi.
        $odpowiedz = $this->actingAs($a)
            ->post(route('posts.comment', $wpis), [
                'body' => 'A JA UWAZAM INACZEJ - TEKST NIE MA PRAWA WYJSC.',
                'parent_id' => $komentarzB->getKey(),
            ]);

        $this->assertSame(
            0,
            Comment::where('parent_id', $komentarzB->getKey())->count(),
            'Powstała odpowiedź pod komentarzem osoby w relacji blokady.',
        );

        // ISSUE #761 — wzmocnienie tego testu: samo `count(parent_id=X) == 0`
        // przechodziło także wtedy, gdy tekst po cichu wylądował jako NOWY
        // KOMENTARZ GŁÓWNY (parent_id=NULL) zamiast zostać odrzucony. To
        // dokładnie ta luka pokrycia, którą wskazano w #761: trzeba sprawdzić
        // odmowę i brak przyrostu wierszy, nie tylko relację do rodzica.
        $odpowiedz->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('comments', ['body' => 'A JA UWAZAM INACZEJ - TEKST NIE MA PRAWA WYJSC.']);
        $this->assertSame($liczbaKomentarzyPrzed, Comment::count(), 'Nie powstał żaden nowy wiersz — ani odpowiedź, ani cichy komentarz główny.');
    }

    public function test_komentarz_bez_pola_parent_id_nie_wywala_serwisu(): void
    {
        // Nie audyt, tylko awaria znaleziona przy okazji: `validate()` NIE
        // zwraca klucza, którego w żądaniu nie było, a `parent_id` jest
        // `nullable`. Nasz formularz zawsze wysyła puste pole, więc z ekranu
        // to nie wychodziło — ale każde żądanie spoza formularza kończyło się
        // „Undefined array key \"parent_id\"", czyli błędem 500 zamiast
        // komentarza.
        $autor = $this->user('autor');
        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user('czytelnik'))
            ->post(route('posts.comment', $wpis), ['body' => 'Bez pola parent_id.'])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', ['body' => 'Bez pola parent_id.']);
    }

    public function test_odpowiedz_pod_komentarzem_z_innej_strony_nie_powstaje(): void
    {
        // Rodzic musi stać pod TĄ treścią. Odpowiedź podpięta pod komentarz
        // z innego wpisu rozjeżdża wątek w obie strony: u siebie jej nie
        // widać, a w cudzym wątku wisi.
        $autor = $this->user('autor');

        $pierwszy = Post::factory()->for($autor, 'author')->create([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHour(),
        ]);
        $drugi = Post::factory()->for($autor, 'author')->create([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHour(),
        ]);

        $obcyKomentarz = Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $pierwszy->getKey(),
            'body' => 'Komentarz spod pierwszego wpisu.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $liczbaKomentarzyPrzed = Comment::count();

        $odpowiedz = $this->actingAs($this->user('czytelnik'))
            ->post(route('posts.comment', $drugi), [
                'body' => 'PODPINAM SIE NIE TAM GDZIE TRZEBA - TEKST NIE MA PRAWA WYJSC.',
                'parent_id' => $obcyKomentarz->getKey(),
            ]);

        $this->assertSame(0, Comment::where('parent_id', $obcyKomentarz->getKey())->count());

        // ISSUE #761 — to samo wzmocnienie co wyżej: dowieść odmowy i braku
        // przyrostu wierszy, nie tylko braku relacji do konkretnego rodzica.
        $odpowiedz->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('comments', ['body' => 'PODPINAM SIE NIE TAM GDZIE TRZEBA - TEKST NIE MA PRAWA WYJSC.']);
        $this->assertSame($liczbaKomentarzyPrzed, Comment::count());
    }

    #[DataProvider('kierunkiBlokady')]
    public function test_http_nie_pozwala_obejsc_blokady_autora_korzenia_przez_odpowiedz(bool $piszacyBlokujeKorzen): void
    {
        $sufiks = $this->kierunek($piszacyBlokujeKorzen);
        $wlasciciel = $this->user('wl_'.$sufiks);
        $autorKorzenia = $this->user('kor_'.$sufiks);
        $autorOdpowiedzi = $this->user('odp_'.$sufiks);
        $piszacy = $this->user('pis_'.$sufiks);

        $this->ustawBlokade($piszacy, $autorKorzenia, $piszacyBlokujeKorzen);

        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);
        [$korzen, $odpowiedz] = $this->watek($wpis, $autorKorzenia, $autorOdpowiedzi);
        $komentarzyPrzed = Comment::count();
        $powiadomienPrzed = Notification::count();

        $wynik = $this->actingAs($piszacy)->post(route('posts.comment', $wpis), [
            'body' => 'Nie wolno dopisać tego zdania do zablokowanego wątku.',
            'parent_id' => $odpowiedz->getKey(),
        ]);

        $wynik->assertSessionHasErrors('body');
        $this->assertSame($komentarzyPrzed, Comment::count(), 'Powstał komentarz mimo blokady z autorem korzenia.');
        $this->assertSame($powiadomienPrzed, Notification::count(), 'Powstało powiadomienie mimo odrzuconego komentarza.');
        $this->assertDatabaseMissing('comments', [
            'parent_id' => $korzen->getKey(),
            'body' => 'Nie wolno dopisać tego zdania do zablokowanego wątku.',
        ]);
    }

    public static function kierunkiBlokady(): array
    {
        return [
            'piszący blokuje autora korzenia' => [true],
            'autor korzenia blokuje piszącego' => [false],
        ];
    }

    public function test_akcja_domenowa_pilnuje_korzenia_dla_przepisu_i_ugotowalem(): void
    {
        $wlasciciel = $this->user('wldomena');
        $autorKorzenia = $this->user('kordomena');
        $autorOdpowiedzi = $this->user('odpdomena');
        $piszacy = $this->user('pisdomena');
        $piszacy->blocking()->attach($autorKorzenia->getKey());

        $przepis = Recipe::factory()->for($wlasciciel, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
        ]);
        $ugotowalem = CookedEvent::factory()
            ->for($this->user('kuchdomena'), 'user')
            ->for($przepis, 'recipe')
            ->create();

        foreach ([$przepis, $ugotowalem] as $indeks => $tresc) {
            [, $odpowiedz] = $this->watek($tresc, $autorKorzenia, $autorOdpowiedzi);
            $komentarzyPrzed = Comment::count();
            $powiadomienPrzed = Notification::count();

            try {
                app(PublishComment::class)->handle(
                    $piszacy,
                    $tresc,
                    'Próba domenowa '.$indeks,
                    $odpowiedz,
                    true,
                );
                $this->fail('Akcja domenowa przyjęła odpowiedź do korzenia objętego blokadą.');
            } catch (BladDlaCzlowieka $e) {
                $this->assertStringContainsString('Odśwież stronę', $e->getMessage());
            }

            $this->assertSame($komentarzyPrzed, Comment::count());
            $this->assertSame($powiadomienPrzed, Notification::count());
        }
    }

    public function test_bez_blokady_odpowiedz_na_odpowiedz_nadal_trafia_do_korzenia(): void
    {
        $wlasciciel = $this->user('wlkontrola');
        $autorKorzenia = $this->user('korkontrola');
        $autorOdpowiedzi = $this->user('odpkontrola');
        $piszacy = $this->user('piskontrola');
        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);
        [$korzen, $odpowiedz] = $this->watek($wpis, $autorKorzenia, $autorOdpowiedzi);

        $nowy = app(PublishComment::class)->handle(
            $piszacy,
            $wpis,
            'Zwykła odpowiedź na odpowiedź.',
            $odpowiedz,
            true,
        );

        $this->assertSame($korzen->getKey(), $nowy->parent_id);
        $this->assertNotSame($odpowiedz->getKey(), $nowy->parent_id);
    }

    private function ustawBlokade(User $piszacy, User $autorKorzenia, bool $piszacyBlokujeKorzen): void
    {
        ($piszacyBlokujeKorzen ? $piszacy : $autorKorzenia)
            ->blocking()
            ->attach(($piszacyBlokujeKorzen ? $autorKorzenia : $piszacy)->getKey());
    }

    private function kierunek(bool $piszacyBlokujeKorzen): string
    {
        return $piszacyBlokujeKorzen ? 'wych' : 'przych';
    }

    /** @return array{Comment, Comment} */
    private function watek(Post|Recipe|CookedEvent $tresc, User $autorKorzenia, User $autorOdpowiedzi): array
    {
        $kolumna = match (true) {
            $tresc instanceof Post => 'post_id',
            $tresc instanceof Recipe => 'recipe_id',
            $tresc instanceof CookedEvent => 'cooked_event_id',
        };
        $korzen = Comment::create([
            'author_id' => $autorKorzenia->getKey(),
            $kolumna => $tresc->getKey(),
            'body' => 'Korzeń rozmowy.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $odpowiedz = Comment::create([
            'author_id' => $autorOdpowiedzi->getKey(),
            $kolumna => $tresc->getKey(),
            'parent_id' => $korzen->getKey(),
            'body' => 'Widoczna odpowiedź innej osoby.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        return [$korzen, $odpowiedz];
    }
}
