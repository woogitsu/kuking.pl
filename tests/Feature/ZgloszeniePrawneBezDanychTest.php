<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zgłoszenie treści NIELEGALNEJ wolno złożyć bez podania danych
 * (art. 16 ust. 2 lit. c DSA, decyzja właściciela z 7 września 2026).
 *
 * CO BYŁO ZŁE
 * `notifier_email` był opcjonalny — z komentarzem powołującym się dokładnie
 * na ten przepis, który zwalnia też z podania IMIENIA. `notifier_name` był
 * wymagany i w formularzu, i w CHECK-u w bazie. Reguła istniała w kodzie
 * w połowie: brak adresu wolno, brak nazwiska nie.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Zwolnienie z ust. 2 lit. c dotyczy zgłoszeń o przestępstwach z art. 3-7
 * dyrektywy 2011/93/UE — przestępstwach seksualnych wobec dzieci. Wymóg
 * nazwiska zamykał więc drogę prawną dokładnie w najcięższych sprawach.
 * I nie teoretycznie: przy społeczności liczonej w dziesiątkach osób
 * z jednej okolicy człowiek zgłaszający kogoś z rodziny albo z sąsiedztwa
 * nie podpisze się nazwiskiem. Serwis dla grupy 50+ w małych
 * miejscowościach to nie jest przypadek skrajny.
 *
 * CZEGO TEN TEST PILNUJE PO DRUGIEJ STRONIE
 * Że anonimowe nie znaczy puste: uzasadnienie nielegalności i oświadczenie
 * o dobrej wierze zostają wymagane — i to nie tylko w formularzu, ale
 * w BAZIE, bo przy audycie liczy się to, czego baza nie mogła przyjąć.
 */
class ZgloszeniePrawneBezDanychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $zmiany
     * @return array<string, mixed>
     */
    private function zgloszenie(array $zmiany = []): array
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

    /**
     * KONTROLA. Zgłoszenie Z DANYMI nadal przechodzi — bez tego pomiar niżej
     * mógłby być zielony dlatego, że formularz przyjmuje wszystko.
     */
    public function test_kontrola_zgloszenie_z_danymi_nadal_przechodzi(): void
    {
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie())
            ->assertSessionHasNoErrors()
            ->assertRedirectContains(route('zglos.nielegalna.potwierdzenie').'?potwierdzenie=');

        $this->assertSame('Anna Kowalska', Report::query()->value('notifier_name'));
    }

    /** WŁAŚCIWY POMIAR. Bez imienia i bez adresu — zgłoszenie zostaje przyjęte. */
    public function test_zgloszenie_bez_imienia_i_bez_adresu_jest_przyjmowane(): void
    {
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie([
            'notifier_name' => '',
            'notifier_email' => '',
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirectContains(route('zglos.nielegalna.potwierdzenie').'?potwierdzenie=');

        $zgloszenie = Report::query()->firstOrFail();

        $this->assertSame(Report::SOURCE_LEGAL_NOTICE, $zgloszenie->source);
        $this->assertNull($zgloszenie->notifier_name, 'Puste imię zostało czymś zastąpione.');
        $this->assertNull($zgloszenie->notifier_email);

        // Treść zgłoszenia jest kompletna — anonimowość nie zabiera niczego,
        // co potrzebne do oceny sprawy.
        $this->assertNotNull($zgloszenie->illegality_explanation);
        $this->assertNotNull($zgloszenie->good_faith_at);
    }

    /**
     * Formularz nie może OZNACZAĆ tego pola jako wymaganego — inaczej
     * człowiek, którego przepis zwalnia z podania danych, i tak by je podał,
     * bo ekran mu tak każe.
     */
    public function test_formularz_nie_oznacza_pola_imienia_jako_wymaganego(): void
    {
        $html = $this->get(route('zglos.nielegalna'))->assertOk()->getContent();
        $this->assertIsString($html);

        preg_match('/<label[^>]*for="f-notifier_name".*?<\/label>/s', $html, $etykieta);

        $this->assertNotEmpty($etykieta, 'Nie znalazłem etykiety pola imienia — test nie mierzy tego, co myśli.');
        $this->assertStringNotContainsString('wymagane', $etykieta[0], 'Formularz nadal oznacza imię jako wymagane.');

        // KONTROLA: uzasadnienie nielegalności JEST oznaczone jako wymagane.
        preg_match('/<label[^>]*for="f-illegality_explanation".*?<\/label>/s', $html, $uzasadnienie);
        $this->assertNotEmpty($uzasadnienie);
        $this->assertStringContainsString('wymagane', $uzasadnienie[0]);
    }

    /** Anonimowe nie znaczy puste: bez uzasadnienia zgłoszenie odpada. */
    public function test_bez_uzasadnienia_zgloszenie_odpada(): void
    {
        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie([
            'notifier_name' => '',
            'illegality_explanation' => '',
        ]))->assertSessionHasErrors('illegality_explanation');

        $this->assertSame(0, Report::query()->count());
    }

    /** Bez oświadczenia o dobrej wierze też odpada (art. 16 ust. 2 lit. d). */
    public function test_bez_dobrej_wiary_zgloszenie_odpada(): void
    {
        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie([
            'notifier_name' => '',
            'good_faith' => '',
        ]))->assertSessionHasErrors('good_faith');

        $this->assertSame(0, Report::query()->count());
    }

    /**
     * KONTROLA W BAZIE, nie w formularzu. Przy audycie liczy się to, czego
     * baza NIE MOGŁA przyjąć — a nie to, co formularz odrzucał w chwili,
     * gdy ktoś na niego patrzył.
     */
    public function test_baza_nadal_odrzuca_zgloszenie_prawne_bez_uzasadnienia(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            // Surowy `INSERT` omija model, a `numer_sprawy` jest `NOT NULL`
            // (D-029) — patrz komentarz w
            // `IdempotencjaZgloszeniaTest::identyfikatory()`.
            'numer_sprawy' => NumerSprawy::wygeneruj(),
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/cos',
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
            'illegality_explanation' => null,
            'good_faith_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A TERAZ TA SAMA WSTAWKA BEZ IMIENIA, ale z uzasadnieniem i dobrą wiarą
     * — baza MUSI ją przyjąć. To jest dowód, że poluzowaliśmy dokładnie jeden
     * warunek, a nie cały CHECK.
     */
    public function test_baza_przyjmuje_zgloszenie_bez_imienia(): void
    {
        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            // Surowy `INSERT` omija model, a `numer_sprawy` jest `NOT NULL`
            // (D-029) — patrz komentarz w
            // `IdempotencjaZgloszeniaTest::identyfikatory()`.
            'numer_sprawy' => NumerSprawy::wygeneruj(),
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/cos',
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
            'notifier_name' => null,
            'illegality_explanation' => 'Uzasadnienie, którego baza wymaga.',
            'good_faith_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, Report::query()->whereNull('notifier_name')->count());
    }

    /**
     * Kolejka moderatora nie może pokazać „przez " i pustego miejsca —
     * wygląda to jak błąd danych, a jest poprawnym zgłoszeniem.
     */
    public function test_kolejka_moderatora_mowi_wprost_ze_zgloszenie_jest_bez_danych(): void
    {
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->zgloszenie([
            'notifier_name' => '',
            'notifier_email' => '',
        ]));

        // `TestCase::moderator()` — konto z rolą ORAZ potwierdzonym 2FA.
        // Bez 2FA panel oddaje 403 i test mierzyłby zamknięte drzwi.
        $odpowiedz = $this->actingAs($this->moderator())->get(route('admin.reports'))->assertOk();

        $odpowiedz->assertSee('bez podania danych zgłaszającego', escape: false);
        $odpowiedz->assertDontSee('przez  (zgłoszenie prawne', escape: false);
    }
}
