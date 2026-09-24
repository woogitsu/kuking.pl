<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wymazanie konta odłącza wiadomości „Napisz do nas" (#995).
 *
 * CO OBIECUJE POLITYKA
 * `resources/legal/polityka-prywatnosci.md`, §2, wiersz „Wiadomości do nas
 * przez formularz »Napisz do nas«": „Jeśli usuniesz konto, wiadomość
 * zostaje, ale przestaje być z nim powiązana".
 *
 * CO ROBIŁ KOD
 * `contact_messages.user_id` ma `nullOnDelete()`, ale konta z Kuking się nie
 * kasuje, tylko anonimizuje — więc akcja klucza obcego nigdy się nie
 * uruchamiała i wiadomość dalej wskazywała UUID wymazanego konta.
 */
class WymazanieKontaOdlaczaWiadomosciDoNasTest extends TestCase
{
    use RefreshDatabase;

    public static function zakresy(): array
    {
        return [
            'minimum' => [User::DELETE_SCOPE_MINIMUM],
            'everything' => [User::DELETE_SCOPE_EVERYTHING],
        ];
    }

    #[DataProvider('zakresy')]
    public function test_po_wymazaniu_wiadomosci_zostaja_bez_powiazania_z_kontem(string $zakres): void
    {
        $operator = $this->user('operator');
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => $zakres,
        ]);
        $zostaje = $this->user('zostaje');

        $zalatwionaKiedy = now()->subMonths(2)->startOfSecond();
        $zalatwiona = ContactMessage::factory()
            ->odZalogowanego($odchodzi)
            ->zalatwiona($operator, $zalatwionaKiedy)
            ->create(['message' => 'Nie działa przycisk Opublikuj.']);
        $nowa = ContactMessage::factory()->odZalogowanego($odchodzi)->create();
        $cudza = ContactMessage::factory()->odZalogowanego($zostaje)->create();

        $odpowiedz = ContactMessageReply::create([
            'contact_message_id' => $zalatwiona->getKey(),
            'author_id' => $operator->getKey(),
            'body' => 'Dziękujemy, już poprawione.',
        ]);

        // Kontrola wstępna: bez niej test przeszedłby także wtedy, gdyby
        // fabryka w ogóle nie zapisywała `user_id`.
        $this->assertSame(2, ContactMessage::query()->where('user_id', $odchodzi->getKey())->count());

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(0, ContactMessage::query()->where('user_id', $odchodzi->getKey())->count());

        $zalatwiona->refresh();
        $this->assertNull($zalatwiona->user_id);
        $this->assertSame('Nie działa przycisk Opublikuj.', $zalatwiona->message);
        $this->assertSame(ContactMessage::STATUS_ZALATWIONA, $zalatwiona->status);
        $this->assertSame($operator->getKey(), $zalatwiona->handled_by);
        $this->assertTrue($zalatwiona->handled_at->equalTo($zalatwionaKiedy), 'Termin retencji liczy się od handled_at — nie wolno go przesunąć.');
        $this->assertNull($zalatwiona->contact_email);

        $this->assertNull($nowa->refresh()->user_id);
        $this->assertSame(ContactMessage::STATUS_NOWA, $nowa->status);

        $this->assertSame('Dziękujemy, już poprawione.', $odpowiedz->refresh()->body);
        $this->assertSame($zalatwiona->getKey(), $odpowiedz->contact_message_id);

        // Wiadomości innych kont nietknięte.
        $this->assertSame($zostaje->getKey(), $cudza->refresh()->user_id);
    }

    /**
     * Kontrola ujemna: samo zgłoszenie usunięcia (karencja trwa, można się
     * rozmyślić) NIE odłącza wiadomości — nocny egzekutor też nie, dopóki
     * 30 dni nie minie.
     */
    public function test_zgloszenie_usuniecia_w_karencji_nie_odlacza_wiadomosci(): void
    {
        $odchodzi = $this->user('odchodzi');
        $wiadomosc = ContactMessage::factory()->odZalogowanego($odchodzi)->create();

        $odchodzi->markForDeletion();
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertSame(User::STATUS_PENDING_DELETE, $odchodzi->refresh()->status);
        $this->assertSame($odchodzi->getKey(), $wiadomosc->refresh()->user_id);
    }
}
