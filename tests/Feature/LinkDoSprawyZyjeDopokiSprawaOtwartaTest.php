<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Link do śledzenia sprawy żyje, dopóki sprawa jest otwarta (issue #798,
 * decyzja właściciela 20.09.2026).
 *
 * CO BYŁO PRZEDTEM (pomiar na `a6d74940`, własny)
 * `DecyzjaWSprawieZgloszenia` podpisywało link przez
 * `URL::temporarySignedRoute(..., $decyzja->appealDeadline())`, więc podpis
 * wygasał DOKŁADNIE z terminem na ZŁOŻENIE odwołania. Człowiek, który złożył
 * odwołanie dzień przed terminem, dostawał 403 na własną, wciąż nierozpatrzoną
 * sprawę — a strona istnieje po to, żeby tę sprawę śledzić.
 *
 * CO JEST TERAZ
 * Ważność wejścia zależy od STANU SPRAWY, nie od stałej daty wyliczonej przy
 * generowaniu linku (`App\Domain\Moderation\DostepDoStronySprawy`). Podpis
 * pozostaje jedyną autoryzacją — nie osłabiamy go, tylko przestajemy mu kazać
 * pilnować terminu, który nie jest jego terminem.
 *
 * CZEGO TA ZMIANA NIE RUSZA
 * Terminu na ZŁOŻENIE odwołania (sześć miesięcy, `appealDeadline()`) ani tego,
 * że po nim `FileReporterAppeal` odwołania nie przyjmie.
 */
class LinkDoSprawyZyjeDopokiSprawaOtwartaTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszeniePrawne(array $atrybuty = []): Report
    {
        return Report::create(array_merge([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => null,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ], $atrybuty));
    }

    /** @return array{0: ModerationAction, 1: Report} */
    private function decyzjaNaZgloszeniuPrawnym(string $akcja = ModerationAction::ACTION_NONE, array $atrybutyZgloszenia = []): array
    {
        $moderator = $this->moderator();
        $zgloszenie = $this->zgloszeniePrawne($atrybutyZgloszenia);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'brak_naruszenia',
            ])
            ->assertRedirect(route('admin.reports'));

        return [ModerationAction::firstOrFail(), $zgloszenie->refresh()];
    }

    private function podpisanyLinkZMaila(): string
    {
        $link = null;

        Notification::assertSentOnDemand(
            DecyzjaWSprawieZgloszenia::class,
            function (DecyzjaWSprawieZgloszenia $notification) use (&$link): bool {
                $link = $notification->toMail((object) [])->actionUrl;

                return true;
            },
        );

        $this->assertNotNull($link, 'Mail z decyzją powinien nieść podpisany link do sprawy.');

        return (string) $link;
    }

    private function okienkoOdczytuWDniach(): int
    {
        return (int) config('kuking.moderation.reporter_case_link_days');
    }

    // ------------------------------------------------------------------
    // ZGŁASZAJĄCY — sprawa otwarta po terminie na złożenie odwołania
    // ------------------------------------------------------------------

    /**
     * SEDNO ZGŁOSZENIA #798: odwołanie złożone tuż przed terminem, termin
     * mija, odpowiedzi jeszcze nie ma — i człowiek nadal ma gdzie sprawdzić,
     * co się z jego sprawą dzieje.
     */
    public function test_link_zyje_gdy_termin_minal_a_odwolanie_wciaz_czeka(): void
    {
        Notification::fake();

        [$decyzja, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->travelTo($decyzja->appealDeadline()->copy()->subHour());

        $this->post($link, ['body' => 'Zgłoszona treść nadal narusza moje prawa — proszę o ponowne sprawdzenie.'])
            ->assertRedirect();

        $this->assertDatabaseCount('appeals', 1);

        // Dwie godziny później termin na ZŁOŻENIE minął, ale sprawa jest
        // otwarta — strona śledzenia ma działać.
        $this->travelTo($decyzja->appealDeadline()->copy()->addHour());

        $this->get($link)
            ->assertOk()
            ->assertSee('Twoje odwołanie')
            ->assertSee($zgloszenie->numer_sprawy);

        $this->travelBack();
    }

    /**
     * Gałąź „termin minął" w `pages/appeals/reporter.blade.php` była dotąd
     * NIEOSIĄGALNA standardowym linkiem — podpis wygasał dokładnie wtedy, gdy
     * zaczynała obowiązywać (`[pomiar cudzy: stanowisko dsa-odwolania,
     * 20.09.2026]`). Tu jest osiągana i tu sprawdzamy, czy mówi prawdę.
     */
    public function test_po_terminie_bez_odwolania_strona_mowi_ze_termin_minal_zamiast_odmawiac(): void
    {
        Notification::fake();

        [$decyzja] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->travelTo($decyzja->appealDeadline()->copy()->addDay());

        $this->get($link)
            ->assertOk()
            ->assertSee('Tej decyzji nie da się już zakwestionować tutaj')
            ->assertSee(Czas::data($decyzja->appealDeadline(), 'j F Y'))
            ->assertSee(config('kuking.community.contact_email'))
            ->assertDontSee('Wyślij odwołanie');

        $this->travelBack();
    }

    /**
     * TA GAŁĄŹ MÓWIŁA O „SZEŚCIU MIESIĄCACH" NA SZTYWNO, a `appealDeadline()`
     * bierze PÓŹNIEJSZĄ z dwóch dat — `appeal_days` z konfiguracji albo sześć
     * miesięcy kalendarzowych. Przy dłuższym `appeal_days` ekran twierdził
     * „sześć miesięcy" obok wypisanej przez siebie, znacznie późniejszej daty.
     * Nikt tego nie widział, bo gałęzi nie dało się otworzyć (#798).
     */
    public function test_strona_po_terminie_nie_obiecuje_szesciu_miesiecy_gdy_termin_jest_dluzszy(): void
    {
        Notification::fake();

        config(['kuking.moderation.appeal_days' => 400]);

        [$decyzja] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->assertTrue(
            $decyzja->appealDeadline()->greaterThan($decyzja->created_at->copy()->addMonths(6)),
            'Ta próba ma sens tylko wtedy, gdy realny termin jest DŁUŻSZY niż sześć miesięcy.',
        );

        $this->travelTo($decyzja->appealDeadline()->copy()->addDay());

        $this->get($link)
            ->assertOk()
            ->assertSee(Czas::data($decyzja->appealDeadline(), 'j F Y'))
            ->assertDontSee('Na odwołanie jest sześć miesięcy od decyzji.');

        $this->travelBack();
    }

    /** Termin na ZŁOŻENIE zostaje terminem — to nie jest zmiana liczby. */
    public function test_po_terminie_tym_samym_linkiem_nie_da_sie_juz_zlozyc_odwolania(): void
    {
        Notification::fake();

        [$decyzja] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->travelTo($decyzja->appealDeadline()->copy()->addDay());

        $this->post($link, ['body' => 'Spóźnione odwołanie, które nie ma prawa przejść.'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('appeals', 0);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // ZGŁASZAJĄCY — po rozstrzygnięciu
    // ------------------------------------------------------------------

    public function test_po_rozstrzygnieciu_link_jeszcze_pokazuje_odpowiedz(): void
    {
        Notification::fake();

        [$decyzja] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->post($link, ['body' => 'Proszę o ponowne przyjrzenie się tej sprawie.'])->assertRedirect();

        $this->rozstrzygnij(Appeal::firstOrFail(), 'Obejrzeliśmy sprawę drugi raz i podtrzymujemy decyzję.');

        $this->travelTo(now()->addDays(max(1, $this->okienkoOdczytuWDniach() - 1)));

        $this->get($link)
            ->assertOk()
            ->assertSee('Nasza odpowiedź')
            ->assertSee('Obejrzeliśmy sprawę drugi raz i podtrzymujemy decyzję.');

        $this->travelBack();
    }

    /**
     * Link nie jest wieczny — ale człowiek, któremu wygasł, ma usłyszeć,
     * GDZIE szukać odpowiedzi, a nie „403".
     */
    public function test_po_oknie_odczytu_link_mowi_gdzie_szukac_odpowiedzi_a_nie_403(): void
    {
        Notification::fake();

        [, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        $this->post($link, ['body' => 'Proszę o ponowne przyjrzenie się tej sprawie.'])->assertRedirect();

        $this->rozstrzygnij(Appeal::firstOrFail(), 'Podtrzymujemy decyzję i wyjaśniamy dlaczego.');

        $this->travelTo(now()->addDays($this->okienkoOdczytuWDniach() + 1));

        $odpowiedz = $this->get($link);

        $odpowiedz->assertStatus(410);
        $odpowiedz->assertSee(config('kuking.community.contact_email'));
        $odpowiedz->assertSee('e-mail', false);

        // Strona po wygaśnięciu NIE pokazuje już samej sprawy.
        $odpowiedz->assertDontSee($zgloszenie->numer_sprawy);
        $odpowiedz->assertDontSee('Podtrzymujemy decyzję i wyjaśniamy dlaczego.');

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // KONTROLA UJEMNA — podpis nadal jest jedyną autoryzacją
    // ------------------------------------------------------------------

    public function test_bez_podpisu_i_z_zepsutym_podpisem_nie_wchodzi_nikt(): void
    {
        Notification::fake();

        [$decyzja, $zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        $link = $this->podpisanyLinkZMaila();

        // Sam UUID w adresie to nie autoryzacja (AGENTS.md §7).
        $this->get(route('appeals.reporter', ['report' => $zgloszenie]))->assertForbidden();
        $this->post(route('appeals.reporter', ['report' => $zgloszenie]), ['body' => 'Próba bez podpisu.'])
            ->assertForbidden();

        $zepsuty = (string) preg_replace_callback(
            '/signature=([0-9a-f])/',
            static fn (array $t): string => 'signature='.($t[1] === '0' ? '1' : '0'),
            $link,
            1,
        );
        $this->assertNotSame($link, $zepsuty, 'Test nie zmienił niczego w linku — sprawdź wzorzec.');
        $this->get($zepsuty)->assertForbidden();

        // I po terminie na odwołanie zgadywanie nadal nic nie daje — to, że
        // link żyje dłużej, nie znaczy, że adres stał się publiczny.
        $this->travelTo($decyzja->appealDeadline()->copy()->addDay());
        $this->get(route('appeals.reporter', ['report' => $zgloszenie]))->assertForbidden();
        $this->get($zepsuty)->assertForbidden();
        $this->travelBack();

        $this->assertDatabaseCount('appeals', 0);
    }

    // ------------------------------------------------------------------
    // AUTOR TREŚCI — druga rola tej samej sprawy
    // ------------------------------------------------------------------

    /**
     * Autor wchodzi przez sesję, nie przez podpis — jego strona nigdy nie
     * wygasała i ta zmiana nie ma prawa tego zepsuć.
     */
    public function test_autor_tresci_widzi_swoja_sprawe_po_terminie_i_po_rozstrzygnieciu(): void
    {
        Notification::fake();

        $moderator = $this->moderator();
        $autor = $this->user('autorka');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $zgloszenie = $this->zgloszeniePrawne(['target_id' => $post->getKey()]);

        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'copyright',
            'user_message' => 'Ostrzeżenie za treść zgłoszoną jako niezgodną z prawem.',
        ]);

        $decyzja = ModerationAction::firstOrFail();

        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'To nie było naruszeniem niczyich praw.'])
            ->assertRedirect(route('appeals.show', $decyzja));

        $odwolanie = Appeal::where('appellant', Appeal::APPELLANT_AUTHOR)->firstOrFail();

        // Sprawa otwarta, termin na złożenie minął — strona autora żyje.
        $this->travelTo($decyzja->appealDeadline()->copy()->addDay());

        $this->actingAs($autor)->get(route('appeals.show', $decyzja))
            ->assertOk()
            ->assertSee('Twoje odwołanie');

        $this->rozstrzygnij($odwolanie, 'Obejrzeliśmy sprawę drugi raz i podtrzymujemy ostrzeżenie.');

        $this->actingAs($autor)->get(route('appeals.show', $decyzja))
            ->assertOk()
            ->assertSee('Obejrzeliśmy sprawę drugi raz i podtrzymujemy ostrzeżenie.');

        $this->travelBack();
    }

    private function rozstrzygnij(Appeal $odwolanie, string $notatka): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => $notatka,
            ])
            ->assertSessionHasNoErrors();

        $odwolanie->refresh();

        $this->assertFalse($odwolanie->isOpen(), 'Odwołanie miało zostać rozstrzygnięte przez panel.');
        $this->assertNotNull($odwolanie->decided_at);
    }
}
