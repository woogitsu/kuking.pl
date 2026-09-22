<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZGŁASZAJĄCY DOSTAJE ODPOWIEDŹ (issue #10, DSA art. 16 ust. 4 i 5).
 *
 * CO BYŁO ZMIERZONE PRZED TĄ ZMIANĄ
 * Zgłoszenie ze zwykłego formularza „Zgłoś" (`ReportController`, konto
 * wymagane) kończyło się jednym zdaniem flash-em w sesji i niczym więcej:
 *
 *  - `NotifyModerationDecision` powiadamia wyłącznie osobę, KTÓREJ decyzja
 *    dotyczy — nigdy `report->reporter`;
 *  - `ModerationController::decide()` powiadamiał zgłaszającego tylko przy
 *    `Report::maAdresDoOdpowiedzi()`, czyli wyłącznie przy zgłoszeniu
 *    PRAWNYM z adresem e-mail. Zgłoszenie społecznościowe nigdy nie ma
 *    `notifier_email`, więc jego autor nie dowiadywał się niczego;
 *  - `Notification` nie miał typu dla „wynik Twojego zgłoszenia";
 *  - nie istniała żadna trasa dostępna zgłaszającemu — `/admin/zgloszenia`
 *    to kolejka moderatora za `auth` + `moderator` + `moderator.2fa`.
 *
 * Ten plik pilnuje, że wszystkie cztery zdania przestały być prawdą — i że
 * zamykając tę lukę nie otworzyliśmy drugiej: zgłaszający nie może
 * dowiedzieć się z odpowiedzi niczego o osobie, którą zgłosił (Luka 3
 * z `docs/research/DSA-LUKI.md`).
 */
class ZglaszajacyDostajeOdpowiedzTest extends TestCase
{
    use RefreshDatabase;

    private User $zglaszajaca;

    private User $autor;

    private Post $wpis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zglaszajaca = $this->user('halina');
        $this->autor = $this->user('krzysztof', ['display_name' => 'Krzysztof Zgłoszony']);
        $this->wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    private function zglos(array $dane = []): Report
    {
        $this->actingAs($this->zglaszajaca)
            ->post(
                route('reports.store', ['type' => 'post', 'id' => $this->wpis->getKey()]),
                array_merge(['reason' => 'harassment', 'details' => 'Nazywa mnie oszustką.'], $dane),
            )
            ->assertSessionHasNoErrors();

        return Report::query()->where('reporter_id', $this->zglaszajaca->getKey())->latest('created_at')->firstOrFail();
    }

    private function rozstrzygnij(Report $zgloszenie, string $akcja = ModerationAction::ACTION_HIDE): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'harassment',
                'user_message' => 'Ukrywamy wpis, bo obraża konkretną osobę.',
                // Przy „Zawieś konto" termin jest obowiązkowy: brak wyboru
                // znaczył kiedyś „bezterminowo", czyli najsurowszą karę
                // przez zaniechanie (`DlugoscZawieszenia`). Przy pozostałych
                // decyzjach ta wartość jest ignorowana.
                'suspend_days' => '7',
            ])
            ->assertSessionHasNoErrors();
    }

    // -----------------------------------------------------------------
    // Art. 16 ust. 4 — potwierdzenie przyjęcia
    // -----------------------------------------------------------------

    public function test_zgloszenie_daje_trwale_potwierdzenie_a_nie_sam_flash(): void
    {
        $zgloszenie = $this->zglos();

        $potwierdzenie = Notification::query()
            ->where('user_id', $this->zglaszajaca->getKey())
            ->where('type', Notification::TYPE_REPORT_RECEIVED)
            ->firstOrFail();

        $this->assertSame((string) $zgloszenie->getKey(), $potwierdzenie->data['report_id']);
        $this->assertSame($zgloszenie->numer_sprawy, $potwierdzenie->data['numer_sprawy']);

        // Potwierdzenie ma być ODCZYTYWALNE PONOWNIE — to jest cała różnica
        // między nim a flash-em, który znikał po odświeżeniu strony.
        $this->actingAs($this->zglaszajaca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Mamy Twoje zgłoszenie.')
            ->assertSee($zgloszenie->numer_sprawy);

        // Kolumna, którą przy audycie sprawdza się jako pierwszą.
        $this->assertNotNull($zgloszenie->refresh()->receipt_sent_at);
    }

    /**
     * JEDNO ZGŁOSZENIE, JEDNO POTWIERDZENIE.
     *
     * Podwójne kliknięcie oddaje tę samą sprawę (`reports_one_open_per_pair`
     * i ciepły `SELECT` w `ReportContent`), więc nie może dołożyć drugiego
     * potwierdzenia — art. 16 ust. 4 mówi o potwierdzeniu jednej sprawy,
     * nie o liście na każde kliknięcie.
     */
    public function test_powtorzone_zgloszenie_nie_daje_drugiego_potwierdzenia(): void
    {
        $pierwsze = $this->zglos();
        $drugie = $this->zglos();

        $this->assertTrue($pierwsze->is($drugie));
        $this->assertSame(1, Notification::query()
            ->where('type', Notification::TYPE_REPORT_RECEIVED)
            ->count());
    }

    /**
     * Droga prawna (bez konta) tej tabeli nie dotyka — odpowiedź idzie tam
     * pocztą (`PotwierdzenieZgloszeniaNielegalnejTresci`). Bez tego warunku
     * `NotifyReporterReceipt` próbowałby zapisać powiadomienie z pustym
     * `user_id`.
     */
    public function test_zgloszenie_bez_konta_nie_tworzy_powiadomienia_w_serwisie(): void
    {
        $this->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('notifications', 0);
    }

    // -----------------------------------------------------------------
    // Art. 16 ust. 5 — informacja o decyzji z pouczeniem
    // -----------------------------------------------------------------

    public function test_rozstrzygniecie_dochodzi_do_zglaszajacej(): void
    {
        $zgloszenie = $this->zglos();
        $this->rozstrzygnij($zgloszenie);

        $powiadomienie = Notification::query()
            ->where('user_id', $this->zglaszajaca->getKey())
            ->where('type', Notification::TYPE_REPORT_DECIDED)
            ->firstOrFail();

        $this->assertSame((string) $zgloszenie->getKey(), $powiadomienie->data['report_id']);
        // Bez `action_id` retencja nie umie policzyć terminu ochrony i
        // powiadomienie z pouczeniem zniknęłoby po ogólnych trzech miesiącach.
        $this->assertNotNull($powiadomienie->data['action_id'] ?? null);

        $this->actingAs($this->zglaszajaca)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Uznaliśmy Twoje zgłoszenie za zasadne.');

        $this->assertNotNull($zgloszenie->refresh()->decision_sent_at);
    }

    /**
     * ODRZUCENIE TEŻ JEST ROZSTRZYGNIĘCIEM.
     *
     * To jest scenariusz z opisu issue: Halina zgłasza komentarz, moderator
     * odrzuca, Halina nigdy się o tym nie dowiaduje i po dwóch tygodniach
     * zgłasza to samo drugi raz.
     */
    public function test_odrzucenie_zgloszenia_tez_dochodzi_i_niesie_pouczenie(): void
    {
        $zgloszenie = $this->zglos();
        $this->rozstrzygnij($zgloszenie, ModerationAction::ACTION_NONE);

        $this->assertSame(Report::STATUS_REJECTED, $zgloszenie->refresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->zglaszajaca->getKey(),
            'type' => Notification::TYPE_REPORT_DECIDED,
        ]);

        $this->actingAs($this->zglaszajaca)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertOk()
            ->assertSee('Po sprawdzeniu uznaliśmy, że ta treść zostaje w serwisie.')
            // POUCZENIE O DOSTĘPNYCH ŚRODKACH — art. 16 ust. 5 zdanie drugie.
            // Bez niego decyzja odmowna jest ścianą bez klamki.
            ->assertSee((string) config('kuking.community.contact_email'))
            ->assertSee($zgloszenie->numer_sprawy)
            ->assertSee('pozasądowego organu');
    }

    /**
     * Nikt nie dostaje odpowiedzi dwa razy: kanał pocztowy dotyczy zgłoszeń
     * prawnych (`maAdresDoOdpowiedzi()`), kanał w serwisie — zgłoszeń
     * z kontem (`reporter_id`). Warunki wykluczają się wzajemnie i ten test
     * jest tym, co ma o tym powiedzieć, gdyby ktoś je rozszerzył.
     */
    public function test_zgloszenie_spolecznosciowe_nie_wysyla_takze_listu(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $zgloszenie = $this->zglos();
        $this->rozstrzygnij($zgloszenie);

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Luka 3 — odpowiedź nie może zdradzać zgłoszonej osoby
    // -----------------------------------------------------------------

    public function test_odpowiedz_nie_zdradza_kogo_ani_jak_ukarano(): void
    {
        // Zawieszenie konta: decyzja, która NIE rusza zgłoszonej treści.
        // Gdyby odpowiedź opisywała karę, byłaby to informacja o cudzym
        // koncie — a mechanizm zgłoszeń nie jest narzędziem do ustalania,
        // kogo ukarano.
        $zgloszenie = $this->zglos();
        $this->rozstrzygnij($zgloszenie, ModerationAction::ACTION_SUSPEND);

        $odpowiedz = $this->actingAs($this->zglaszajaca)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertOk();

        $odpowiedz->assertDontSee('Krzysztof Zgłoszony');
        $odpowiedz->assertDontSee('krzysztof');
        $odpowiedz->assertDontSee($this->autor->email);
        $odpowiedz->assertDontSee('zawiesiliśmy', false);
        $odpowiedz->assertDontSee('Zawieszenie');

        // KONTROLA: brak tych słów nie może brać się stąd, że strona jest
        // pusta — zgłaszająca ma się dowiedzieć, że zgłoszenie było zasadne.
        $odpowiedz->assertSee('Uznaliśmy Twoje zgłoszenie za zasadne');

        // Notatka wewnętrzna moderatora nie jest odpowiedzią dla nikogo.
        $odpowiedz->assertDontSee('Ukrywamy wpis, bo obraża konkretną osobę.');
    }

    // -----------------------------------------------------------------
    // Trasa zgłaszającego — UUID w adresie to nie autoryzacja
    // -----------------------------------------------------------------

    public function test_zglaszajaca_widzi_swoje_zgloszenia(): void
    {
        $zgloszenie = $this->zglos();

        $this->actingAs($this->zglaszajaca)
            ->get(route('reports.mine'))
            ->assertOk()
            ->assertSee($zgloszenie->numer_sprawy)
            ->assertSee('Sprawdzamy.');
    }

    public function test_osoba_trzecia_nie_wejdzie_na_cudze_zgloszenie(): void
    {
        $zgloszenie = $this->zglos();
        $ktos = $this->user('ktos');

        $this->actingAs($ktos)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertForbidden();

        // Także zgłoszony autor — to jego sprawa dotyczy, ale nie jest ona
        // jego pismem i nie ma prawa czytać, co o nim napisano.
        $this->actingAs($this->autor)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertForbidden();
    }

    /**
     * MODERATOR TEŻ NIE — świadomie, patrz `ReportPolicy`. Ma własny ekran
     * pokazujący więcej; druga droga do tej samej sprawy tylko rozszerzałaby
     * powierzchnię, na której trzeba pilnować, komu co pokazujemy.
     */
    public function test_moderator_nie_wchodzi_na_karte_zglaszajacego(): void
    {
        $zgloszenie = $this->zglos();

        $this->actingAs($this->moderator())
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertForbidden();
    }

    public function test_gosc_trafia_na_logowanie(): void
    {
        $zgloszenie = $this->zglos();

        $this->post('/logout');

        $this->get(route('reports.mine'))->assertRedirect(route('login'));
        $this->get(route('reports.mine.show', $zgloszenie))->assertRedirect(route('login'));
    }

    /**
     * Zgłoszenie prawne (bez konta) nie należy w tej tabeli do nikogo —
     * nie ma go jak pokazać w serwisie i nikt nie może go tą drogą otworzyć.
     */
    public function test_zgloszenia_bez_konta_nie_da_sie_otworzyc_z_serwisu(): void
    {
        $bezKonta = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $this->wpis->getKey(),
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->zglaszajaca)
            ->get(route('reports.mine.show', $bezKonta))
            ->assertForbidden();
    }

    /**
     * Lista pokazuje WYŁĄCZNIE własne sprawy. Bez tego testu wystarczyłoby
     * zgubić `where('reporter_id', ...)` w kontrolerze, żeby każdy czytał
     * cudze pisma, a `ReportPolicy` niczego by nie złapała — ona pilnuje
     * karty, nie listy.
     */
    public function test_lista_nie_pokazuje_cudzych_spraw(): void
    {
        $moje = $this->zglos();

        $ktos = $this->user('ktos');
        $komentarz = Comment::factory()->create([
            'author_id' => $this->autor->getKey(),
            'post_id' => $this->wpis->getKey(),
        ]);

        $this->actingAs($ktos)
            ->post(route('reports.store', ['type' => 'comment', 'id' => $komentarz->getKey()]), [
                'reason' => 'spam',
            ])->assertSessionHasNoErrors();

        $cudze = Report::query()->where('reporter_id', $ktos->getKey())->firstOrFail();

        $this->actingAs($this->zglaszajaca)
            ->get(route('reports.mine'))
            ->assertOk()
            ->assertSee($moje->numer_sprawy)
            ->assertDontSee($cudze->numer_sprawy);
    }
}
