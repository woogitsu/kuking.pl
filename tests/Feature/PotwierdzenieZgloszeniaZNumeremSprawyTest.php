<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Potwierdzenie przyjęcia zgłoszenia z numerem sprawy (#2708, pyt. 11 analizy prawnej z 2.10.2026).
 *
 * Gość bez konta, który podał adres e-mail, dostaje list z numerem sprawy,
 * a ekran po wysłaniu pokazuje ten sam numer. List nie powtarza zgłoszonej
 * treści (opisu, nazwiska zgłaszającego) i mówi, że potwierdza odbiór, a nie
 * przesądza wyniku.
 */
class PotwierdzenieZgloszeniaZNumeremSprawyTest extends TestCase
{
    use RefreshDatabase;

    private const OPIS = 'Ten opis zgłoszenia nie może wrócić w liście, bo to cudza treść.';

    /** @return array<string, mixed> */
    private function zgloszenie(array $zmiany = []): array
    {
        return array_merge([
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => self::OPIS,
            'good_faith' => '1',
        ], $zmiany);
    }

    public function test_gosc_z_adresem_dostaje_list_z_numerem_sprawy_a_ekran_pokazuje_ten_sam_numer(): void
    {
        Notification::fake();

        $odpowiedz = $this->post(route('zglos.nielegalna.store'), $this->zgloszenie())
            ->assertSessionHasNoErrors();

        $numer = (string) Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail()->numer_sprawy;
        $this->assertNotSame('', $numer);

        // Ekran po wysłaniu: numer i informacja o liście.
        $this->get((string) $odpowiedz->headers->get('Location'))
            ->assertOk()
            ->assertSee('Przyjęliśmy Twoje zgłoszenie')
            ->assertSee($numer)
            ->assertSee('wysłaliśmy');

        // List: jedno powiadomienie na adres z formularza, z numerem w temacie i w treści.
        Notification::assertSentOnDemand(
            PotwierdzenieZgloszeniaNielegalnejTresci::class,
            function (PotwierdzenieZgloszeniaNielegalnejTresci $powiadomienie, array $kanaly, AnonymousNotifiable $odbiorca) use ($numer): bool {
                $list = $powiadomienie->toMail($odbiorca);
                $tresc = (string) $list->render();

                $this->assertSame('anna@kancelaria.example', $odbiorca->routes['mail']);
                $this->assertStringContainsString($numer, $list->subject);
                $this->assertStringContainsString($numer, $tresc);
                $this->assertStringContainsString('nie przesądza jego wyniku', $tresc);
                $this->assertStringContainsString('Sprawdzimy to i odpiszemy Ci z decyzją', $tresc);

                // Nie powtarzamy zgłoszonej treści ani danych zgłaszającego.
                $this->assertStringNotContainsString(self::OPIS, $tresc);
                $this->assertStringNotContainsString('Anna Kowalska', $tresc);

                return true;
            },
        );
    }

    public function test_gosc_bez_adresu_dostaje_numer_na_ekranie_i_zadnego_listu(): void
    {
        Notification::fake();

        $odpowiedz = $this->post(route('zglos.nielegalna.store'), $this->zgloszenie([
            'notifier_email' => null,
            'reason' => 'minor',
        ]))->assertSessionHasNoErrors();

        $numer = (string) Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail()->numer_sprawy;

        $this->get((string) $odpowiedz->headers->get('Location'))
            ->assertOk()
            ->assertSee($numer)
            ->assertSee('jedynym śladem');
        Notification::assertNothingSent();
    }
}
