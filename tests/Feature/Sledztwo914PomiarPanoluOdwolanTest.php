<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZanotujOstatniaWizyte;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #914: dowód i regresja dla fałszywego DOMAIN_CHANGED w mierniku panelu (#581).
 *
 * HIPOTEZA (potwierdzona tym testem)
 * `scripts/panel-validation.mjs::runCandidate` porównuje migawkę SIEDMIU
 * tabel wziętą TUŻ PRZED wysłaniem odrzuconego formularza z migawką wziętą
 * PO nim — a między tymi dwiema migawkami leży dokładnie jedno dodatkowe
 * uwierzytelnione żądanie GET (`page.goto(list)` po przekierowaniu z
 * walidacji). Globalny middleware `AktualizujOstatniaWizyte`
 * (`bootstrap/app.php`, grupa `web`, KAŻDE uwierzytelnione żądanie) woła
 * `ZanotujOstatniaWizyte::handle()`, który zapisuje
 * `users.ostatnio_widziany_at` — throttlowane co
 * `config('kuking.analytics.last_seen_throttle_minutes')` (domyślnie 15 min).
 * Jeśli między migawkami próg throttla akurat wygasł, ten zapis "przy okazji"
 * jest jedyną różnicą — a `isDeepStrictEqual` i tak zgłasza `DOMAIN_CHANGED`,
 * mimo że żadna akcja DOMENOWA (odwołanie, decyzja, zgłoszenie) nic nie
 * zapisała.
 *
 * DLACZEGO TEN TEST NIE CZEKA 15 MINUT
 * Zamiast czekać na naturalne wygaśnięcie throttla, test COFA
 * `users.ostatnio_widziany_at` badanego konta o więcej niż próg — dokładnie
 * ten warunek, który w CI zdarza się losowo, tu jest wymuszony
 * deterministycznie i w ułamku sekundy.
 *
 * DLACZEGO PRAWDZIWE ŻĄDANIA HTTP, NIE WYWOŁANIE KLASY WPROST
 * Test woła `$this->post()` i `$this->get()` na prawdziwych trasach panelu —
 * to przechodzi przez PEŁNY stos middleware grupy `web` (w tym
 * `AktualizujOstatniaWizyte`), dokładnie tak, jak robi to
 * `scripts/panel-validation.mjs` przez Playwrighta. Różnica jest wyłącznie
 * w warstwie transportu (kernel HTTP Laravela zamiast prawdziwego gniazda
 * TCP) — middleware, sesja i baza są te same.
 */
class Sledztwo914PomiarPanoluOdwolanTest extends TestCase
{
    use RefreshDatabase;

    private const TABELE = ['reports', 'appeals', 'posts', 'users', 'moderation_actions', 'notifications', 'audit_log'];

    /**
     * Te same dwie kolumny co wykluczone w
     * `scripts/panel-validation-snapshot.php` (`$klucePomijaneWUsers`,
     * komentarz #914) — zapisywane PRZY OKAZJI każdego uwierzytelnionego
     * żądania przez globalny middleware `AktualizujOstatniaWizyte`, nie
     * przez żadną akcję panelu.
     */
    private const POMIJANE_W_USERS = ['ostatnio_widziany_at', 'pwa_prompt_state'];

    /**
     * Migawka WSZYSTKICH kolumn z siedmiu tabel — tak, jak DZIŚ (przed
     * naprawą) robi to `scripts/panel-validation-snapshot.php` (~linia 118):
     * `SELECT *`, bez wykluczenia żadnej kolumny.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function migawkaPelna(): array
    {
        $wynik = [];
        foreach (self::TABELE as $tabela) {
            $wynik[$tabela] = DB::table($tabela)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $wynik;
    }

    /**
     * Ta sama migawka, ale po naprawie z ZADANIA 3: kolumny zapisywane przez
     * globalny middleware "przy okazji" żądania są wykluczone WYŁĄCZNIE
     * z sekcji `users`. Reszta tabel — w tym `audit_log` — zostaje
     * nietknięta, więc prawdziwy zapis w dowolnej z nich nadal jest widoczny.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function migawkaPoNaprawie(): array
    {
        $migawka = $this->migawkaPelna();
        $migawka['users'] = array_map(
            static fn (array $row): array => array_diff_key($row, array_flip(self::POMIJANE_W_USERS)),
            $migawka['users'],
        );

        return $migawka;
    }

    /**
     * Różnica między dwiema migawkami: lista `tabela.kolumna` (i klucz
     * wiersza), na której wartość się zmieniła. Puste == identyczne, tak jak
     * `isDeepStrictEqual` w mjs — tylko że tu widać KONKRETNIE co i gdzie.
     *
     * @return list<string>
     */
    private function roznice(array $przed, array $po): array
    {
        $roznice = [];
        foreach (self::TABELE as $tabela) {
            $przedTabela = collect($przed[$tabela])->keyBy('id');
            $poTabela = collect($po[$tabela])->keyBy('id');
            foreach ($poTabela as $id => $wiersz) {
                $stary = $przedTabela->get($id);
                if ($stary === null) {
                    $roznice[] = "{$tabela}[{$id}]: NOWY WIERSZ";

                    continue;
                }
                foreach ($wiersz as $kolumna => $wartosc) {
                    if ($wartosc !== $stary[$kolumna]) {
                        $roznice[] = "{$tabela}[{$id}].{$kolumna}";
                    }
                }
            }
            foreach ($przedTabela->keys() as $id) {
                if (! $poTabela->has($id)) {
                    $roznice[] = "{$tabela}[{$id}]: WIERSZ ZNIKNĄŁ";
                }
            }
        }

        return $roznice;
    }

    private function zbudujOdwolanie(User $autor, User $moderator): Appeal
    {
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);

        $report = Report::create([
            'reporter_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'harassment',
        ]);
        // `status` NIE jest w $fillable (AGENTS.md §7) — ustawiane wprost.
        DB::table('reports')->where('id', $report->getKey())->update(['status' => Report::STATUS_RESOLVED]);

        $action = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
        ]);

        return Appeal::create([
            'moderation_action_id' => $action->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie było spamem, proszę o ponowne rozpatrzenie mojej sprawy.',
            'status' => Appeal::STATUS_OPEN,
        ]);
    }

    /**
     * ZADANIE 1: dowód deterministyczny.
     *
     * Scenariusz `appeal-short` z `scripts/panel-validation.mjs`: `outcome`
     * poprawny, `decision_note` za krótkie (< 10 znaków) — walidacja
     * ODRZUCA żądanie, `ResolveAppeal::handle()` NIGDY się nie wykonuje.
     * Mimo to migawka "przed" i "po" (dziś, bez naprawy) różnią się —
     * i wyłącznie w jednym miejscu.
     */
    public function test_throttlowany_zapis_ostatniej_wizyty_wyglada_jak_zmiana_domeny_dla_falszywej_walidacji(): void
    {
        $admin = $this->admin();
        $autor = $this->user('autor914');
        $odwolanie = $this->zbudujOdwolanie($autor, $admin);

        // WYMUSZENIE WARUNKU: cofamy ostatnią wizytę admina o więcej niż próg
        // throttla (domyślnie 15 min) — zamiast czekać, aż to się zdarzy samo.
        $prog = (int) config('kuking.analytics.last_seen_throttle_minutes');
        $this->assertGreaterThan(0, $prog, 'Test zakłada dodatni próg throttla z config/kuking.php.');
        DB::table('users')->where('id', $admin->getKey())
            ->update(['ostatnio_widziany_at' => now()->subMinutes($prog + 5)]);

        // Ta sama sekwencja co scripts/panel-validation.mjs: zrzut →
        // odrzucone żądanie → GET listy → zrzut.
        $przed = $this->migawkaPelna();

        $this->actingAs($admin)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'test',
            ])
            ->assertSessionHasErrors('decision_note');

        // Dokładnie to dodatkowe żądanie GET, które w mjs leci między
        // "before" a "after" (`page.goto(list)` po przekierowaniu z walidacji).
        $this->get(route('admin.appeals'))->assertOk();

        $po = $this->migawkaPelna();
        $roznice = $this->roznice($przed, $po);

        // Odwołanie NIE zostało rozpatrzone — żadna akcja domenowa się nie
        // wykonała.
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->refresh()->status, 'Odwołanie nie powinno zostać rozstrzygnięte przy odrzuconej walidacji.');

        // DOWÓD: migawka SIĘ RÓŻNI (to jest dzisiejszy fałszywy `DOMAIN_CHANGED`),
        // ale WYŁĄCZNIE w jednym, konkretnym miejscu.
        $this->assertNotEmpty($roznice, 'Oczekiwano zaobserwować dzisiejszy fałszywy alarm (throttl last-seen) — jeśli to pole jest puste, hipoteza się nie potwierdziła albo throttl już nie działa jak opisano.');
        $this->assertSame(
            ["users[{$admin->getKey()}].ostatnio_widziany_at"],
            $roznice,
            'Hipoteza NIEPEŁNA: różnica obejmuje coś więcej niż users.ostatnio_widziany_at — patrz lista $roznice.',
        );
    }

    /**
     * KONTROLA UJEMNA: throttl JESZCZE nie minął (konto widziane przed
     * chwilą) → środek naprawy nie powinien być nawet potrzebny, migawki są
     * identyczne. Sprawdza, że sam fakt istnienia globalnego middleware'u nie
     * psuje niczego, gdy throttl chroni przed zapisem — bug jest wyłącznie
     * w OKNIE, gdy throttl akurat wygasa.
     */
    public function test_bez_wygasniecia_throttla_migawki_sa_identyczne(): void
    {
        $admin = $this->admin();
        $autor = $this->user('autor914b');
        $odwolanie = $this->zbudujOdwolanie($autor, $admin);

        DB::table('users')->where('id', $admin->getKey())->update(['ostatnio_widziany_at' => now()]);

        $przed = $this->migawkaPelna();

        $this->actingAs($admin)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'test',
            ])
            ->assertSessionHasErrors('decision_note');
        $this->get(route('admin.appeals'))->assertOk();

        $po = $this->migawkaPelna();

        $this->assertSame([], $this->roznice($przed, $po), 'Wewnątrz okna throttla migawki powinny być identyczne.');
    }

    /**
     * ZADANIE 3 (zielony po naprawie): dokładnie ten sam scenariusz co
     * pierwszy test — throttl last-seen wygasł tuż przed dodatkowym GET-em —
     * ale migawka liczona jak PO naprawie (`migawkaPoNaprawie()`). Fałszywy
     * alarm znika.
     *
     * ZADANIE 2 (kontrola dodatnia, uruchamiana z zewnątrz przez
     * `scripts/sledztwo-914-kontrola-dodatnia.sh`): ten sam test, ale
     * z tymczasowo zmutowanym `AppealController::resolve()`, który zapisuje
     * do `audit_log` NIEZALEŻNIE od wyniku walidacji. Wtedy ten test MUSI
     * failnąć — i to z konkretnym powodem `audit_log[...].id: NOWY WIERSZ`,
     * nie z powodu `ostatnio_widziany_at`. Dowód czerwieni/zieleni i MD5
     * kontrolera przed/po jest w raporcie zadania, nie w tym pliku — testy
     * PHPUnit nie edytują innych plików produkcyjnych same z siebie.
     */
    public function test_po_naprawie_falszywy_alarm_znika_ale_prawdziwy_zapis_nadal_by_wykryto(): void
    {
        $admin = $this->admin();
        $autor = $this->user('autor914c');
        $odwolanie = $this->zbudujOdwolanie($autor, $admin);

        $prog = (int) config('kuking.analytics.last_seen_throttle_minutes');
        DB::table('users')->where('id', $admin->getKey())
            ->update(['ostatnio_widziany_at' => now()->subMinutes($prog + 5)]);

        $przed = $this->migawkaPoNaprawie();

        $this->actingAs($admin)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'test',
            ])
            ->assertSessionHasErrors('decision_note');
        $this->get(route('admin.appeals'))->assertOk();

        $po = $this->migawkaPoNaprawie();

        // Ten sam throttl, który w pierwszym teście dawał fałszywy alarm,
        // po naprawie nie daje ŻADNEJ różnicy — bo obie wykluczone kolumny
        // zniknęły z OBU migawek, więc `roznice()` już ich nie widzi.
        $this->assertSame([], $this->roznice($przed, $po), 'Po naprawie migawka nie powinna zgłaszać różnicy tylko z powodu throttla last-seen.');
    }
}
