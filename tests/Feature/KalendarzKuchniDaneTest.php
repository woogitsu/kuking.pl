<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spójność materiału redakcyjnego, nie decyzja o terminach ani progu publikacji. */
class KalendarzKuchniDaneTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(): array
    {
        $path = base_path('docs/product/dane/kalendarz-polskiej-kuchni.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_calendar_really_covers_every_month_and_has_editorial_material(): void
    {
        $calendar = $this->calendar();
        $this->assertSame(range(1, 12), array_column($calendar['miesiace'], 'miesiac'), 'Kalendarz musi zawierać każdy miesiąc dokładnie raz.');
        $this->assertNotEmpty($calendar['okazje'], 'Nie pomijaj okazji kulinarnych.');
        $ids = array_column($calendar['okazje'], 'id');
        $this->assertCount(count($ids), array_unique($ids), 'Identyfikatory okazji muszą być różne.');

        foreach ($calendar['miesiace'] as $month) {
            $this->assertNotEmpty($month['nazwa']);
            $this->assertIsArray($month['zbiory']);
            $this->assertNotEmpty($month['zapasy']);
            $this->assertNotEmpty($month['codzienne_gotowanie']);
            $this->assertNotEmpty($month['uwaga']);
            $this->assertNotEmpty($month['propozycje'], 'Dodaj propozycję do miesiąca: '.$month['nazwa']);
        }

        foreach ($calendar['okazje'] as $occasion) {
            $this->assertNotEmpty($occasion['termin']);
            $this->assertContains($occasion['rodzaj_terminu'], ['stały', 'ruchomy', 'lokalny']);
            $this->assertNotEmpty($occasion['przygotowanie']);
            $this->assertNotEmpty($occasion['propozycje']);
        }
    }

    public function test_proposals_use_real_canonical_tags_and_notes_fit_the_existing_form(): void
    {
        $this->seed(TagSeeder::class);
        $names = Tag::query()->where('status', Tag::STATUS_ACTIVE)->pluck('name')->all();
        $this->assertContains('pierogi', $names, 'Kontrola: słownik naprawdę trafił do bazy.');
        $calendar = $this->calendar();
        $count = 0;

        foreach (array_merge($calendar['miesiace'], $calendar['okazje']) as $entry) {
            foreach ($entry['propozycje'] as $proposal) {
                $count++;
                $this->assertContains($proposal['tag'], $names, 'Nieznany tag redakcyjny: '.$proposal['tag']);
                $this->assertNotSame('', trim($proposal['notatka']));
                $this->assertLessThanOrEqual(200, mb_strlen($proposal['notatka']), 'Skróć notatkę, żeby można ją było zapisać w panelu.');
                $this->assertSame(strip_tags($proposal['notatka']), $proposal['notatka'], 'Notatki są zwykłym tekstem.');
            }
        }

        $this->assertGreaterThanOrEqual(12, $count, 'Kontrola: test musi przeczytać propozycje, nie pusty plik.');
    }
}
