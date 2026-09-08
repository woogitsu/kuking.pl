<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users.ostatnio_widziany_at` — zapisywane throttlowanym, globalnym
 * middlewarem `App\Http\Middleware\AktualizujOstatniaWizyte` (issue
 * #114/#115, bramka V1 z `docs/ROADMAP.md`).
 *
 * Trasa `/home` (`auth`) jest tu wyłącznie NOŚNIKIEM żądania uwierzytelnionego
 * — te testy nie sprawdzają niczego z ekranu strony głównej.
 */
class OstatniaWizytaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwsza_wizyta_ustawia_znacznik(): void
    {
        $basia = $this->user('basia');
        $this->assertNull($basia->ostatnio_widziany_at, 'Kontrola: świeże konto nie ma jeszcze znacznika.');

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertOk();

        $basia->refresh();
        $this->assertNotNull($basia->ostatnio_widziany_at, 'Wizyta zalogowanej osoby powinna ustawić znacznik.');
        $this->assertTrue(
            $basia->ostatnio_widziany_at->equalTo(Carbon::parse('2026-09-08 10:00:00', 'UTC')),
            'Znacznik powinien nieść dokładny moment wizyty, nie przybliżony.',
        );
    }

    public function test_druga_wizyta_w_oknie_throttla_nie_nadpisuje_znacznika(): void
    {
        config(['kuking.analytics.last_seen_throttle_minutes' => 15]);

        $basia = $this->user('basia');

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertOk();

        // ASERCJA KONTROLNA, NIE OZDOBA. `actingAs()` wpina do bramki
        // uwierzytelniania DOKŁADNIE ten obiekt PHP, którego mu podano — nie
        // odpytuje bazy od nowa przy KOLEJNYM żądaniu w tym samym teście
        // (w przeciwieństwie do prawdziwego żądania HTTP, gdzie sesja zawsze
        // ładuje świeży wiersz). Bez `refresh()` niżej `$basia` w PHP nadal
        // niosłaby `ostatnio_widziany_at = null` sprzed tej wizyty, a drugie
        // `actingAs($basia)` wstrzyknęłoby tę SAMĄ nieodświeżoną wartość do
        // żądania — throttl zobaczyłby `null`, uznał drugą wizytę za
        // pierwszą i przeszedł niezależnie od tego, czy warunek throttla
        // naprawdę działa. Assercja niżej rozstrzyga to jednoznacznie: jeśli
        // ona padnie, problem jest w PIERWSZYM zapisie (kolejność
        // middleware, cast kolumny, zapytanie `UPDATE`), nie w throttlu.
        $basia->refresh();
        $this->assertTrue(
            $basia->ostatnio_widziany_at->equalTo(Carbon::parse('2026-09-08 10:00:00', 'UTC')),
            'Kontrola: pierwsza wizyta nie zapisała dokładnego momentu — reszta tego testu '
            .'nic by wtedy nie sprawdzała.',
        );

        // Osiem minut później — wewnątrz okna 15 minut. Podajemy ODŚWIEŻONEGO
        // `$basia` (patrz komentarz wyżej) — dokładnie to, co dostałby
        // prawdziwy request z sesji.
        $this->travelTo(Carbon::parse('2026-09-08 10:08:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertOk();

        $basia->refresh();
        $this->assertTrue(
            $basia->ostatnio_widziany_at->equalTo(Carbon::parse('2026-09-08 10:00:00', 'UTC')),
            'Druga wizyta w oknie throttla nadpisała znacznik — zapis miał kosztować co najwyżej '
            .'raz na kilkanaście minut, nie przy każdym żądaniu.',
        );
    }

    public function test_wizyta_po_uplywie_throttla_nadpisuje_znacznik(): void
    {
        config(['kuking.analytics.last_seen_throttle_minutes' => 15]);

        $basia = $this->user('basia');

        $this->travelTo(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertOk();

        // Odświeżony obiekt, z tego samego powodu co w teście throttla wyżej
        // — `actingAs()` nie odpytuje bazy sam, więc bez tego druga wizyta
        // dostałaby wciąż `null` i przeszłaby niezależnie od tego, czy okno
        // throttla naprawdę minęło.
        $basia->refresh();
        $this->assertTrue(
            $basia->ostatnio_widziany_at->equalTo(Carbon::parse('2026-09-08 10:00:00', 'UTC')),
            'Kontrola: pierwsza wizyta nie zapisała dokładnego momentu.',
        );

        // Szesnaście minut później — poza oknem 15 minut.
        $this->travelTo(Carbon::parse('2026-09-08 10:16:00', 'UTC'));
        $this->actingAs($basia)->get(route('home'))->assertOk();

        $basia->refresh();
        $this->assertTrue(
            $basia->ostatnio_widziany_at->equalTo(Carbon::parse('2026-09-08 10:16:00', 'UTC')),
            'Wizyta poza oknem throttla powinna odświeżyć znacznik na nowy moment.',
        );
    }

    public function test_gosc_nie_dostaje_znacznika(): void
    {
        // Kontrola nietrywialna: bez żadnego zalogowanego żądania middleware
        // musi po prostu nic nie robić, a nie np. wywrócić stronę gościa.
        $this->get(route('landing'))->assertOk();

        $this->assertSame(0, User::query()->whereNotNull('ostatnio_widziany_at')->count());
    }

    public function test_zbanowane_konto_zostaje_wylogowane_przed_zapisaniem_znacznika(): void
    {
        // `EnsureAccountIsActive` stoi PRZED tym middlewarem w `bootstrap/app.php`
        // i wylogowuje konto zbanowane, zanim ten kod w ogóle dostanie żądanie
        // — kolejność jest częścią specyfikacji, nie przypadkiem, więc ten test
        // pilnuje, żeby ktoś jej kiedyś nie odwrócił.
        $marek = $this->user('marek', ['status' => User::STATUS_BANNED]);

        $this->actingAs($marek)->get(route('home'))->assertRedirect(route('login'));

        $marek->refresh();
        $this->assertNull(
            $marek->ostatnio_widziany_at,
            'Konto zbanowane zostaje wylogowane wcześniej w stosie middleware\'ów — nie powinno '
            .'zasilać żadnej metryki aktywności.',
        );
    }
}
