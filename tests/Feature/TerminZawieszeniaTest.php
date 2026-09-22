<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\DlugoscZawieszenia;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Report;
use App\Models\User;
use App\Support\WierszFormularza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DŁUGOŚĆ ZAWIESZENIA W FORMULARZU DECYZJI (zgłoszenie właściciela).
 *
 * Dwie usterki, jedna groźniejsza, niż wygląda:
 *
 *  1. Grupa „Na jak długo" nie miała pozycji zerowej, więc raz zaznaczonego
 *     „Na 1 dzień" nie dało się odkliknąć. Jedyną drogą powrotu było
 *     odświeżenie strony — i utrata wszystkiego, co moderator wpisał
 *     w uzasadnieniu (AGENTS.md §5: poprawne dane nigdy nie znikają).
 *
 *  2. BRAK WYBORU ZNACZYŁ ZAWIESZENIE BEZTERMINOWE. Pomyłka przez
 *     zaniechanie dawała więc najsurowszą karę, jaką panel potrafi wydać —
 *     odwracalną wyłącznie przez odwołanie (DSA art. 20). To jest ta
 *     groźniejsza połowa zgłoszenia i pilnują jej dwa testy niżej:
 *     `test_zawieszenie_bez_wybranego_terminu_nie_przechodzi` oraz
 *     `test_zadanie_bez_pola_terminu_tez_nie_znaczy_bezterminowo`.
 *
 * Do tego trzeci brak: 1/7/30 dni to nie wszystkie sensowne wybory.
 */
class TerminZawieszeniaTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenieNa(User $osoba): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'user',
            'target_id' => $osoba->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dane
     */
    private function decyzja(User $moderator, Report $zgloszenie, array $dane): TestResponse
    {
        return $this->actingAs($moderator)
            ->from(route('admin.reports'))
            // `_wiersz` tak, jak wysyła go prawdziwy formularz (issue #243,
            // `App\Support\WierszFormularza`): bez niego `old()` po nieudanej
            // walidacji nie wie, KTÓRE zgłoszenie naprawdę wróciło z błędem,
            // i nie pokazuje niczego z powrotem — nawet na stronie z jednym
            // zgłoszeniem, jak w tym pliku.
            ->post(route('admin.reports.decide', $zgloszenie), array_merge([
                'reason_code' => 'obrazanie-nekanie',
                WierszFormularza::POLE => (string) $zgloszenie->id,
            ], $dane));
    }

    // -----------------------------------------------------------------
    // 1. „Bez zawieszenia" jest stanem domyślnym
    // -----------------------------------------------------------------

    public function test_bez_zawieszenia_jest_pierwsza_i_domyslnie_zaznaczona_pozycja(): void
    {
        $moderator = $this->moderator();
        $this->zgloszenieNa($this->user('basia'));

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.reports'))
            ->assertOk()
            ->getContent();

        // Zaznaczona jest pozycja „brak" i tylko ona.
        $this->assertMatchesRegularExpression(
            '/name="suspend_days" value="brak"[^>]*checked/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="suspend_days" value="(1|7|30|wlasny|bezterminowo)"[^>]*checked/',
            $html,
        );

        // PIERWSZA, nie dopisana na końcu: kolejność jest tu treścią, bo
        // pozycja pierwsza czyta się jako stan wyjściowy.
        $this->assertLessThan(
            (int) strpos($html, 'Na 1 dzień'),
            (int) strpos($html, 'Bez zawieszenia'),
        );

        // Podpis pod grupą mówił nieprawdę o nowym zachowaniu.
        $this->assertStringNotContainsString('Bez wyboru zawieszenie jest bezterminowe', $html);
    }

    public function test_bez_zawieszenia_naprawde_nie_zawiesza(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_WARN,
            'suspend_days' => DlugoscZawieszenia::BRAK,
            'user_message' => 'Prosimy o spokojniejszy ton.',
        ])->assertSessionHasNoErrors();

        $basia->refresh();

        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }

    public function test_zawieszenie_bez_wybranego_terminu_nie_przechodzi(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $zgloszenie = $this->zgloszenieNa($basia);

        $odpowiedz = $this->decyzja($moderator, $zgloszenie, [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::BRAK,
        ]);

        $odpowiedz->assertSessionHasErrors('suspend_days');

        // NAJWAŻNIEJSZE: konto zostaje nietknięte. Dawne zachowanie
        // („brak wyboru = bezterminowo") zawiesiłoby je tutaj na zawsze.
        $basia->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->status_expires_at);

        // I nie ma po tej nie-decyzji ani śladu w logu, ani zamkniętej sprawy.
        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->refresh()->status);
    }

    /**
     * TEN PRZYPADEK JEST SEDNEM ODWRÓCENIA DOMYŚLNEGO ZACHOWANIA.
     *
     * Żądanie bez pola `suspend_days` to dawny formularz, zakładka otwarta
     * przed wdrożeniem albo drugie wejście dopisane w przyszłości. Do dziś
     * kończyło się karą BEZTERMINOWĄ. Teraz kończy się pytaniem.
     */
    public function test_zadanie_bez_pola_terminu_tez_nie_znaczy_bezterminowo(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
        ])->assertSessionHasErrors('suspend_days');

        $basia->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
    }

    // -----------------------------------------------------------------
    // 2. Gotowe terminy działają jak dotąd
    // -----------------------------------------------------------------

    public function test_gotowe_terminy_dzialaja_jak_dotad(): void
    {
        $moderator = $this->moderator();

        foreach (DlugoscZawieszenia::GOTOWE as $dni) {
            $osoba = $this->user('karany'.$dni);

            $this->decyzja($moderator, $this->zgloszenieNa($osoba), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'suspend_days' => (string) $dni,
            ])->assertSessionHasNoErrors();

            $osoba->refresh();

            $this->assertSame(User::STATUS_SUSPENDED, $osoba->status);
            $this->assertNotNull($osoba->status_expires_at);
            $this->assertEqualsWithDelta(
                now()->addDays($dni)->timestamp,
                $osoba->status_expires_at->timestamp,
                5,
                'Termin dla '.$dni.' dni policzony inaczej niż przed zmianą.',
            );
        }
    }

    public function test_bezterminowo_nadal_zostawia_konto_bez_terminu(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::BEZTERMINOWO,
        ])->assertSessionHasNoErrors();

        $basia->refresh();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }

    // -----------------------------------------------------------------
    // 3. Własny termin
    // -----------------------------------------------------------------

    public function test_wlasny_termin_ustawia_dokladnie_tyle_dni(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            'suspend_days_custom' => '14',
        ])->assertSessionHasNoErrors();

        $basia->refresh();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->status);
        $this->assertNotNull($basia->status_expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(14)->timestamp,
            $basia->status_expires_at->timestamp,
            5,
        );
    }

    public function test_wlasny_termin_bez_liczby_daje_blad_po_polsku(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $odpowiedz = $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
        ]);

        $odpowiedz->assertSessionHasErrors('suspend_days_custom');

        $blad = (string) session('errors')->first('suspend_days_custom');

        // Komunikat ma powiedzieć, CO ZROBIĆ (AGENTS.md §5), a nie „422".
        $this->assertStringContainsString('wpisz liczbę dni', $blad);
        $this->assertStringContainsString('365', $blad);
        $this->assertStringNotContainsString('suspend_days', $blad);

        $basia->refresh();
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
    }

    /**
     * TO JEST SEDNO ZGŁOSZENIA: „muszę odświeżyć stronę, a wtedy tracę
     * wszystko, co wpisałem". Po nieudanej walidacji uzasadnienie, notatka
     * i wybrana decyzja MUSZĄ wrócić na ekran.
     */
    public function test_uzasadnienie_nie_ginie_przy_bledzie_walidacji(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $uzasadnienie = 'Komentarz pod przepisem obraża konkretną osobę i wraca po ostrzeżeniu.';
        $notatka = 'Trzecie zgłoszenie tej samej osoby w tym tygodniu.';

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            // Liczby brakuje — to jedyny błąd w tym formularzu.
            'note' => $notatka,
            'user_message' => $uzasadnienie,
        ])->assertRedirect(route('admin.reports'));

        $kolejka = $this->actingAs($moderator)->get(route('admin.reports'))->assertOk();

        $kolejka->assertSee($uzasadnienie, false);
        $kolejka->assertSee($notatka, false);

        // Błąd jest widoczny NIE TYLKO przy polu: nad listą stoi
        // podsumowanie (UX_50_PLUS.md) — jedno na ekran, nie jedno na każde
        // z dwudziestu pięciu zgłoszeń.
        $kolejka->assertSee('Sprawdź formularz');
        $this->assertSame(
            1,
            substr_count((string) $kolejka->getContent(), 'class="error-summary"'),
            'Podsumowanie błędów ma być jedno na ekran.',
        );

        // Wybrana decyzja też nie ma znikać — inaczej moderator klika ją
        // drugi raz i może kliknąć inną niż za pierwszym razem.
        $this->assertMatchesRegularExpression(
            '/name="action" value="suspend"[^>]*checked/',
            (string) $kolejka->getContent(),
        );

        // I sam wybór terminu: „własny termin" zostaje zaznaczony, żeby było
        // widać, PRZY KTÓREJ pozycji brakuje liczby.
        $this->assertMatchesRegularExpression(
            '/name="suspend_days" value="wlasny"[^>]*checked/',
            (string) $kolejka->getContent(),
        );
    }

    public function test_liczba_dni_poza_zakresem_jest_odrzucona(): void
    {
        $moderator = $this->moderator();

        foreach (['0', '-3', (string) (DlugoscZawieszenia::MAX_DNI + 1), 'dwa tygodnie'] as $liczba) {
            $osoba = $this->user('poza'.preg_replace('/\W/', '', $liczba));

            $this->decyzja($moderator, $this->zgloszenieNa($osoba), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'suspend_days' => DlugoscZawieszenia::WLASNY,
                'suspend_days_custom' => $liczba,
            ])->assertSessionHasErrors('suspend_days_custom');

            $this->assertSame(
                User::STATUS_ACTIVE,
                $osoba->refresh()->status,
                'Termin „'.$liczba.'" nie powinien zawiesić konta.',
            );
        }
    }

    /**
     * Liczba wpisana przy INNYM wyborze niż „własny termin" jest po cichu
     * ignorowana — także wtedy, gdy jest bzdurna. Moderator, który zaczął
     * wpisywać własny termin i zmienił zdanie, nie ma dostawać błędu za
     * pole, którego nie użył.
     */
    public function test_liczba_przy_gotowym_terminie_jest_po_cichu_ignorowana(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => '7',
            'suspend_days_custom' => '9999',
        ])->assertSessionHasNoErrors();

        $basia->refresh();

        $this->assertEqualsWithDelta(
            now()->addDays(7)->timestamp,
            $basia->status_expires_at?->timestamp,
            5,
        );
    }

    public function test_liczba_przy_decyzji_innej_niz_zawieszenie_nie_zawiesza(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_WARN,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            'suspend_days_custom' => '9999',
            'user_message' => 'Prosimy o spokojniejszy ton.',
        ])->assertSessionHasNoErrors();

        $basia->refresh();

        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertNull($basia->status_expires_at);
    }

    // -----------------------------------------------------------------
    // 4. Człowiek musi wiedzieć, ile trwa jego kara
    // -----------------------------------------------------------------

    public function test_powiadomienie_podaje_wlasny_termin_po_polsku(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            'suspend_days_custom' => '45',
        ])->assertSessionHasNoErrors();

        $powiadomienie = Notification::query()
            ->where('user_id', $basia->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->latest()
            ->firstOrFail();

        $this->assertStringContainsString(
            now()->addDays(45)->translatedFormat('j F Y'),
            (string) $powiadomienie->data['title'],
        );
    }

    /**
     * Log moderacji musi dać się przeczytać przy odwołaniu. Sam wybór
     * `wlasny` nie mówi w nim nic — potrzebna jest data, która trafiła
     * na konto.
     */
    public function test_dziennik_audytu_zapisuje_faktyczny_termin(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            'suspend_days_custom' => '21',
        ])->assertSessionHasNoErrors();

        $wpis = AuditLogEntry::query()
            ->where('action', 'moderation.decided')
            ->latest()
            ->firstOrFail();

        $this->assertSame(DlugoscZawieszenia::WLASNY, $wpis->metadata['suspend_days']);
        $this->assertNotNull($wpis->metadata['suspend_until'] ?? null);
        $this->assertStringStartsWith(
            now()->addDays(21)->format('Y-m-d'),
            (string) $wpis->metadata['suspend_until'],
        );
    }

    // -----------------------------------------------------------------
    // 5. Kara z własnym terminem zdejmuje się sama
    // -----------------------------------------------------------------

    /**
     * `kuking:zdejmij-wygasle-kary` nie zna pojęcia „1, 7 albo 30 dni" —
     * pyta o datę. Ten test pilnuje, że własny termin nie wypada z tego
     * automatu, bo kara, której nikt nie zdejmuje, jest w praktyce trwała.
     */
    public function test_komenda_zdejmuje_kare_z_wlasnym_terminem(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $this->decyzja($moderator, $this->zgloszenieNa($basia), [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => DlugoscZawieszenia::WLASNY,
            'suspend_days_custom' => '3',
        ])->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_SUSPENDED, $basia->refresh()->status);

        $this->travel(4)->days();

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $this->assertSame(User::STATUS_ACTIVE, $basia->refresh()->status);
        $this->assertNull($basia->status_expires_at);
    }
}
