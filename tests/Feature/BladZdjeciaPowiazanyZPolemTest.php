<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Support\LimityZdjec;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Komunikat błędu przy polu zdjęć jest powiązany z tym polem (issue #1572).
 *
 * Podsumowanie błędów prowadzi odnośnikiem do `#f-photos`. Czytnik ekranu,
 * który tam wyląduje, czyta etykietę i OPIS pola — a opisem było tylko
 * `f-photos-help`. Komunikat błędu stał obok, ale nikt go nie wskazywał,
 * więc człowiek słyszał „Dodaj zdjęcie" i nie wiedział, co jest nie tak.
 *
 * Przy błędzie pole ma dostać `aria-invalid="true"` i `aria-describedby`
 * z pomocą NA PIERWSZYM miejscu i identyfikatorem komunikatu po niej.
 * Kontrola dodatnia: bez błędu opis to sama pomoc, bez `aria-invalid`.
 */
class BladZdjeciaPowiazanyZPolemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.questions.enabled' => true]);
    }

    /**
     * [ekran, akcja formularza, id pola, co wysłać, żeby padł błąd].
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function polaZwyklychFormularzy(): array
    {
        return [
            'wpis: za dużo zdjęć (photos)' => ['posts.create', 'posts.store', 'f-photos', 'za_duzo'],
            'wpis: zły plik (photos.0)' => ['posts.create', 'posts.store', 'f-photos', 'photos'],
            'pytanie: za dużo zdjęć (photos)' => ['questions.create', 'questions.store', 'f-photos', 'za_duzo'],
            'pytanie: zły plik (photos.0)' => ['questions.create', 'questions.store', 'f-photos', 'photos'],
            'ugotowałem: za dużo zdjęć (photos)' => ['cooked.create', 'cooked.store', 'f-photos', 'za_duzo'],
            'ugotowałem: zły plik (photos.0)' => ['cooked.create', 'cooked.store', 'f-photos', 'photos'],
            'przepis krótki: zdjęcie dania' => ['recipes.create', 'recipes.store', 'f-hero_photo', 'hero_photo'],
            'przepis na stronie: zdjęcie dania' => ['recipes.create.simple', 'recipes.store', 'f-hero_photo', 'hero_photo'],
            'przepis na stronie: skan kartki' => ['recipes.create.simple', 'recipes.store', 'f-source_scan', 'source_scan'],
            'przepis na stronie: zdjęcie kroku' => ['recipes.create.simple', 'recipes.store', 'f-steps-0-photo', 'krok'],
        ];
    }

    #[DataProvider('polaZwyklychFormularzy')]
    public function test_blad_zdjecia_jest_opisem_pola_plikow(string $ekran, string $akcja, string $idPola, string $zlyPlik): void
    {
        $pole = $this->pole($this->poBledzie($ekran, $akcja, $zlyPlik), $idPola);

        $this->assertSame('true', $pole['input']->getAttribute('aria-invalid'), $ekran);
        $opis = explode(' ', $pole['input']->getAttribute('aria-describedby'));
        $this->assertSame($idPola.'-help', $opis[0], 'Pomoc przy polu musi zostać pierwsza w opisie.');
        $this->assertCount(2, $opis, 'Opis to pomoc i JEDEN komunikat błędu.');

        $komunikat = $pole['xpath']->query('//*[@id="'.$opis[1].'"]');
        $this->assertSame(1, $komunikat->length, 'aria-describedby wskazuje nieistniejący element: '.$opis[1]);
        $this->assertStringContainsString('field-error', $komunikat->item(0)->getAttribute('class'));
        $this->assertNotSame('', trim($komunikat->item(0)->textContent));
        $this->assertSame(1, $pole['xpath']->query('//*[@id="'.$idPola.'-help"]')->length, 'Pomoc przy polu zniknęła.');
    }

    #[DataProvider('polaZwyklychFormularzy')]
    public function test_bez_bledu_opisem_pola_jest_sama_pomoc(string $ekran, string $akcja, string $idPola, string $zlyPlik): void
    {
        $pole = $this->pole($this->actingAs($this->user('basia'))->get($this->adres($ekran))->assertOk()->getContent(), $idPola);

        $this->assertFalse($pole['input']->hasAttribute('aria-invalid'), $ekran);
        $this->assertSame($idPola.'-help', $pole['input']->getAttribute('aria-describedby'));
    }

    /**
     * Prawdziwa droga: za duży plik odrzucony przez walidację daje błąd
     * `photos.0`, a pole plików po powrocie wskazuje ten komunikat.
     */
    public function test_odrzucony_plik_wpisu_opisuje_pole_po_powrocie_formularza(): void
    {
        config(['kuking.media.max_bytes' => 1024 * 1024]);
        $user = $this->user();
        $this->actingAs($user)->get(route('posts.create'))->assertOk();

        $html = $this->followingRedirects()->actingAs($user)->from(route('posts.create'))->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('duze.jpg')->size(1024 + 1)],
            'body' => 'Rosół na niedzielę.',
            'visibility' => 'private',
        ])->assertOk()->getContent();
        $this->followRedirects = false;

        $pole = $this->pole($html, 'f-photos');
        $this->assertSame('true', $pole['input']->getAttribute('aria-invalid'));
        $this->assertSame('f-photos-help f-photos-plik-error', $pole['input']->getAttribute('aria-describedby'));
        $this->assertNotSame('', trim($pole['xpath']->query('//*[@id="f-photos-plik-error"]')->item(0)->textContent));
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function polaKreatora(): array
    {
        return [
            'zdjęcie dania (krok 1)' => [1, 'heroPhoto', 'f-heroPhoto'],
            'zdjęcie kroku (krok 3)' => [3, 'steps.0.photo', 'f-steps-0-photo'],
        ];
    }

    #[DataProvider('polaKreatora')]
    public function test_kreator_wiaze_blad_zdjecia_z_polem(int $krok, string $wlasciwosc, string $id): void
    {
        $komponent = Livewire::actingAs($this->user('basia'))->test('recipe-wizard')
            ->set('title', 'Kotlet schabowy')
            ->set('ingredients.0.text', 'schab')
            ->set('steps.0.instruction', 'Rozbij mięso.')
            ->set('step', $krok);

        $pole = $this->pole($komponent->html(), $id);
        $this->assertFalse($pole['input']->hasAttribute('aria-invalid'), $id);
        $this->assertSame($id.'-help', $pole['input']->getAttribute('aria-describedby'));

        $komponent->set($wlasciwosc, UploadedFile::fake()->create('przepis.txt', 1, 'text/plain'))
            ->assertHasErrors($wlasciwosc);

        $pole = $this->pole($komponent->html(), $id);
        $this->assertSame('true', $pole['input']->getAttribute('aria-invalid'), $id);
        $this->assertSame($id.'-help '.$id.'-error', $pole['input']->getAttribute('aria-describedby'));
        $this->assertNotSame('', trim($pole['xpath']->query('//*[@id="'.$id.'-error"]')->item(0)->textContent));
    }

    private ?string $slugPrzepisu = null;

    private function adres(string $trasa): string
    {
        if (! str_starts_with($trasa, 'cooked.')) {
            return route($trasa);
        }

        $this->slugPrzepisu ??= Recipe::factory()->for($this->user('autorprzepisu'), 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ])->slug;

        return route($trasa, $this->slugPrzepisu);
    }

    /**
     * Wysyła formularz z błędnym plikiem i zwraca ekran, na który wrócił.
     */
    private function poBledzie(string $ekran, string $akcja, string $zlyPlik): string
    {
        $tekst = UploadedFile::fake()->create('przepis.txt', 1, 'text/plain');
        $dane = match ($zlyPlik) {
            'za_duzo' => ['photos' => array_map(
                fn (int $i) => UploadedFile::fake()->image("obiad-{$i}.jpg"),
                range(0, $ekran === 'questions.create' ? 1 : LimityZdjec::maksZdjecNaWysylke()),
            )],
            'photos' => ['photos' => [$tekst]],
            'krok' => ['steps' => [['instruction' => 'Zagotuj wodę.', 'photo' => $tekst]]],
            default => [$zlyPlik => $tekst],
        };
        $dane += ['body' => 'Rosół na niedzielę.', 'title' => 'Rosół', 'visibility' => 'private'];

        $user = $this->user('basia');
        $this->actingAs($user)->get($this->adres($ekran))->assertOk();
        $html = $this->followingRedirects()->actingAs($user)->from($this->adres($ekran))
            ->post($this->adres($akcja), $dane)->assertOk()->getContent();
        $this->followRedirects = false;

        return $html;
    }

    /**
     * @return array{input: DOMElement, xpath: DOMXPath}
     */
    private function pole(string $html, string $id): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);
        $wejscia = $xpath->query('//input[@type="file" and @id="'.$id.'"]');
        $this->assertSame(1, $wejscia->length, 'Brak pola plików #'.$id);

        return ['input' => $wejscia->item(0), 'xpath' => $xpath];
    }
}
