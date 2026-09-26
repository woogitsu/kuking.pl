<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\NumerSprawy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PONOWNE ZGŁOSZENIE NIE UDAJE, ŻE PRZYJĘŁO NOWY OPIS (issue #796).
 *
 * CO ZMIERZONO PRZED ZMIANĄ — sonda na nietkniętym `534e0a51`, PostgreSQL
 * 55439: drugie zgłoszenie tej samej treści z innym powodem i opisem
 * `NOWA_INFORMACJA` zostawiało w bazie `reason = spam`, `details =
 * PIERWSZY_OPIS`, a człowiek dostawał komunikat „Dziękujemy. Zgłoszenie
 * trafiło do nas…" — dokładnie ten sam co przy przyjęciu nowej sprawy.
 *
 * CZEGO TEN PLIK NIE KWESTIONUJE
 * Deduplikacji. Jedna otwarta sprawa na parę (zgłaszający, treść) zostaje,
 * indeks `reports_one_open_per_pair` zostaje, liczba powiadomień zostaje.
 * Problem dotyczy KOMUNIKATU i porzuconego tekstu, nie liczby spraw.
 */
class PonowneZgloszenieMowiCzegoNieZapisaloTest extends TestCase
{
    use RefreshDatabase;

    private int $licznik = 0;

    private function wpis(): Post
    {
        return Post::factory()->for($this->user('autor'.(++$this->licznik)), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    private function zglos(User $kto, Post $co, array $tresc): TestResponse
    {
        return $this->actingAs($kto)
            ->from(route('reports.create', ['type' => 'post', 'id' => $co->getKey()]))
            ->post(route('reports.store', ['type' => 'post', 'id' => $co->getKey()]), $tresc);
    }

    // ------------------------------------------------------------------
    // Droga ciepłego `SELECT`-a
    // ------------------------------------------------------------------

    public function test_nowy_opis_nie_dostaje_zwyklego_potwierdzenia_przyjecia(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796a');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'spam', 'details' => 'PIERWSZY_OPIS'])
            ->assertSessionHasNoErrors();

        $drugie = $this->zglos($zglaszajacy, $wpis, ['reason' => 'harassment', 'details' => 'NOWA_INFORMACJA']);

        // 1. BAZA: sprawa jedna, a oryginalny dowód nietknięty.
        $sprawa = Report::firstOrFail();
        $this->assertSame(1, Report::query()->count());
        $this->assertSame('spam', $sprawa->reason);
        $this->assertSame('PIERWSZY_OPIS', $sprawa->details);

        // 2. ODPOWIEDŹ: nie ma w niej zdania o przyjęciu zgłoszenia.
        $drugie->assertSessionMissing('status');
        $drugie->assertSessionHasErrors('details');

        $komunikat = (string) session('errors')->first('details');
        $this->assertStringContainsString('NIE dopisaliśmy', $komunikat);
        $this->assertStringContainsString($sprawa->numer_sprawy, $komunikat);
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $komunikat);
    }

    /**
     * TEKST CZŁOWIEKA NIE ZNIKA — wraca do formularza, da się go skopiować
     * albo poprawić (AGENTS.md: poprawne dane nigdy nie giną).
     */
    public function test_wpisany_tekst_wraca_do_formularza(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796b');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'spam', 'details' => 'PIERWSZY_OPIS'])
            ->assertSessionHasNoErrors();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'harassment', 'details' => 'NOWA_INFORMACJA'])
            ->assertRedirect(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]));

        // Renderowany HTML, nie sam status przekierowania: tekst ma być
        // widoczny w polu, a komunikat w podsumowaniu błędów.
        $formularz = $this->actingAs($zglaszajacy)
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk();

        $formularz->assertSee('NOWA_INFORMACJA');
        $formularz->assertSee('NIE dopisaliśmy', false);
        $formularz->assertSee('value="harassment" checked', false);
    }

    // ------------------------------------------------------------------
    // Zwykłe podwójne kliknięcie — inna sytuacja, inna odpowiedź
    // ------------------------------------------------------------------

    public function test_identyczne_ponowienie_mowi_ze_sprawa_juz_jest_a_nie_ze_przyjeto_nowa(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796c');
        $wpis = $this->wpis();
        $tresc = ['reason' => 'spam', 'details' => 'To jest reklama.'];

        $this->zglos($zglaszajacy, $wpis, $tresc)->assertSessionHasNoErrors();
        $drugie = $this->zglos($zglaszajacy, $wpis, $tresc)->assertSessionHasNoErrors();

        $sprawa = Report::firstOrFail();
        $this->assertSame(1, Report::query()->count());

        $drugie->assertRedirect(route('reports.mine.show', $sprawa));

        $status = (string) self::sesjaPrzekierowania($drugie)->get('status');
        $this->assertStringContainsString('już u nas jest', $status);
        $this->assertStringContainsString($sprawa->numer_sprawy, $status);
        $this->assertStringNotContainsString('Zgłoszenie trafiło do nas', $status);

        // Dotychczasowe gwarancje liczby powiadomień zostają nietknięte.
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
    }

    /**
     * Ponowienie z PUSTYM opisem nie jest nową informacją — nikt niczego
     * nie dopisał, więc nie ma o czym mówić „nie zapisaliśmy".
     */
    public function test_ponowienie_bez_opisu_nie_jest_uzupelnieniem(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796d');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'spam', 'details' => 'PIERWSZY_OPIS'])
            ->assertSessionHasNoErrors();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'spam'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('reports.mine.show', Report::firstOrFail()));
    }

    // ------------------------------------------------------------------
    // Droga konfliktu unikalności — ta sama odpowiedź
    // ------------------------------------------------------------------

    /**
     * `ReportContent` ma DWIE drogi powrotu do istniejącej sprawy: ciepły
     * `SELECT` i odbicie się o indeks `reports_one_open_per_pair`. Test
     * wyżej chodzi pierwszą; ten wymusza drugą, wstawiając wiersz między
     * `SELECT`-em a `INSERT`-em. Poprawka, która zna tylko jedną z nich,
     * oblewa tutaj.
     */
    public function test_droga_konfliktu_unikalnosci_odpowiada_tak_samo(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796e');
        $wpis = $this->wpis();

        $podstawiony = false;

        DB::listen(function ($zapytanie) use (&$podstawiony, $zglaszajacy, $wpis): void {
            if ($podstawiony || ! str_contains($zapytanie->sql, 'select * from "reports"')) {
                return;
            }

            $podstawiony = true;

            // Sprawa powstaje MIĘDZY `SELECT`-em a `INSERT`-em akcji —
            // dokładnie ten wyścig, który odbija indeks.
            DB::table('reports')->insert([
                'id' => (string) Str::uuid7(),
                'numer_sprawy' => NumerSprawy::wygeneruj(),
                'reporter_id' => $zglaszajacy->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reason' => 'spam',
                'details' => 'PIERWSZY_OPIS',
                'status' => Report::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $odpowiedz = $this->zglos($zglaszajacy, $wpis, ['reason' => 'harassment', 'details' => 'NOWA_INFORMACJA']);

        $this->assertTrue($podstawiony, 'Wyścig nie został wymuszony — test nie mierzy tego, co obiecuje.');
        $this->assertSame(1, Report::query()->count());
        $this->assertSame('PIERWSZY_OPIS', Report::firstOrFail()->details);

        $odpowiedz->assertSessionHasErrors('details');
        $odpowiedz->assertSessionMissing('status');
    }

    // ------------------------------------------------------------------
    // Granice
    // ------------------------------------------------------------------

    public function test_po_zamknieciu_sprawy_nowe_zgloszenie_jest_przyjmowane_normalnie(): void
    {
        $zglaszajacy = $this->user('zglaszajacy796f');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis, ['reason' => 'spam', 'details' => 'PIERWSZY_OPIS'])
            ->assertSessionHasNoErrors();

        Report::firstOrFail()->forceFill([
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $nowe = $this->zglos($zglaszajacy, $wpis, ['reason' => 'harassment', 'details' => 'NOWA_INFORMACJA']);

        $nowe->assertSessionHasNoErrors();
        $this->assertSame(2, Report::query()->count());
        $this->assertStringContainsString(
            'Zgłoszenie trafiło do nas',
            (string) self::sesjaPrzekierowania($nowe)->get('status'),
        );
    }

    public function test_cudza_sprawa_nie_blokuje_wlasnego_zgloszenia(): void
    {
        $wpis = $this->wpis();

        $this->zglos($this->user('pierwsza796'), $wpis, ['reason' => 'spam', 'details' => 'PIERWSZY_OPIS'])
            ->assertSessionHasNoErrors();

        $druga = $this->zglos($this->user('druga796'), $wpis, ['reason' => 'harassment', 'details' => 'NOWA_INFORMACJA']);

        $druga->assertSessionHasNoErrors();
        $this->assertSame(2, Report::query()->count());
    }
}
