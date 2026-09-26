<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zdjęcia w formularzach publikacji po błędzie walidacji — issues #871, #872, #874.
 *
 * #871 — `old('media_ids')` szło prosto do `whereIn('id', …)` w widokach wpisu
 * i pytania. Odrzucony przez walidację „nie-uuid" wracał w starych danych,
 * a PostgreSQL odrzucał porównanie z kolumną uuid: strona naprawy formularza
 * kończyła się 500 zamiast komunikatem.
 *
 * #872 — „Ugotowałem" zapisywało zdjęcia dopiero po walidacji wszystkich pól.
 * „1h 30" w polu minut odsyłało formularz bez zdjęcia; zdjęcie jest
 * opcjonalne, więc po poprawce czasu wykonanie zapisywało się bez niego.
 *
 * #874 — błąd pojedynczego pliku ma klucz `photos.0`, a podsumowanie błędów
 * linkowało do nieistniejącego `#f-photos-0`.
 *
 * Wszystkie scenariusze idą prawdziwą drogą POST → przekierowanie → GET
 * z tą samą sesją, nie przez ręczne wywołanie pomocnika.
 */
class ZdjeciaFormularzyPoBledzieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.questions.enabled' => true]);
    }

    /**
     * POST → przekierowanie → GET w tej samej sesji, jak w przeglądarce.
     *
     * `followingRedirects()`, nie `post()` + `assertSessionHasErrors()` +
     * osobny `get()`: sama asercja sesji zostawia w niej worek błędów jako
     * tablicę i następny GET nie widzi już żadnego błędu (zmierzone).
     *
     * @param  array<string, mixed>  $dane
     */
    private function wyslij(string $formularz, string $akcja, array $dane): TestResponse
    {
        $odpowiedz = $this->from($formularz)->followingRedirects()->post($akcja, $dane);
        $this->followRedirects = false;

        return $odpowiedz->assertOk();
    }

    private function zdjecie(string $nazwa = 'obiad.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nazwa, 800, 600);
    }

    private function nieZdjecie(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('notatka.jpg', 'to nie jest obraz');
    }

    /**
     * @return list<string>
     */
    private function ukryteMediaIds(string $html): array
    {
        $wynik = [];

        foreach ($this->xpath($html)->query('//input[@type="hidden"][@name="media_ids[]"]') as $pole) {
            $wynik[] = $pole->getAttribute('value');
        }

        return $wynik;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    /**
     * Każdy link z podsumowania błędów musi prowadzić do elementu, który
     * naprawdę jest w dokumencie — sam tekst błędu to za mało (#874).
     *
     * @return list<string> cele linków
     */
    private function assertLinkiPodsumowaniaMajaCel(string $html): array
    {
        $xpath = $this->xpath($html);
        $cele = [];

        $linki = $xpath->query('//div[contains(@class, "error-summary")]//a');
        $this->assertGreaterThan(0, $linki->length, 'Brak podsumowania błędów na stronie.');

        foreach ($linki as $link) {
            $cel = ltrim($link->getAttribute('href'), '#');
            $cele[] = $cel;
            $this->assertSame(
                1,
                $xpath->query('//*[@id="'.$cel.'"]')->length,
                "Link z podsumowania prowadzi do #{$cel}, a takiego elementu nie ma w formularzu.",
            );
        }

        return $cele;
    }

    /**
     * @return array{0: User, 1: Recipe}
     */
    private function kucharzIPrzepis(): array
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');

        return [$kucharz, Recipe::factory()->create(['author_id' => $autor->getKey()])];
    }

    // ------------------------------------------------------------------
    // #871 — niezaufane `old('media_ids')` przy odtwarzaniu formularza
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function zepsuteMediaIds(): array
    {
        return [
            'nie-uuid' => [['nie-uuid']],
            'zagnieżdżona tablica' => [[['x' => 'y']]],
            'null w liście' => [[null]],
            'pusta lista' => [[]],
        ];
    }

    #[DataProvider('zepsuteMediaIds')]
    public function test_wpis_z_zepsutym_media_ids_wraca_do_formularza_z_tekstem(mixed $mediaIds): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia);

        $this->wyslij(route('posts.create'), route('posts.store'), [
            'body' => str_repeat('R', 4001),
            'visibility' => 'public',
            'media_ids' => $mediaIds,
        ])->assertSee('Sprawdź formularz')->assertSee(str_repeat('R', 4001));
    }

    #[DataProvider('zepsuteMediaIds')]
    public function test_pytanie_z_zepsutym_media_ids_wraca_do_formularza_z_tekstem(mixed $mediaIds): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia);

        // Treść ponad limit wymusza błąd także dla poprawnej listy zdjęć.
        $this->wyslij(route('questions.create'), route('questions.store'), [
            'title' => 'Jak uratować przesoloną zupę?',
            'body' => str_repeat('b', 4001),
            'media_ids' => $mediaIds,
        ])->assertSee('Sprawdź formularz')->assertSee('Jak uratować przesoloną zupę?');
    }

    public function test_nie_uuid_daje_komunikat_po_polsku_z_linkiem_do_pola_zdjec(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia);

        $html = $this->wyslij(route('posts.create'), route('posts.store'), [
            'body' => 'Rosół na niedzielę',
            'visibility' => 'public',
            'media_ids' => ['nie-uuid'],
        ])
            ->assertSee('Rosół na niedzielę')
            ->assertSee(LimityZdjec::komunikatZepsutegoZachowanegoZdjecia())
            ->assertDontSee('UUID')
            ->getContent();

        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
    }

    public function test_poprawne_wlasne_zdjecie_wraca_a_cudze_nie(): void
    {
        $basia = $this->user('basia');
        $janek = $this->user('janek');
        $wlasne = Media::factory()->create(['owner_id' => $basia->getKey()])->getKey();
        $cudze = Media::factory()->create(['owner_id' => $janek->getKey()])->getKey();

        $this->actingAs($basia);

        $html = $this->wyslij(route('posts.create'), route('posts.store'), [
            'body' => str_repeat('a', 4001),
            'visibility' => 'public',
            'media_ids' => [$cudze, $wlasne],
        ])->assertSee('Twoje zdjęcia są zachowane.')->getContent();

        $this->assertSame([$wlasne], $this->ukryteMediaIds($html));
    }

    // ------------------------------------------------------------------
    // #872 — „Ugotowałem" zachowuje poprawne zdjęcia po błędzie innego pola
    // ------------------------------------------------------------------

    public function test_ugotowalem_zachowuje_zdjecie_przez_dwa_bledy_i_zapisuje_je_z_wykonaniem(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();
        $formularz = route('cooked.create', $recipe->slug);

        $html = $this->actingAs($kucharz)->get($formularz)->assertOk()->getContent();
        $klucz = $this->xpath($html)->query('//input[@name="klucz_wyslania"]')->item(0)?->getAttribute('value');

        // 1. Poprawne zdjęcie + zły czas.
        $html = $this->wyslij($formularz, route('cooked.store', $recipe->slug), [
            'klucz_wyslania' => $klucz,
            'photos' => [$this->zdjecie()],
            'note' => 'Wyszło pięknie',
            'actual_minutes' => '1h 30',
        ])
            ->assertSee('Wpisz sam czas w minutach')
            ->assertSee('Twoje zdjęcia są zachowane.')
            ->assertSee('Wyszło pięknie')
            ->getContent();

        $this->assertSame(1, Media::query()->count());
        $zdjecie = (string) Media::query()->value('id');
        $this->assertSame([$zdjecie], $this->ukryteMediaIds($html));

        // 2. Drugi błąd — to samo zdjęcie, bez ponownego uploadu i duplikatu.
        $html = $this->wyslij($formularz, route('cooked.store', $recipe->slug), [
            'klucz_wyslania' => $klucz,
            'media_ids' => $this->ukryteMediaIds($html),
            'note' => str_repeat('n', 2001),
            'actual_minutes' => '90',
        ])->assertSee('Ta uwaga jest za długa.')->getContent();
        $this->assertSame([$zdjecie], $this->ukryteMediaIds($html));
        $this->assertSame(1, Media::query()->count());

        // 3. Poprawione tylko pole tekstowe — wykonanie z pierwotnym zdjęciem.
        $this->from($formularz)->post(route('cooked.store', $recipe->slug), [
            'klucz_wyslania' => $klucz,
            'media_ids' => $this->ukryteMediaIds($html),
            'note' => 'Wyszło pięknie',
            'actual_minutes' => '90',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count());
        $event = CookedEvent::query()->firstOrFail();
        $this->assertSame([$zdjecie], $event->media()->pluck('media.id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame(90, $event->actual_minutes);
        $this->assertSame(1, Media::query()->count());

        // Ponowienie tego samego wysłania nie dubluje wykonania.
        $this->from($formularz)->post(route('cooked.store', $recipe->slug), [
            'klucz_wyslania' => $klucz,
            'media_ids' => [$zdjecie],
            'note' => 'Wyszło pięknie',
            'actual_minutes' => '90',
        ]);
        $this->assertSame(1, CookedEvent::query()->count());
    }

    public function test_ugotowalem_swiadome_usuniecie_zachowanego_zdjecia(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();
        $formularz = route('cooked.create', $recipe->slug);
        $zdjecie = Media::factory()->create(['owner_id' => $kucharz->getKey()])->getKey();

        $this->actingAs($kucharz)->from($formularz)->post(route('cooked.store', $recipe->slug), [
            'media_ids' => [$zdjecie],
            'note' => 'Bez zdjęcia jednak',
            'usun_zdjecie' => $zdjecie,
        ])->assertRedirect($formularz)->assertSessionHasNoErrors();

        $this->assertSame(0, CookedEvent::query()->count(), '„Usuń to zdjęcie" nie wysyła wykonania.');

        $html = $this->get($formularz)
            ->assertOk()
            ->assertSee('Bez zdjęcia jednak')
            ->assertDontSee('Sprawdź formularz')
            ->assertDontSee('Twoje zdjęcia są zachowane.')
            ->getContent();
        $this->assertSame([], $this->ukryteMediaIds($html));

        $this->from($formularz)->post(route('cooked.store', $recipe->slug), [
            'media_ids' => $this->ukryteMediaIds($html),
            'note' => 'Bez zdjęcia jednak',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, CookedEvent::query()->firstOrFail()->media()->count());
    }

    public function test_ugotowalem_przyjmuje_tylko_wlasne_nieprzypiete_zdjecia(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();
        $formularz = route('cooked.create', $recipe->slug);
        $janek = $this->user('janek');

        $cudze = Media::factory()->create(['owner_id' => $janek->getKey()])->getKey();
        $wWykonaniu = Media::factory()->create(['owner_id' => $kucharz->getKey()])->getKey();
        $wczorajsze = CookedEvent::query()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $recipe->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $wczorajsze->media()->attach($wWykonaniu, ['position' => 0]);
        $wlasne = Media::factory()->create(['owner_id' => $kucharz->getKey()])->getKey();
        $nieistniejace = (string) Str::uuid();

        // Odtworzenie po błędzie pokazuje tylko własne, nieprzypięte.
        $this->actingAs($kucharz);

        $html = $this->wyslij($formularz, route('cooked.store', $recipe->slug), [
            'media_ids' => [$cudze, $wWykonaniu, $wlasne, $nieistniejace],
            'actual_minutes' => 'dużo',
        ])->assertSee('Wpisz sam czas w minutach')->getContent();
        $this->assertSame([$wlasne], $this->ukryteMediaIds($html));

        // Zapis też ich nie przyjmuje, nawet wysłanych wprost.
        $this->from($formularz)->post(route('cooked.store', $recipe->slug), [
            'media_ids' => [$cudze, $wWykonaniu, $nieistniejace],
        ])->assertSessionHasNoErrors();

        $nowe = CookedEvent::query()->whereKeyNot($wczorajsze->getKey())->firstOrFail();
        $this->assertSame(0, $nowe->media()->count());
        $this->assertSame(1, $wczorajsze->media()->count());
    }

    public function test_ugotowalem_z_zepsutym_media_ids_wraca_z_komunikatem(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();
        $formularz = route('cooked.create', $recipe->slug);

        $this->actingAs($kucharz);

        $html = $this->wyslij($formularz, route('cooked.store', $recipe->slug), [
            'note' => 'Wyszło',
            'media_ids' => ['nie-uuid'],
        ])
            ->assertSee('Wyszło')
            ->assertSee(LimityZdjec::komunikatZepsutegoZachowanegoZdjecia())
            ->getContent();

        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_ugotowalem_bez_zdjecia_dalej_dozwolone(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();

        $this->actingAs($kucharz)->post(route('cooked.store', $recipe->slug))->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(0, Media::query()->count());
    }

    // ------------------------------------------------------------------
    // #874 — link z podsumowania przy błędzie pojedynczego pliku
    // ------------------------------------------------------------------

    public function test_blad_pojedynczego_pliku_wpisu_prowadzi_do_pola_zdjec(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia);

        // Drugi plik zły — błąd pod kluczem `photos.1`.
        $html = $this->wyslij(route('posts.create'), route('posts.store'), [
            'photos' => [$this->zdjecie(), $this->nieZdjecie()],
            'body' => 'Rosół',
            'visibility' => 'public',
        ])->assertSee('Rosół')->getContent();

        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
    }

    public function test_blad_pojedynczego_pliku_pytania_prowadzi_do_pola_zdjec(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia);

        $html = $this->wyslij(route('questions.create'), route('questions.store'), [
            'title' => 'Jak uratować przesoloną zupę?',
            'photos' => [$this->nieZdjecie()],
        ])->getContent();

        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
    }

    public function test_pytanie_z_zachowanym_zdjeciem_ma_cel_linku_mimo_braku_pola_pliku(): void
    {
        $basia = $this->user('basia');
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()])->getKey();

        $this->actingAs($basia);

        $html = $this->wyslij(route('questions.create'), route('questions.store'), [
            'title' => 'Jak uratować przesoloną zupę?',
            'media_ids' => [$zdjecie],
            'photos' => [$this->nieZdjecie()],
        ])->getContent();

        $this->assertSame([$zdjecie], $this->ukryteMediaIds($html));
        $this->assertSame(0, $this->xpath($html)->query('//input[@type="file"]')->length);
        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
    }

    public function test_blad_pojedynczego_pliku_ugotowalem_prowadzi_do_pola_zdjec(): void
    {
        [$kucharz, $recipe] = $this->kucharzIPrzepis();
        $formularz = route('cooked.create', $recipe->slug);

        $this->actingAs($kucharz);

        $html = $this->wyslij($formularz, route('cooked.store', $recipe->slug), [
            'photos' => [$this->nieZdjecie()],
        ])->getContent();

        $this->assertSame(['f-photos'], $this->assertLinkiPodsumowaniaMajaCel($html));
    }

    public function test_indeksowane_pola_bez_wzorca_zachowuja_wlasny_cel(): void
    {
        $this->withViewErrors([
            'steps.0.instruction' => 'Opisz ten krok.',
            'photos.0' => 'Ten plik nie wygląda na zdjęcie.',
        ])->blade('<x-error-summary :field-ids="[\'photos.*\' => \'f-photos\']" />')
            ->assertSee('href="#f-steps-0-instruction"', false)
            ->assertSee('href="#f-photos"', false)
            ->assertDontSee('#f-photos-0', false);

        // Bez wzorca strona zachowuje się jak dawniej.
        $this->withViewErrors(['photos.0' => 'Ten plik nie wygląda na zdjęcie.'])
            ->blade('<x-error-summary />')
            ->assertSee('href="#f-photos-0"', false);
    }

    public function test_wpis_bez_bledu_zdjec_nadal_publikuje_sie_ze_zdjeciem(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$this->zdjecie()],
            'body' => 'Rosół',
            'visibility' => 'public',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Post::query()->firstOrFail()->media()->count());
    }
}
