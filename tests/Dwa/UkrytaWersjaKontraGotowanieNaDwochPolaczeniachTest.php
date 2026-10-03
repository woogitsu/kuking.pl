<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Gotowanie\WersjaWykonania;
use App\Domain\Recipes\Historia\UkrywanieWersji;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Formularz pobrał v1, autor zatwierdził jej ukrycie, dopiero potem
 * przyszło ścisłe żądanie zapisu. Baza wyścigów zatwierdza każdy etap naprawdę.
 */
#[Group('dwa-polaczenia')]
final class UkrytaWersjaKontraGotowanieNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('recipe_versions')->whereIn('editor_id', $this->konta)->delete();
        parent::tearDown();
    }

    public function test_zatwierdzone_ukrycie_po_wstepnej_kontroli_odmawia_scislego_wykonania(): void
    {
        $autor = $this->konto(['email' => 'race-'.bin2hex(random_bytes(12)).'@example.invalid']);
        $kucharz = $this->konto(['email' => 'race-'.bin2hex(random_bytes(12)).'@example.invalid']);
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public', 'published_at' => now()->subDay(),
        ]);
        $v1 = app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $autor, 'pierwsza');
        $proba = app(RecordCookedEvent::class)->handle(cook: $kucharz, recipe: $przepis->fresh());
        $przepis->forceFill(['title' => 'Druga wersja'])->save();
        app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $autor, 'druga');

        $this->assertSame($v1->getKey(), WersjaWykonania::dla($proba, $kucharz)?->getKey());
        $przed = CookedEvent::query()->where('recipe_id', $przepis->getKey())->count();
        $powiadomienia = Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count();

        $this->assertSame(UkrywanieWersji::UKRYTO, app(UkrywanieWersji::class)->ukryj($autor, $przepis, 1));
        $odczyt = $this->nowePolaczenie()->prepare('SELECT hidden_at IS NOT NULL FROM recipe_versions WHERE id = ?');
        $odczyt->execute([$v1->getKey()]);
        $this->assertTrue((bool) $odczyt->fetchColumn(), 'Osobne połączenie widzi zatwierdzone ukrycie.');

        try {
            app(RecordCookedEvent::class)->handle(
                cook: $kucharz, recipe: $przepis->fresh(), note: 'Po ukryciu',
                wersjaPrzepisuId: (string) $v1->getKey(), wersjaScisla: true,
            );
            $this->fail('GOTUJ_2808_PO_COMMIT: ścisły zapis przyjął ukrytą wersję.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame($przed, CookedEvent::query()->where('recipe_id', $przepis->getKey())->count());
            $this->assertSame($powiadomienia, Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());
        }
    }
}
