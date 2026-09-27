<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * Klucze paczki z danymi (`dane.json`) nie przypisują czytelnikowi rodzaju
 * (issue #1750, rodzina #274).
 *
 * DLACZEGO OSOBNY TEST, SKORO JEST `TekstyNiePrzypisujaPlciTest`
 * Tamten skanuje TEKSTY — widoki, dokumenty prawne, napisy w PHP. Klucz
 * tablicy (`'w_czym_jestem_dobra' =>`) dla niego nie jest zdaniem: ma
 * podkreślenia zamiast spacji, a „dobra” nie kończy się na „-łam”, więc
 * żaden wzorzec go nie łapał. A klucze paczki CZYTA CZŁOWIEK — są po polsku
 * właśnie po to (AGENTS.md §11) — i to każdy, kto pobrał swoje dane.
 *
 * Test buduje paczkę PRAWDZIWĄ akcją, nie czyta źródła: klucz dodany kiedyś
 * w innym miejscu `CollectUserExportData` też tu trafi.
 */
final class EksportKluczeBezRodzajuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pierwsza albo druga osoba z przymiotnikiem albo imiesłowem po „jestem”:
     * „jestem dobra”, „jesteś gotowy”. `WzorceRodzaju` łapie czasowniki
     * („ugotowałam”), ale nie tę konstrukcję — a od niej zaczęła się #1750.
     * Wzorzec stoi TU, a nie w `WzorceRodzaju`, bo w zwykłych zdaniach
     * serwisu „jesteś na stronie…” dawałoby fałszywe trafienia; w nazwach
     * kluczy nie ma przyimków.
     */
    private const JESTEM_PRZYMIOTNIK = '/\b(?:jestem|jesteś|jestes)\s+\p{L}+[ayi]\b/u';

    public function test_specjalnosc_w_paczce_ma_klucz_bez_rodzaju(): void
    {
        $basia = $this->user('basia');
        Profile::query()->where('user_id', $basia->getKey())->update(['speciality' => 'zupy i kiszonki']);
        $basia->refresh();

        $dane = $this->paczka($basia);

        $this->assertSame('zupy i kiszonki', $dane['profil']['na_czym_sie_znam'] ?? null);
        $this->assertArrayNotHasKey('w_czym_jestem_dobra', $dane['profil']);
    }

    public function test_zaden_klucz_paczki_nie_przypisuje_rodzaju(): void
    {
        $basia = $this->user('basia');
        Profile::query()->where('user_id', $basia->getKey())->update([
            'speciality' => 'zupy i kiszonki',
            'bio' => 'Gotuję od zawsze.',
            'region' => 'Podkarpacie',
        ]);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        Post::factory()->for($basia, 'author')->create(['body' => 'Dziś pierogi z kaszą']);
        $basia->refresh();

        $klucze = $this->klucze($this->paczka($basia));

        // Sanity: bez tego pusty wynik (np. pusta paczka) byłby zielony.
        $this->assertContains('na czym sie znam', $klucze);
        $this->assertContains('o mnie', $klucze);

        $tekst = implode("\n", $klucze);
        preg_match_all(self::JESTEM_PRZYMIOTNIK, $tekst, $jestem);

        $this->assertSame(
            [],
            array_merge(WzorceRodzaju::trafienia($tekst), $jestem[0]),
            'Klucz paczki przypisuje czytelnikowi rodzaj. Przebuduj nazwę '
            .'(COPY_STYLE.md §2), np. „na_czym_sie_znam” zamiast „w_czym_jestem_dobra”.',
        );
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());
    }

    /**
     * Wszystkie klucze tekstowe na każdym poziomie, z podkreśleniami
     * zamienionymi na spacje — tak, jak je czyta człowiek.
     *
     * @param  array<mixed>  $dane
     * @return list<string>
     */
    private function klucze(array $dane): array
    {
        $wynik = [];

        foreach ($dane as $klucz => $wartosc) {
            if (is_string($klucz)) {
                $wynik[] = str_replace('_', ' ', $klucz);
            }

            if (is_array($wartosc)) {
                array_push($wynik, ...$this->klucze($wartosc));
            }
        }

        return array_values(array_unique($wynik));
    }
}
