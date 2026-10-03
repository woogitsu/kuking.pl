<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Odzyskiwanie\PunktOdzyskaniaSzkicu;
use App\Models\DraftRestorePoint;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** Stary SELECT sprzątania nie może skasować punktu odnowionego przywróceniem. */
#[Group('dwa-polaczenia')]
final class OdnowionyPunktKontraSprzatanieTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $szkice = [];

    protected function tearDown(): void
    {
        if ($this->szkice !== []) {
            DB::table('recipes')->whereIn('id', $this->szkice)->delete();
        }

        parent::tearDown();
    }

    public function test_odnowienie_po_wyborze_kandydata_chroni_ten_sam_punkt(): void
    {
        $autor = $this->konto();
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Tekst A',
            'summary' => 'Rodzinny przepis.',
            'servings' => 4,
        ]);
        $this->szkice[] = (string) $szkic->getKey();
        $szkic->ingredients()->create(['ingredient_text' => 'mąka', 'position' => 0]);
        $szkic->steps()->create(['instruction' => 'Wymieszaj.', 'position' => 0]);
        $this->assertTrue(app(PunktOdzyskaniaSzkicu::class)->zachowajPrzedEdycja($szkic));

        $punkt = DraftRestorePoint::query()->where('recipe_id', $szkic->getKey())->firstOrFail();
        // Worker ma próg 13 dni, a autor nadal ma pełne 14 dni na przywrócenie.
        $punkt->forceFill(['taken_at' => now()->subDays(13)->subHours(12)])->save();
        $szkic->forceFill(['title' => 'Tekst B', 'content_revision' => $szkic->content_revision + 1])->save();

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2849, 1)', []);
        try {
            $sprzatanie = $this->wTle('sprzataj-punkty-2849', ['dni' => '13']);
            $this->czekajNaZablokowane(1);

            $wynik = app(PunktOdzyskaniaSzkicu::class)->przywroc(
                $autor,
                (string) $szkic->getKey(),
                (int) $szkic->content_revision,
                $punkt->taken_at->utc()->format(PunktOdzyskaniaSzkicu::FORMAT_ZNACZNIKA),
            );
            $this->assertSame(PunktOdzyskaniaSzkicu::ODZYSKANO, $wynik->status, 'Kontrola dodatnia: tekst A musi wrócić przed DELETE. '.$wynik->komunikat);
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $rezultat = $sprzatanie->wynik();
        $this->assertBezZakleszczenia($rezultat, 'sprzątanie punktu po odnowieniu');
        $this->assertTrue($rezultat['ok'], 'Sprzątanie nie skończyło się: '.$rezultat['komunikat']);
        $this->assertSame(0, $rezultat['wartosc'], 'PUNKT_2849_ODNOWIONY: stary wybór nie może skasować świeżego punktu.');

        $swiezy = DraftRestorePoint::query()->whereKey($punkt->getKey())->first();
        $this->assertNotNull($swiezy, 'PUNKT_2849_ODNOWIONY: świeży punkt musi nadal istnieć.');
        $this->assertSame('Tekst B', $swiezy->snapshot['title'], 'PUNKT_2849_ODNOWIONY: musi umożliwić powrót do tekstu B.');
        $this->assertTrue($swiezy->taken_at->greaterThan(now()->subMinute()));
        $this->assertSame('Tekst A', $szkic->fresh()->title);

        $szkicPoPierwszym = $szkic->fresh();
        $powrotDoB = app(PunktOdzyskaniaSzkicu::class)->przywroc(
            $autor,
            (string) $szkicPoPierwszym->getKey(),
            (int) $szkicPoPierwszym->content_revision,
            $swiezy->taken_at->utc()->format(PunktOdzyskaniaSzkicu::FORMAT_ZNACZNIKA),
        );
        $this->assertSame(PunktOdzyskaniaSzkicu::ODZYSKANO, $powrotDoB->status, 'PUNKT_2849_ODNOWIONY: drugi powrót do B musi działać. '.$powrotDoB->komunikat);
        $this->assertSame('Tekst B', $szkic->fresh()->title, 'PUNKT_2849_ODNOWIONY: tekst B wraca z odnowionego punktu.');
    }
}
