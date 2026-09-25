<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Osoba bez adresu strony ma wyjście w formularzu zgłoszenia prawnego (DSA).
 *
 * SKĄD TEN TEST (audyt B9, pkt 2)
 * Podpowiedź pola „Adres strony z tą treścią” mówiła: „Jeśli nie masz adresu,
 * opisz poniżej, gdzie to jest”. Pole jest jednak wymagane, więc kto posłuchał
 * i zostawił je puste, dostawał „Wklej adres strony…” — błąd sprzeczny
 * z podpowiedzią i żadnej drogi dalej. Pole przyjmuje dowolny tekst, więc
 * poprawką są słowa: opis miejsca wpisuje się W TO pole, i mówią to zarówno
 * podpowiedź, jak i komunikat błędu.
 *
 * KONTROLA DODATNIA: zgłoszenie z opisem zamiast adresu przechodzi i zapisuje
 * opis — gdyby ktoś dołożył regułę `url`, nowe zdania stałyby się nieprawdą,
 * a ten test by to złapał.
 */
class ZgloszenieNielegalnejTresciBezAdresuTest extends TestCase
{
    use RefreshDatabase;

    private const OPIS = 'Przepis „Rosół babci Zofii”, autorka Halina';

    public function test_podpowiedz_kaze_wpisac_opis_w_to_samo_pole(): void
    {
        $html = (string) $this->get(route('zglos.nielegalna'))->assertOk()->getContent();

        $this->assertStringContainsString('Jeśli go nie masz, wpisz tutaj, gdzie to widzisz', $html);
        $this->assertStringNotContainsString('opisz poniżej', $html);
    }

    public function test_puste_pole_dostaje_komunikat_zgodny_z_podpowiedzia(): void
    {
        $this->from(route('zglos.nielegalna'))
            ->post(route('zglos.nielegalna.store'), $this->zgloszenie(['target_url' => '']))
            ->assertSessionHasErrors(['target_url' => 'Wklej adres strony z tą treścią. Jeśli go nie masz, wpisz, gdzie ją widzisz — na przykład tytuł przepisu i nazwę autora.']);

        $this->assertSame(0, Report::count());
    }

    public function test_opis_miejsca_zamiast_adresu_jest_przyjmowany(): void
    {
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie(['target_url' => self::OPIS]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('reports', [
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_url' => self::OPIS,
        ]);
    }

    /** @return array<string, mixed> */
    private function zgloszenie(array $zmiany): array
    {
        return array_merge([
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ], $zmiany);
    }
}
