<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Domain\Social\ListyWidza;
use App\Domain\Social\SkrotyObserwowania;
use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Start (`GET /`) czyta listy widza — obserwowane osoby, obserwowane tematy
 * i blokady — RAZ na żądanie (W7, audyt wydajności).
 *
 * Przed zmianą ta sama lista obserwowanych osób szła z bazy cztery razy
 * (wybór źródła feedu, strona feedu, tablica dnia, skróty w menu kart),
 * a blokady w obie strony trzy razy. Teraz tablica dnia i skróty biorą listy
 * z `ListyWidza`: osoby dwa razy (feed czyta je świeżo, #983), blokady raz. Druga połowa testu pilnuje ceny tej
 * pamięci — nieświeżości: każda akcja zmieniająca obserwowanie, blokadę albo
 * tematy ma uniewaznić pamięć TEGO SAMEGO żądania.
 */
final class StartListyWidzaBezPowtorekTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_czyta_obserwowanych_tematy_i_blokady_po_jednym_razie(): void
    {
        $widz = $this->user(null);
        $obserwowany = $this->user(null);
        $blokowany = $this->user(null);
        $blokujacy = $this->user(null);
        $widz->following()->attach($obserwowany->getKey(), ['created_at' => now()]);
        DB::table('blocks')->insert(['blocker_id' => $widz->getKey(), 'blocked_id' => $blokowany->getKey(), 'created_at' => now()]);
        DB::table('blocks')->insert(['blocker_id' => $blokujacy->getKey(), 'blocked_id' => $widz->getKey(), 'created_at' => now()]);
        $tag = Tag::factory()->create();
        DB::table('tag_follows')->insert(['user_id' => $widz->getKey(), 'tag_id' => $tag->getKey(), 'created_at' => now()]);
        $wpis = Post::factory()->for($obserwowany, 'author')->create(['body' => 'Wpis obserwowanego z Startu']);

        // Rozgrzewka: tablica dnia trzyma kandydatów w cache, więc pomiar
        // bierzemy z ciepłym cache (inaczej liczba zapytań zależy od kolejności).
        Cache::flush();
        $this->actingAs($widz)->get(route('landing'))->assertOk();

        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            $zapytania[] = $zapytanie->sql;
        });

        $html = $this->actingAs($widz)->get(route('landing'))->assertOk()->getContent();

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): Start pokazuje wpis
        // z feedu obserwowanych, więc pomiar obejmuje feed, a nie pusty ekran.
        $this->assertStringContainsString('Wpis obserwowanego z Startu', $html);
        $this->assertNotNull($wpis);

        $ile = fn (string $wzorzec): int => count(array_filter($zapytania, fn (string $sql): bool => preg_match($wzorzec, $sql) === 1));

        // Feed czyta obserwowane osoby świeżo dwa razy (wybór źródła i strona,
        // #983) i zasila tym pamięć; tablica dnia i skróty w menu już z niej
        // korzystają (przedtem cztery odczyty).
        $this->assertSame(2, $ile('/inner join "follows"/'), 'Obserwowane osoby czytane częściej niż dwa razy na żądanie.');
        // Tematy: dwa świeże odczyty feedu (podzapytanie) + jeden odczyt
        // skrótów w menu (złączenie) — bez zmian, feed ma je czytać świeżo.
        $this->assertSame(3, $ile('/"tag_follows"/'), 'Obserwowane tematy czytane częściej niż trzy razy na żądanie.');
        $this->assertSame(1, $ile('/^select "blocked_id" from "blocks"/'), 'Blokady widza czytane więcej niż raz na żądanie.');
        $this->assertSame(1, $ile('/^select "blocker_id" from "blocks"/'), 'Blokady na widzu czytane więcej niż raz na żądanie.');
        // Tablica dnia liczyła blokady sama (dwa razy po dwa zapytania).
        $this->assertSame(0, $ile('/^select "users"."id" from "users" inner join "blocks"/'), 'Tablica dnia znów czyta blokady poza wspólną pamięcią.');
    }

    public function test_obserwowanie_w_tym_samym_zadaniu_uniewaznia_pamiec(): void
    {
        $widz = $this->user(null);
        $inna = $this->user(null);
        $listy = app(ListyWidza::class);

        $this->assertSame([], $listy->osoby($widz));

        app(FollowUser::class)->handle($widz, $inna);
        $this->assertSame([$inna->getKey()], $listy->osoby($widz));

        app(UnfollowUser::class)->handle($widz, $inna);
        $this->assertSame([], $listy->osoby($widz));
    }

    public function test_blokada_i_odblokowanie_w_tym_samym_zadaniu_uniewaznia_pamiec(): void
    {
        $widz = $this->user(null);
        $inna = $this->user(null);
        $listy = app(ListyWidza::class);
        $skroty = app(SkrotyObserwowania::class);

        $widz->following()->attach($inna->getKey(), ['created_at' => now()]);
        $this->assertSame([$inna->getKey()], $listy->osoby($widz));
        $this->assertSame([], $listy->blokady($widz));
        $this->assertTrue($skroty->obserwuje($widz, $inna));

        app(BlockUser::class)->handle($widz, $inna);
        $this->assertSame([$inna->getKey()], $listy->blokady($widz));
        $this->assertSame([], $listy->osoby($widz), 'Blokada kasuje obserwowanie — pamięć nie może go pamiętać.');
        $this->assertFalse($skroty->obserwuje($widz, $inna));
        $this->assertFalse($skroty->osobaDoObserwowania($widz, $inna));

        app(UnblockUser::class)->handle($widz, $inna);
        $this->assertSame([], $listy->blokady($widz));
        $this->assertTrue($skroty->osobaDoObserwowania($widz, $inna));
    }

    public function test_zmiana_tematow_w_tym_samym_zadaniu_uniewaznia_pamiec(): void
    {
        $widz = $this->user(null);
        $tag = Tag::factory()->create();
        $listy = app(ListyWidza::class);

        $this->assertSame([], $listy->tagiSurowe($widz));

        app(UpdateTagFollows::class)->follow($widz, [$tag->getKey()]);
        $this->assertSame([$tag->getKey()], $listy->tagiSurowe($widz));

        app(UpdateTagFollows::class)->unfollow($widz, $tag->getKey());
        $this->assertSame([], $listy->tagiSurowe($widz));
    }

    public function test_pamiec_jest_osobna_dla_kazdego_widza(): void
    {
        $pierwszy = $this->user(null);
        $drugi = $this->user(null);
        $inna = $this->user(null);
        $pierwszy->following()->attach($inna->getKey(), ['created_at' => now()]);
        $listy = app(ListyWidza::class);

        $this->assertSame([$inna->getKey()], $listy->osoby($pierwszy));
        $this->assertSame([], $listy->osoby($drugi));
        $this->assertSame([$inna->getKey()], $listy->osoby($pierwszy));
    }
}
