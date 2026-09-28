<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pierwsza publikacja pokazuje datę wpisu i jawny krok „Zobacz swój wpis”
 * (issue #1881).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * `docs/product/COLD_START.md` i `docs/product/SOUL.md` obiecują po
 * pierwszym „Opublikuj”: „Gotowe. To Twój pierwszy wpis w Kuking — {data
 * z `published_at`}.” + jawny przycisk „Zobacz swój wpis”. Do 26 września
 * 2026 `PostController::store()` mówił tylko „od teraz masz swoje
 * archiwum” — bez daty, która jest całą pointą „archiwum od pierwszej
 * sekundy” — i bez żadnego linku. Przy dwóch i więcej zdjęciach
 * przekierowanie ląduje na ekranie układu (`posts.media.edit`), nie na
 * wpisie, więc tam obietnicy z dokumentu nie było widać wcale.
 *
 * Test sprawdza WSZYSTKIE TRZY warianty z odtworzenia w issue: 0 zdjęć,
 * 1 zdjęcie, 2+ zdjęć — oraz KONTROLĘ UJEMNĄ: drugi (nie pierwszy) wpis tej
 * samej osoby nie ma dostać tego samego, mocniejszego potwierdzenia.
 */
class PierwszaPublikacjaPokazujeDateITrasyDoWpisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_pierwsza_publikacja_bez_zdjecia_pokazuje_date_i_link(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Pierwszy wpis, bez zdjęcia.',
            'visibility' => 'public',
        ]);

        $post = Post::query()->sole();
        $odpowiedz->assertRedirect(route('posts.show', $post));

        $this->assertNotNull($post->published_at, 'Bez `published_at` test nie sprawdza tego, o co chodzi w issue.');
        $data = Czas::data($post->published_at);

        $odpowiedz->assertSessionHas('status', fn (string $status) => str_contains($status, $data));
        $odpowiedz->assertSessionHas(
            'status_akcja',
            fn (array $akcja) => $akcja['url'] === $post->url() && $akcja['etykieta'] === 'Zobacz swój wpis',
        );

        $this->actingAs($basia)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee($data)
            ->assertSee('Zobacz swój wpis');
    }

    public function test_pierwsza_publikacja_z_jednym_zdjeciem_pokazuje_date_i_link(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('rosol.jpg', 1200, 900)],
            'body' => 'Rosół na niedzielę.',
            'visibility' => 'public',
        ]);

        $post = Post::query()->sole();
        $odpowiedz->assertRedirect(route('posts.show', $post));

        $data = Czas::data($post->published_at);

        $odpowiedz->assertSessionHas('status', fn (string $status) => str_contains($status, $data));
        $odpowiedz->assertSessionHas(
            'status_akcja',
            fn (array $akcja) => $akcja['url'] === $post->url() && $akcja['etykieta'] === 'Zobacz swój wpis',
        );

        $this->actingAs($basia)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee($data)
            ->assertSee('Zobacz swój wpis');
    }

    public function test_pierwsza_publikacja_z_dwoma_zdjeciami_ladujaca_na_ekranie_ukladu_tez_pokazuje_date_i_link(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [
                UploadedFile::fake()->image('rosol1.jpg', 1200, 900),
                UploadedFile::fake()->image('rosol2.jpg', 1200, 900),
            ],
            'body' => 'Rosół, dwa zdjęcia.',
            'visibility' => 'public',
        ]);

        $post = Post::query()->sole();
        $odpowiedz->assertRedirect(route('posts.media.edit', $post));

        $data = Czas::data($post->published_at);

        $odpowiedz->assertSessionHas('status', fn (string $status) => str_contains($status, $data));
        $odpowiedz->assertSessionHas(
            'status_akcja',
            fn (array $akcja) => $akcja['url'] === $post->url() && $akcja['etykieta'] === 'Zobacz swój wpis',
        );

        // KONSEKWENCJA Z EKRANEM UKŁADU: to jest ta sama droga, którą przeszła
        // by osoba z issue #1881, więc potwierdzenie ma tam wyglądać
        // dokładnie tak samo — data i link, nie samo „Opublikowane".
        $this->actingAs($basia)->get(route('posts.media.edit', $post))
            ->assertOk()
            ->assertSee($data)
            ->assertSee('Zobacz swój wpis');
    }

    /**
     * KONTROLA UJEMNA: drugi wpis tej samej osoby nie jest „pierwszą
     * publikacją” — nie ma dostać ani daty w tym silniejszym komunikacie
     * (`isFirstPost` jest fałszywe), ani przycisku „Zobacz swój wpis”. Bez
     * tej kontroli asercja „zawiera datę” przeszłaby też wtedy, gdyby
     * poprzedni test zwyczajnie pokazywał datę wszędzie, niezależnie od
     * tego, czy to naprawdę pierwszy wpis.
     */
    public function test_drugi_wpis_tej_samej_osoby_nie_dostaje_mocniejszego_potwierdzenia(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Pierwszy wpis.',
            'visibility' => 'public',
        ])->assertRedirect();

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Drugi wpis.',
            'visibility' => 'public',
        ]);

        $drugiWpis = Post::query()->where('body', 'Drugi wpis.')->sole();
        $odpowiedz->assertRedirect(route('posts.show', $drugiWpis));

        $odpowiedz->assertSessionHas('status', 'Opublikowane. Dziękujemy.');
        $odpowiedz->assertSessionMissing('status_akcja');

        $this->actingAs($basia)->get(route('posts.show', $drugiWpis))
            ->assertOk()
            ->assertDontSee('Zobacz swój wpis');
    }
}
