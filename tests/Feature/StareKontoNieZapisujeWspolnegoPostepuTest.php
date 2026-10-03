<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** #2879: stary model konta nie nadaje prawa do zapisu po publicznym suspend(). */
final class StareKontoNieZapisujeWspolnegoPostepuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function operacje(): array
    {
        return [
            'gospodarz-zrobiono' => ['gospodarz', 'zrobiono'],
            'gospodarz-cofnieto' => ['gospodarz', 'cofnieto'],
            'pomocnik-zrobiono' => ['pomocnik', 'zrobiono'],
            'pomocnik-cofnieto' => ['pomocnik', 'cofnieto'],
            'gospodarz-wyczysc' => ['gospodarz', 'wyczysc'],
        ];
    }

    #[DataProvider('operacje')]
    public function test_stary_model_po_zawieszeniu_odmawia_bez_zmiany_krokow_i_rewizji(string $rola, string $operacja): void
    {
        $gospodarz = $this->user();
        $pomocnik = $this->user();
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        $kroki = [];
        foreach ([0, 1] as $i) {
            $kroki[] = (string) RecipeStep::create([
                'recipe_id' => $przepis->getKey(), 'position' => $i, 'instruction' => 'Krok '.($i + 1).'.',
            ])->getKey();
        }
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $przepis);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $token);
        $konto = $rola === 'gospodarz' ? $gospodarz : $pomocnik;
        $postep = app(PostepWspolnegoGotowania::class);
        $postep->ustaw($gospodarz, $sesja, $kroki[1], true);
        if ($operacja !== 'zrobiono') {
            $postep->ustaw($gospodarz, $sesja, $kroki[0], true);
        }

        // Ta sama droga zapisuje dla aktywnego konta, zanim inne wystąpienie
        // modelu zatwierdzi zawieszenie. Potem odtwarzamy stan do odmowy.
        $rewizja = $sesja->fresh()?->revision;
        $this->zapisz($konto, $sesja, $kroki[0], $operacja);
        $this->assertSame($rewizja + 1, $sesja->fresh()?->revision);
        $postep->ustaw($gospodarz, $sesja, $kroki[1], true);
        $postep->ustaw($gospodarz, $sesja, $kroki[0], $operacja !== 'zrobiono');

        $konto->fresh()->suspend();
        $this->assertSame(User::STATUS_ACTIVE, $konto->status);
        $this->assertSame(User::STATUS_SUSPENDED, $konto->fresh()?->status);
        $this->assertTrue(Gate::forUser($konto)->allows($operacja === 'wyczysc' ? 'manage' : 'update', $sesja));
        $przed = $this->stan($sesja);
        try {
            $this->zapisz($konto, $sesja, $kroki[0], $operacja);
            $this->fail('WSPOLNE_2879_SWIEZE_KONTO_ODMOWA: stary aktywny model zapisał postęp po suspend().');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame('Konto jest zawieszone, więc możesz tylko czytać.', $e->getMessage());
        }
        $this->assertSame($przed, $this->stan($sesja), 'WSPOLNE_2879_SWIEZE_KONTO_ODMOWA: odmowa zmieniła kroki lub rewizję.');

        // GET nadal czyta. Osobno sprawdzamy istniejące prawo akcji domenowej
        // do odejścia/zakończenia; ten test nie dowodzi ich dostępności HTTP.
        $swiezeKonto = $konto->fresh();
        $this->assertNotNull($swiezeKonto);
        $this->assertFalse(Gate::forUser($swiezeKonto)->allows($operacja === 'wyczysc' ? 'manage' : 'update', $sesja));
        $this->actingAs($swiezeKonto)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        if ($rola === 'gospodarz') {
            app(SesjaWspolnegoGotowania::class)->zakoncz($swiezeKonto, $sesja);
            $this->assertNull(CookingSession::query()->find($sesja->getKey()));
            $this->assertSame(0, DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->count());
        } else {
            app(SesjaWspolnegoGotowania::class)->wyjdz($swiezeKonto, $sesja);
            $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->where('user_id', $konto->getKey())->count());
            $this->assertSame($przed['kroki'], $this->stan($sesja)['kroki']);
        }
    }

    private function zapisz(User $konto, CookingSession $sesja, string $krok, string $operacja): void
    {
        $akcja = app(PostepWspolnegoGotowania::class);
        if ($operacja === 'wyczysc') {
            $akcja->wyczysc($konto, $sesja);
        } else {
            $this->assertTrue($akcja->ustaw($konto, $sesja, $krok, $operacja === 'zrobiono'));
        }
    }

    /** @return array{rewizja: int, kroki: list<array<string, mixed>>} */
    private function stan(CookingSession $sesja): array
    {
        return [
            'rewizja' => (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision'),
            'kroki' => DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())
                ->orderBy('step_id')->get()->map(fn (object $wiersz): array => (array) $wiersz)->all(),
        ];
    }
}
