<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Odzyskiwanie\PunktOdzyskaniaSzkicu;
use App\Models\DraftRestorePoint;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #2810: przepis B i zachowanie zastąpionego tekstu A to jedna transakcja.
 *
 * Wyzwalacz PostgreSQL naprawdę odmawia drugiego zapisu. Zmiany w zdarzeniu
 * saved/listenerze SQL to kontrolowane przeploty na jednym połączeniu, nie
 * pomiar dwóch sesji ani czekania na blokadę. RefreshDatabase przygotowuje
 * schemat, ale connectionsToTransact jest puste: zewnętrzna transakcja przy
 * mutacji ukryłaby utratę A za 25P02. Sprzątamy własne fixture przez FK,
 * bez TRUNCATE dzienników dopisywania. Drugi PID odczytu opisuje receipt.
 * Testy nie czytają źródeł: sprawdzają pełne wiersze i rzeczywisty wynik HTTP.
 */
class OdzyskanieTekstuSzkicuAtomowaZamianaTest extends TestCase
{
    use RefreshDatabase;

    private array $konta = [];

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        // Także ostatni przypadek nie zostawia własnych kont ani przepisów
        // kolejnym klasom korzystającym z cache migracji RefreshDatabase.
        DB::table('recipes')->whereIn('author_id', $this->konta)->delete();
        DB::table('users')->whereIn('id', $this->konta)->delete();

        parent::tearDown();
    }

    public function test_awaria_drugiego_zapisu_cofa_przepis_skladniki_kroki_i_rewizje(): void
    {
        [$autor, $szkic, $punkt] = $this->fixture();
        $przed = $this->stan($szkic);
        $this->wyzwalaczAwarii();
        $blad = null;

        try {
            $this->przywroc($autor, $szkic, $punkt);
        } catch (QueryException $e) {
            $blad = $e;
        } finally {
            DB::unprepared('DROP TRIGGER test_2810_awaria_punktu ON draft_restore_points; DROP FUNCTION test_2810_awaria_punktu();');
        }

        $this->assertInstanceOf(QueryException::class, $blad);
        $this->assertSame('P0001', (string) $blad->getCode());
        $this->assertStringContainsString('ODZYSKANIE_2810_WYMUSZONA_AWARIA_PUNKTU', $blad->getMessage());
        $this->assertSame($przed, $this->stan($szkic), 'ODZYSKANIE_2810_ATOMOWA_ZAMIANA: awaria punktu utraciła tekst A, wiersze albo odcisk podglądu.');
        $this->bezPublikacji($szkic, $autor);
    }

    public function test_udana_zamiana_zachowuje_a_a_swiadome_cofniecie_oddaje_a(): void
    {
        [$autor, $szkic, $punkt] = $this->fixture();
        $punkty = app(PunktOdzyskaniaSzkicu::class);
        $a = $punkty->migawka($szkic->fresh());
        $b = $punkt->snapshot;
        $staraRewizja = $szkic->content_revision;
        $staryZnacznik = $this->znacznik($punkt);
        $this->travel(1)->seconds();

        $this->assertSame(PunktOdzyskaniaSzkicu::ODZYSKANO, $this->przywroc($autor, $szkic, $punkt)->status);
        $this->assertEquals($b, $punkty->migawka($szkic->fresh()));
        $this->assertEquals($a, $punkt->fresh()->snapshot);
        $po = $this->stan($szkic);

        // Ponowiony formularz nie zamienia z powrotem tego, co już przywrócił.
        $this->assertSame(PunktOdzyskaniaSzkicu::KONFLIKT, $punkty->przywroc($autor, (string) $szkic->getKey(), $staraRewizja, $staryZnacznik)->status);
        $this->assertSame($po, $this->stan($szkic));

        $this->travel(1)->seconds();
        $this->assertSame(PunktOdzyskaniaSzkicu::ODZYSKANO, $this->przywroc($autor, $szkic->fresh(), $punkt->fresh())->status);
        $this->assertEquals($a, $punkty->migawka($szkic->fresh()));
        $this->assertEquals($b, $punkt->fresh()->snapshot);
        $this->assertSame((int) $staraRewizja + 2, (int) $szkic->fresh()->content_revision);
        $this->bezPublikacji($szkic, $autor);
    }

    #[DataProvider('zmianyPunktu')]
    public function test_zmiana_punktu_po_zapisie_tekstu_odmawia_i_cofa_cala_zamiane(string $zmiana): void
    {
        [$autor, $szkic, $punkt] = $this->fixture();
        $obcy = $this->konto();
        $inny = Recipe::factory()->draft()->create(['author_id' => $autor->getKey()]);
        $przed = $this->stan($szkic);
        $uruchomiona = false;

        Recipe::saved(function (Recipe $zapisany) use ($szkic, $punkt, $obcy, $inny, $zmiana, &$uruchomiona): void {
            if ($uruchomiona || $zapisany->getKey() !== $szkic->getKey()) {
                return;
            }
            $uruchomiona = true;
            $wiersz = DB::table('draft_restore_points')->where('id', $punkt->getKey());
            match ($zmiana) {
                'brak' => $wiersz->delete(),
                'tozsamosc' => $wiersz->update(['id' => (string) Str::uuid()]),
                'wlasciciel' => $wiersz->update(['user_id' => $obcy->getKey()]),
                'przepis' => $wiersz->update(['recipe_id' => $inny->getKey()]),
                'znacznik' => $wiersz->update(['taken_at' => $punkt->taken_at->addSecond()->format('Y-m-d H:i:s.uP')]),
                // Termin upływa bez zmiany odcisku: odmowę musi powodować
                // samo okno, a nie wcześniejsze porównanie znacznika.
                'termin' => $this->travel((int) config('kuking.przepisy.szkic_punkt_odzyskania_dni'))->days(),
                // Zachowany znacznik nie jest zgodą na inną zawartość.
                'tresc' => $wiersz->update(['snapshot' => json_encode([...$punkt->snapshot, 'title' => 'Inna kopia C'], JSON_THROW_ON_ERROR)]),
                default => throw new LogicException('Nieznana zmiana punktu w fixture.'),
            };
        });

        $wynik = $this->przywroc($autor, $szkic, $punkt);

        $this->assertTrue($uruchomiona, 'Przeplot musi nastąpić po zapisie przepisu.');
        $this->assertSame(PunktOdzyskaniaSzkicu::BLAD, $wynik->status, 'ODZYSKANIE_2810_ZMIENIONY_PUNKT: zmiana '.$zmiana.' została zatwierdzona jako sukces.');
        $this->assertSame($przed, $this->stan($szkic), 'ODZYSKANIE_2810_ZMIENIONY_PUNKT: odmowa '.$zmiana.' nie cofnęła pełnej zamiany.');
        $this->bezPublikacji($szkic, $autor);
    }

    public static function zmianyPunktu(): array
    {
        return array_combine(['brak', 'tozsamosc', 'wlasciciel', 'przepis', 'znacznik', 'termin', 'tresc'], array_map(static fn (string $zmiana): array => [$zmiana], ['brak', 'tozsamosc', 'wlasciciel', 'przepis', 'znacznik', 'termin', 'tresc']));
    }

    public function test_odmowa_po_zmianie_punktu_nie_staje_sie_sukcesem_w_kontrolerze(): void
    {
        [$autor, $szkic, $punkt] = $this->fixture();
        $przed = $this->stan($szkic);
        Recipe::saved(function (Recipe $zapisany) use ($szkic, $punkt): void {
            if ($zapisany->getKey() === $szkic->getKey()) {
                DB::table('draft_restore_points')->where('id', $punkt->getKey())->delete();
            }
        });

        $this->actingAs($autor)->post(route('recipes.drafts.restore', $szkic->getKey()), [
            'rewizja' => $szkic->content_revision,
            'znacznik' => $this->znacznik($punkt),
        ])->assertRedirect(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertSessionHas('status_rodzaj', 'blad')
            ->assertSessionHas('status', static fn (string $tekst): bool => str_contains($tekst, 'Otwórz aktualny podgląd') && str_contains($tekst, 'Bieżący tekst szkicu został bez zmian.'));

        $this->assertSame($przed, $this->stan($szkic), 'ODZYSKANIE_2810_HTTP_ODMOWA: kontroler utracił pracę A mimo odmowy.');
    }

    #[DataProvider('zmianyPrzedBlokada')]
    public function test_swiezosc_konta_i_szkicu_po_podgladzie_odmawia_bez_nadpisania(string $zmiana): void
    {
        [$autor, $szkic, $punkt] = $this->fixture();
        $obcy = $this->konto();
        $uruchomiona = false;
        $poZmianie = null;

        // Ostatni odczyt punktu przed transakcją: zmiana już zatwierdzona
        // przez inną kartę musi być sprawdzona na świeżym wierszu pod blokadą.
        DB::listen(function (QueryExecuted $query) use ($autor, $szkic, $obcy, $zmiana, &$uruchomiona, &$poZmianie): void {
            if ($uruchomiona || ! str_contains($query->sql, 'from "draft_restore_points"')) {
                return;
            }
            $uruchomiona = true;
            match ($zmiana) {
                'rewizja' => DB::table('recipes')->where('id', $szkic->getKey())->update(['content_revision' => $szkic->content_revision + 1, 'title' => 'Nowsza praca A']),
                'publikacja' => DB::table('recipes')->where('id', $szkic->getKey())->update(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()]),
                'wlasciciel' => DB::table('recipes')->where('id', $szkic->getKey())->update(['author_id' => $obcy->getKey()]),
                'moderacja' => DB::table('recipes')->where('id', $szkic->getKey())->update(['status' => Recipe::STATUS_HIDDEN]),
                'prawo' => $autor->suspend(until: now()->addDay()),
                default => throw new LogicException('Nieznana zmiana prawa albo szkicu w fixture.'),
            };
            $poZmianie = $this->stan($szkic);
        });

        $wynik = $this->przywroc($autor, $szkic, $punkt);

        $this->assertTrue($uruchomiona);
        $this->assertSame(PunktOdzyskaniaSzkicu::BLAD, $wynik->status, 'ODZYSKANIE_2810_SWIEZE_PRAWO: zmiana '.$zmiana.' została nadpisana.');
        $this->assertSame($poZmianie, $this->stan($szkic), 'ODZYSKANIE_2810_SWIEZE_PRAWO: odmowa '.$zmiana.' zmieniła zapisane dane.');
    }

    public static function zmianyPrzedBlokada(): array
    {
        return [['rewizja'], ['publikacja'], ['wlasciciel'], ['moderacja'], ['prawo']];
    }

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0, 0));
        $this->assertSame(0, DB::transactionLevel(), 'Pomiar #2810 wymaga rzeczywistych commitów, bez zewnętrznej transakcji testu.');
        $autor = $this->konto();
        $publish = app(PublishRecipe::class);
        $szkic = $publish->handle(
            author: $autor,
            attributes: ['title' => 'Kopia B', 'summary' => 'Opis kopii B', 'visibility' => 'private', 'servings' => 4],
            ingredients: [['text' => 'mąka kopii B', 'group_name' => 'Ciasto'], ['text' => 'woda kopii B', 'note' => 'letnia']],
            steps: [['instruction' => 'Krok kopii B.', 'timer_minutes' => 5]],
            publish: false,
            wersjaPoprawki: false,
        );
        $this->assertTrue(app(PunktOdzyskaniaSzkicu::class)->zachowajPrzedEdycja($szkic));
        $punkt = DraftRestorePoint::query()->where('recipe_id', $szkic->getKey())->firstOrFail();
        $szkic = $publish->handle(
            author: $autor,
            attributes: ['title' => 'Bieżąca praca A', 'summary' => 'Opis bieżący A', 'visibility' => 'private', 'servings' => 6],
            ingredients: [['text' => 'jajka bieżące A'], ['text' => 'masło bieżące A'], ['text' => 'sól bieżąca A', 'no_amount' => true]],
            steps: [['instruction' => 'Pierwszy krok A.', 'section_name' => 'Przygotowanie'], ['instruction' => 'Drugi krok A.']],
            publish: false,
            existing: $szkic,
            wersjaPoprawki: false,
            oczekiwanaRewizja: $szkic->content_revision,
        );

        return [$autor, $szkic->fresh(), $punkt];
    }

    private function konto(): User
    {
        $konto = User::factory()->create();
        $this->konta[] = $konto->getKey();

        return $konto;
    }

    private function przywroc(User $autor, Recipe $szkic, DraftRestorePoint $punkt): object
    {
        return app(PunktOdzyskaniaSzkicu::class)->przywroc($autor, (string) $szkic->getKey(), (int) $szkic->content_revision, $this->znacznik($punkt));
    }

    private function znacznik(DraftRestorePoint $punkt): string
    {
        return $punkt->taken_at->utc()->format(PunktOdzyskaniaSzkicu::FORMAT_ZNACZNIKA);
    }

    /** Pełne surowe wiersze: obejmuje ID, rewizję, czasy, źródło i metadane. */
    private function stan(Recipe $szkic): array
    {
        $id = $szkic->getKey();

        return [
            'przepis' => (array) DB::table('recipes')->where('id', $id)->first(),
            'skladniki' => DB::table('recipe_ingredients')->where('recipe_id', $id)->orderBy('position')->get()->map(static fn (object $w): array => (array) $w)->all(),
            'kroki' => DB::table('recipe_steps')->where('recipe_id', $id)->orderBy('position')->get()->map(static fn (object $w): array => (array) $w)->all(),
            'punkty' => DB::table('draft_restore_points')->orderBy('id')->get()->map(static fn (object $w): array => (array) $w)->all(),
        ];
    }

    private function bezPublikacji(Recipe $szkic, User $autor): void
    {
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);
        $this->assertNull($szkic->fresh()->published_at);
        $this->assertSame(0, $szkic->versions()->count());
        $this->assertSame(0, DB::table('cooked_events')->where('recipe_id', $szkic->getKey())->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $autor->getKey())->count());
    }

    private function wyzwalaczAwarii(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION test_2810_awaria_punktu() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.snapshot IS DISTINCT FROM OLD.snapshot THEN
        RAISE EXCEPTION 'ODZYSKANIE_2810_WYMUSZONA_AWARIA_PUNKTU' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER test_2810_awaria_punktu BEFORE UPDATE ON draft_restore_points
FOR EACH ROW EXECUTE FUNCTION test_2810_awaria_punktu();
SQL);
    }
}
