<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\HeroKolaz;
use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NAJWAŻNIEJSZY TEST KOLAŻU W HERO: do kolażu na stronie powitalnej nie ma
 * prawa wejść ani jedno zdjęcie, którego nie wolno pokazać nieznajomemu —
 * ani przy wyborze w panelu, ani PÓŹNIEJ, gdy wpis albo konto zmieni stan.
 *
 * DLACZEGO OSOBNY PLIK, SKORO JEST JUŻ `DailyBoardTest`
 * Bo to jest inna klasa użycia i inna stawka. Tablica dnia stoi w środku
 * strony, za dwoma sekcjami; kolaż jest PIERWSZĄ rzeczą, jaką widzi ktoś
 * z wyszukiwarki, i jest tam po to, żeby zachęcić do rejestracji. Cudze
 * prywatne zdjęcie w tym miejscu to nie jest ta sama usterka co cudze
 * prywatne zdjęcie na tablicy — to jest ta sama usterka pomnożona przez
 * cały ruch wchodzący.
 *
 * DWIE GRANICE, KTÓRE TEN PLIK SPRAWDZA OSOBNO
 *  1. BRAMKA ZAPISU (`HeroKolaz::dopuszczZdjecia`, panel): czego w ogóle nie
 *     da się wskazać.
 *  2. FILTR WYŚWIETLENIA (`HeroKolaz::doKolazu`, strona powitalna): co
 *     wypada Z JUŻ ZAPISANEGO wyboru, bo stan zmienił się po nim.
 *
 * Druga granica jest tą ważniejszą i tą, której nie da się zastąpić pierwszą.
 * Wpis wskazany dziś jako publiczny może jutro być prywatny, schowany przez
 * moderację albo należeć do konta zawieszonego — i nikt nie kasuje wtedy
 * wiersza w `hero_picks`.
 *
 * KONTROLA UJEMNA (wykonana ręcznie, opis w raporcie): podmiana
 * `publiclyVisible()->tylkoOdAktywnychAutorow()` w
 * `HeroKolaz::wpisyDoPokazania()` na samo `Post::query()` oblewa
 * `test_wpis_prywatny_nie_wchodzi_do_kolazu`,
 * `test_wpis_schowany_przez_moderacje_wypada_z_gotowego_wyboru`
 * i `test_zdjecie_z_konta_zawieszonego_wypada_z_gotowego_wyboru`.
 * Po każdym sabotażu sprawdzone `grep`-em, że zmiana naprawdę jest w pliku.
 */
class KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wpis z jednym gotowym zdjęciem. Zwraca parę [wpis, zdjęcie].
     *
     * @return array{0: Post, 1: Media}
     */
    private function wpisZeZdjeciem(User $autor, array $atrybuty = []): array
    {
        $wpis = Post::factory()->create(array_merge([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(random_int(1, 500)),
        ], $atrybuty));

        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$wpis->refresh(), $zdjecie];
    }

    private function wskaz(Post $wpis, Media $zdjecie, int $pozycja = 0): HeroPick
    {
        return HeroPick::create([
            'media_id' => $zdjecie->getKey(),
            'post_id' => $wpis->getKey(),
            'position' => $pozycja,
        ]);
    }

    /** @return list<string> */
    private function idZdjecWKolazu(): array
    {
        return app(HeroKolaz::class)
            ->doKolazu()
            ->map(fn (array $kafel) => (string) $kafel['media']->getKey())
            ->all();
    }

    /**
     * Czterech autorów, czworo zdjęć — tyle, ile ma kolaż. Dzięki temu
     * usunięcie JEDNEGO z nich naprawdę coś zmienia: bez tego zapasu każdy
     * test kończyłby się pustym kolażem niezależnie od tego, co sprawdza.
     *
     * @return list<array{0: Post, 1: Media, 2: User}>
     */
    private function czteryPubliczneZdjecia(): array
    {
        $zestaw = [];

        foreach (range(1, 4) as $i) {
            $autor = $this->user('autor_'.$i);
            [$wpis, $zdjecie] = $this->wpisZeZdjeciem($autor);
            $zestaw[] = [$wpis, $zdjecie, $autor];
        }

        return $zestaw;
    }

    // ---------------------------------------------------------------------
    // 1. Kolaż w ogóle działa — kontrola DODATNIA
    // ---------------------------------------------------------------------

    /**
     * Bez tego testu każdy test niżej przechodziłby także wtedy, gdyby
     * `doKolazu()` zwracało pustkę ZAWSZE (pułapka 2 z `docs/PULAPKI_TESTOW.md`).
     */
    public function test_cztery_publiczne_zdjecia_daja_pelny_kolaz(): void
    {
        $this->czteryPubliczneZdjecia();

        $this->assertCount(HeroKolaz::SLOTOW, $this->idZdjecWKolazu());
    }

    public function test_od_zera_do_czterech_dostepnych_zdjec_jest_renderowane_bez_odrzucania_mniejszego_zestawu(): void
    {
        for ($liczba = 0; $liczba <= 4; $liczba++) {
            if ($liczba > 0) {
                $this->wpisZeZdjeciem($this->user('kafel_'.$liczba));
            }

            $this->assertCount($liczba, $this->idZdjecWKolazu());
            $response = $this->get('/')->assertOk();
            $this->assertSame($liczba, substr_count($response->getContent(), 'class="hero-kolaz-kafel"'));
        }
    }

    public function test_nowsze_wpisy_bez_gotowych_zdjec_nie_wypychaja_zdjecia_z_automatu(): void
    {
        $autor = $this->user('fotograf');
        [, $zdjecie] = $this->wpisZeZdjeciem($autor, ['published_at' => now()->subDays(2)]);
        Post::factory()->count(41)->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        foreach (range(1, 41) as $i) {
            [, $niegotowe] = $this->wpisZeZdjeciem($autor, ['published_at' => now()]);
            $niegotowe->update(['status' => ['pending', 'processing', 'deleted'][$i % 3]]);
        }

        $this->assertSame([(string) $zdjecie->getKey()], $this->idZdjecWKolazu());
    }

    public function test_wskazane_zdjecie_stoi_w_kolazu_na_pierwszym_miejscu(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();

        // Wskazujemy NAJSTARSZY wpis, żeby wyprzedzenie było skutkiem
        // wskazania, a nie kolejności chronologicznej.
        $najstarszy = collect($zestaw)->sortBy(fn (array $t) => $t[0]->published_at)->first();

        $this->wskaz($najstarszy[0], $najstarszy[1]);

        $this->assertSame((string) $najstarszy[1]->getKey(), $this->idZdjecWKolazu()[0]);
    }

    // ---------------------------------------------------------------------
    // 2. Bramka ZAPISU — czego nie da się nawet wskazać
    // ---------------------------------------------------------------------

    public function test_wpis_prywatny_nie_wchodzi_do_kolazu(): void
    {
        $autor = $this->user('prywatny');
        [$wpis, $zdjecie] = $this->wpisZeZdjeciem($autor, ['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->assertSame([], app(HeroKolaz::class)->dopuszczZdjecia([(string) $zdjecie->getKey()]));

        // I nie wchodzi też wtedy, gdy ktoś wiersz wstawi z pominięciem panelu.
        $this->wskaz($wpis, $zdjecie);
        $this->assertNotContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());
    }

    public function test_wpis_tylko_dla_obserwujacych_nie_wchodzi_do_kolazu(): void
    {
        $autor = $this->user('obserwujacy');
        [, $zdjecie] = $this->wpisZeZdjeciem($autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        $this->assertSame([], app(HeroKolaz::class)->dopuszczZdjecia([(string) $zdjecie->getKey()]));
    }

    public function test_szkic_i_wpis_schowany_nie_wchodza_do_kolazu(): void
    {
        $autor = $this->user('szkicowy');
        [, $szkic] = $this->wpisZeZdjeciem($autor, ['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        [, $schowany] = $this->wpisZeZdjeciem($autor, ['status' => Post::STATUS_HIDDEN]);

        $this->assertSame([], app(HeroKolaz::class)->dopuszczZdjecia([
            (string) $szkic->getKey(),
            (string) $schowany->getKey(),
        ]));
    }

    public function test_zdjecie_jeszcze_nieprzetworzone_nie_wchodzi_do_kolazu(): void
    {
        $autor = $this->user('nieprzetworzony');

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $zdjecie = Media::factory()->for($autor, 'owner')->pending()->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->assertSame([], app(HeroKolaz::class)->dopuszczZdjecia([(string) $zdjecie->getKey()]));
    }

    public function test_identyfikator_wziety_z_sufitu_nie_przechodzi(): void
    {
        $this->czteryPubliczneZdjecia();

        $this->assertSame(
            [],
            app(HeroKolaz::class)->dopuszczZdjecia(['0c4b1a9e-0000-4000-8000-000000000000']),
        );
    }

    // ---------------------------------------------------------------------
    // 3. Filtr WYŚWIETLENIA — co wypada z już zapisanego wyboru
    // ---------------------------------------------------------------------

    public function test_wpis_przelaczony_na_prywatny_po_wyborze_wypada_z_kolazu(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();
        [$wpis, $zdjecie] = $zestaw[0];

        $this->wskaz($wpis, $zdjecie);
        $this->assertContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());

        $wpis->update(['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->assertNotContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());
    }

    public function test_wpis_schowany_przez_moderacje_wypada_z_gotowego_wyboru(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();
        [$wpis, $zdjecie] = $zestaw[1];

        $this->wskaz($wpis, $zdjecie);
        $wpis->update(['status' => Post::STATUS_HIDDEN]);

        $this->assertNotContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());
    }

    public function test_zdjecie_z_konta_zawieszonego_wypada_z_gotowego_wyboru(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();
        [$wpis, $zdjecie, $autor] = $zestaw[2];

        $this->wskaz($wpis, $zdjecie);
        $this->assertContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());

        // `suspend()`, nie `update(['status' => …])` — `status` NIGDY nie jest
        // w `$fillable` (AGENTS.md §7) i test ma chodzić tą samą drogą,
        // którą chodzi moderator.
        $autor->suspend();

        $this->assertNotContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());
    }

    public function test_skasowanie_wpisu_kasuje_pozycje_kolazu(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();
        [$wpis, $zdjecie] = $zestaw[3];

        $this->wskaz($wpis, $zdjecie);
        $this->assertSame(1, HeroPick::query()->count());

        $wpis->forceDelete();

        $this->assertSame(0, HeroPick::query()->count());
        $this->assertNotContains((string) $zdjecie->getKey(), $this->idZdjecWKolazu());
    }

    // ---------------------------------------------------------------------
    // 4. Stan zapasowy
    // ---------------------------------------------------------------------

    public function test_wybor_niepelny_uzupelnia_sie_do_czterech(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();
        $this->wskaz($zestaw[0][0], $zestaw[0][1]);

        $kolaz = $this->idZdjecWKolazu();

        $this->assertCount(HeroKolaz::SLOTOW, $kolaz);
        $this->assertSame((string) $zestaw[0][1]->getKey(), $kolaz[0]);
        $this->assertSame(count($kolaz), count(array_unique($kolaz)), 'To samo zdjęcie weszło do kolażu dwa razy.');
    }

    public function test_mniejszy_kolaz_zachowuje_limit_dwoch_zdjec_od_osoby(): void
    {
        // Trzy zdjęcia, ale wszystkie od JEDNEJ osoby: dobór automatyczny
        // bierze najwyżej dwa od osoby i pokazuje te dwa zamiast pustki.
        $autor = $this->user('samotny');

        foreach (range(1, 3) as $i) {
            $this->wpisZeZdjeciem($autor);
        }

        $this->assertCount(2, $this->idZdjecWKolazu());
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'class="hero-kolaz-kafel"'));
    }

    public function test_dwie_osoby_po_dwa_zdjecia_daja_pelny_kolaz(): void
    {
        foreach (['jedna', 'druga'] as $nazwa) {
            $autor = $this->user($nazwa);
            $this->wpisZeZdjeciem($autor);
            $this->wpisZeZdjeciem($autor);
        }

        $this->assertCount(HeroKolaz::SLOTOW, $this->idZdjecWKolazu());
    }

    // ---------------------------------------------------------------------
    // 5. Strona powitalna — oba stany, mierzone osobno
    // ---------------------------------------------------------------------

    /**
     * Ekran o dwóch stanach bywa mierzony w niewłaściwym (D-106, D-099):
     * hero z pustym kolażem odpowiada 200 i w raporcie wygląda jak pełny.
     * Dlatego oba stany mają tu osobną asercję na LICZBĘ kafli w dokumencie,
     * a nie na sam kod odpowiedzi.
     */
    public function test_strona_powitalna_pokazuje_cztery_kafle_gdy_jest_z_czego(): void
    {
        $this->czteryPubliczneZdjecia();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertSame(
            HeroKolaz::SLOTOW,
            substr_count($html, 'hero-kolaz-kafel'),
            'Kolaż w hero ma inną liczbę zdjęć niż cztery.',
        );
    }

    public function test_kolaz_jest_ozdobnikiem_wiec_zdjecia_maja_puste_alt(): void
    {
        $this->czteryPubliczneZdjecia();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="hero-kolaz" aria-hidden="true">', $html);
        $this->assertSame(
            HeroKolaz::SLOTOW,
            preg_match_all('~class="hero-kolaz-kafel"[^>]*\salt=""~', $html),
            'Któreś zdjęcie kolażu przestało być ozdobnikiem — patrz uzasadnienie w `landing.blade.php`.',
        );
    }

    public function test_podpis_z_autorami_nie_jest_ukryty_przed_czytnikiem_ekranu(): void
    {
        $zestaw = $this->czteryPubliczneZdjecia();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $podpis = $this->wytnij($html, '<figcaption class="hero-kolaz-podpis">', '</figcaption>');

        $this->assertNotSame('', $podpis, 'Zniknął podpis z nazwami autorów zdjęć z kolażu.');

        foreach ($zestaw as [, , $autor]) {
            $this->assertStringContainsString($autor->displayName(), $podpis);
        }
    }

    private function wytnij(string $html, string $od, string $do): string
    {
        $start = strpos($html, $od);

        if ($start === false) {
            return '';
        }

        $koniec = strpos($html, $do, $start);

        return $koniec === false ? '' : substr($html, $start, $koniec - $start);
    }
}
