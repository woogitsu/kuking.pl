<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Ekran „Wiadomości do nas" w panelu — kto tam wchodzi i co może zrobić.
 *
 * DLACZEGO POLITYKA JEST SPRAWDZANA OSOBNO OD MIDDLEWARE'U
 * Trasy stoją za `auth` + `moderator` + `moderator.2fa`, więc łatwo uznać, że
 * polityka jest ozdobą. Nie jest: middleware pilnuje WEJŚCIA DO PANELU, a nie
 * prawa do konkretnego wiersza — te dwie rzeczy rozjeżdżają się w chwili,
 * w której ktoś doda drugą drogę do tego samego modelu i zapomni powtórzyć
 * całą trójkę. UUID w adresie nie jest autoryzacją (AGENTS.md §7).
 *
 * Dlatego jeden test pyta o HTTP (co widzi obcy), a drugi wprost o `Gate` —
 * czyli o to, co zostanie, gdy middleware'y kiedyś się przesuną.
 */
class PanelWiadomosciDoOperatoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_nie_widzi_kolejki(): void
    {
        $this->get(route('admin.contact'))->assertRedirect(route('login'));
    }

    public function test_zwykly_uzytkownik_dostaje_404(): void
    {
        // 404, nie 403 — panel moderacji nie musi nikomu potwierdzać,
        // że istnieje (`EnsureUserIsModerator`).
        $this->actingAs($this->user('basia'))
            ->get(route('admin.contact'))
            ->assertNotFound();
    }

    public function test_polityka_odmawia_zwyklemu_uzytkownikowi_niezaleznie_od_trasy(): void
    {
        $basia = $this->user('basia');
        $wiadomosc = ContactMessage::factory()->create();

        $this->assertFalse(Gate::forUser($basia)->allows('viewAny', ContactMessage::class));
        $this->assertFalse(Gate::forUser($basia)->allows('view', $wiadomosc));
        $this->assertFalse(Gate::forUser($basia)->allows('handle', $wiadomosc));

        $moderator = $this->moderator();

        $this->assertTrue(Gate::forUser($moderator)->allows('viewAny', ContactMessage::class));
        $this->assertTrue(Gate::forUser($moderator)->allows('view', $wiadomosc));
        $this->assertTrue(Gate::forUser($moderator)->allows('handle', $wiadomosc));
    }

    public function test_moderator_widzi_kolejke_i_pojedyncza_wiadomosc(): void
    {
        $wiadomosc = ContactMessage::factory()->create([
            'message' => 'Przy powiększonym tekście przycisk Opublikuj ucieka poza ekran.',
        ]);

        $this->actingAs($this->moderator())
            ->get(route('admin.contact'))
            ->assertOk()
            ->assertSee('Wiadomości do nas')
            ->assertSee('Opublikuj ucieka poza ekran', false);

        $this->actingAs($this->moderator())
            ->get(route('admin.contact.show', $wiadomosc))
            ->assertOk()
            ->assertSee('Opublikuj ucieka poza ekran', false)
            ->assertSee($wiadomosc->contact_email);
    }

    /**
     * Kolejka wiadomości NIE JEST kolejką zgłoszeń i to musi być widać na
     * ekranie — inaczej operator zacznie traktować „nie działa mi przycisk"
     * jak sprawę moderacyjną z decyzją i terminem.
     */
    public function test_kolejka_wiadomosci_odsyla_do_osobnej_kolejki_zgloszen(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.contact'))
            ->assertOk()
            ->assertSee(route('admin.reports'), false)
            ->assertSee('To nie są zgłoszenia treści', false);
    }

    public function test_moderator_zamyka_wiadomosc_i_zostawia_slad(): void
    {
        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->create();

        $this->actingAs($moderator)
            ->post(route('admin.contact.update', $wiadomosc), [
                'status' => ContactMessage::STATUS_ZALATWIONA,
                'handler_note' => 'Odpisane, poprawka w issue #200.',
            ])
            ->assertRedirect(route('admin.contact.show', $wiadomosc));

        $wiadomosc->refresh();

        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);
        $this->assertSame($moderator->getKey(), $wiadomosc->handled_by);
        $this->assertNotNull(
            $wiadomosc->handled_at,
            'Brak `handled_at` znaczy, że retencja nie ma od czego liczyć — wiadomość '
            .'zostałaby w bazie na zawsze.',
        );
        $this->assertSame('Odpisane, poprawka w issue #200.', $wiadomosc->handler_note);
    }

    /**
     * Powrót na „Nowa" czyści ślad obsługi — inaczej w bazie stałby wiersz
     * wewnętrznie sprzeczny („nikt tego nie tknął", ale wiadomo kto i kiedy),
     * którego i tak nie przyjąłby CHECK `contact_messages_handled_complete`.
     */
    public function test_powrot_na_nowa_czysci_slad_obslugi(): void
    {
        $moderator = $this->moderator();
        $wiadomosc = ContactMessage::factory()->zalatwiona($moderator)->create();

        $this->actingAs($moderator)
            ->post(route('admin.contact.update', $wiadomosc), [
                'status' => ContactMessage::STATUS_NOWA,
                'handler_note' => 'Jednak nie załatwione.',
            ])
            ->assertRedirect(route('admin.contact.show', $wiadomosc));

        $wiadomosc->refresh();

        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->status);
        $this->assertNull($wiadomosc->handled_by);
        $this->assertNull($wiadomosc->handled_at);
        $this->assertSame('Jednak nie załatwione.', $wiadomosc->handler_note);
    }

    /**
     * #843: edycja samej notatki zamkniętej sprawy nie jest ponownym
     * zamknięciem. Druga osoba dopisująca numer issue nie staje się „tą,
     * która załatwiła", a zegar retencji (od `handled_at`) nie startuje od
     * nowa. Kontrola dodatnia: prawdziwe otwarcie i ponowne zamknięcie
     * NADAL wpisuje nową datę i nowego operatora.
     */
    public function test_edycja_notatki_zamknietej_nie_nadpisuje_daty_i_autora_zalatwienia(): void
    {
        $zamykajacy = $this->moderator();
        $poprawiajacy = $this->moderator();
        $zamknieta = now()->subMonths(13)->startOfSecond();
        $wiadomosc = ContactMessage::factory()->zalatwiona($zamykajacy, $zamknieta)->create();
        $formularz = [
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'handler_note' => 'Odpisane, poprawka w issue #843.',
        ];

        foreach ([1, 2] as $_) {
            $this->actingAs($poprawiajacy)
                ->post(route('admin.contact.update', $wiadomosc), $formularz)
                ->assertRedirect(route('admin.contact.show', $wiadomosc));

            $wiadomosc->refresh();
            $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);
            $this->assertSame($zamykajacy->getKey(), $wiadomosc->handled_by);
            $this->assertTrue($zamknieta->equalTo($wiadomosc->handled_at));
            $this->assertSame('Odpisane, poprawka w issue #843.', $wiadomosc->handler_note);
        }

        $this->assertSame(
            1,
            app(PrzedawnioneWiadomosciDoOperatora::class)->posprzataj(12, naSucho: true),
            'Dopisanie notatki nie może przesuwać terminu sprzątania.',
        );

        $this->actingAs($poprawiajacy)->post(route('admin.contact.update', $wiadomosc), [
            'status' => ContactMessage::STATUS_W_TOKU,
            'handler_note' => 'Wraca do roboty.',
        ]);
        $this->actingAs($poprawiajacy)->post(route('admin.contact.update', $wiadomosc), $formularz);

        $wiadomosc->refresh();
        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $wiadomosc->status);
        $this->assertSame($poprawiajacy->getKey(), $wiadomosc->handled_by);
        $this->assertTrue($wiadomosc->handled_at->greaterThan($zamknieta->copy()->addMonths(12)));
        $this->assertSame(
            0,
            app(PrzedawnioneWiadomosciDoOperatora::class)->posprzataj(12, naSucho: true),
            'Kontrola dodatnia: prawdziwe ponowne zamknięcie liczy retencję od nowa.',
        );
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_zwykly_uzytkownik_nie_zamknie_cudzej_wiadomosci(): void
    {
        $wiadomosc = ContactMessage::factory()->create();

        $this->actingAs($this->user('basia'))
            ->post(route('admin.contact.update', $wiadomosc), [
                'status' => ContactMessage::STATUS_ZALATWIONA,
            ])
            ->assertNotFound();

        $this->assertSame(ContactMessage::STATUS_NOWA, $wiadomosc->refresh()->status);
    }

    public function test_kolejka_pokazuje_najstarsze_na_gorze(): void
    {
        $stara = ContactMessage::factory()->create([
            'message' => 'Najstarsza wiadomość, czeka najdłużej.',
            'created_at' => now()->subDays(5),
        ]);
        $nowa = ContactMessage::factory()->create([
            'message' => 'Najnowsza wiadomość, przyszła przed chwilą.',
            'created_at' => now(),
        ]);

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.contact'))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'Najnowsza wiadomość'),
            strpos($html, 'Najstarsza wiadomość'),
            'Kolejka pokazuje najnowsze na górze. Przy jednoosobowej obsłudze to znaczy, '
            .'że ktoś czeka najdłużej i jest odsuwany najdalej.',
        );

        $this->assertNotSame($stara->getKey(), $nowa->getKey());
    }
}
