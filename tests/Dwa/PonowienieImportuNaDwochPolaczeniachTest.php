<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\WpisZgody;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** Dwa kliknięcia starego „Spróbuj jeszcze raz” mogą uruchomić tylko jeden płatny odczyt. */
#[Group('dwa-polaczenia')]
final class PonowienieImportuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private ?string $szkicId = null;

    protected function tearDown(): void
    {
        if ($this->szkicId !== null) {
            $importy = DB::table('importy_przepisow')->where('recipe_id', $this->szkicId)->pluck('id');
            foreach ($importy as $importId) {
                DB::table('jobs')->where('payload', 'like', '%'.$importId.'%')->delete();
            }
        }

        parent::tearDown();
    }

    public function test_dwa_rownolegle_ponowienia_zwroca_jedno_zlecenie_i_jedno_zadanie(): void
    {
        $osoba = $this->konto();
        app(PrzestawZgodeNaOdczytAi::class)->handle($osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $osoba->getKey(),
            'visibility' => 'private',
        ]);
        $this->szkicId = (string) $szkic->getKey();
        $poprzednie = new ImportPrzepisu;
        $poprzednie->forceFill([
            'user_id' => $osoba->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY,
        ])->save();

        // Oba procesy muszą stanąć na rzeczywistej blokadzie importów tej osoby.
        // Stary kod też miał tę blokadę, lecz po kolei zapisywał DWA retry.
        $bariera = $this->bariera(
            "SELECT pg_advisory_xact_lock(hashtext('kuking:import:' || ?))",
            [(string) $osoba->getKey()],
        );
        $argumenty = ['kto' => (string) $osoba->getKey(), 'poprzednie' => (string) $poprzednie->getKey()];
        $pierwszy = $this->wTle('ponow-import', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('ponow-import', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $a = $pierwszy->wynik();
        $b = $drugi->wynik();
        $this->assertBezZakleszczenia($a, 'pierwszy retry');
        $this->assertBezZakleszczenia($b, 'drugi retry');
        $this->assertTrue($a['ok'], $a['komunikat']);
        $this->assertTrue($b['ok'], $b['komunikat']);
        $this->assertSame($a['wartosc'], $b['wartosc']);
        $this->assertSame(2, DB::table('importy_przepisow')->where('recipe_id', $szkic->getKey())->count());
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%'.$a['wartosc'].'%')->count());
        $this->assertSame((string) $poprzednie->getKey(), DB::table('importy_przepisow')->where('id', $a['wartosc'])->value('klucz_wyslania'));
    }
}
