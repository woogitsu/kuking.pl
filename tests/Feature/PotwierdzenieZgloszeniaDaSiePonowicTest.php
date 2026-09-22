<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\NotifyReporterReceipt;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * POTWIERDZENIE PRZYJĘCIA ZGŁOSZENIA DA SIĘ BEZPIECZNIE PONOWIĆ
 * (issue #797, DSA art. 16 ust. 4).
 *
 * CO ZMIERZONO PRZED ZMIANĄ — sonda na nietkniętym `534e0a51`, PostgreSQL
 * 55439: sprawa zapisana bez potwierdzenia (stan po awarii) zostawała bez
 * niego NA ZAWSZE. Ponowne kliknięcie „Zgłoś" oddawało tę samą sprawę
 * i wracało wcześniej: 1 sprawa, 0 potwierdzeń, `receipt_sent_at = null`.
 *
 * DWIE AWARIE, KTÓRE TEN PLIK WSTRZYKUJE NAPRAWDĘ
 * A. `INSERT` powiadomienia pada po udanym zapisie sprawy.
 * B. zapis znacznika pada po udanym `INSERT` powiadomienia.
 * Obie przez `DB::listen`, na rzeczywistej bazie tego zadania.
 *
 * GRANICA, KTÓREJ NIE WOLNO PRZEKROCZYĆ PRZY NAPRAWIE (z komentarza pod
 * issue): „nie ma powiadomienia → utwórz" byłoby błędem. `RetencjaPowiadomien`
 * świadomie kasuje ping po ogólnym okresie retencji, a poprawnie
 * potwierdzona sprawa zostaje na 36 miesięcy. Pilnuje tego
 * `test_retencja_pingu_nie_wskrzesza_potwierdzenia`.
 */
class PotwierdzenieZgloszeniaDaSiePonowicTest extends TestCase
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

    private function potwierdzenia(): int
    {
        return Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->count();
    }

    /**
     * Wstrzyknięcie awarii w PIERWSZE zapytanie pasujące do wzorca.
     *
     * `DB::listen` woła się PO wykonaniu zapytania, więc wyjątek leci już po
     * zapisie — dokładnie ten kształt awarii opisuje issue („awaria PO
     * zapisie"). Zwracana domknięta funkcja wyłącza pułapkę.
     */
    private function zepsuj(string $wzorzec): callable
    {
        $aktywna = true;

        DB::listen(function ($zapytanie) use ($wzorzec, &$aktywna): void {
            if ($aktywna && str_contains($zapytanie->sql, $wzorzec)) {
                $aktywna = false;
                throw new RuntimeException('Wstrzyknięta awaria: '.$wzorzec);
            }
        });

        return function () use (&$aktywna): void {
            $aktywna = false;
        };
    }

    private function zglos(User $kto, Post $co, array $tresc = ['reason' => 'spam', 'details' => 'To jest reklama.']): TestResponse
    {
        return $this->actingAs($kto)->post(
            route('reports.store', ['type' => 'post', 'id' => $co->getKey()]),
            $tresc,
        );
    }

    // ------------------------------------------------------------------
    // AWARIA A: sprawa zapisana, powiadomienia nie ma
    // ------------------------------------------------------------------

    public function test_po_awarii_zapisu_powiadomienia_ponowienie_dokancza_potwierdzenie(): void
    {
        $zglaszajacy = $this->user('zglaszajacyA');
        $wpis = $this->wpis();

        $wylacz = $this->zepsuj('insert into "notifications"');
        $this->zglos($zglaszajacy, $wpis);
        $wylacz();

        // Sprawa PRZEŻYŁA awarię potwierdzenia — to jest celowa ochrona
        // i ta zmiana jej nie rusza.
        $this->assertSame(1, Report::query()->count());
        $this->assertSame(0, $this->potwierdzenia());
        $this->assertNull(Report::firstOrFail()->receipt_sent_at);

        // PONOWIENIE tego samego zgłoszenia dokańcza zaległe potwierdzenie.
        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->count(), 'Ponowienie utworzyło drugą sprawę.');
        $this->assertSame(1, $this->potwierdzenia(), 'Zaległe potwierdzenie nie zostało dokończone.');
        $this->assertNotNull(Report::firstOrFail()->receipt_sent_at);
    }

    /**
     * Awaria potwierdzenia nie wywraca przyjęcia sprawy: człowiek trafia na
     * kartę swojej sprawy i widzi jej numer, zamiast strony błędu
     * sugerującej, że zgłoszenie przepadło (kryterium 5).
     */
    public function test_awaria_potwierdzenia_nie_zabiera_czlowiekowi_numeru_sprawy(): void
    {
        $zglaszajacy = $this->user('zglaszajacyA2');
        $wpis = $this->wpis();

        $wylacz = $this->zepsuj('insert into "notifications"');
        $odpowiedz = $this->zglos($zglaszajacy, $wpis);
        $wylacz();

        $sprawa = Report::firstOrFail();
        $odpowiedz->assertRedirect(route('reports.mine.show', $sprawa))->assertSessionHasNoErrors();

        $this->actingAs($zglaszajacy)->get(route('reports.mine.show', $sprawa))
            ->assertOk()
            ->assertSee($sprawa->numer_sprawy);
    }

    // ------------------------------------------------------------------
    // AWARIA B: powiadomienie zapisane, znacznik nie
    // ------------------------------------------------------------------

    public function test_awaria_znacznika_nie_zostawia_powiadomienia_bez_znacznika(): void
    {
        $zglaszajacy = $this->user('zglaszajacyB');
        $wpis = $this->wpis();

        // Znacznik idzie dziś PRZED powiadomieniem i oba są w jednej
        // transakcji, więc awaria na którymkolwiek z nich cofa całość.
        $wylacz = $this->zepsuj('update "reports" set "receipt_sent_at"');
        $this->zglos($zglaszajacy, $wpis);
        $wylacz();

        $this->assertSame(1, Report::query()->count());
        $this->assertNull(Report::firstOrFail()->receipt_sent_at);
        $this->assertSame(
            0,
            $this->potwierdzenia(),
            'Powiadomienie przeżyło awarię znacznika — naiwne ponowienie zrobi z niego duplikat.',
        );

        // Ponowienie dokańcza — i nadal DOKŁADNIE jedno potwierdzenie.
        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();

        $this->assertSame(1, $this->potwierdzenia());
        $this->assertNotNull(Report::firstOrFail()->receipt_sent_at);
    }

    // ------------------------------------------------------------------
    // Granice naprawy
    // ------------------------------------------------------------------

    public function test_zwykle_ponowienie_nie_mnozy_spraw_ani_pingow(): void
    {
        $zglaszajacy = $this->user('zglaszajacyC');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();
        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();
        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()->count());
        $this->assertSame(1, $this->potwierdzenia());
    }

    /**
     * NAJWAŻNIEJSZA GRANICA: potwierdzenie, które kiedyś doszło do skutku,
     * a potem zostało skasowane przez retencję, NIE jest zaległością.
     */
    public function test_retencja_pingu_nie_wskrzesza_potwierdzenia(): void
    {
        $zglaszajacy = $this->user('zglaszajacyD');
        $wpis = $this->wpis();

        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->potwierdzenia());

        // Tak robi `kuking:sprzataj-powiadomienia`: ping znika, sprawa
        // i jej znacznik zostają.
        Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->delete();
        $this->assertNotNull(Report::firstOrFail()->receipt_sent_at);

        $this->zglos($zglaszajacy, $wpis)->assertSessionHasNoErrors();

        $this->assertSame(0, $this->potwierdzenia(), 'Ponowienie wskrzesiło ping skasowany przez retencję.');
    }

    /**
     * Zgłoszenie bez konta nie ma adresata — to legalny stan, a nie
     * zaległe potwierdzenie. Ponowienie nie może go „naprawiać".
     */
    public function test_sprawa_bez_adresata_nie_jest_zalegloscia(): void
    {
        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        $this->assertNull(app(NotifyReporterReceipt::class)->handle($zgloszenie));
        $this->assertSame(0, $this->potwierdzenia());
        $this->assertNull($zgloszenie->refresh()->receipt_sent_at);
    }

    /**
     * Dwa równoległe ponowienia nie mnożą pingów: rozstrzyga o tym warunkowy
     * `UPDATE` w bazie, nie `SELECT` + `if` w PHP.
     */
    public function test_dwa_rownolegle_ponowienia_daja_jedno_potwierdzenie(): void
    {
        $zglaszajacy = $this->user('zglaszajacyE');
        $wpis = $this->wpis();

        $wylacz = $this->zepsuj('insert into "notifications"');
        $this->zglos($zglaszajacy, $wpis);
        $wylacz();

        $this->assertSame(0, $this->potwierdzenia());

        // Dwa wołania tej samej akcji na DWÓCH różnych egzemplarzach modelu —
        // tak wygląda stan dwóch żądań, z których każde odczytało sprawę
        // przed potwierdzeniem.
        $akcja = app(NotifyReporterReceipt::class);
        $pierwszy = Report::firstOrFail();
        $drugi = Report::firstOrFail();

        $this->assertNotNull($akcja->handle($pierwszy));
        $this->assertNull($akcja->handle($drugi), 'Drugie ponowienie utworzyło drugi ping.');

        $this->assertSame(1, $this->potwierdzenia());
    }
}
