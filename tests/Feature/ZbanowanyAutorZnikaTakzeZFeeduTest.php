<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wpis zbanowanego autora znika ze WSZYSTKICH list, nie tylko spod swojego adresu.
 *
 * CO BYŁO ZEPSUTE
 * `PostPolicy::view()` odmawiał dostępu do wpisu, którego autor jest
 * zbanowany albo oznaczony do usunięcia — więc wejście na `/wpisy/{id}`
 * dawało 403. `FollowingFeed::paginate()` tej reguły nie miał, a
 * `FollowingFeed::isEmptyFor()` — kilkanaście linijek niżej, w tym samym
 * pliku — miał ją od początku. Ten sam wpis znikał więc spod własnego
 * adresu i jednocześnie stał w feedzie każdego, kto tę osobę obserwował:
 * ze zdjęciem, nazwą i treścią. Strona główna zalogowanej osoby pokazuje
 * ten sam feed, więc leciało to na dwa ekrany naraz.
 *
 * To jest DOKŁADNIE ta usterka, którą audyt W5-08 zamknął w zeszycie
 * i w mapie strony (`CollectionController`, `SitemapController` mają obok
 * `widoczneDla()` jawne `dostepnyJakoAutor()`), tylko w miejscu, do którego
 * tamta poprawka nie dotarła. „Treść mniej dostępna przez drzwi frontowe
 * niż przez okno" — słowami komentarza z `User::scopeDostepnyJakoAutor`.
 *
 * DLACZEGO KAŻDY TEST MA WPIS KONTROLNY
 * Bo „nie widać wpisu zbanowanego" przechodzi także wtedy, gdy nie widać
 * NICZEGO — bo feed jest pusty, bo zmienił się szablon, bo strona zwróciła
 * pustą listę z innego powodu. Wpis autora bez bana, identyczny pod każdym
 * innym względem, musi być widoczny w tej samej odpowiedzi. Bez tej pary
 * asercji test mierzy własne złudzenie.
 */
class ZbanowanyAutorZnikaTakzeZFeeduTest extends TestCase
{
    use RefreshDatabase;

    private const ZNACZNIK_BAN = 'ZnacznikWpisuZbanowanego';

    private const ZNACZNIK_KONTROLNY = 'ZnacznikWpisuKontrolnego';

    public function test_feed_obserwowanych_nie_pokazuje_wpisu_zbanowanego_autora(): void
    {
        [$czytelnik] = $this->scena();

        $this->actingAs($czytelnik)->get('/home')
            ->assertOk()
            ->assertDontSee(self::ZNACZNIK_BAN)
            ->assertSee(self::ZNACZNIK_KONTROLNY);
    }

    public function test_strona_glowna_zalogowanej_osoby_tez_go_nie_pokazuje(): void
    {
        // Osobno od `/home`, bo to inny kontroler — a przed poprawką leciało
        // na obu ekranach z tego samego powodu.
        [$czytelnik] = $this->scena();

        $this->actingAs($czytelnik)->get('/')
            ->assertOk()
            ->assertDontSee(self::ZNACZNIK_BAN)
            ->assertSee(self::ZNACZNIK_KONTROLNY);
    }

    public function test_odkryj_nie_pokazuje_go_ani_goscowi_ani_zalogowanemu(): void
    {
        // Tu poprawka nie była potrzebna — `DiscoverFeed` miał regułę od
        // audytu A5. Test stoi tutaj, bo to jest ta sama reguła i ma zostać
        // sprawdzona w jednym miejscu razem z resztą, a nie osobno.
        [$czytelnik] = $this->scena();

        $this->get('/odkryj')
            ->assertOk()
            ->assertDontSee(self::ZNACZNIK_BAN)
            ->assertSee(self::ZNACZNIK_KONTROLNY);

        $this->actingAs($czytelnik)->get('/odkryj')
            ->assertOk()
            ->assertDontSee(self::ZNACZNIK_BAN)
            ->assertSee(self::ZNACZNIK_KONTROLNY);
    }

    public function test_pod_wlasnym_adresem_dalej_daje_403(): void
    {
        // Kontrola drugiej warstwy: poprawka w feedzie nie mogła po drodze
        // rozluźnić polityki.
        [$czytelnik, $wpisZbanowanego] = $this->scena();

        $this->actingAs($czytelnik)
            ->get(route('posts.show', $wpisZbanowanego))
            ->assertForbidden();
    }

    public function test_konto_oznaczone_do_usuniecia_traktujemy_tak_samo(): void
    {
        // `pending_delete` stoi w `scopeDostepnyJakoAutor` obok bana i ma
        // dokładnie tę samą konsekwencję. Osobny test, bo to osobny status
        // i osobna ścieżka w kodzie, którą łatwo dodać tylko w jednym miejscu.
        [$czytelnik, , $zbanowany] = $this->scena();

        $zbanowany->status = User::STATUS_PENDING_DELETE;
        $zbanowany->save();

        $this->actingAs($czytelnik)->get('/home')
            ->assertOk()
            ->assertDontSee(self::ZNACZNIK_BAN)
            ->assertSee(self::ZNACZNIK_KONTROLNY);
    }

    /**
     * Czytelnik obserwujący dwie osoby: jedną zbanowaną, jedną zdrową.
     * Oba wpisy są publiczne, opublikowane i identyczne poza autorem.
     *
     * @return array{0: User, 1: Post, 2: User}
     */
    private function scena(): array
    {
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);
        $zdrowy = $this->user('zdrowy');
        $czytelnik = $this->user('czytelnik');

        $wpisZbanowanego = Post::factory()->create([
            'author_id' => $zbanowany->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
            'body' => self::ZNACZNIK_BAN,
        ]);

        Post::factory()->create([
            'author_id' => $zdrowy->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
            'body' => self::ZNACZNIK_KONTROLNY,
        ]);

        $czytelnik->following()->attach([$zbanowany->getKey(), $zdrowy->getKey()]);

        return [$czytelnik, $wpisZbanowanego, $zbanowany];
    }
}
