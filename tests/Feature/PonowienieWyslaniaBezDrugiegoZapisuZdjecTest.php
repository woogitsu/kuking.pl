<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

/**
 * Ponowione, już zakończone wysłanie nie zapisuje zdjęć drugi raz (issue #873).
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Deduplikacja po `klucz_wyslania` działała w akcjach domenowych, czyli PO
 * pętli `StoreUploadedImage` w kontrolerze. Dwa identyczne multipart POST
 * dawały jedno wykonanie (albo jeden wpis), ale DWA wiersze `media`, dwa
 * obiekty w magazynie i dwa zadania `ProcessUploadedImage` — drugie zdjęcie
 * osierocone do sprzątania po dobie.
 *
 * CZEGO TEN PLIK NIE DOWODZI
 * Testy są sekwencyjne. Dwa JEDNOCZESNE żądania mogą oba minąć wczesne
 * pytanie o klucz; tam jedno wykonanie nadal gwarantuje indeks UNIQUE,
 * ale zdjęcia drugiego żądania mogą się zapisać (i zostać osierocone).
 *
 * Ponowienie z samymi `media_ids` nie jest tu reprezentatywne — nie
 * przechodzi przez pętlę uploadu — więc każde żądanie niesie nowe pliki.
 */
class PonowienieWyslaniaBezDrugiegoZapisuZdjecTest extends TestCase
{
    use RefreshDatabase;

    private QueueFake $kolejka;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('kuking.media.disk'));
        Storage::fake((string) config('kuking.media.public_disk'));
        $this->kolejka = Queue::fake([ProcessUploadedImage::class]);
    }

    private function zdjecie(): UploadedFile
    {
        return UploadedFile::fake()->image('obiad.jpg', 640, 480);
    }

    private function przepis(User $autor, string $widocznosc = 'public'): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $widocznosc,
            'published_at' => now()->subDay(),
        ]);
    }

    private function plikiWMagazynie(): int
    {
        return count(Storage::disk((string) config('kuking.media.disk'))->allFiles());
    }

    private function powiadomieniaUgotowalem(User $autor): int
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->count();
    }

    /**
     * @return array{media: int, pliki: int, zadania: int}
     */
    private function koszt(): array
    {
        return [
            'media' => Media::query()->count(),
            'pliki' => $this->plikiWMagazynie(),
            'zadania' => $this->kolejka->pushed(ProcessUploadedImage::class)->count(),
        ];
    }

    public function test_ponowione_ugotowalem_nie_zapisuje_zdjecia_drugi_raz(): void
    {
        $autor = $this->user('autorprzepisu');
        $kucharka = $this->user('kucharka');
        $przepis = $this->przepis($autor);
        $klucz = (string) Str::uuid();

        $pierwsze = $this->actingAs($kucharka)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Wyszło pięknie.', 'klucz_wyslania' => $klucz, 'photos' => [$this->zdjecie()],
        ]);
        $poPierwszym = $this->koszt();

        $drugie = $this->actingAs($kucharka)->post(route('cooked.store', $przepis->slug), [
            'note' => 'Wyszło pięknie.', 'klucz_wyslania' => $klucz, 'photos' => [$this->zdjecie()],
        ]);

        $drugie->assertSessionHasNoErrors();
        $this->assertSame([1, 1], [$poPierwszym['media'], $poPierwszym['zadania']], 'Kontrola dodatnia: pierwsze wysłanie nie zapisało zdjęcia.');
        $this->assertGreaterThan(0, $poPierwszym['pliki'], 'Kontrola dodatnia: pierwsze wysłanie nie zapisało zdjęcia.');
        $this->assertSame($poPierwszym, $this->koszt(), 'Ponowione wysłanie zapisało i przetworzyło zdjęcie drugi raz.');
        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(1, $this->powiadomieniaUgotowalem($autor));
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_dwa_rozne_klucze_to_dwa_wykonania_z_wlasnymi_zdjeciami(): void
    {
        // D-005: każde gotowanie liczy się osobno — wczesne wyjście nie może
        // połknąć nowego wykonania z nowego formularza.
        $autor = $this->user('autorprzepisu');
        $kucharka = $this->user('kucharka');
        $przepis = $this->przepis($autor);

        foreach ([1, 2] as $ktore) {
            $this->actingAs($kucharka)->post(route('cooked.store', $przepis->slug), [
                'note' => 'Znowu.', 'klucz_wyslania' => (string) Str::uuid(), 'photos' => [$this->zdjecie()],
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, CookedEvent::query()->count());
        $this->assertSame(2, $this->powiadomieniaUgotowalem($autor));
        $koszt = $this->koszt();
        $this->assertSame([2, 2], [$koszt['media'], $koszt['zadania']]);
        $this->assertGreaterThan(0, $koszt['pliki']);
    }

    public function test_odrzucona_autoryzacja_ugotowalem_nie_przetwarza_zdjec(): void
    {
        $autor = $this->user('autorprzepisu');
        $obcy = $this->user('ktosobcy');
        $przepis = $this->przepis($autor, 'private');

        $this->actingAs($obcy)->post(route('cooked.store', $przepis->slug), [
            'klucz_wyslania' => (string) Str::uuid(), 'photos' => [$this->zdjecie()],
        ])->assertForbidden();

        $this->assertSame(['media' => 0, 'pliki' => 0, 'zadania' => 0], $this->koszt());
    }

    public function test_cudzy_klucz_ugotowalem_nie_oddaje_cudzego_wykonania(): void
    {
        $autor = $this->user('autorprzepisu');
        $kucharka = $this->user('kucharka');
        $obcy = $this->user('ktosobcy');
        $przepis = $this->przepis($autor);
        $klucz = (string) Str::uuid();

        $jej = $this->actingAs($kucharka)->post(route('cooked.store', $przepis->slug), [
            'klucz_wyslania' => $klucz, 'photos' => [$this->zdjecie()],
        ]);
        $jego = $this->actingAs($obcy)->post(route('cooked.store', $przepis->slug), [
            'klucz_wyslania' => $klucz, 'photos' => [$this->zdjecie()],
        ]);

        $jego->assertSessionHasNoErrors();
        $this->assertNotSame($jej->headers->get('Location'), $jego->headers->get('Location'), 'Cudzy klucz odesłał na cudze wykonanie.');
        $this->assertSame(2, CookedEvent::query()->count());
        $this->assertSame(2, Media::query()->count(), 'Własne zdjęcie drugiej osoby się nie zapisało.');
    }

    public function test_ponowiony_wpis_nie_zapisuje_zdjecia_drugi_raz(): void
    {
        $autorka = $this->user('autorkawpisu');
        $tresc = fn (): array => [
            'body' => 'Rosół na niedzielę.', 'visibility' => 'public',
            'klucz_wyslania' => '0b8f4a52-3c1d-4e6f-9a7b-2d5c8e1f0a93', 'photos' => [$this->zdjecie()],
        ];

        $pierwsze = $this->actingAs($autorka)->post(route('posts.store'), $tresc());
        $poPierwszym = $this->koszt();
        $drugie = $this->actingAs($autorka)->post(route('posts.store'), $tresc());

        $drugie->assertSessionHasNoErrors();
        $this->assertSame([1, 1], [$poPierwszym['media'], $poPierwszym['zadania']], 'Kontrola dodatnia: pierwszy wpis nie zapisał zdjęcia.');
        $this->assertGreaterThan(0, $poPierwszym['pliki'], 'Kontrola dodatnia: pierwszy wpis nie zapisał zdjęcia.');
        $this->assertSame($poPierwszym, $this->koszt(), 'Ponowiony wpis zapisał i przetworzył zdjęcie drugi raz.');
        $this->assertSame(1, Post::query()->count());
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_cudzy_klucz_wpisu_nie_blokuje_wlasnych_zdjec(): void
    {
        $autorka = $this->user('autorkawpisu');
        $obcy = $this->user('ktosobcy');
        $klucz = (string) Str::uuid();

        foreach ([$autorka, $obcy] as $osoba) {
            $this->actingAs($osoba)->post(route('posts.store'), [
                'body' => 'Obiad.', 'visibility' => 'public', 'klucz_wyslania' => $klucz, 'photos' => [$this->zdjecie()],
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Post::query()->count());
        $this->assertSame(2, Media::query()->count());
    }

    public function test_ponowione_pytanie_nie_zapisuje_zdjecia_drugi_raz(): void
    {
        config(['kuking.questions.enabled' => true]);
        $pytajaca = $this->user('pytajaca');
        $tresc = fn (): array => [
            'title' => 'Jak uratować przesoloną zupę?',
            'klucz_wyslania' => '5e2d7c14-8a3b-4f60-b1d9-7c4e2a6f8b05', 'photos' => [$this->zdjecie()],
        ];

        $pierwsze = $this->actingAs($pytajaca)->post(route('questions.store'), $tresc());
        $poPierwszym = $this->koszt();
        $drugie = $this->actingAs($pytajaca)->post(route('questions.store'), $tresc());

        $drugie->assertSessionHasNoErrors();
        $this->assertSame([1, 1], [$poPierwszym['media'], $poPierwszym['zadania']], 'Kontrola dodatnia: pierwsze pytanie nie zapisało zdjęcia.');
        $this->assertGreaterThan(0, $poPierwszym['pliki'], 'Kontrola dodatnia: pierwsze pytanie nie zapisało zdjęcia.');
        $this->assertSame($poPierwszym, $this->koszt(), 'Ponowione pytanie zapisało i przetworzyło zdjęcie drugi raz.');
        $this->assertSame(1, Post::query()->count());
        $this->assertSame($pierwsze->headers->get('Location'), $drugie->headers->get('Location'));
    }

    public function test_przycisk_tagu_na_opublikowanym_formularzu_dalej_wraca_do_formularza(): void
    {
        // Przyciski tagów to praca nad formularzem, nie wysłanie — wczesne
        // wyjście ich nie dotyczy (tak jak przed zmianą).
        $autorka = $this->user('autorkawpisu');
        $klucz = (string) Str::uuid();

        $this->actingAs($autorka)->post(route('posts.store'), [
            'body' => 'Obiad.', 'visibility' => 'public', 'klucz_wyslania' => $klucz,
        ]);

        $odpowiedz = $this->actingAs($autorka)->from(route('posts.create'))->post(route('posts.store'), [
            'body' => 'Obiad.', 'visibility' => 'public', 'klucz_wyslania' => $klucz, 'dodaj_tag' => 'rosół',
        ]);

        $this->assertStringStartsWith(route('posts.create'), (string) $odpowiedz->headers->get('Location'));
    }
}
