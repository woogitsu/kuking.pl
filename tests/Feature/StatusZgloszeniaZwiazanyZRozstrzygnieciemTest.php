<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #997 — `reports_resolution_complete_check`.
 *
 * Stan otwarty nie ma pól rozstrzygnięcia, stan końcowy ma `resolved_at`.
 * Retencja (`PrzedawnioneSprawyModeracyjne`) liczy od `resolved_at` tylko dla
 * `resolved`/`rejected` — zamknięta sprawa bez daty żyłaby wiecznie.
 *
 * Każda próba zapisu niespójnego stanu idzie w zagnieżdżonej transakcji
 * (savepoint): w PostgreSQL nieudane zapytanie przerywa całą transakcję,
 * a `RefreshDatabase` trzyma test w jednej.
 *
 * @bez-kontroli-dodatniej plik migracji jest wczytywany tylko po to, żeby wywołać `up()`/`down()` na bazie; asercje dotyczą zachowania PostgreSQL, a kontrola dodatnia jest w samym teście (te same wiersze przechodzą po `down()`).
 */
class StatusZgloszeniaZwiazanyZRozstrzygnieciemTest extends TestCase
{
    use RefreshDatabase;

    private const OGRANICZENIE = 'reports_resolution_complete_check';

    private const PLIK = 'migrations/2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php';

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /** @param  array<string, mixed>  $pola */
    private function wstaw(array $pola): string
    {
        $id = (string) Str::uuid7();

        DB::table('reports')->insert($pola + [
            'id' => $id,
            'numer_sprawy' => NumerSprawy::wygeneruj(),
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $pola */
    private function assertBazaOdrzuca(array $pola, string $dlaczego): void
    {
        try {
            DB::transaction(fn () => $this->wstaw($pola));
        } catch (QueryException $e) {
            $this->assertStringContainsString(self::OGRANICZENIE, $e->getMessage(), $dlaczego);

            return;
        }

        $this->fail('PostgreSQL przyjął niespójny stan: '.$dlaczego);
    }

    private function ograniczenieZwalidowane(): ?bool
    {
        $wiersz = DB::selectOne(
            'SELECT convalidated FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [self::OGRANICZENIE, 'reports'],
        );

        return $wiersz === null ? null : (bool) $wiersz->convalidated;
    }

    public function test_zamkniety_status_bez_daty_rozstrzygniecia_odbija_sie_o_baze(): void
    {
        foreach ([Report::STATUS_RESOLVED, Report::STATUS_REJECTED] as $status) {
            $this->assertBazaOdrzuca(
                ['status' => $status, 'resolved_by' => $this->moderator()->getKey()],
                "`{$status}` bez `resolved_at` — retencja nie skasowałaby tej sprawy nigdy.",
            );
        }
    }

    public function test_otwarty_status_z_polami_rozstrzygniecia_odbija_sie_o_baze(): void
    {
        foreach ([Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING] as $status) {
            $this->assertBazaOdrzuca(
                ['status' => $status, 'resolved_at' => now()],
                "`{$status}` z `resolved_at` — fałszywy ślad rozstrzygnięcia.",
            );
        }

        $this->assertBazaOdrzuca(
            ['status' => Report::STATUS_OPEN, 'resolved_by' => $this->moderator()->getKey()],
            'Otwarta sprawa z moderatorem, który ją „rozstrzygnął".',
        );
        $this->assertBazaOdrzuca(
            ['status' => Report::STATUS_OPEN, 'resolution_note' => 'Notatka bez decyzji.'],
            'Otwarta sprawa z notatką rozstrzygnięcia.',
        );
    }

    public function test_spojne_stany_przechodza_takze_bez_moderatora_i_notatki(): void
    {
        // Kontrola dodatnia — CHECK, który odrzuca wszystko, też przeszedłby
        // dwa testy wyżej.
        $this->wstaw(['status' => Report::STATUS_OPEN]);
        $this->wstaw(['status' => Report::STATUS_REVIEWING]);
        $this->wstaw([
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $this->moderator()->getKey(),
            'resolution_note' => 'Ukryte.',
        ]);
        // `resolved_by` ma `nullOnDelete()`, a `resolution_note` jest
        // opcjonalna w formularzu — stan końcowy wymaga tylko daty.
        $this->wstaw(['status' => Report::STATUS_REJECTED, 'resolved_at' => now()]);

        $this->assertSame(4, DB::table('reports')->count());
    }

    public function test_usuniecie_konta_moderatora_nie_uniewaznia_rozstrzygnietej_sprawy(): void
    {
        $operator = $this->user('bylyoperator');
        $id = $this->wstaw([
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $operator->getKey(),
        ]);

        DB::table('users')->where('id', $operator->getKey())->delete();

        $wiersz = DB::table('reports')->where('id', $id)->first();
        $this->assertNull($wiersz->resolved_by);
        $this->assertNotNull($wiersz->resolved_at);
    }

    public function test_decyzja_moderatora_zapisuje_date_i_moderatora(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->for($this->user('autor'), 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        foreach ([ModerationAction::ACTION_HIDE => Report::STATUS_RESOLVED, ModerationAction::ACTION_NONE => Report::STATUS_REJECTED] as $akcja => $status) {
            $zgloszenie = Report::create([
                'reporter_id' => $this->user('zglaszajacy'.$akcja)->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);

            $this->actingAs($moderator)
                ->post(route('admin.reports.decide', $zgloszenie), [
                    'action' => $akcja,
                    'reason_code' => 'spam',
                ])
                ->assertSessionHasNoErrors();

            $zgloszenie->refresh();
            $this->assertSame($status, $zgloszenie->status);
            $this->assertNotNull($zgloszenie->resolved_at, "Decyzja `{$akcja}` zamknęła sprawę bez daty.");
            $this->assertSame($moderator->getKey(), $zgloszenie->resolved_by);
        }
    }

    public function test_odrzucenie_oznaczen_automatu_zapisuje_date(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('oznaczony');
        $wpis = Post::factory()->for($autor, 'author')->create();

        $oznaczenie = Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'autor_tresci_id' => $autor->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'automat_wzorzec',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey(), 'oznaczenia' => $this->oznaczeniaNaEkranie()])
            ->assertSessionHasNoErrors();

        $oznaczenie->refresh();
        $this->assertSame(Report::STATUS_REJECTED, $oznaczenie->status);
        $this->assertNotNull($oznaczenie->resolved_at);
        $this->assertSame($moderator->getKey(), $oznaczenie->resolved_by);
    }

    public function test_migracja_odmawia_przy_niespojnych_danych_i_niczego_nie_zmienia(): void
    {
        $migracja = $this->migracja();
        $migracja->down();
        $this->assertNull($this->ograniczenieZwalidowane());

        // Stan, który stary schemat przyjmował.
        $zle = $this->wstaw(['status' => Report::STATUS_RESOLVED]);
        $this->wstaw(['status' => Report::STATUS_OPEN, 'resolved_at' => now()]);

        try {
            $migracja->up();
            $this->fail('Migracja założyła CHECK mimo niespójnych danych.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Zamknięte (resolved/rejected) bez resolved_at: 1.', $e->getMessage());
            $this->assertStringContainsString('Otwarte (open/triage/reviewing) z resolved_at: 1.', $e->getMessage());
        }

        $this->assertNull($this->ograniczenieZwalidowane(), 'Odmowa zostawiła CHECK w bazie.');
        $this->assertSame(Report::STATUS_RESOLVED, DB::table('reports')->where('id', $zle)->value('status'));
        $this->assertNull(DB::table('reports')->where('id', $zle)->value('resolved_at'), 'Migracja zgadła datę.');
    }

    public function test_migracja_w_gore_i_w_dol_na_spojnych_danych(): void
    {
        $this->assertTrue($this->ograniczenieZwalidowane(), 'Po migracji CHECK musi być zwalidowany.');

        $this->wstaw(['status' => Report::STATUS_OPEN]);
        $this->wstaw(['status' => Report::STATUS_REJECTED, 'resolved_at' => now()]);

        $migracja = $this->migracja();
        $migracja->down();
        $this->assertNull($this->ograniczenieZwalidowane());

        $migracja->up();
        $this->assertTrue($this->ograniczenieZwalidowane(), 'VALIDATE CONSTRAINT nie przeszedł.');
        $this->assertSame(2, DB::table('reports')->count());
    }

    /**
     * Identyfikatory otwartych oznaczeń automatu — to, co formularz grupy
     * niesie z ekranu (#1059). Przysłana lista tylko ogranicza zakres, więc
     * oznaczenia innych grup w niej nie szkodzą.
     *
     * @return list<string>
     */
    private function oznaczeniaNaEkranie(): array
    {
        return \App\Models\Report::query()
            ->where('source', \App\Models\Report::SOURCE_AUTOMAT)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
