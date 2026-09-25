<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kolejka sygnałów rozwija najwyżej dziesięć pozycji z grupy — i najwyżej
 * tyle wyciąga z bazy (issue #1060).
 *
 * Wcześniej `SygnalyController::pozycje()` pobierał WSZYSTKIE otwarte
 * oznaczenia kont z danej strony, a widok obcinał je `take(10)`. Konto
 * z trzystoma oznaczeniami kosztowało trzysta modeli i trzysta podglądów
 * treści na każde otwarcie ekranu — dokładnie przy fali spamu, kiedy
 * moderator tego ekranu potrzebuje.
 */
class KolejkaSygnalowLimitNaGrupeTest extends TestCase
{
    use RefreshDatabase;

    private function oznaczenie(User $autor, string $powod, string $kiedy): Report
    {
        $wpis = Post::factory()->for($autor, 'author')->create([
            'body' => 'Ciasta na zamówienie, oferta '.$kiedy.'.',
            'status' => Post::STATUS_PUBLISHED,
        ]);

        // Fabryki dla `Report` nie ma — patrz `KolejkaSygnalowPokazujePodgladTest`.
        $oznaczenie = Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'autor_tresci_id' => $autor->getKey(),
            'source' => Report::SOURCE_AUTOMAT,
            'status' => Report::STATUS_OPEN,
            'reason' => $powod,
        ]);
        $oznaczenie->forceFill(['created_at' => $kiedy])->save();

        return $oznaczenie;
    }

    #[Test]
    public function test_grupa_wieksza_niz_dziesiec_wyciaga_z_bazy_tylko_dziesiec_najwazniejszych(): void
    {
        $spamer = User::factory()->hasProfile()->create();
        $drugi = User::factory()->hasProfile()->create();

        // Najcięższy sygnał jest NAJSTARSZY — gdyby limit szedł po samej
        // dacie, wypadłby z pierwszej dziesiątki.
        $najciezszy = $this->oznaczenie($spamer, 'automat_wzorzec', '2026-09-01 08:00:00');

        foreach (range(1, 14) as $i) {
            $this->oznaczenie($spamer, 'automat_odnosnik', sprintf('2026-09-02 %02d:00:00', $i));
        }

        $pojedyncze = $this->oznaczenie($drugi, 'automat_odnosnik', '2026-09-03 08:00:00');

        $wczytanych = 0;
        Report::retrieved(function () use (&$wczytanych): void {
            $wczytanych++;
        });

        $odpowiedz = $this->actingAs($this->moderator())
            ->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee('15 oznaczeń')
            ->assertSee('…i jeszcze 5 z tego samego konta.');

        /** @var Collection<string, Collection<int, Report>> $pozycje */
        $pozycje = $odpowiedz->viewData('pozycje');

        $grupaSpamera = $pozycje->get((string) $spamer->getKey());
        $this->assertCount(10, $grupaSpamera);
        $this->assertSame((string) $najciezszy->getKey(), (string) $grupaSpamera->first()->getKey(),
            'Najcięższy sygnał prowadzi grupę — limit liczony w tej samej kolejności co widok.');

        // Pozostałe dziewięć to najnowsze lżejsze (godziny 14…6), bez żadnego starszego.
        $this->assertSame(
            array_map(static fn (int $h): string => sprintf('2026-09-02 %02d:00:00', $h), range(14, 6)),
            $grupaSpamera->slice(1)->map(static fn (Report $r): string => $r->created_at->format('Y-m-d H:i:s'))->values()->all(),
        );

        $this->assertSame([(string) $pojedyncze->getKey()], $pozycje->get((string) $drugi->getKey())
            ->map(static fn (Report $r): string => (string) $r->getKey())->all());

        // Dwa wiersze agregatu grup + 10 + 1 pozycji. Przed poprawką:
        // 2 + 15 + 1 — każda pozycja grupy wychodziła z bazy.
        $this->assertSame(13, $wczytanych);
    }
}
