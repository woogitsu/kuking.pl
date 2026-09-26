<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\SygnalyController;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use App\Support\WierszFormularza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * „TO NIC TAKIEGO — ZAMKNIJ WSZYSTKIE N" ZAMYKA GRUPĘ, KTÓRĄ MODERATOR WIDZIAŁ (#1059).
 *
 * Zastane: `SygnalyController::odrzucGrupe()` wybierał zakres dopiero przy
 * POST — „wszystkie otwarte oznaczenia tego autora w tej chwili". Automat,
 * który dopisał oznaczenie B między odczytem strony a kliknięciem, dostawał
 * decyzję człowieka, którego nikt nie widział, a `OznaczDoPrzegladu::juzOgladane()`
 * nie dawał mu wrócić do kolejki.
 *
 * Kontrakt (decyzja właściciela, wariant b): formularz niesie klucz grupy,
 * liczbę oznaczeń i identyfikator najnowszego z nich. Serwer zamyka całą
 * grupę tylko wtedy, gdy od wyświetlenia nic do niej nie doszło; inaczej
 * nie zamyka niczego i mówi, co zrobić. Widok nadal rozwija najwyżej
 * 10 pozycji na grupę (#1060).
 *
 * Każdy test idzie drogą przeglądarki: znacznik bierze Z HTML-a strony, nie
 * z bazy — inaczej sprawdzałby kontroler z formularzem, którego widok nie
 * wysyła.
 */
class ZbiorczeZamkniecieSygnalowTylkoZEkranuTest extends TestCase
{
    use RefreshDatabase;

    private function oznaczenie(?User $autor, string $reason = OcenaModelem::KOD): Report
    {
        $wpis = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'author_id' => $autor?->getKey() ?? $this->user()->getKey(),
        ]);

        return Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'autor_tresci_id' => $autor?->getKey(),
            'subject_user_id' => $autor?->getKey(),
            'source' => Report::SOURCE_AUTOMAT,
            'status' => Report::STATUS_OPEN,
            'reason' => $reason,
            'details' => 'Model ocenił zdjęcie: przemoc (pewność 86%).',
        ]);
    }

    /** @return array{stan_ile: string, stan_najnowsze: string} znacznik, który formularz TEJ grupy niesie z ekranu */
    private function zEkranu(TestResponse $strona, string $grupa): array
    {
        $html = (string) $strona->getContent();
        $poczatek = strpos($html, 'name="autor" value="'.$grupa.'"');
        $this->assertIsInt($poczatek, 'Na ekranie nie ma formularza grupy '.$grupa.'.');
        $koniec = strpos($html, '</form>', $poczatek);
        $this->assertIsInt($koniec);
        $formularz = substr($html, $poczatek, $koniec - $poczatek);

        $this->assertSame(1, preg_match('/name="stan_ile" value="(\d+)"/', $formularz, $ile), 'Formularz grupy nie niesie liczby oznaczeń.');
        $this->assertSame(1, preg_match('/name="stan_najnowsze" value="([0-9a-f-]{36})"/', $formularz, $najnowsze), 'Formularz grupy nie niesie najnowszego oznaczenia.');
        $this->assertSame(0, preg_match('/name="oznaczenia\[\]"/', $formularz), 'Formularz znowu wypisuje listę identyfikatorów.');

        return ['stan_ile' => $ile[1], 'stan_najnowsze' => $najnowsze[1]];
    }

    /** @param  array<string, string>  $znacznik */
    private function zamknij(User $moderator, string $grupa, array $znacznik): TestResponse
    {
        return $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), [
                'autor' => $grupa,
                WierszFormularza::POLE => $grupa,
                ...$znacznik,
            ]);
    }

    private function ileDecyzji(): int
    {
        return ModerationAction::query()->count();
    }

    #[Test]
    public function test_oznaczenie_dopisane_po_otwarciu_strony_zostaje_otwarte_a_grupa_nie_jest_zamykana(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('jedenautor');
        $a = $this->oznaczenie($autor);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()
            ->assertSee('To nic takiego — zamknij to oznaczenie');
        $znacznik = $this->zEkranu($strona, (string) $autor->getKey());
        $this->assertSame(['stan_ile' => '1', 'stan_najnowsze' => (string) $a->getKey()], $znacznik);

        // Wyścig: automat zapisuje B — inny sygnał — zanim moderator kliknie.
        $b = $this->oznaczenie($autor, 'automat_wzorzec');

        $this->zamknij($moderator, (string) $autor->getKey(), $znacznik)
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasErrors(['autor' => SygnalyController::GRUPA_UROSLA])
            ->assertSessionMissing('status');

        // Nic się nie zmieniło — ani B, ani A (grupa to jedna decyzja).
        $this->assertSame(Report::STATUS_OPEN, $b->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());

        // Po odświeżeniu ta sama grupa zamyka się jednym kliknięciem.
        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()
            ->assertSee('To nic takiego — zamknij wszystkie 2');

        $this->zamknij($moderator, (string) $autor->getKey(), $this->zEkranu($strona, (string) $autor->getKey()))
            ->assertSessionHasNoErrors();

        $this->assertSame(Report::STATUS_REJECTED, $a->refresh()->status);
        $this->assertSame(Report::STATUS_REJECTED, $b->refresh()->status);
    }

    /**
     * Dopisanie w tej samej chwili, którego kolejność nie odróżni: ten sam
     * `created_at` i identyfikator MNIEJSZY od najnowszego z ekranu. Łapie
     * je dopiero liczba w znaczniku.
     */
    #[Test]
    public function test_dopisanie_w_tej_samej_chwili_lapie_liczba_oznaczen(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('tasamachwila');
        $a = $this->oznaczenie($autor);

        $znacznik = $this->zEkranu($this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk(), (string) $autor->getKey());

        $b = $this->oznaczenie($autor, 'automat_wzorzec');
        $b->forceFill(['created_at' => $a->created_at])->save();
        Report::query()->whereKey($b->getKey())->update(['id' => '00000000-0000-7000-8000-000000000001']);
        $this->assertSame(2, Report::query()->where('autor_tresci_id', $autor->getKey())->where('created_at', $a->created_at)->count(),
            'Kontrola danych: oba oznaczenia mają tę samą chwilę.');

        $this->zamknij($moderator, (string) $autor->getKey(), $znacznik)
            ->assertSessionHasErrors(['autor' => SygnalyController::GRUPA_UROSLA]);

        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());
    }

    /**
     * Ktoś zamknął jedno z widzianych, a automat dopisał nowe: liczba się
     * zgadza, ale najnowsze jest nowsze od znacznika — odmowa.
     */
    #[Test]
    public function test_nowe_oznaczenie_przy_tej_samej_liczbie_tez_daje_odmowe(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('tasamaliczba');
        $a = $this->oznaczenie($autor);
        $c = $this->oznaczenie($autor);
        $a->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $c->forceFill(['created_at' => now()->subMinute()])->save();

        $znacznik = $this->zEkranu($this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk(), (string) $autor->getKey());
        $this->assertSame(['stan_ile' => '2', 'stan_najnowsze' => (string) $c->getKey()], $znacznik);

        $a->forceFill(['status' => Report::STATUS_REJECTED, 'resolved_at' => now()])->save();
        $b = $this->oznaczenie($autor, 'automat_wzorzec');

        $this->zamknij($moderator, (string) $autor->getKey(), $znacznik)
            ->assertSessionHasErrors(['autor' => SygnalyController::GRUPA_UROSLA]);

        $this->assertSame(Report::STATUS_OPEN, $b->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $c->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());
    }

    /** Kontrola dodatnia: niezmieniona grupa — także większa niż 10 podglądów — zamyka się w całości. */
    #[Test]
    public function test_niezmieniona_grupa_ponad_dziesiec_pozycji_zamyka_sie_jednym_zatwierdzeniem(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('duzagrupa');
        $inny = $this->user('innyautor');

        for ($i = 0; $i < 12; $i++) {
            $this->oznaczenie($autor);
        }
        $cudze = $this->oznaczenie($inny);

        // Zgłoszenie od człowieka o treści tego samego autora — nie jest
        // sygnałem automatu i zbiorcza decyzja ma go nie dotknąć.
        $odCzlowieka = Report::create([
            'target_type' => 'post',
            'target_id' => Post::factory()->create(['author_id' => $autor->getKey()])->getKey(),
            'autor_tresci_id' => $autor->getKey(),
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'source' => Report::SOURCE_COMMUNITY,
            'status' => Report::STATUS_OPEN,
            'reason' => 'spam',
        ]);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()
            ->assertSee('To nic takiego — zamknij wszystkie 12');
        $znacznik = $this->zEkranu($strona, (string) $autor->getKey());
        $this->assertSame('12', $znacznik['stan_ile'], 'Formularz ma nieść liczbę całej grupy, nie 10 podglądów.');

        $this->zamknij($moderator, (string) $autor->getKey(), $znacznik)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(12, Report::query()
            ->where('autor_tresci_id', $autor->getKey())
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('status', Report::STATUS_REJECTED)
            ->count());
        $this->assertSame(12, ModerationAction::query()->where('action', ModerationAction::ACTION_NONE)->count());
        $this->assertSame(Report::STATUS_OPEN, $cudze->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $odCzlowieka->refresh()->status);
    }

    /** Znacznik z CUDZEJ grupy nie jest przepustką — identyfikator z formularza nie jest autoryzacją. */
    #[Test]
    public function test_znacznik_z_innej_grupy_niczego_nie_zamyka(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('mojagrupa');
        $inny = $this->user('cudzagrupa');
        $moje = $this->oznaczenie($autor);
        $cudze = $this->oznaczenie($inny);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $cudzyZnacznik = $this->zEkranu($strona, (string) $inny->getKey());

        $this->zamknij($moderator, (string) $autor->getKey(), $cudzyZnacznik)
            ->assertSessionHasErrors(['autor' => SygnalyController::GRUPA_UROSLA]);

        $this->assertSame(Report::STATUS_OPEN, $moje->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $cudze->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());
    }

    #[Test]
    public function test_oznaczenie_zamkniete_w_miedzyczasie_przez_kogos_innego_nie_dostaje_drugiej_decyzji(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('dwaoznaczenia');
        $a = $this->oznaczenie($autor);
        $c = $this->oznaczenie($autor);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $znacznik = $this->zEkranu($strona, (string) $autor->getKey());

        // Drugi moderator w drugiej karcie zamyka A pojedynczą decyzją.
        ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'report_id' => $a->getKey(),
            'target_type' => $a->target_type,
            'target_id' => $a->target_id,
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'automat-falszywy-alarm',
        ]);
        $a->forceFill(['status' => Report::STATUS_REJECTED, 'resolved_at' => now()])->save();

        $this->zamknij($moderator, (string) $autor->getKey(), $znacznik)->assertSessionHasNoErrors();

        $this->assertSame(1, ModerationAction::query()->where('report_id', $a->getKey())->count());
        $this->assertSame(1, ModerationAction::query()->where('report_id', $c->getKey())->count());
        $this->assertSame(Report::STATUS_REJECTED, $c->refresh()->status);
    }

    #[Test]
    public function test_grupa_bez_autora_tez_zamyka_tylko_to_co_bylo_na_ekranie(): void
    {
        $moderator = $this->moderator();
        $a = $this->oznaczenie(null);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $znacznik = $this->zEkranu($strona, 'brak');
        $this->assertSame(['stan_ile' => '1', 'stan_najnowsze' => (string) $a->getKey()], $znacznik);

        $b = $this->oznaczenie(null);

        $this->zamknij($moderator, 'brak', $znacznik)
            ->assertSessionHasErrors(['autor' => SygnalyController::GRUPA_UROSLA]);
        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $b->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());
    }

    /** Formularz sprzed tej zmiany (bez znacznika) nie zamyka „wszystkiego, co jest" — każe odświeżyć. */
    #[Test]
    public function test_bez_znacznika_z_ekranu_nic_sie_nie_zamyka(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('starakarta');
        $a = $this->oznaczenie($autor);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasErrors(['stan_ile' => SygnalyController::NIEZNANY_STAN]);

        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
        $this->assertSame(0, $this->ileDecyzji());
    }
}
