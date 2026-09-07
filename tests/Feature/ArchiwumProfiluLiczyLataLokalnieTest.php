<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archiwum profilu przypisuje wpis do roku, w którym POWSTAŁ LOKALNIE.
 *
 * TEN SAM BŁĄD CO WE WSPOMNIENIACH, TYLKO O ROK
 * `published_at` jest kolumną `timestamptz`, a `extract(year from …)` bez
 * `at time zone` czyta ją w UTC. Aplikacja liczy i zapisuje w UTC
 * (`app.timezone`), ale człowiek żyje w `Europe/Warsaw` — i tę drugą strefę
 * widzi wszędzie na ekranie, bo daty renderuje `App\Support\Czas`.
 *
 * Skutek: wpis z sylwestrowej nocy, opublikowany 1 stycznia o 00:30 czasu
 * polskiego (31 grudnia 23:30 UTC), lądował w archiwum pod POPRZEDNIM rokiem.
 * Na tej samej stronie karta wpisu pokazywała przy nim „1 stycznia 2026" —
 * pod nagłówkiem „2025". Dotyczy każdego wpisu z przedziału 00:00–02:00 czasu
 * polskiego 1 stycznia (00:00–01:00 zimą, czyli akurat wtedy: 1 stycznia jest
 * zawsze w czasie zimowym, więc okno to 00:00–01:00).
 *
 * Znalezione przez przeszukanie kodu pod kątem tej samej klasy błędu, po tym
 * jak identyczna usterka wyszła we `Wspomnieniach`
 * (`docs/legal/BRAMKA_BETY.md` §7). Ta klasa wraca, bo `extract()` wygląda
 * niewinnie i nikt nie pamięta, że kolumna niesie strefę.
 */
class ArchiwumProfiluLiczyLataLokalnieTest extends TestCase
{
    use RefreshDatabase;

    public function test_wpis_z_nocy_sylwestrowej_jest_w_roku_lokalnym_a_nie_w_utc(): void
    {
        $basia = $this->user('basia');

        // 1 stycznia 2026, 00:30 czasu polskiego = 31 grudnia 2025, 23:30 UTC.
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => Carbon::parse('2025-12-31 23:30:00', 'UTC'),
        ]);

        // Drugi wpis, żeby lista lat w ogóle się renderowała (widok pokazuje ją
        // dopiero od dwóch lat) i żeby było widać, że filtr rozróżnia lata.
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => Carbon::parse('2024-06-15 12:00:00', 'UTC'),
        ]);

        $odpowiedz = $this->get(route('profile.show', ['username' => 'basia']));
        $odpowiedz->assertOk();

        /** @var iterable<int, int> $lata */
        $lata = $odpowiedz->viewData('lata');
        $lata = collect($lata)->all();

        $this->assertContains(
            2026,
            $lata,
            'Wpis z 1 stycznia 00:30 czasu polskiego nie utworzył w archiwum roku 2026. '
            .'`extract(year from published_at)` bez `at time zone` czyta kolumnę '
            .'`timestamptz` w UTC, a człowiek widzi przy tym wpisie datę lokalną.',
        );

        $this->assertNotContains(
            2025,
            $lata,
            'W archiwum pojawił się rok 2025, w którym — po polsku — nie ma ani '
            .'jednego wpisu. To ten sam wpis policzony w niewłaściwej strefie.',
        );
    }

    public function test_filtr_roku_znajduje_wpis_z_nocy_sylwestrowej(): void
    {
        $basia = $this->user('basia');

        $sylwester = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => Carbon::parse('2025-12-31 23:30:00', 'UTC'),
        ]);

        $w2026 = $this->get(route('profile.show', ['username' => 'basia', 'rok' => 2026]));
        $w2026->assertOk();

        $this->assertContains(
            $sylwester->getKey(),
            collect($w2026->viewData('posts')->items())->map(fn ($p) => $p->getKey())->all(),
            'Filtr „2026" nie znalazł wpisu, który po polsku powstał 1 stycznia 2026.',
        );

        $w2025 = $this->get(route('profile.show', ['username' => 'basia', 'rok' => 2025]));
        $w2025->assertOk();

        $this->assertSame(
            [],
            collect($w2025->viewData('posts')->items())->map(fn ($p) => $p->getKey())->all(),
            'Filtr „2025" pokazał wpis, którego w polskim 2025 roku nie ma.',
        );
    }
}
