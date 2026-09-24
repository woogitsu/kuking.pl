<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * „TO NIC TAKIEGO — ZAMKNIJ WSZYSTKIE N" ZAMYKA TE N, KTÓRE BYŁY NA EKRANIE (#1059).
 *
 * Zastane: `SygnalyController::odrzucGrupe()` wybierał zakres dopiero przy
 * POST — „wszystkie otwarte oznaczenia tego autora w tej chwili". Automat,
 * który dopisał oznaczenie B między odczytem strony a kliknięciem, dostawał
 * decyzję człowieka, którego nikt nie widział, a `OznaczDoPrzegladu::juzOgladane()`
 * nie dawał mu wrócić do kolejki.
 *
 * Każdy test idzie drogą przeglądarki: identyfikatory bierze Z HTML-a
 * strony, nie z bazy — inaczej sprawdzałby kontroler z formularzem, którego
 * widok nie wysyła.
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

    /** @return list<string> identyfikatory, które formularz TEJ grupy niesie z ekranu */
    private function zEkranu(TestResponse $strona, string $grupa): array
    {
        $html = (string) $strona->getContent();
        $poczatek = strpos($html, 'name="autor" value="'.$grupa.'"');
        $this->assertIsInt($poczatek, 'Na ekranie nie ma formularza grupy '.$grupa.'.');
        $koniec = strpos($html, '</form>', $poczatek);
        $this->assertIsInt($koniec);

        preg_match_all('/name="oznaczenia\[\]" value="([^"]+)"/', substr($html, $poczatek, $koniec - $poczatek), $m);

        return $m[1];
    }

    private function zamknij(User $moderator, string $grupa, array $oznaczenia): TestResponse
    {
        return $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), [
                'autor' => $grupa,
                WierszFormularza::POLE => $grupa,
                'oznaczenia' => $oznaczenia,
            ]);
    }

    #[Test]
    public function test_oznaczenie_dopisane_po_otwarciu_strony_zostaje_otwarte_a_grupa_nie_jest_zamykana(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('jedenautor');
        $a = $this->oznaczenie($autor);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()
            ->assertSee('To nic takiego — zamknij to oznaczenie');
        $zEkranu = $this->zEkranu($strona, (string) $autor->getKey());
        $this->assertSame([(string) $a->getKey()], $zEkranu);

        // Automat zapisuje B — inny sygnał — zanim moderator kliknie.
        $b = $this->oznaczenie($autor, 'automat_wzorzec');

        $this->zamknij($moderator, (string) $autor->getKey(), $zEkranu)
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasErrors(['autor' => 'Od otwarcia strony w tej grupie pojawiło się nowe oznaczenie. Nic nie zamknęliśmy — przejrzyj grupę jeszcze raz i dopiero wtedy ją zamknij.'])
            ->assertSessionMissing('status');

        // Nic się nie zmieniło — ani B, ani A (grupa to jedna decyzja).
        $this->assertSame(Report::STATUS_OPEN, $b->refresh()->status);
        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
        $this->assertSame(0, ModerationAction::query()->count());

        // Po ponownym przeglądzie ta sama grupa zamyka się jednym kliknięciem.
        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk()
            ->assertSee('To nic takiego — zamknij wszystkie 2');

        $this->zamknij($moderator, (string) $autor->getKey(), $this->zEkranu($strona, (string) $autor->getKey()))
            ->assertSessionHasNoErrors();

        $this->assertSame(Report::STATUS_REJECTED, $a->refresh()->status);
        $this->assertSame(Report::STATUS_REJECTED, $b->refresh()->status);
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
        $zEkranu = $this->zEkranu($strona, (string) $autor->getKey());
        $this->assertCount(12, $zEkranu, 'Formularz ma nieść całą grupę, nie tylko 10 podglądów.');

        // Nawet dopisanie CUDZEGO identyfikatora do listy nie rozszerza zakresu.
        $this->zamknij($moderator, (string) $autor->getKey(), [...$zEkranu, (string) $cudze->getKey(), (string) $odCzlowieka->getKey()])
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

    #[Test]
    public function test_oznaczenie_zamkniete_w_miedzyczasie_przez_kogos_innego_nie_dostaje_drugiej_decyzji(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('dwaoznaczenia');
        $a = $this->oznaczenie($autor);
        $c = $this->oznaczenie($autor);

        $strona = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $zEkranu = $this->zEkranu($strona, (string) $autor->getKey());

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

        $this->zamknij($moderator, (string) $autor->getKey(), $zEkranu)->assertSessionHasNoErrors();

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
        $zEkranu = $this->zEkranu($strona, 'brak');
        $this->assertSame([(string) $a->getKey()], $zEkranu);

        $b = $this->oznaczenie(null);

        $this->zamknij($moderator, 'brak', $zEkranu)->assertSessionHasErrors('autor');
        $this->assertSame(Report::STATUS_OPEN, $b->refresh()->status);
        $this->assertSame(0, ModerationAction::query()->count());
    }

    /** Formularz sprzed tej zmiany (bez listy) nie zamyka „wszystkiego, co jest" — każe odświeżyć. */
    #[Test]
    public function test_bez_listy_z_ekranu_nic_sie_nie_zamyka(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('starakarta');
        $a = $this->oznaczenie($autor);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasErrors(['oznaczenia' => 'Nie wiadomo, które oznaczenia zamknąć. Odśwież stronę i spróbuj jeszcze raz.']);

        $this->assertSame(Report::STATUS_OPEN, $a->refresh()->status);
    }
}
