<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #853 NA DWÓCH POŁĄCZENIACH: „Obserwuj” (`UpdateTagFollows::follow`)
 * i scalenie (`MergeTags`) nie zostawią wiersza `tag_follows` do źródła,
 * które już jest `merged`.
 *
 * Bariera trzyma wyłączny `TagMutationLock` (ten sam zamek, który bierze
 * scalenie), a obaj uczestnicy ustawiają się za nią w wymuszonej kolejności:
 *
 *   najpierw obserwowanie, potem scalenie  →  obserwowanie przechodzi,
 *                                             scalenie przepina je na cel
 *   najpierw scalenie, potem obserwowanie  →  obserwowanie widzi świeży
 *                                             status `merged` i odmawia
 *
 * Kontrola dodatnia w każdym przebiegu: obie operacje NAPRAWDĘ się wykonały
 * (źródło jest `merged`, a obserwowanie albo trafiło na cel, albo odmówiło
 * właśnie walidacją), a nie padły po drodze z innego powodu.
 *
 * ── KONTROLA UJEMNA (wykonana 24.09.2026) ──
 *
 * Usunięcie warunku `status !== STATUS_ACTIVE` z `UpdateTagFollows::insert()`
 * oblewa przeplot „najpierw scalenie”: obserwowanie po zakończonym scaleniu
 * dopisuje wiersz do źródła `merged`. Po przywróceniu warunku oba zielone.
 */
#[Group('dwa-polaczenia')]
final class ObserwowanieTaguKontraScalenieTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tagi = [];

    protected function tearDown(): void
    {
        if ($this->tagi !== []) {
            $sprzataczka = $this->nowePolaczenie();
            $ids = '{'.implode(',', $this->tagi).'}';
            // Najpierw scalone źródła — klucz obcy `merged_into_tag_id` jest
            // świadomie bez `ON DELETE`. Aliasy, wpisy i obserwowania znikają
            // kaskadą.
            $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[]) AND merged_into_tag_id IS NOT NULL')->execute([$ids]);
            $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[])')->execute([$ids]);
            $this->tagi = [];
        }

        parent::tearDown();
    }

    public function test_obserwowanie_przed_scaleniem_trafia_na_cel(): void
    {
        [$kto, $zrodlo, $cel] = $this->przygotuj();

        [$obserwowanie, $scalenie] = $this->uruchom($kto, $zrodlo, $cel, najpierwScalenie: false);

        $this->assertTrue($obserwowanie['ok'], 'Obserwowanie przed scaleniem padło: '.$obserwowanie['komunikat']);
        $this->assertTrue($scalenie['ok'], 'Scalenie padło: '.$scalenie['komunikat']);
        $this->assertBezWierszaDoZrodla($kto, $zrodlo, $cel);
        $this->assertSame(1, DB::table('tag_follows')->where('user_id', $kto)->where('tag_id', $cel)->count(), 'Scalenie nie przepięło obserwowania na cel.');
    }

    public function test_obserwowanie_po_scaleniu_odmawia_i_nie_dopisuje_zrodla(): void
    {
        [$kto, $zrodlo, $cel] = $this->przygotuj();

        [$obserwowanie, $scalenie] = $this->uruchom($kto, $zrodlo, $cel, najpierwScalenie: true);

        $this->assertTrue($scalenie['ok'], 'Scalenie padło: '.$scalenie['komunikat']);
        $this->assertFalse($obserwowanie['ok'], 'Obserwowanie po scaleniu przeszło.');
        $this->assertSame(ValidationException::class, $obserwowanie['wyjatek'], 'Obserwowanie padło z innego powodu: '.$obserwowanie['komunikat']);
        $this->assertBezWierszaDoZrodla($kto, $zrodlo, $cel);
        $this->assertSame(0, DB::table('tag_follows')->where('user_id', $kto)->count());
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function przygotuj(): array
    {
        $kto = (string) $this->konto()->getKey();
        $zrodlo = (string) Tag::factory()->create()->getKey();
        $cel = (string) Tag::factory()->create()->getKey();
        $this->tagi = [$zrodlo, $cel];

        return [$kto, $zrodlo, $cel];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} obserwowanie, scalenie */
    private function uruchom(string $kto, string $zrodlo, string $cel, bool $najpierwScalenie): array
    {
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(647, 1)', []);
        $obserwuj = fn () => $this->wTle('obserwuj-tag', ['kto' => $kto, 'tag' => $zrodlo]);
        $scal = fn () => $this->wTle('scal-tagi', ['zrodlo' => $zrodlo, 'cel' => $cel]);

        $pierwszy = $najpierwScalenie ? $scal() : $obserwuj();
        $this->czekajNaZablokowane(1);
        $drugi = $najpierwScalenie ? $obserwuj() : $scal();
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = $najpierwScalenie
            ? [$drugi->wynik(), $pierwszy->wynik()]
            : [$pierwszy->wynik(), $drugi->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'obserwowanie tagu');
        $this->assertBezZakleszczenia($wyniki[1], 'scalenie tagów');

        return $wyniki;
    }

    private function assertBezWierszaDoZrodla(string $kto, string $zrodlo, string $cel): void
    {
        $this->assertSame(
            ['merged', $cel],
            array_values((array) DB::table('tags')->where('id', $zrodlo)->first(['status', 'merged_into_tag_id'])),
            'Źródło nie jest scalone — przebieg nic nie zmierzył.',
        );
        $this->assertSame(0, DB::table('tag_follows')->where('user_id', $kto)->where('tag_id', $zrodlo)->count(), 'Zostało obserwowanie scalonego źródła.');
    }
}
