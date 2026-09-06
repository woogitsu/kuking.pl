<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\OsieroconeZdjecia;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * C1 — nieudana walidacja kasowała wybrane zdjęcia.
 *
 * Basia wybierała trzy zdjęcia z galerii telefonu, pisała długi tekst,
 * przekraczała 4000 znaków i dostawała „Ten wpis jest za długi". Tekst
 * zostawał, ZDJĘCIA ZNIKAŁY — bo `withInput()` nie przenosi plików
 * i przenieść nie może: przeglądarka nie pozwala wypełnić `<input type="file">`
 * z serwera, i dobrze robi, bo inaczej strona mogłaby podkraść plik z dysku.
 *
 * AGENTS.md §5 mówi „poprawne dane nigdy nie znikają", a zdjęcie jest w tym
 * produkcie najważniejszą wpisaną daną.
 */
class ZdjeciaPrzezylyBladWalidacjiTest extends TestCase
{
    use RefreshDatabase;

    private function zdjecie(string $nazwa = 'obiad.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nazwa, 800, 600);
    }

    public function test_blad_walidacji_nie_kasuje_wybranych_zdjec(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'photos' => [$this->zdjecie()],
                'body' => str_repeat('a', 4001),
                'visibility' => 'public',
            ]);

        $odpowiedz->assertRedirect(route('posts.create'))->assertSessionHasErrors('body');

        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        //
        // Zdjęcie jest już wgrane i wraca do formularza jako identyfikator.
        // Bez tego Basia musi przejść przez galerię telefonu jeszcze raz —
        // po to tylko, że pomyliła się w tekście.
        $zachowane = session()->getOldInput('media_ids');

        $this->assertIsArray($zachowane);
        $this->assertCount(1, $zachowane);
        $this->assertSame(1, Media::query()->whereIn('id', $zachowane)->where('owner_id', $basia->getKey())->count());

        // Tekst też zostaje — to działało już wcześniej i ma działać dalej.
        $this->assertSame(str_repeat('a', 4001), session()->getOldInput('body'));
    }

    public function test_formularz_pokazuje_zachowane_zdjecia_i_mowi_o_nich(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('posts.create'))
            ->post(route('posts.store'), [
                'photos' => [$this->zdjecie()],
                'body' => str_repeat('a', 4001),
                'visibility' => 'public',
            ]);

        // Zachowanie, o którym człowiek się nie dowie, jest tym samym co brak
        // zachowania: Basia i tak kliknie „wybierz zdjęcie" drugi raz.
        $this->actingAs($basia)->get(route('posts.create'))
            ->assertOk()
            ->assertSee('Twoje zdjęcia są zachowane', escape: false)
            ->assertSee('name="media_ids[]"', escape: false);
    }

    public function test_zachowane_zdjecie_trafia_do_opublikowanego_wpisu(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $this->actingAs($basia)->from(route('posts.create'))->post(route('posts.store'), [
            'photos' => [$this->zdjecie()],
            'body' => str_repeat('a', 4001),
            'visibility' => 'public',
        ]);

        $zachowane = session()->getOldInput('media_ids');

        // Druga strona reguły: samo przetrwanie identyfikatora nic nie daje,
        // jeśli poprawiona wysyłka i tak publikuje wpis bez zdjęcia.
        $this->actingAs($basia)->post(route('posts.store'), [
            'media_ids' => $zachowane,
            'body' => 'Krotszy tekst',
            'visibility' => 'public',
        ])->assertRedirect();

        $wpis = Post::firstOrFail();
        $this->assertSame(1, $wpis->media()->count());
        $this->assertSame($zachowane[0], $wpis->media()->first()->getKey());
    }

    public function test_nie_da_sie_podpiac_cudzego_zdjecia(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');

        $cudze = Media::factory()->create(['owner_id' => $obcy->getKey()]);

        $this->actingAs($basia)->post(route('posts.store'), [
            'media_ids' => [$cudze->getKey()],
            'body' => 'Moj wpis',
            'visibility' => 'public',
        ])->assertRedirect();

        // UCZCIWIE: TEN TEST PRZECHODZI TAKŻE BEZ BRAMKI W KONTROLERZE.
        //
        // `PublishPost` filtruje zdjęcia po właścicielu od początku, więc
        // ta asercja pilnuje DRUGIEJ warstwy, nie pierwszej. Zmierzyłem to:
        // po wyjęciu bramki z kontrolera ten test dalej jest zielony.
        //
        // Zostaje świadomie, jako strażnik: `media_ids` przychodzi od klienta,
        // a gdyby ktoś kiedyś uprościł `PublishPost`, to jest jedyne miejsce,
        // które by o tym powiedziało. UUID w formularzu to nie autoryzacja,
        // dokładnie tak samo jak UUID w adresie.
        $this->assertSame(0, Post::firstOrFail()->media()->count());
    }

    public function test_nie_da_sie_podpiac_zdjecia_juz_przypietego_do_wpisu(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$this->zdjecie()],
            'body' => 'Pierwszy',
            'visibility' => 'public',
        ])->assertRedirect();

        $uzyte = Post::firstOrFail()->media()->first()->getKey();

        $this->actingAs($basia)->post(route('posts.store'), [
            'media_ids' => [$uzyte],
            'body' => 'Drugi',
            'visibility' => 'public',
        ])->assertRedirect();

        $drugi = Post::where('body', 'Drugi')->firstOrFail();
        $this->assertSame(0, $drugi->media()->count());
    }

    public function test_limit_zdjec_liczy_sie_na_sumie_obu_drog(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');
        $maks = (int) config('kuking.media.max_per_post');

        $wgrane = collect(range(1, $maks))
            ->map(fn () => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey())
            ->all();

        $this->actingAs($basia)->from(route('posts.create'))->post(route('posts.store'), [
            'media_ids' => $wgrane,
            'photos' => [$this->zdjecie()],
            'body' => 'Za duzo',
            'visibility' => 'public',
        ])->assertSessionHasErrors('photos');

        // Bez liczenia na SUMIE dało by się obejść limit, wysyłając połowę
        // zdjęć w plikach, a połowę w ukrytych polach.
        $this->assertSame(0, Post::count());
    }

    // ---------------------------------------------------------------
    // Sprzątanie po tej naprawie
    // ---------------------------------------------------------------

    public function test_zdjecie_nieprzypiete_do_niczego_jest_sprzatane_po_karencji(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $swieze = Media::factory()->create(['owner_id' => $basia->getKey(), 'created_at' => now()]);
        $stare = Media::factory()->create(['owner_id' => $basia->getKey(), 'created_at' => now()->subDays(3)]);

        $ile = (new OsieroconeZdjecia(24))->posprzataj();

        $this->assertSame(1, $ile);
        $this->assertNull($stare->fresh());

        // Nieprzypięte zdjęcie sprzed pięciu minut to najprawdopodobniej
        // formularz, który ktoś poprawia w drugiej karcie. Skasowanie go
        // byłoby tą samą krzywdą, tylko zadaną z drugiej strony.
        $this->assertNotNull($swieze->fresh());
    }

    public function test_sprzatanie_nie_rusza_zdjecia_przypietego_do_wpisu(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [$this->zdjecie()],
            'body' => 'Wpis ze zdjeciem',
            'visibility' => 'public',
        ])->assertRedirect();

        $przypiete = Post::firstOrFail()->media()->first();
        DB::table('media')->where('id', $przypiete->getKey())->update(['created_at' => now()->subYear()]);

        (new OsieroconeZdjecia(24))->posprzataj();

        // Kasowanie za dużo jest tu nieodwracalne — pliku nie da się przywrócić.
        $this->assertNotNull($przypiete->fresh());
    }

    public function test_lista_odwolan_do_media_jest_pelna(): void
    {
        // TO JEST TEST, KTÓRY PILNUJE JEDYNEGO RYZYKA SPRZĄTANIA.
        //
        // Pominięcie jednej kolumny w `OsieroconeZdjecia::ODWOLANIA` znaczy
        // kasowanie zdjęć, które ktoś ma przypięte do przepisu albo do awatara.
        // Czytamy więc schemat bazy i porównujemy z listą — jeśli ktoś doda
        // nową kolumnę wskazującą na `media` i zapomni o sprzątaczce,
        // ten test padnie, zanim zdjęcia zaczną znikać.
        $zSchematu = collect(DB::select(<<<'SQL'
            SELECT tc.table_name, kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage ccu
              ON tc.constraint_name = ccu.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND ccu.table_name = 'media'
        SQL))
            ->map(fn ($w) => [$w->table_name, $w->column_name])
            ->sortBy(fn ($p) => implode('.', $p))
            ->values()
            ->all();

        $zListy = collect(OsieroconeZdjecia::ODWOLANIA)
            ->sortBy(fn ($p) => implode('.', $p))
            ->values()
            ->all();

        $this->assertTrue(Schema::hasTable('media'));
        $this->assertSame(
            $zSchematu,
            $zListy,
            "Lista odwołań do `media` w OsieroconeZdjecia nie zgadza się ze schematem bazy.\n".
            'Zdjęcia przypięte przez pominiętą kolumnę zostałyby skasowane.',
        );
    }
}
