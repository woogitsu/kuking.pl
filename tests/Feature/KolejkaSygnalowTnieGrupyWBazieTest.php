<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Kolejka sygnałów pobiera tylko te pozycje, które pokazuje (issue #1060).
 *
 * CO SIĘ DZIAŁO
 * `/admin/sygnaly` pokazuje 20 grup po najwyżej 10 pozycji, ale
 * `SygnalyController::pozycje()` kończyło się nieograniczonym `get()` —
 * jedno konto z tysiącem oznaczeń dawało tysiąc zgłoszeń w pamięci,
 * a `podglady()` budowało tysiąc podglądów z wpisami i mediami. Widok
 * obcinał to do dziesięciu dopiero przy wypisywaniu.
 *
 * DLACZEGO LICZYMY MODELE, NIE ZAPYTANIA
 * `KolejkiModeracjiBezWachlarzaZapytanTest` pilnuje N+1 i zostaje, ale
 * usterka nie dodawała zapytań — dodawała WIERSZE do tego samego zapytania.
 * Sam brak nadmiarowych tekstów w HTML też by jej nie złapał, bo widok
 * i tak obcinał do dziesięciu. Liczymy więc hydratowane `Report` i `Post`.
 */
class KolejkaSygnalowTnieGrupyWBazieTest extends TestCase
{
    use RefreshDatabase;

    public function test_duze_grupy_pobieraja_po_dziesiec_pozycji_a_licznik_zostaje_pelny(): void
    {
        $moderator = $this->moderator();

        // Trzy nierówne grupy na górze kolejki i grupa bez autora.
        $duza = $this->user('duza_grupa', ['display_name' => 'Duża Grupa']);
        $this->oznaczenia($duza, 25, 'Duża');
        $this->oznaczenia(null, 12, 'Bezpańska');
        $mala = $this->user('mala_grupa', ['display_name' => 'Mała Grupa']);
        $this->oznaczenia($mala, 3, 'Mała');

        // Siedemnaście pojedynczych grup dopełnia stronę do 20, a jedna —
        // najstarsza, „Pojedyncza 0" — wypada na drugą: jej wpis nie może
        // zostać pobrany.
        for ($i = 0; $i < 18; $i++) {
            $this->oznaczenia($this->user(), 1, 'Pojedyncza '.$i, przesuniecie: $i);
        }

        $zgloszen = 0;
        $wpisow = 0;
        Event::listen('eloquent.retrieved: '.Report::class, function () use (&$zgloszen): void {
            $zgloszen++;
        });
        Event::listen('eloquent.retrieved: '.Post::class, function () use (&$wpisow): void {
            $wpisow++;
        });

        $html = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()->getContent();

        // KONTROLE DODATNIE: pełne liczniki z agregatu i właściwe pozycje.
        $this->assertStringContainsString('25 oznaczeń', $html);
        $this->assertStringContainsString('…i jeszcze 15 z tego samego konta.', $html);
        $this->assertStringContainsString('…i jeszcze 2 z tego samego konta.', $html);
        // Kolejność jak dotąd: przy równej wadze najnowsze na górze.
        $this->assertStringContainsString('Duża numer 24,', $html);
        $this->assertStringContainsString('Duża numer 15,', $html);
        $this->assertStringNotContainsString('Duża numer 14,', $html);
        $this->assertStringContainsString('Bezpańska numer 11,', $html);
        $this->assertStringNotContainsString('Bezpańska numer 1,', $html);
        $this->assertStringContainsString('Mała numer 0,', $html);
        $this->assertStringNotContainsString('Pojedyncza 0 numer', $html);

        // 20 wierszy agregatu grup + (10 + 10 + 3 + 17) pozycji.
        $this->assertLessThanOrEqual(60, $zgloszen, "Kolejka zmaterializowała {$zgloszen} zgłoszeń zamiast najwyżej 60.");
        $this->assertLessThanOrEqual(40, $wpisow, "Kolejka pobrała {$wpisow} wpisów do podglądów zamiast najwyżej 40.");
    }

    private function oznaczenia(?User $autor, int $ile, string $etykieta, ?int $przesuniecie = null): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $wpis = Post::factory()->for($autor ?? $this->user(), 'author')->create([
                'body' => $etykieta.' numer '.$i.', zupa jak u mamy.',
            ]);

            $zgloszenie = Report::create([
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'subject_user_id' => $autor?->getKey(),
                'autor_tresci_id' => $autor?->getKey(),
                'source' => Report::SOURCE_AUTOMAT,
                'status' => Report::STATUS_OPEN,
                'reason' => OcenaModelem::KOD,
            ]);

            // Rosnące daty w obrębie grupy — „numer 24" jest najnowszy.
            // Pojedyncze grupy dostają osobne, starsze daty, żeby kolejność
            // grup (i to, która wypada na drugą stronę) nie zależała od remisu.
            $zgloszenie->forceFill([
                'created_at' => $przesuniecie === null
                    ? now()->subDay()->addMinutes($i)
                    : now()->subDays(30)->addMinutes($przesuniecie),
            ])->save();
        }
    }
}
