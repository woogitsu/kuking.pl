<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Posts\Actions\EditPost;
use App\Jobs\PrzeanalizujAwatar;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CO WOLNO WYSŁAĆ DO OPENAI — D-240.
 *
 * Cztery wymagania właściciela, każde z testem, który pada bez poprawki:
 *
 *  1. wychodzi WYŁĄCZNIE treść publiczna, czyli taka, którą gość bez konta
 *     zobaczyłby w serwisie w chwili wykonania zadania (#827);
 *  2. zdjęcie wychodzi POMNIEJSZONE — mierzymy bajty, które naprawdę
 *     poszły w żądaniu, a nie to, co twierdzą metadane;
 *  3. zdjęcie profilowe nie wychodzi wcale — nie istnieje potwierdzona
 *     zgoda na jego ocenę;
 *  4. brak klucza i awaria dostawcy zostawiają ślad i nie zamieniają się
 *     w „treść sprawdzona, czysta".
 *
 * Każde żądanie idzie w atrapę (`Http::fake`); `TestCase` ma
 * `preventStrayRequests()`, więc nic nie może wyjść do prawdziwego API.
 */
class GranicaWysylkiDoOpenAiTest extends TestCase
{
    use RefreshDatabase;

    private const ZNACZNIK = 'ZNACZNIKGRANICY';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('public');

        config([
            'kuking.moderation.sygnaly.wlaczone' => true,
            'kuking.moderation.model.klucz' => 'atrapa-klucza',
            'kuking.moderation.model.ocenia_zdjecia' => true,
            'kuking.moderation.model.zdjec_na_wpis' => 4,
        ]);

        // Jedna atrapa na cały test, a odpowiedź podmienia `$this->odpowiedz`.
        // Kolejne `Http::fake()` DOKŁADAJĄ wzorce za pierwszym, więc wzorzec
        // `*` z tego miejsca przykryłby każdą awarię ustawioną w teście.
        $this->odpowiedz = fn () => Http::response(['results' => [['category_scores' => ['hate' => 0.95]]]]);
        Http::fake(fn (Request $r) => ($this->odpowiedz)($r));
    }

    /** @var \Closure(Request): mixed */
    private \Closure $odpowiedz;

    // ---------------------------------------------------------------
    // 1. WYŁĄCZNIE TREŚĆ PUBLICZNA
    // ---------------------------------------------------------------

    /**
     * Trzecia kolumna: czy wolno wysłać do OpenAI. Czwarta: czy lokalny
     * sygnał spamu (D-052, nie wychodzi z serwera) może postawić oznaczenie.
     * „Dla obserwujących" rozdziela te dwie granice: do dostawcy nie, przed
     * moderatora tak — jak przed D-240.
     *
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function rodzice(): iterable
    {
        $stany = [
            'publiczny' => [true, true],
            'prywatny' => [false, false],
            'dla_obserwujacych' => [false, true],
            'ukryty_przez_moderacje' => [false, false],
            'usuniety' => [false, false],
            // D-241: do dostawcy nie, ale lokalny sygnał spamu działa dalej.
            'autor_zbanowany' => [false, true],
            'autor_w_karencji_usuniecia' => [false, false],
        ];

        foreach (['wpis', 'przepis', 'wykonanie'] as $rodzic) {
            foreach ($stany as $stan => [$wyslac, $oznaczyc]) {
                yield "$rodzic $stan" => [$rodzic, $stan, $wyslac, $oznaczyc];
            }
        }
    }

    /**
     * Komentarz powstaje pod PUBLICZNYM rodzicem prawdziwą akcją, zadanie
     * czeka w kolejce, rodzic się zmienia, dopiero potem zadanie rusza.
     * Tak wygląda scenariusz z #827.
     */
    #[DataProvider('rodzice')]
    public function test_komentarz_wychodzi_tylko_pod_publicznym_rodzicem(string $rodzic, string $stan, bool $wyslac, bool $oznaczyc): void
    {
        Queue::fake();
        $wlasciciel = $this->user('wlasciciel');

        $przepis = Recipe::factory()->create(['author_id' => $wlasciciel->getKey()]);
        $przedmiot = match ($rodzic) {
            'wpis' => $this->wpis($wlasciciel, 'Wpis pod komentarzem.'),
            'przepis' => $przepis,
            'wykonanie' => CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $wlasciciel->getKey()]),
        };

        $komentarz = app(PublishComment::class)->handle($this->user('komentuje'), $przedmiot, self::ZNACZNIK.' zarabiaj z domu');
        Queue::assertPushed(PrzeanalizujTresc::class);

        // Dla wykonania o widoczności decyduje przepis, więc to jego zmieniamy.
        $zmieniany = $przedmiot instanceof CookedEvent ? $przepis : $przedmiot;

        match ($stan) {
            'publiczny' => null,
            'prywatny', 'dla_obserwujacych' => $this->zmienWidocznosc($zmieniany, $stan === 'prywatny' ? 'private' : 'followers'),
            'ukryty_przez_moderacje' => $zmieniany->forceFill(['status' => 'hidden'])->save(),
            'usuniety' => $zmieniany->delete(),
            'autor_zbanowany' => $wlasciciel->forceFill(['status' => User::STATUS_BANNED])->save(),
            'autor_w_karencji_usuniecia' => $wlasciciel->forceFill(['status' => User::STATUS_PENDING_DELETE])->save(),
        };

        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->fresh()->status);

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        if ($wyslac) {
            // Kontrola dodatnia: bez niej „nic nie wyszło" przechodziłoby
            // także dla zepsutej atrapy.
            Http::assertSentCount(1);
            Http::assertSent(fn (Request $r): bool => str_contains($r->body(), self::ZNACZNIK));
        } else {
            Http::assertNothingSent();
        }

        $this->assertSame(
            $oznaczyc ? 1 : 0,
            $this->oznaczenia($komentarz),
            $oznaczyc ? 'Lokalny sygnał przestał działać.' : 'Moderator dostał pozycję z treścią, której poza autorem nikt nie widzi.',
        );
    }

    /** @return array<string, array{string}> */
    public static function stanyKomentarza(): array
    {
        return [
            'ukryty przez moderacje' => ['ukryty'],
            'zastapiony sladem usuniecia' => ['slad'],
            'usuniety' => ['usuniety'],
        ];
    }

    #[DataProvider('stanyKomentarza')]
    public function test_niepubliczny_komentarz_nie_wychodzi(string $stan): void
    {
        $autor = $this->user('autorka');
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $this->wpis($this->user('gospodarz'), 'Publiczny wpis.')->getKey(),
            'body' => self::ZNACZNIK.' zarabiaj z domu',
        ]);

        match ($stan) {
            'ukryty' => $komentarz->forceFill(['status' => Comment::STATUS_HIDDEN])->save(),
            'slad' => $komentarz->forceFill(['body_removed_at' => now()])->save(),
            'usuniety' => $komentarz->delete(),
        };

        $this->analizuj(PrzeanalizujTresc::TYP_KOMENTARZ, $komentarz);

        Http::assertNothingSent();
        $this->assertSame(0, $this->oznaczenia($komentarz));
    }

    /** @return array<string, array{string, int}> */
    public static function stanyWpisu(): array
    {
        return [
            'prywatny' => ['private', 0],
            // Do dostawcy nie, ale lokalny sygnał spamu dalej stawia oznaczenie.
            'dla obserwujacych' => ['followers', 1],
            'ukryty przez moderacje' => ['hidden', 0],
            'usuniety' => ['deleted', 0],
            // D-241: ban nie zdejmuje lokalnej analizy — tylko wysyłkę.
            'autor zbanowany' => ['banned', 1],
        ];
    }

    #[DataProvider('stanyWpisu')]
    public function test_niepubliczny_wpis_nie_wychodzi_ani_tekstem_ani_zdjeciem(string $stan, int $oznaczen): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, self::ZNACZNIK.' zarabiaj z domu');
        $wpis->media()->attach($this->zdjecie($autor, ['thumb' => [320, 240]]));

        match ($stan) {
            'private', 'followers' => app(EditPost::class)->handle($autor, $wpis, $wpis->body, $stan),
            'hidden' => $wpis->forceFill(['status' => Post::STATUS_HIDDEN])->save(),
            'deleted' => $wpis->delete(),
            'banned' => $autor->forceFill(['status' => User::STATUS_BANNED])->save(),
        };

        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        Http::assertNothingSent();
        $this->assertSame($oznaczen, $this->oznaczenia($wpis));
    }

    /**
     * Granica pytana jest przy KAŻDYM zdjęciu, nie raz na początku zadania.
     * Autor, który w trakcie oceny tekstu przełączył wpis na prywatny, nie
     * wysyła już zdjęć — a moderator nie dostaje pozycji o treści, której
     * nikt poza autorem nie widzi.
     */
    public function test_zmiana_na_prywatny_w_trakcie_oceny_zatrzymuje_reszte(): void
    {
        $autor = $this->user('zmienia');
        $wpis = $this->wpis($autor, self::ZNACZNIK.' zwykła zupa');
        $wpis->media()->attach($this->zdjecie($autor, ['thumb' => [320, 240]]));

        $this->odpowiedz = function (Request $r) use ($wpis) {
            Post::query()->whereKey($wpis->getKey())->update(['visibility' => Post::VISIBILITY_PRIVATE]);

            return Http::response(['results' => [['category_scores' => ['hate' => 0.95]]]]);
        };

        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => ($r['input'][0]['type'] ?? null) === 'text');
        $this->assertSame(0, $this->oznaczenia($wpis));
    }

    // ---------------------------------------------------------------
    // 2. ZDJĘCIE WYCHODZI POMNIEJSZONE — MIERZYMY TO, CO WYSZŁO
    // ---------------------------------------------------------------

    public function test_wychodzi_miniatura_zmierzona_z_bajtow_zadania(): void
    {
        $autor = $this->user('zdjecia');
        $wpis = $this->wpis($autor, '');
        $wpis->media()->attach($this->zdjecie($autor, ['thumb' => [320, 240], 'large' => [1600, 1200]]));

        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        $this->assertSame([[320, 240, IMAGETYPE_JPEG]], $this->wyslaneObrazy());
    }

    /** @return array<string, array{array<string, array{int, int}|string>}> */
    public static function zleMiniatury(): array
    {
        return [
            // Stare metadane albo przerwane przetwarzanie: jest tylko duży
            // wariant. Dawniej `wariantDoSerwowania('thumb')` podstawiał go
            // po cichu i do OpenAI szło 1600 px.
            'brak thumb, jest large' => [['large' => [1600, 1200]]],
            'brak thumb, jest feed' => [['feed' => [960, 720]]],
            // Wariant nazywa się `thumb`, ale bajty są duże.
            'thumb za szeroki' => [['thumb' => [321, 240]]],
            'thumb za wysoki' => [['thumb' => [240, 321]]],
            'thumb w rozmiarze oryginalu' => [['thumb' => [2048, 1536]]],
            'thumb to nie obraz' => [['thumb' => 'to nie jest obraz']],
        ];
    }

    /** @param array<string, array{int, int}|string> $warianty */
    #[DataProvider('zleMiniatury')]
    public function test_bez_prawdziwej_miniatury_zdjecie_nie_wychodzi(array $warianty): void
    {
        $autor = $this->user('stare');
        $wpis = $this->wpis($autor, '');
        $wpis->media()->attach($this->zdjecie($autor, $warianty));
        $log = Log::spy();

        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        $this->assertSame([], $this->wyslaneObrazy(), 'Do OpenAI wyszło zdjęcie większe niż miniatura.');
        // Pominięcie nie jest ciche: moderator nie wie, że zdjęcia nikt nie
        // oglądał, więc musi to zostać przynajmniej w dzienniku.
        $log->shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // 3. AWATAR BEZ POTWIERDZONEJ ZGODY NIE WYCHODZI WCALE
    // ---------------------------------------------------------------

    public function test_awatar_z_poprawna_miniatura_nie_wychodzi(): void
    {
        $osoba = $this->user('awatar');
        $zdjecie = $this->zdjecie($osoba, ['thumb' => [320, 320]]);
        Profile::query()->where('user_id', $osoba->getKey())->update(['avatar_media_id' => $zdjecie->getKey()]);

        // Zadanie mogło zostać w kolejce sprzed tej zmiany — też nie wysyła.
        dispatch_sync(new PrzeanalizujAwatar((string) $zdjecie->getKey()));

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    public function test_wgranie_awatara_nie_wysyla_niczego_do_openai(): void
    {
        $osoba = $this->user('wgrywa');

        // Kolejka `sync` z `phpunit.xml`: wszystko, co zostałoby zlecone,
        // wykonuje się tu i teraz, także przetwarzanie wariantów.
        $this->actingAs($osoba)
            ->post(route('settings.avatar.update'), ['avatar' => UploadedFile::fake()->image('ja.jpg', 800, 800)])
            ->assertRedirect(route('settings.avatar'));

        $this->assertNotNull($osoba->refresh()->profile->avatar_media_id);
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // 4. BRAK KLUCZA I AWARIA NIE UDAJĄ „SPRAWDZONE, CZYSTE"
    // ---------------------------------------------------------------

    public function test_brak_klucza_na_produkcji_zostawia_slad_a_lokalne_sygnaly_dzialaja(): void
    {
        config(['kuking.moderation.model.klucz' => null]);
        $this->app->detectEnvironment(fn (): string => 'production');
        $log = Log::spy();

        $wpis = $this->wpis($this->user('bezklucza'), 'Zarabiaj z domu, tel. 600 100 200');
        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        Http::assertNothingSent();
        $this->assertSame(1, $this->oznaczenia($wpis), 'Bez klucza przestały działać także lokalne sygnały.');
        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc, array $kontekst = []): bool => ($kontekst['stage'] ?? null) === 'openai_disabled',
        )->once();
    }

    /** @return array<string, array{int, mixed}> */
    public static function awarie(): array
    {
        return [
            'HTTP 503' => [503, ''],
            'puste wyniki' => [200, ['results' => [['category_scores' => []]]]],
            'wynik jako napis' => [200, ['results' => [['category_scores' => ['hate' => 'wysoki']]]]],
            'wynik poza skala' => [200, ['results' => [['category_scores' => ['hate' => 7]]]]],
            'same nieznane kategorie' => [200, ['results' => [['category_scores' => ['cos/nowego' => 0.1]]]]],
        ];
    }

    #[DataProvider('awarie')]
    public function test_awaria_dostawcy_zostawia_slad_i_nie_udaje_czystej_oceny(int $status, mixed $cialo): void
    {
        $this->odpowiedz = fn () => Http::response($cialo, $status);
        $log = Log::spy();

        $wpis = $this->wpis($this->user('awaria'), 'Zarabiaj z domu, tel. 600 100 200');
        $this->analizuj(PrzeanalizujTresc::TYP_WPIS, $wpis);

        Http::assertSentCount(1);
        $this->assertSame(1, $this->oznaczenia($wpis), 'Awaria modelu wyłączyła lokalne sygnały.');
        $log->shouldHaveReceived('warning')->atLeast()->once();
    }

    // ---------------------------------------------------------------
    // POMOCNICZE
    // ---------------------------------------------------------------

    private function wpis(User $autor, string $tekst): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    private function zmienWidocznosc(Post|Recipe $tresc, string $widocznosc): void
    {
        if ($tresc instanceof Post) {
            app(EditPost::class)->handle($tresc->author, $tresc, $tresc->body, $widocznosc);

            return;
        }

        $tresc->forceFill(['visibility' => $widocznosc])->save();
    }

    /**
     * Zdjęcie z PRAWDZIWYMI bajtami wariantów, bo granica mierzy bajty.
     *
     * @param  array<string, array{int, int}|string>  $warianty
     */
    private function zdjecie(User $wlasciciel, array $warianty): Media
    {
        $metadane = [];

        foreach ($warianty as $nazwa => $wymiary) {
            $klucz = 'media/test/'.Str::uuid()->toString().'_'.$nazwa.'.webp';
            $bajty = is_string($wymiary)
                ? $wymiary
                : (string) ImageManager::gd()->create($wymiary[0], $wymiary[1])->fill('cc4400')->toWebp();
            Storage::disk('public')->put($klucz, $bajty);
            // Metadane celowo „kłamią" o wymiarach — granica ma im nie ufać.
            $metadane[$nazwa] = ['key' => $klucz, 'width' => 320, 'height' => 240];
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => null,
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $metadane],
        ]);
    }

    private function analizuj(string $typ, Post|Comment $tresc): void
    {
        $this->app->call([new PrzeanalizujTresc($typ, (string) $tresc->getKey()), 'handle']);
    }

    private function oznaczenia(Post|Comment $tresc): int
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_id', $tresc->getKey())
            ->count();
    }

    /**
     * Wymiary i typ obrazów, które NAPRAWDĘ wyszły w żądaniach do atrapy.
     *
     * @return list<array{int, int, int}>
     */
    private function wyslaneObrazy(): array
    {
        $wynik = [];

        foreach (Http::recorded() as [$zadanie]) {
            $adres = $zadanie['input'][0]['image_url']['url'] ?? null;

            if (! is_string($adres)) {
                continue;
            }

            $rozmiar = getimagesizefromstring((string) base64_decode(explode(',', $adres, 2)[1] ?? ''));
            $wynik[] = $rozmiar === false ? [0, 0, 0] : [$rozmiar[0], $rozmiar[1], $rozmiar[2]];
        }

        return $wynik;
    }
}
