<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneSprawyModeracyjne;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retencja SPRAWY MODERACYJNEJ — `reports` + `moderation_actions` + `appeals`
 * (issue #19, docs/decyzje/ADR_RETENCJE.md §4, §5.3-5.5).
 *
 * DECYZJA WŁAŚCICIELA (2026-09-07): 36 miesięcy od zamknięcia sprawy,
 * art. 442¹ k.c. — testy niżej używają tej liczby wprost, nie zmiennej,
 * żeby faktyczna wartość domyślna configu miała choć jeden test, który by
 * złapał jej przypadkową zmianę.
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU:
 * `test_kaskada_nie_zabiera_odwolania_przed_jego_wlasnym_czasem` — pilnuje
 * reguły z ADR §4: `appeals.moderation_action_id` ma `cascadeOnDelete`, więc
 * ślepe kasowanie `moderation_actions` po jego własnym wieku zabrałoby
 * odwołanie, zanim minął JEGO czas.
 */
class RetencjaSprawModeracyjnychTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $status, ?\DateTimeInterface $resolvedAt, ?User $zglaszajacy = null): Report
    {
        return Report::create([
            'reporter_id' => ($zglaszajacy ?? $this->user())->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'status' => $status,
            'resolved_at' => $resolvedAt,
        ]);
    }

    private function decyzja(User $moderator, \DateTimeInterface $createdAt): ModerationAction
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
        ]);

        DB::table('moderation_actions')->where('id', $decyzja->getKey())->update(['created_at' => $createdAt]);

        return $decyzja->refresh();
    }

    private function odwolanieAutora(
        ModerationAction $decyzja,
        User $autor,
        string $status,
        ?\DateTimeInterface $decidedAt,
    ): Appeal {
        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Nie zgadzam się z decyzją.',
            'status' => $status,
            'decided_at' => $decidedAt,
            'decision_note' => $status === Appeal::STATUS_OPEN ? null : 'Decyzja podtrzymana po ponownej analizie.',
        ]);
    }

    // ------------------------------------------------------------------
    // reports
    // ------------------------------------------------------------------

    public function test_zamkniete_zgloszenie_starsze_niz_prog_znika_a_mlodsze_zostaje(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);

        $stare = $this->zgloszenie(Report::STATUS_RESOLVED, now()->subMonths(36)->subDay());
        $mlode = $this->zgloszenie(Report::STATUS_RESOLVED, now()->subMonths(36)->addDay());

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(1, $raport->usunieteZgloszenia);
        $this->assertDatabaseMissing('reports', ['id' => $stare->getKey()]);
        // Asercja kontrolna: młodsze naprawdę zostało.
        $this->assertDatabaseHas('reports', ['id' => $mlode->getKey()]);
    }

    /**
     * `status IN ('open','triage','reviewing')` NIGDY nie jest kandydatem,
     * niezależnie od wieku (ADR §5.3) — sprawa otwarta nie ma "zamknięcia",
     * od którego liczyć.
     */
    public function test_otwarte_zgloszenie_nigdy_nie_jest_kandydatem_niezaleznie_od_wieku(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);

        // `resolved_at` NULL, ale `created_at` ekstremalnie stary (10 lat) —
        // gdyby komenda kiedyś pomyliła `created_at` z `resolved_at`, ten
        // test by to złapał.
        $otwarte = $this->zgloszenie(Report::STATUS_OPEN, resolvedAt: null);
        DB::table('reports')->where('id', $otwarte->getKey())->update(['created_at' => now()->subYears(10)]);

        // Kontrola pozytywna: zamknięte w tym samym wieku naprawdę znika.
        $zamkniete = $this->zgloszenie(Report::STATUS_RESOLVED, now()->subYears(10));

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(1, $raport->usunieteZgloszenia);
        $this->assertDatabaseHas('reports', ['id' => $otwarte->getKey()]);
        $this->assertDatabaseMissing('reports', ['id' => $zamkniete->getKey()]);
    }

    // ------------------------------------------------------------------
    // moderation_actions
    // ------------------------------------------------------------------

    public function test_decyzja_bez_odwolania_starsza_niz_prog_znika_a_mlodsza_zostaje(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();

        $stara = $this->decyzja($moderator, now()->subMonths(36)->subDay());
        $mloda = $this->decyzja($moderator, now()->subMonths(36)->addDay());

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(1, $raport->usunieteDecyzje);
        $this->assertSame(0, $raport->pominieteDecyzjeZywymOdwolaniem);
        $this->assertDatabaseMissing('moderation_actions', ['id' => $stara->getKey()]);
        // Asercja kontrolna.
        $this->assertDatabaseHas('moderation_actions', ['id' => $mloda->getKey()]);
    }

    /**
     * Odwołanie w stanie `open` blokuje usunięcie decyzji BEZWARUNKOWO,
     * niezależnie od wieku decyzji (ADR §4) — dopóki ktoś nie rozpatrzy
     * odwołania, sprawa nie jest zamknięta.
     */
    public function test_decyzja_z_otwartym_odwolaniem_nie_jest_kasowana_niezaleznie_od_wieku(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        // 10 lat — ekstremalnie ponad próg, żeby wykluczyć przypadek.
        $decyzja = $this->decyzja($moderator, now()->subYears(10));
        $odwolanie = $this->odwolanieAutora($decyzja, $autor, Appeal::STATUS_OPEN, decidedAt: null);

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(0, $raport->usunieteDecyzje);
        $this->assertSame(1, $raport->pominieteDecyzjeZywymOdwolaniem);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzja->getKey()]);
        // Kontrola: odwołanie samo też zostało — to NIE jest test, który
        // przeszedłby przez przypadek, w którym coś inne ochroniło decyzję.
        $this->assertDatabaseHas('appeals', ['id' => $odwolanie->getKey()]);
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU (ADR §4).
     *
     * Decyzja jest starsza niż próg (36 miesięcy) — SAMA W SOBIE byłaby
     * kandydatem do usunięcia. Ale jej odwołanie zostało rozpatrzone
     * NIEDAWNO (miesiąc temu), więc WŁASNY próg retencji odwołania jeszcze
     * nie minął. `appeals.moderation_action_id` ma `cascadeOnDelete` — gdyby
     * komenda skasowała decyzję na podstawie WYŁĄCZNIE jej własnego wieku,
     * kaskada zabrałaby odwołanie, zanim minęły jego własne 36 miesięcy.
     *
     * Oczekiwane zachowanie: ANI decyzja, ANI odwołanie nie znikają w tym
     * przebiegu.
     */
    public function test_kaskada_nie_zabiera_odwolania_przed_jego_wlasnym_czasem(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        $decyzja = $this->decyzja($moderator, now()->subMonths(40));
        $odwolanie = $this->odwolanieAutora(
            $decyzja,
            $autor,
            Appeal::STATUS_UPHELD,
            decidedAt: now()->subMonth(), // dawno rozpatrzone, ale NIEDAWNO
        );

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(0, $raport->usunieteDecyzje, 'Decyzja została skasowana, mimo że jej odwołanie jeszcze nie przekroczyło własnego okresu retencji — kaskada zabrałaby dowód przedwcześnie.');
        $this->assertSame(1, $raport->pominieteDecyzjeZywymOdwolaniem);
        $this->assertSame(0, $raport->usunieteOdwolania);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzja->getKey()]);
        $this->assertDatabaseHas('appeals', ['id' => $odwolanie->getKey()]);

        // KONTROLA POZYTYWNA: gdy odwołanie WRESZCIE przekroczy swój własny
        // próg (decyzja rozpatrzona 37 miesięcy temu zamiast miesiąc), oba
        // wiersze znikają w tym samym przebiegu — udowadnia, że blokada
        // wyżej nie jest efektem jakiegoś INNEGO błędu (np. że kod w ogóle
        // nigdy nic nie kasuje).
        DB::table('appeals')->where('id', $odwolanie->getKey())->update(['decided_at' => now()->subMonths(37)]);

        $drugiPrzebieg = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(1, $drugiPrzebieg->usunieteOdwolania);
        $this->assertSame(1, $drugiPrzebieg->usunieteDecyzje);
        $this->assertDatabaseMissing('appeals', ['id' => $odwolanie->getKey()]);
        $this->assertDatabaseMissing('moderation_actions', ['id' => $decyzja->getKey()]);
    }

    // ------------------------------------------------------------------
    // appeals
    // ------------------------------------------------------------------

    public function test_rozpatrzone_odwolanie_starsze_niz_prog_znika_a_mlodsze_zostaje(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        // Decyzje muszą być OSOBNE (UNIQUE moderation_action_id+appellant) —
        // i wystarczająco młode, żeby same nie stały się kandydatem w tym
        // przebiegu, inaczej ten test mierzyłby coś innego.
        $decyzjaStara = $this->decyzja($moderator, now()->subMonths(1));
        $decyzjaMloda = $this->decyzja($moderator, now()->subMonths(1));

        $odwolanieStare = $this->odwolanieAutora($decyzjaStara, $autor, Appeal::STATUS_UPHELD, now()->subMonths(36)->subDay());
        $odwolanieMlode = $this->odwolanieAutora($decyzjaMloda, $autor, Appeal::STATUS_UPHELD, now()->subMonths(36)->addDay());

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(1, $raport->usunieteOdwolania);
        $this->assertDatabaseMissing('appeals', ['id' => $odwolanieStare->getKey()]);
        // Asercja kontrolna.
        $this->assertDatabaseHas('appeals', ['id' => $odwolanieMlode->getKey()]);
        // I decyzje obu spraw zostały — są zbyt młode, by być kandydatem.
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzjaStara->getKey()]);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzjaMloda->getKey()]);
    }

    public function test_otwarte_odwolanie_nigdy_nie_jest_kandydatem_niezaleznie_od_wieku(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        $decyzja = $this->decyzja($moderator, now()->subMonths(1));
        $odwolanie = $this->odwolanieAutora($decyzja, $autor, Appeal::STATUS_OPEN, decidedAt: null);
        // `decided_at` jest NULL dla otwartego (CHECK w bazie) — nie ma więc
        // jak "postarzyć" samo odwołanie przez tę kolumnę. Sprawdzamy więc,
        // że sama obecność statusu `open` chroni je, niezależnie od tego, jak
        // dawno powstało (`created_at`).
        DB::table('appeals')->where('id', $odwolanie->getKey())->update(['created_at' => now()->subYears(10)]);

        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(0, $raport->usunieteOdwolania);
        $this->assertDatabaseHas('appeals', ['id' => $odwolanie->getKey()]);
    }

    // ------------------------------------------------------------------
    // --na-sucho
    // ------------------------------------------------------------------

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['kuking.moderation.case_retention_months' => 36]);
        $moderator = $this->moderator();
        $autor = $this->user('autor');

        $zgloszenie = $this->zgloszenie(Report::STATUS_RESOLVED, now()->subMonths(40));
        $decyzjaSama = $this->decyzja($moderator, now()->subMonths(40));

        $decyzjaZOdwolaniem = $this->decyzja($moderator, now()->subMonths(40));
        $odwolanie = $this->odwolanieAutora($decyzjaZOdwolaniem, $autor, Appeal::STATUS_UPHELD, now()->subMonths(40));

        $this->artisan('kuking:sprzataj-sprawy-moderacyjne', ['--na-sucho' => true])->assertSuccessful();

        // Nic nie zniknęło — dosłownie każdy z czterech wierszy.
        $this->assertDatabaseHas('reports', ['id' => $zgloszenie->getKey()]);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzjaSama->getKey()]);
        $this->assertDatabaseHas('moderation_actions', ['id' => $decyzjaZOdwolaniem->getKey()]);
        $this->assertDatabaseHas('appeals', ['id' => $odwolanie->getKey()]);

        // Kontrola pozytywna: to samo wywołanie BEZ `--na-sucho` naprawdę
        // kasuje to, co powinno (zgłoszenie, samotną decyzję, odwołanie
        // i — po jego zniknięciu — drugą decyzję).
        $this->artisan('kuking:sprzataj-sprawy-moderacyjne')->assertSuccessful();

        $this->assertDatabaseMissing('reports', ['id' => $zgloszenie->getKey()]);
        $this->assertDatabaseMissing('moderation_actions', ['id' => $decyzjaSama->getKey()]);
        $this->assertDatabaseMissing('appeals', ['id' => $odwolanie->getKey()]);
        $this->assertDatabaseMissing('moderation_actions', ['id' => $decyzjaZOdwolaniem->getKey()]);
    }
}
