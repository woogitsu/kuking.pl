<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Posts\ZapisWpisuRequest;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Walidacja zapisu wpisu i pytania wyjęta z `PostController::store()`
 * (issue #970, krok 3). Zachowanie nie miało się zmienić — ten test pilnuje
 * tego, co przy przenosinach łatwo zgubić: kolejności (404 przed walidacją,
 * zdjęcia przed treścią) i tego, że stare wejście niesie NOWĄ listę tagów.
 */
class ZapisWpisuRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_wylaczone_pytania_daja_404_a_nie_bledy_pol(): void
    {
        config(['kuking.questions.enabled' => false]);

        // Puste żądanie złamałoby walidację treści; 404 musi przyjść pierwsze.
        $this->actingAs($this->user('pytajacy970'))->post(route('questions.store'), ['title' => ''])
            ->assertNotFound();

        // Kontrola dodatnia: włączone pytania dochodzą do walidacji.
        config(['kuking.questions.enabled' => true]);
        $this->actingAs($this->user('pytajacy971'))->from('/pytania/zadaj')->post(route('questions.store'), ['title' => ''])
            ->assertSessionHasErrors(['title' => 'Napisz pytanie w tytule.']);
    }

    public function test_pytanie_przyjmuje_jedno_zdjecie_a_wpis_kilka(): void
    {
        config(['kuking.questions.enabled' => true]);
        Storage::fake();
        $zdjecia = fn (): array => [
            UploadedFile::fake()->image('a.jpg', 400, 300),
            UploadedFile::fake()->image('b.jpg', 400, 300),
        ];

        $this->actingAs($this->user('pytajacy972'))->from('/pytania/zadaj')
            ->post(route('questions.store'), ['title' => 'Jak zrobić rosół?', 'photos' => $zdjecia()])
            ->assertSessionHasErrors(['photos' => 'Do pytania wybierz jedno zdjęcie.']);

        // Kontrola ujemna: te same dwa zdjęcia nie łamią limitu zwykłego wpisu.
        $this->from('/dodaj/zdjecie')
            ->post(route('posts.store'), ['visibility' => 'public', 'photos' => $zdjecia()])
            ->assertSessionHasNoErrors();
    }

    public function test_tresc_wpisu_wymaga_widocznosci_a_pytania_tytulu(): void
    {
        $wpis = $this->zadanie('posts.store', ['visibility' => 'wszyscy'])->walidatorTresci();
        $this->assertTrue($wpis->fails());
        $this->assertSame(
            'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.',
            $wpis->errors()->first('visibility'),
        );
        $this->assertFalse($this->zadanie('posts.store', ['visibility' => 'followers'])->walidatorTresci()->fails());

        $pytanie = $this->zadanie('questions.store', ['title' => 'Za krótko'])->walidatorTresci();
        $this->assertSame('Rozwiń pytanie do co najmniej 10 znaków.', $pytanie->errors()->first('title'));
        $this->assertFalse($this->zadanie('questions.store', ['title' => 'Jak zrobić rosół?'])->walidatorTresci()->fails());
    }

    public function test_pytanie_jest_zawsze_publiczne_a_wpis_bierze_widocznosc_z_formularza(): void
    {
        $pytanie = $this->zadanie('questions.store', ['title' => 'Jak zrobić rosół?', 'visibility' => 'private']);
        $this->assertSame(Post::VISIBILITY_PUBLIC, $pytanie->widocznosc($pytanie->walidatorTresci()->validated()));

        $wpis = $this->zadanie('posts.store', ['visibility' => 'private']);
        $this->assertSame('private', $wpis->widocznosc($wpis->walidatorTresci()->validated()));
    }

    public function test_stare_wejscie_niesie_nowa_liste_tagow_i_zdjec_a_nie_pliki(): void
    {
        $zadanie = $this->zadanie('posts.store', [
            'body' => 'Szkic', 'tag_names' => ['stary'], 'media_ids' => ['x'],
        ], ['photos' => [UploadedFile::fake()->image('a.jpg')]]);

        $wejscie = $zadanie->wejscieBezPlikowITagow(['nowy-uuid'], ['nowy']);

        $this->assertSame(['nowy'], $wejscie['tag_names']);
        $this->assertSame(['nowy-uuid'], $wejscie['media_ids']);
        $this->assertSame('Szkic', $wejscie['body']);
        $this->assertArrayNotHasKey('photos', $wejscie);

        $bezTagow = $zadanie->wejscieBezPlikow(['nowy-uuid']);
        $this->assertSame(['stary'], $bezTagow['tag_names']);
        $this->assertArrayNotHasKey('photos', $bezTagow);
    }

    /**
     * @param  array<string, mixed>  $dane
     * @param  array<string, mixed>  $pliki
     */
    private function zadanie(string $trasa, array $dane, array $pliki = []): ZapisWpisuRequest
    {
        $adres = $trasa === 'questions.store' ? '/pytania' : '/dodaj/zdjecie';
        $zadanie = ZapisWpisuRequest::create($adres, 'POST', $dane, [], $pliki);
        $zadanie->setRouteResolver(fn () => app('router')->getRoutes()->getByName($trasa)->bind($zadanie));

        return $zadanie;
    }
}
