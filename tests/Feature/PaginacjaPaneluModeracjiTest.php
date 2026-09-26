<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kolejki panelu moderacji stronicują widokiem, który naprawdę widać.
 *
 * SKĄD TEN TEST (audyt B1, znalezisko 1; B9 §2)
 * Pięć ekranów panelu wołało `{{ $x->links() }}`, czyli gotowy widok Laravela
 * `pagination::tailwind`. Ten opiera się na klasach Tailwinda, których nasz
 * build nie zna, bo Tailwind 4 nie skanuje `vendor/`. Na komputerze blok
 * nawigacji miał `hidden sm:flex`, a `sm:flex` w CSS nie istniało: pod listą
 * 25 zgłoszeń nie było niczego i 15 kolejnych leżało niewidocznych. W HTML-u
 * stało przy tym „Showing 26 to 50 of 100 results” i „Pagination Navigation”.
 *
 * KONTROLA DODATNIA
 * Pierwszy test niżej zakłada 26 zgłoszeń i sprawdza, że link do strony 2
 * JEST — gdyby komponent nic nie rysował, zieleń asercji „nie ma Showing”
 * byłaby pusta. Zmierzone: z przywróconym `{{ $reports->links() }}` test
 * oblewa na „Następna strona” w `.btn` i na „Showing”.
 */
class PaginacjaPaneluModeracjiTest extends TestCase
{
    use RefreshDatabase;

    private const EKRANY_PANELU = [
        'pages/admin/reports.blade.php',
        'pages/admin/appeals.blade.php',
        'pages/admin/sygnaly.blade.php',
        'pages/admin/uzytkownicy.blade.php',
        'pages/admin/wiadomosci.blade.php',
    ];

    public function test_kolejka_zgloszen_ma_druga_strone_widoczna_i_po_polsku(): void
    {
        for ($i = 0; $i < 26; $i++) {
            Report::create([
                'target_type' => 'post',
                'target_id' => (string) Str::uuid7(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);
        }

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.reports'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<a class="btn btn-secondary" href="[^"]*page=2[^"]*" rel="next">Następna strona</a>~',
            $html,
            'Pod kolejką 26 zgłoszeń nie ma przycisku do strony 2.',
        );
        $this->assertStringContainsString('Strona 1 z 2', $html);
        $this->assertStringContainsString('aria-label="Strony listy"', $html);

        foreach (['Showing', 'results', 'Pagination Navigation', 'Go to page', 'sm:flex', 'sm:hidden'] as $obce) {
            $this->assertStringNotContainsString($obce, $html, "W kolejce zgłoszeń stoi „{$obce}” z domyślnego widoku Laravela.");
        }
    }

    public function test_srodkowa_strona_ma_oba_przyciski_a_pierwsza_i_ostatnia_bez_martwego(): void
    {
        $srodek = $this->wyrenderuj(page: 2);
        $this->assertStringContainsString('rel="prev">Poprzednia strona</a>', $srodek);
        $this->assertStringContainsString('Strona 2 z 4', $srodek);
        $this->assertStringContainsString('rel="next">Następna strona</a>', $srodek);

        $pierwsza = $this->wyrenderuj(page: 1);
        $this->assertStringNotContainsString('Poprzednia strona', $pierwsza);
        $this->assertStringContainsString('Następna strona', $pierwsza);

        $ostatnia = $this->wyrenderuj(page: 4);
        $this->assertStringContainsString('Poprzednia strona', $ostatnia);
        $this->assertStringNotContainsString('Następna strona', $ostatnia);

        $this->assertSame('', trim($this->wyrenderuj(page: 1, lacznie: 10)), 'Jedna strona nie potrzebuje nawigacji.');
    }

    public function test_zaden_ekran_panelu_nie_wraca_do_domyslnego_widoku_laravela(): void
    {
        foreach (self::EKRANY_PANELU as $widok) {
            $tresc = (string) file_get_contents(resource_path('views/'.$widok));

            $this->assertStringNotContainsString('->links(', $tresc, "{$widok}: `links()` renderuje widok Tailwinda, którego na komputerze nie widać.");
            $this->assertStringContainsString('<x-paginacja-panelu', $tresc, "{$widok}: brak stronicowania panelu.");
        }
    }

    private function wyrenderuj(int $page, int $lacznie = 100): string
    {
        $paginator = new LengthAwarePaginator(range(1, 25), $lacznie, 25, $page, ['path' => '/admin/zgloszenia']);

        return Blade::render('<x-paginacja-panelu :paginator="$p" />', ['p' => $paginator]);
    }
}
