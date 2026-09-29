<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ZamknijGrupeSygnalow;
use App\Domain\Moderation\WynikZamknieciaGrupy;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\Przelacznik;
use Tests\TestCase;

/**
 * `ZamknijGrupeSygnalow` — przypadek użycia wyjęty z `SygnalyController::odrzucGrupe()` (#970).
 *
 * Testy wołają akcję BEZPOŚREDNIO, bez HTTP: reguły, blokada i transakcja
 * muszą trzymać się bez kontrolera. Zachowanie przez HTTP pilnują dalej
 * `ZbiorczeZamkniecieSygnalowTylkoZEkranuTest` i `SygnalyWlasnychTresciModeratorTest`.
 */
class ZamknijGrupeSygnalowTest extends TestCase
{
    use RefreshDatabase;

    private function oznaczenie(?User $autor): Report
    {
        $wpis = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'author_id' => $autor?->getKey() ?? $this->user()->getKey(),
        ]);

        return Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'autor_tresci_id' => $autor?->getKey(),
            'source' => Report::SOURCE_AUTOMAT,
            'status' => Report::STATUS_OPEN,
            'reason' => OcenaModelem::KOD,
            'details' => 'Model ocenił zdjęcie: przemoc (pewność 86%).',
        ]);
    }

    /** Znacznik stanu tak, jak niesie go formularz: liczba i najnowsze oznaczenie grupy. */
    private function stan(?User $autor): array
    {
        $grupa = ZamknijGrupeSygnalow::otwarte()
            ->when($autor === null,
                fn ($q) => $q->whereNull('autor_tresci_id'),
                fn ($q) => $q->where('autor_tresci_id', $autor->getKey()),
            )
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return [$grupa->count(), (string) $grupa->first()->getKey()];
    }

    private function zamknij(User $moderator, string $autor, array $stan, ?string $notatka = null): WynikZamknieciaGrupy
    {
        return app(ZamknijGrupeSygnalow::class)->handle($moderator, $autor, $stan[0], $stan[1], $notatka, '127.0.0.1');
    }

    private function otwartych(): int
    {
        return ZamknijGrupeSygnalow::otwarte()->count();
    }

    #[Test]
    public function zamyka_grupe_z_decyzja_na_kazde_oznaczenie_i_jednym_wpisem_w_dzienniku(): void
    {
        $autor = $this->user();
        $moderator = $this->moderator();
        $this->oznaczenie($autor);
        $this->oznaczenie($autor);
        $cudze = $this->oznaczenie($this->user());

        $wynik = $this->zamknij($moderator, (string) $autor->getKey(), $this->stan($autor), 'Pomyłka automatu.');

        $this->assertSame(WynikZamknieciaGrupy::ZAMKNIETO, $wynik->rodzaj);
        $this->assertSame(2, $wynik->ile);
        $this->assertSame(2, Report::query()->where('status', Report::STATUS_REJECTED)
            ->where('resolved_by', $moderator->getKey())->where('resolution_note', 'Pomyłka automatu.')->count());
        $this->assertSame(2, ModerationAction::query()->where('reason_code', 'automat-falszywy-alarm')
            ->where('action', ModerationAction::ACTION_NONE)->where('subject_user_id', $autor->getKey())->count());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'moderation.automat_dismissed')->count());
        $this->assertSame(Report::STATUS_OPEN, $cudze->refresh()->status, 'Grupa innego autora nie może być ruszona.');
    }

    #[Test]
    public function grupa_bez_autora_zamyka_sie_pod_kluczem_brak(): void
    {
        $moderator = $this->moderator();
        $this->oznaczenie(null);

        $wynik = $this->zamknij($moderator, 'brak', $this->stan(null));

        $this->assertSame(WynikZamknieciaGrupy::ZAMKNIETO, $wynik->rodzaj);
        $this->assertSame(0, $this->otwartych());
    }

    #[Test]
    public function wlasnych_oznaczen_moderator_nie_zamyka_takze_w_innym_zapisie_uuid(): void
    {
        $moderator = $this->moderator();
        $this->oznaczenie($moderator);
        $stan = $this->stan($moderator);
        $uuid = (string) $moderator->getKey();

        foreach ([$uuid, strtoupper($uuid)] as $zapis) {
            $this->assertSame(WynikZamknieciaGrupy::WLASNE, $this->zamknij($moderator, $zapis, $stan)->rodzaj);
        }

        // Bez myślników to nie jest postać kanoniczna: odmowa „nie wiadomo, którą grupę".
        foreach ([str_replace('-', '', $uuid), '{'.$uuid.'}', 'cokolwiek'] as $zapis) {
            $this->assertSame(WynikZamknieciaGrupy::NIEZNANA_GRUPA, $this->zamknij($moderator, $zapis, $stan)->rodzaj);
        }

        $this->assertSame(1, $this->otwartych());
        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'moderation.automat_dismissed')->count());
    }

    #[Test]
    public function grupa_ktora_urosla_od_wyswietlenia_strony_nie_zamyka_niczego(): void
    {
        $autor = $this->user();
        $moderator = $this->moderator();
        $this->oznaczenie($autor);
        $stan = $this->stan($autor);

        $this->oznaczenie($autor); // automat dopisał drugie po odczycie strony

        $wynik = $this->zamknij($moderator, (string) $autor->getKey(), $stan);

        $this->assertSame(WynikZamknieciaGrupy::UROSLA, $wynik->rodzaj);
        $this->assertSame(2, $this->otwartych());
        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'moderation.automat_dismissed')->count());
    }

    #[Test]
    public function znacznik_z_cudzej_grupy_to_formularz_nieaktualny(): void
    {
        $autor = $this->user();
        $inny = $this->user();
        $moderator = $this->moderator();
        $this->oznaczenie($autor);
        $this->oznaczenie($inny);

        $wynik = $this->zamknij($moderator, (string) $autor->getKey(), $this->stan($inny));

        $this->assertSame(WynikZamknieciaGrupy::UROSLA, $wynik->rodzaj);
        $this->assertSame(2, $this->otwartych());
    }

    #[Test]
    public function grupa_zamknieta_w_miedzyczasie_daje_pusty_wynik_a_drugi_raz_nic_nie_zapisuje(): void
    {
        $autor = $this->user();
        $moderator = $this->moderator();
        $this->oznaczenie($autor);
        $stan = $this->stan($autor);

        $this->assertSame(WynikZamknieciaGrupy::ZAMKNIETO, $this->zamknij($moderator, (string) $autor->getKey(), $stan)->rodzaj);

        // Znacznik wciąż wskazuje wiersz tej grupy (zamknięty), ale otwartych już nie ma — idempotencja.
        $drugi = $this->zamknij($moderator, (string) $autor->getKey(), $stan);

        $this->assertSame(WynikZamknieciaGrupy::PUSTA, $drugi->rodzaj);
        $this->assertSame(1, ModerationAction::query()->count());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'moderation.automat_dismissed')->count());
    }

    #[Test]
    public function awaria_dziennika_cofa_decyzje_i_statusy_razem_z_nim(): void
    {
        $autor = $this->user();
        $moderator = $this->moderator();
        $this->oznaczenie($autor);
        $this->oznaczenie($autor);
        $stan = $this->stan($autor);

        $awaria = new Przelacznik;
        DB::listen(function (QueryExecuted $zapytanie) use ($awaria): void {
            if ($awaria->wlaczony
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array('moderation.automat_dismissed', $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika.');
            }
        });

        try {
            $this->zamknij($moderator, (string) $autor->getKey(), $stan);
            $this->fail('Awaria dziennika miała wyjść z akcji jako wyjątek.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Wstrzyknięta awaria', $e->getMessage());
        }

        $this->assertSame(2, $this->otwartych(), 'Statusy muszą wrócić do otwartych.');
        $this->assertSame(0, ModerationAction::query()->count(), 'Decyzje muszą zniknąć razem z wpisem.');

        $awaria->wlaczony = false;
        $this->assertSame(2, $this->zamknij($moderator, (string) $autor->getKey(), $stan)->ile, 'Ponowienie daje jeden komplet.');
    }
}
