<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Kuking na dziś" zmienia się o północy czasu polskiego, nie o 02:00.
 *
 * TA SAMA KLASA BŁĘDU CO WE WSPOMNIENIACH I W ARCHIWUM PROFILU
 * `shown_on` jest zwykłą kolumną `date` — bez strefy — a i zapis
 * (`now()->toDateString()`), i odczyt (`whereDate('shown_on', now())`) liczyły
 * „dziś" przez `now()`, czyli w `app.timezone` = UTC. Aplikacja MUSI liczyć
 * w UTC (`App\Support\Czas` tłumaczy dlaczego), ale „dzień" na tablicy dnia
 * jest pojęciem człowieka, nie serwera.
 *
 * DWIE RZECZY, KTÓRE Z TEGO WYNIKAŁY
 * 1. Tablica zmieniała się o 02:00 czasu polskiego (01:00 zimą), nie o północy.
 * 2. Gorsze: gospodarz układający tablicę PO PÓŁNOCY zapisywał ją pod datą
 *    dnia poprzedniego. Widział ją jeszcze przez godzinę-dwie, a potem
 *    znikała — z jego punktu widzenia bez powodu, tego samego dnia, którego
 *    ją ustawił. Ten test odtwarza dokładnie ten przebieg.
 *
 * Znalezione przy przeszukaniu kodu pod kątem tej samej klasy błędu po
 * `Wspomnieniach` (`docs/legal/BRAMKA_BETY.md` §7).
 */
class TablicaDniaLiczyDzienLokalnieTest extends TestCase
{
    use RefreshDatabase;

    public function test_tablica_ulozona_po_polnocy_zostaje_na_caly_ten_dzien(): void
    {
        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos');
        $wpis = Post::factory()->create(['author_id' => $ktos->getKey()]);

        // 8 września 2026, 00:30 czasu polskiego = 7 września 22:30 UTC.
        $this->travelTo(Carbon::parse('2026-09-07 22:30:00', 'UTC'));

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), [
            'wpisy' => [$wpis->getKey()],
        ])->assertRedirect();

        $this->assertSame(
            '2026-09-08',
            DailyPick::query()->first()?->shown_on?->toDateString(),
            'Tablica ułożona o 00:30 czasu polskiego zapisała się pod datą dnia '
            .'poprzedniego. Gospodarz układa „dziś", a nie „wczoraj".',
        );

        // Ten sam dzień, rano — tablica ma dalej być.
        $this->travelTo(Carbon::parse('2026-09-08 08:00:00', 'UTC'));

        $this->assertCount(
            1,
            DailyPick::query()->forDate()->get(),
            'Tablica ułożona po północy zniknęła tego samego dnia rano. Zapis '
            .'i odczyt liczyły „dziś" w UTC, więc rozjeżdżały się o dwie godziny.',
        );
    }

    public function test_tablica_ulozona_wieczorem_znika_o_polnocy_czasu_polskiego(): void
    {
        $gospodarz = $this->moderator();
        $ktos = $this->user('ktos');
        $wpis = Post::factory()->create(['author_id' => $ktos->getKey()]);

        // 7 września, 21:00 czasu polskiego = 19:00 UTC.
        $this->travelTo(Carbon::parse('2026-09-07 19:00:00', 'UTC'));

        $this->actingAs($gospodarz)->put(route('admin.daily-board'), [
            'wpisy' => [$wpis->getKey()],
        ])->assertRedirect();

        $this->assertCount(1, DailyPick::query()->forDate()->get());

        // 8 września, 00:30 czasu polskiego = 7 września 22:30 UTC. Nowy dzień
        // po polsku, więc wczorajsza tablica ma już nie obowiązywać.
        $this->travelTo(Carbon::parse('2026-09-07 22:30:00', 'UTC'));

        $this->assertCount(
            0,
            DailyPick::query()->forDate()->get(),
            'Po polskiej północy tablica dnia dalej pokazywała wybór z wczoraj. '
            .'„Kuking na dziś" ma się zmieniać o północy, którą widzi człowiek.',
        );
    }
}
