<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Napisz do nas" i zgłaszanie treści to DWIE RÓŻNE RZECZY — i ma to być
 * widać zarówno w bazie, jak i na ekranie.
 *
 * DLACZEGO TO WYMAGA WŁASNEGO TESTU
 * Bo pomylenie ich jest kosztowne w obie strony i w obie strony ciche:
 *
 *  - skarga na czyjś wpis wrzucona do `contact_messages` nie trafia do
 *    kolejki moderacyjnej, nie dostaje decyzji i nie uruchamia prawa do
 *    odwołania (DSA art. 20) — czyli osoba zgłaszająca myśli, że zgłosiła,
 *    a formalnie nie zgłosiła nic;
 *  - „nie działa mi przycisk" wrzucone do `reports` czeka na DECYZJĘ
 *    moderatora, której nikt nie wyda, i zapycha kolejkę, która przy zespole
 *    jedno-, najwyżej dwuosobowym (D-012) jest najwęższym gardłem serwisu.
 *    Do tego żyje w bazie 36 miesięcy zamiast 12.
 *
 * Rozdział jest zrobiony w trzech miejscach naraz i ten plik sprawdza
 * wszystkie trzy: osobna tabela, osobna kolejka w panelu, wzajemne linki
 * na obu formularzach.
 */
class WiadomosciDoOperatoraSaOddzielneOdZgloszenTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiadomosc_do_operatora_nie_zaklada_zgloszenia_moderacyjnego(): void
    {
        $this->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Nie mogę wgrać zdjęcia z telefonu, nic się nie dzieje po kliknięciu.',
        ])->assertRedirectContains(route('kontakt.potwierdzenie').'?potwierdzenie=');

        $this->assertSame(1, ContactMessage::count());
        $this->assertSame(
            0,
            Report::count(),
            'Wiadomość do operatora założyła sprawę moderacyjną. To jest kolejka, w której '
            .'czeka się na decyzję z prawem do odwołania — nie miejsce na opis awarii.',
        );
    }

    public function test_zgloszenie_nielegalnej_tresci_nie_laduje_w_wiadomosciach(): void
    {
        $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ])->assertRedirectContains(route('zglos.nielegalna.potwierdzenie').'?potwierdzenie=');

        $this->assertSame(1, Report::count());
        $this->assertSame(
            0,
            ContactMessage::count(),
            'Zgłoszenie prawne wpadło do skrzynki technicznej. Tam nie ma decyzji, terminu '
            .'ani pouczenia o środkach odwoławczych, których wymaga DSA art. 16.',
        );
    }

    public function test_oba_formularze_wskazuja_na_siebie_nawzajem(): void
    {
        $this->get(route('kontakt'))
            ->assertOk()
            ->assertSee(route('zglos.nielegalna'), false)
            ->assertSee('Chodzi o czyjś wpis', false);

        $this->get(route('zglos.nielegalna'))
            ->assertOk()
            ->assertSee(route('kontakt'), false);
    }

    public function test_panel_ma_dwie_osobne_kolejki(): void
    {
        ContactMessage::factory()->create(['message' => 'Wiadomość techniczna od człowieka.']);

        Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'copyright',
            'illegality_explanation' => 'Zgłoszenie prawne, zupełnie inna sprawa.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        $moderator = $this->moderator();

        // Kolejka wiadomości nie pokazuje spraw moderacyjnych…
        $this->actingAs($moderator)
            ->get(route('admin.contact'))
            ->assertOk()
            ->assertSee('Wiadomość techniczna od człowieka', false)
            ->assertDontSee('Zgłoszenie prawne, zupełnie inna sprawa', false);

        // …a kolejka zgłoszeń nie pokazuje wiadomości technicznych.
        $this->actingAs($moderator)
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Zgłoszenie prawne, zupełnie inna sprawa', false)
            ->assertDontSee('Wiadomość techniczna od człowieka', false);
    }

    /**
     * Rodzaje wiadomości NIE MOGĄ zacząć wyglądać jak powody zgłoszenia.
     *
     * Gdyby na formularzu technicznym pojawiło się „Mowa nienawiści" albo
     * „Ktoś podaje się za inną osobę", człowiek zgłaszałby sąsiada tą drogą —
     * i słusznie, bo formularz by go do tego zapraszał.
     */
    public function test_rodzaje_wiadomosci_nie_pokrywaja_sie_z_powodami_zgloszen(): void
    {
        $wspolne = array_intersect(
            array_keys(ContactMessage::RODZAJE),
            array_keys(Report::REASONS),
        );

        $this->assertSame(
            [],
            $wspolne,
            'Rodzaj wiadomości technicznej nazywa się tak samo jak powód zgłoszenia treści: '
            .implode(', ', $wspolne).'. To zaprasza do zgłaszania ludzi formularzem technicznym.',
        );
    }
}
