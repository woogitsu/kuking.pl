<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nowa odpowiedź przy ZAMKNIĘTEJ sprawie dziedziczy jej bliski termin
 * usunięcia (issue #847) — pomiar całej drogi: wysyłka z panelu, sprzątanie,
 * ponowne otwarcie i świadome ponowne zamknięcie.
 *
 * KONTRAKT (decyzja właściciela z 20.09.2026, bez zmiany retencji):
 *  - odpowiedź nie przesuwa `handled_at` i ginie razem ze sprawą
 *    (kaskada `contact_message_replies`);
 *  - operator widzi termin PRZED wysyłką (`TerminUsunieciaKorespondencjiTest`)
 *    i — to pilnuje ten plik — jeszcze raz W POTWIERDZENIU po wysyłce,
 *    razem z drogą ponownego otwarcia;
 *  - sprawa otwarta ponownie nie jest kandydatem do sprzątania, a ponowne
 *    zamknięcie liczy retencję od nowa, bo to jest jawna decyzja człowieka.
 */
class OdpowiedzPrzyZamknietejSprawieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['kuking.kontakt.retention_months' => 12, 'kuking.strefa' => 'Europe/Warsaw']);
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sprzataj(bool $naSucho = false): int
    {
        return app(PrzedawnioneWiadomosciDoOperatora::class)->posprzataj(12, $naSucho);
    }

    /** Sprawa zamknięta rok temu, jutro w południe przekracza próg. */
    private function zamknietaTuzPrzedProgiem(): ContactMessage
    {
        return ContactMessage::factory()
            ->zalatwiona($this->moderator(), Carbon::parse('2025-09-21 12:00:00', 'UTC'))
            ->create(['contact_email' => 'basia@wp.pl']);
    }

    private function odpisz(ContactMessage $wiadomosc): TestResponse
    {
        return $this->actingAs($this->moderator())
            ->post(route('admin.contact.reply', $wiadomosc), [
                'reply_key' => (string) Str::uuid(),
                'odpowiedz' => 'Dzień dobry, sprawdziliśmy jeszcze raz — wszystko działa.',
            ]);
    }

    public function test_potwierdzenie_wysylki_przy_zamknietej_sprawie_podaje_termin_i_droge_otwarcia(): void
    {
        $wiadomosc = $this->zamknietaTuzPrzedProgiem();

        $this->odpisz($wiadomosc)
            ->assertRedirect(route('admin.contact.show', $wiadomosc))
            // 12:00 UTC to 14:00 w Warszawie (CEST).
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, '21 września 2026, 14:00')
                && str_contains($s, 'otwórz sprawę ponownie'));

        $this->assertTrue(
            $wiadomosc->fresh()->handled_at->equalTo(Carbon::parse('2025-09-21 12:00:00', 'UTC')),
            'Odpowiedź nie może po cichu przesuwać retencji (#843, #847).',
        );
    }

    public function test_potwierdzenie_przy_otwartej_sprawie_nie_straszy_terminem(): void
    {
        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $this->odpisz($wiadomosc)
            ->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Odpowiedź wysłana')
                && ! str_contains($s, 'skasowana'));
    }

    public function test_bez_ponownego_otwarcia_nastepnego_dnia_sprzatanie_zabiera_sprawe_z_nowa_odpowiedzia(): void
    {
        $wiadomosc = $this->zamknietaTuzPrzedProgiem();
        $this->odpisz($wiadomosc);
        $this->assertSame(1, ContactMessageReply::query()->where('contact_message_id', $wiadomosc->getKey())->count());

        Carbon::setTestNow(Carbon::parse('2026-09-21 13:00:00', 'UTC'));

        $this->assertSame(1, $this->sprzataj(naSucho: true));
        $this->assertSame(1, $this->sprzataj());
        $this->assertDatabaseMissing('contact_messages', ['id' => $wiadomosc->getKey()]);
        $this->assertSame(0, ContactMessageReply::query()->where('contact_message_id', $wiadomosc->getKey())->count());
    }

    public function test_otwarta_ponownie_sprawa_z_odpowiedzia_przezywa_sprzatanie_a_ponowne_zamkniecie_liczy_termin_od_nowa(): void
    {
        $wiadomosc = $this->zamknietaTuzPrzedProgiem();
        $operator = $this->moderator();
        $this->odpisz($wiadomosc);

        $wiadomosc->fresh()->oznaczJako(ContactMessage::STATUS_W_TOKU, $operator);

        Carbon::setTestNow(Carbon::parse('2026-09-21 13:00:00', 'UTC'));
        $this->assertSame(0, $this->sprzataj());
        $this->assertSame(1, ContactMessageReply::query()->where('contact_message_id', $wiadomosc->getKey())->count());

        // Świadome ponowne zamknięcie — nowy `handled_at`, nowy termin za rok.
        $wiadomosc->fresh()->oznaczJako(ContactMessage::STATUS_ZALATWIONA, $operator);
        $termin = app(PrzedawnioneWiadomosciDoOperatora::class)->terminUsuniecia($wiadomosc->fresh());
        $this->assertSame('2027-09-21 13:00', $termin?->utc()->format('Y-m-d H:i'));

        Carbon::setTestNow(Carbon::parse('2026-09-22 13:00:00', 'UTC'));
        $this->assertSame(0, $this->sprzataj());
        $this->assertDatabaseHas('contact_messages', ['id' => $wiadomosc->getKey()]);
    }
}
