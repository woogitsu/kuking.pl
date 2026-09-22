<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WyslijLinkDoLogowania;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dwie równoległe prośby o link do logowania (ustalenie AUTH-02 / RACE-02
 * z audytu drugiej warstwy, 10.09.2026; D-075).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO TE TESTY PILNUJĄ — I DLACZEGO TO JEST SPRAWA BEZPIECZEŃSTWA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Formularz „wyślij mi link do logowania" jest ŚWIADOMIE nieodróżnialny dla
 * adresu, na którym jest konto, i dla adresu, na którym go nie ma (D-056).
 * Ekran mówi „Jeśli na ten adres jest konto w Kuking, wysłaliśmy tam
 * wiadomość" właśnie po to, żeby z tego formularza nie dało się zrobić
 * pytania „kto tu gotuje".
 *
 * `WyslijLinkDoLogowania` kasowało poprzedni token i zakładało nowy BEZ
 * BLOKADY WIERSZA KONTA. Dwie prośby naraz mogły więc obie przejść `DELETE`
 * (zero wierszy) i obie dojść do `INSERT` — a `login_link_tokens.user_id`
 * jest unikalne, więc jedna z nich odbijała się o constraint. I tu jest
 * sedno: taki wyjątek MOŻE POWSTAĆ WYŁĄCZNIE TAM, GDZIE KONTO ISTNIEJE, bo
 * dla adresu bez konta akcja kończy się, zanim dojdzie do zapisu. Para
 * równoległych żądań stawała się więc wyrocznią obecności konta — tą samą,
 * którą cała reszta tej drogi zasłania.
 *
 * Druga strona tej samej usterki jest zwyczajna i trafia w naszą grupę
 * wprost: podwójne kliknięcie „Wyślij" na ekranie logowania. To jest główna
 * droga wejścia dla osób 60+ (`docs/research/AUDIENCE_50_PLUS.md`), więc
 * dwuklik jest tam scenariuszem typowym, nie skrajnym.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  JAK WYMUSZAMY WYŚCIG BEZ DRUGIEGO PROCESU
 * ══════════════════════════════════════════════════════════════════════
 *
 * Testy chodzą na jednym połączeniu i w jednej transakcji `RefreshDatabase`,
 * więc drugiego żądania nie da się tu odpalić naprawdę równolegle. Zamiast
 * tego podstawiamy DOKŁADNIE TEN STAN, który zostawia po sobie przegrany
 * wyścig: wiersz z tym samym `user_id` pojawia się MIĘDZY `DELETE` a
 * `INSERT` (zdarzenie `creating` modelu). Dla `INSERT`-a jest to nie do
 * odróżnienia od wiersza wstawionego przez równoległe żądanie — i to jest
 * cała rzecz, którą chcemy zmierzyć.
 */
class WyscigLinkuDoLogowaniaTest extends TestCase
{
    use RefreshDatabase;

    /** Czy podstawione „równoległe żądanie" wstawiło już swój wiersz. */
    private bool $rywalWstawiony = false;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za „poczta nie działa" — formularz chowa się wtedy
        // w całości i trasa oddaje inny komunikat. Bez tego mierzylibyśmy
        // ekran bez formularza. Ta sama linijka co w `LogowanieLinkiemTest`.
        config(['mail.default' => 'smtp']);
    }

    /**
     * SEDNO ZADANIA: konflikt unikalności nie ma prawa być odróżnialny od
     * spokojnej ścieżki „na ten adres nie ma konta".
     *
     * Porównujemy JEDNĄ ODPOWIEDŹ Z DRUGĄ — kod HTTP, adres przekierowania
     * i treść komunikatu — a nie „czy każda z nich jest OK". Test patrzący
     * tylko na 302 przechodziłby także wtedy, gdyby komunikaty się różniły,
     * a wyrocznię robi każda różnica, nie tylko kod odpowiedzi.
     */
    public function test_konflikt_przy_zapisie_tokenu_nie_zdradza_ze_konto_istnieje(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $this->wstawRywalaMiedzyKasowaniemIZapisem($basia);

        $zKontem = $this->wyslijFormularz('basia@example.com');
        $bezKonta = $this->wyslijFormularz('bogumila@example.com');

        $this->assertSame(
            $zKontem->status(),
            $bezKonta->status(),
            'Kod odpowiedzi różni się po konflikcie zapisu tokenu — para równoległych próśb '
            .'odpowiada wtedy na pytanie „czy na ten adres jest konto w Kuking".',
        );

        $this->assertSame(
            $zKontem->headers->get('Location'),
            $bezKonta->headers->get('Location'),
            'Różny adres przekierowania po konflikcie zapisu tokenu.',
        );

        $this->assertSame(
            $this->komunikat($zKontem),
            $this->komunikat($bezKonta),
            'Komunikat po konflikcie zapisu tokenu różni się od komunikatu dla adresu bez konta.',
        );

        // Kontrola, że test mierzy to, co ma mierzyć: konflikt naprawdę
        // został wymuszony (rywal wstawił swój wiersz) i naprawdę powstała
        // odpowiedź dla człowieka, a nie wyjątek.
        $this->assertTrue($this->rywalWstawiony, 'Wyścig nie został wymuszony — test nic nie mierzy.');
        $this->assertSame(302, $zKontem->status());
    }

    /**
     * PODWÓJNE KLIKNIĘCIE „WYŚLIJ" — ten sam wyścig widziany od strony
     * człowieka, który po prostu nie jest pewien, czy przycisk zadziałał.
     *
     * Dwie prośby pod rząd mają dać dwie identyczne odpowiedzi i zostawić
     * DOKŁADNIE JEDEN ważny token — ten Z DRUGIEGO LISTU. To nie jest
     * drobiazg redakcyjny: człowiek klika w najnowszą wiadomość w skrzynce,
     * więc gdyby druga prośba cicho odpuściła (a odpuszcza po konflikcie
     * unikalności, patrz `WyslijLinkDoLogowania::wymienToken()`), zielony
     * przycisk z ostatniego listu byłby martwy i człowiek nie miałby jak
     * się domyślić, że ma szukać wiadomości WCZEŚNIEJSZEJ.
     */
    public function test_dwuklik_wyslij_daje_dwie_identyczne_odpowiedzi_i_zywy_link_z_drugiego_listu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $pierwsza = $this->wyslijFormularz($basia->email);
        $druga = $this->wyslijFormularz($basia->email);

        $this->assertSame($pierwsza->status(), $druga->status(), 'Dwuklik oddaje dwa różne kody odpowiedzi.');
        $this->assertSame(
            $pierwsza->headers->get('Location'),
            $druga->headers->get('Location'),
            'Dwuklik oddaje dwa różne adresy przekierowania.',
        );
        $this->assertSame(
            $this->komunikat($pierwsza),
            $this->komunikat($druga),
            'Dwuklik oddaje dwa różne komunikaty.',
        );

        $this->assertSame(302, $druga->status());
        $this->assertDatabaseCount('login_link_tokens', 1);

        // Poszły dwa listy i link z DRUGIEGO wpuszcza na konto.
        $linki = $this->linkiZListow($basia);

        $this->assertCount(2, $linki, 'Dwuklik nie wysłał dwóch listów — druga prośba przepadła po cichu.');

        $this->from($linki[1])
            ->post(route('login.link.store'), ['token' => $this->tokenZLinku($linki[1])])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
    }

    /**
     * SERIALIZACJA, A NIE TYLKO ŁAPANIE WYJĄTKU.
     *
     * Ten test nie da się napisać przez obserwację odpowiedzi HTTP, bo
     * w jednym procesie wyścigu nie ma. Sprawdza więc rzecz, która wyścigowi
     * zapobiega: wymiana tokenu idzie POD BLOKADĄ WIERSZA KONTA, a blokada
     * jest brana PRZED kasowaniem starego tokenu.
     *
     * KOLEJNOŚĆ JEST TU CAŁĄ TREŚCIĄ. Blokada wzięta po `DELETE` nie
     * serializuje niczego, bo oba żądania zdążyłyby już skasować zero
     * wierszy. A „konto najpierw" jest jedyną kolejnością blokad, jaka
     * w tym repozytorium obowiązuje (D-075) — dwie różne kolejności to
     * zakleszczenie, które PostgreSQL rozwiązuje zabiciem jednego
     * z żądań.
     */
    public function test_wymiana_tokenu_idzie_pod_blokada_wiersza_konta(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        /** @var list<string> $zapytania */
        $zapytania = [];

        DB::listen(function ($zapytanie) use (&$zapytania): void {
            $zapytania[] = mb_strtolower($zapytanie->sql);
        });

        app(WyslijLinkDoLogowania::class)->handle($basia->email);

        $blokada = null;
        $kasowanie = null;

        foreach ($zapytania as $i => $sql) {
            if ($blokada === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokada = $i;
            }

            if ($kasowanie === null && str_contains($sql, 'delete from "login_link_tokens"')
                && str_contains($sql, 'user_id')) {
                $kasowanie = $i;
            }
        }

        $this->assertNotNull(
            $blokada,
            'Wymiana tokenu nie bierze blokady wiersza konta (`select ... from "users" ... for update`) '
            .'— dwie równoległe prośby nie ustawiają się w kolejce.',
        );
        $this->assertNotNull($kasowanie, 'Wymiana tokenu nie kasuje poprzedniego tokenu — test nic nie mierzy.');
        $this->assertLessThan(
            $kasowanie,
            $blokada,
            'Blokada wiersza konta jest brana PO skasowaniu poprzedniego tokenu — w tej kolejności '
            .'nie serializuje niczego.',
        );
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Podstawia stan, który zostawia po sobie równoległe żądanie: wiersz
     * z tym samym `user_id` pojawia się po `DELETE`, a przed `INSERT`.
     *
     * Wstawiamy przez `DB::table()`, nie przez model — inaczej to samo
     * zdarzenie `creating` złapałoby własny zapis i pętla nie miałaby końca.
     */
    private function wstawRywalaMiedzyKasowaniemIZapisem(User $user): void
    {
        LoginLinkToken::creating(function () use ($user): void {
            if ($this->rywalWstawiony) {
                return;
            }

            $this->rywalWstawiony = true;

            DB::table('login_link_tokens')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                // CHECK w bazie wymaga 64 znaków szesnastkowych małymi
                // literami — patrz migracja `login_link_tokens`.
                'token_hash' => hash('sha256', 'rownolegle-zadanie'),
                'created_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ]);
        });
    }

    /**
     * Adresy linków, które NAPRAWDĘ poszły w listach, w kolejności wysyłki.
     *
     * Czytamy je z powiadomień, a nie z bazy — w bazie leży skrót. Ta sama
     * droga co w `LogowanieLinkiemTest::popros()`.
     *
     * @return list<string>
     */
    private function linkiZListow(User $user): array
    {
        return Notification::sent($user, LinkDoLogowania::class)
            ->map(fn (LinkDoLogowania $powiadomienie): string => (string) (
                $powiadomienie->toMail($user)->viewData['linkUrl'] ?? ''
            ))
            ->values()
            ->all();
    }

    private function tokenZLinku(string $link): string
    {
        return (string) mb_substr($link, mb_strrpos($link, '/') + 1);
    }

    private function wyslijFormularz(string $adres): TestResponse
    {
        return $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }

    private function komunikat(TestResponse $odpowiedz): string
    {
        return (string) $odpowiedz->getSession()->get('status', '');
    }
}
