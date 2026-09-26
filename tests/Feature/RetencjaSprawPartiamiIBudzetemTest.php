<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneSprawyModeracyjne;
use App\Domain\Moderation\KolejkiPanelu;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retencja spraw moderacyjnych przy backlogu większym niż partia (issue #998).
 *
 * Wcześniej `PrzedawnioneSprawyModeracyjne` brał WSZYSTKICH kandydatów
 * jednym `get()` i kasował ich pojedynczo — każdy `delete()` na `Appeal`
 * i `Report` przeliczał przy tym od nowa wszystkie liczniki panelu. Teraz:
 * partie, budżet jednego przebiegu, reszta w następnym — i dalej te same
 * efekty uboczne.
 */
class RetencjaSprawPartiamiIBudzetemTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.moderation.case_retention_months' => 36]);
        $this->moderator = $this->moderator();
    }

    private function zgloszenie(string $status = Report::STATUS_RESOLVED, int $miesiecyTemu = 40): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'status' => $status,
            'resolved_at' => $status === Report::STATUS_OPEN ? null : now()->subMonths($miesiecyTemu),
        ]);
    }

    private function decyzja(int $miesiecyTemu): ModerationAction
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
        ]);

        DB::table('moderation_actions')->where('id', $decyzja->getKey())
            ->update(['created_at' => now()->subMonths($miesiecyTemu)]);

        return $decyzja->refresh();
    }

    private function odwolanie(ModerationAction $decyzja, int $miesiecyTemu): Appeal
    {
        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $this->user()->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Nie zgadzam się z decyzją.',
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now()->subMonths($miesiecyTemu),
            'decision_note' => 'Decyzja podtrzymana po ponownej analizie.',
        ]);
    }

    private function kasowan(callable $przebieg): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $przebieg();
        $delete = collect(DB::getQueryLog())
            ->filter(static fn (array $q): bool => str_starts_with(strtolower(ltrim($q['query'])), 'delete'))
            ->count();
        DB::disableQueryLog();

        return $delete;
    }

    public function test_backlog_wiekszy_niz_budzet_znika_w_dwoch_przebiegach_partiami(): void
    {
        foreach (range(1, 7) as $_) {
            $this->zgloszenie();
            $this->decyzja(40);
            // Odwołanie przedawnione, decyzja młoda — sama nie jest kandydatem.
            $this->odwolanie($this->decyzja(1), 40);
        }

        $log = Log::spy();
        $sprzataj = new PrzedawnioneSprawyModeracyjne(rozmiarPartii: 2, budzetPrzebiegu: 5);

        $raport = null;
        $kasowan = $this->kasowan(function () use ($sprzataj, &$raport): void {
            $raport = $sprzataj->posprzataj(36);
        });

        $this->assertSame(5, $raport->usunieteOdwolania);
        $this->assertSame(5, $raport->usunieteDecyzje);
        $this->assertSame(5, $raport->usunieteZgloszenia);
        $this->assertSame(6, $raport->pozostaloNaKolejnyPrzebieg);
        // Partie 2 + 2 + 1 w każdej z trzech tabel — nie 15 `DELETE` po jednym wierszu.
        $this->assertSame(9, $kasowan);
        $log->shouldHaveReceived('warning')
            ->withArgs(static fn (string $komunikat, array $kontekst): bool => ($kontekst['pozostalo'] ?? null) === 6)
            ->once();

        $drugi = $sprzataj->posprzataj(36);

        $this->assertSame(2, $drugi->usunieteOdwolania);
        $this->assertSame(2, $drugi->usunieteDecyzje);
        $this->assertSame(2, $drugi->usunieteZgloszenia);
        $this->assertSame(0, $drugi->pozostaloNaKolejnyPrzebieg);

        $this->assertSame(0, Appeal::query()->count());
        $this->assertSame(0, Report::query()->count());
        // Zostają tylko młode decyzje, spod których zniknęły odwołania.
        $this->assertSame(7, ModerationAction::query()->count());
        $this->assertSame(0, ModerationAction::query()->where('created_at', '<', now()->subMonths(36))->count());
    }

    public function test_po_kasowaniu_zbiorczym_liczniki_panelu_sa_przeliczone(): void
    {
        $this->zgloszenie();
        $this->zgloszenie(Report::STATUS_OPEN);

        // Nieświeży wpis — tak, jakby od ostatniego przeliczenia coś się zmieniło.
        Cache::forever('panel:kolejki', ['zgloszenia' => 99]);
        $this->assertSame(99, app(KolejkiPanelu::class)->liczby()['zgloszenia']);

        (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        // Kasowanie zbiorcze nie odpala `deleted` na modelu, więc przeliczenie
        // (dawniej po każdym wierszu) musi zrobić sama retencja.
        $this->assertSame(1, app(KolejkiPanelu::class)->liczby()['zgloszenia']);
    }

    public function test_blad_jednego_odwolania_nie_zatrzymuje_partii_i_chroni_jego_decyzje(): void
    {
        $zly = null;
        $decyzjaZlego = null;

        foreach (range(1, 4) as $i) {
            $decyzja = $this->decyzja(40);
            $odwolanie = $this->odwolanie($decyzja, 40);

            if ($i === 2) {
                [$zly, $decyzjaZlego] = [$odwolanie, $decyzja];
            }
        }

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION test_awaria_kasowania_odwolania() RETURNS trigger AS $$
            BEGIN
                IF OLD.id = '{$zly->getKey()}' THEN
                    RAISE EXCEPTION 'symulowana awaria kasowania odwołania';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER test_awaria_kasowania_odwolania
            BEFORE DELETE ON appeals
            FOR EACH ROW EXECUTE FUNCTION test_awaria_kasowania_odwolania();
        SQL);

        $raport = (new PrzedawnioneSprawyModeracyjne(rozmiarPartii: 10))->posprzataj(36);

        $this->assertSame(3, $raport->usunieteOdwolania, 'Jeden zły wiersz nie może zatrzymać reszty partii.');
        $this->assertSame(1, $raport->bledyOdwolan);
        $this->assertSame(3, $raport->usunieteDecyzje);
        $this->assertSame(1, $raport->pominieteDecyzjeZywymOdwolaniem);
        $this->assertSame(1, $raport->pozostaloNaKolejnyPrzebieg);

        // ADR §5.4: nieudane skasowanie odwołania blokuje kasowanie jego decyzji.
        $this->assertDatabaseHas('appeals', ['id' => $zly->getKey()]);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzjaZlego->getKey()]);
        $this->assertSame(1, Appeal::query()->count());
        $this->assertSame(1, ModerationAction::query()->count());
    }
}
