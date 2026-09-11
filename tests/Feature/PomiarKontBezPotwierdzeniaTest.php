<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\KontaBezPotwierdzonegoAdresu;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pomiar kont bez potwierdzonego adresu e-mail (issue #317).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK PILNUJE — I DLACZEGO NIE WYSTARCZY „LICZY SIĘ BEZ BŁĘDU"
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ten pomiar ma posłużyć właścicielowi za podstawę decyzji o zamknięciu
 * drogi wejścia na konto. Liczba, która wyjdzie za małą, każe mu zamknąć
 * drzwi ludziom, o których nie wiedział; liczba za duża każe mu odłożyć
 * naprawę dziury, przez którą da się przejąć konto. Obie pomyłki są
 * kosztowne i ŻADNA NIE WYGLĄDA NA BŁĄD, bo komenda w obu przypadkach
 * kończy się sukcesem i wypisuje ładną tabelkę.
 *
 * Dlatego są tu trzy rodzaje testu, a nie jeden:
 *
 *  1. KONTROLA DODATNIA NA ZNANEJ POPULACJI (`test_pomiar_na_populacji…`)
 *     — podstawiamy dane, w których odpowiedź znamy z góry, i sprawdzamy
 *     KAŻDĄ liczbę osobno. Asercja na jednej liczbie przechodziłaby także
 *     wtedy, gdyby pozostałe jedenaście liczyło coś innego, niż twierdzi
 *     ich nazwa (`docs/PULAPKI_TESTOW.md` §3b: każda gałąź potrzebuje
 *     własnej asercji).
 *
 *  2. ZGODNOŚĆ Z RZECZYWISTOŚCIĄ (`test_pomiar_zgadza_sie_z_tym_komu…`)
 *     — dla każdej odjętej kategorii sprawdzamy PRAWDZIWYM ŻĄDANIEM HTTP,
 *     czy list z linkiem naprawdę nie wychodzi. To jedyna asercja w tym
 *     pliku, która broni się przed rozjazdem z
 *     `WyslijLinkDoLogowania::wolnoWyslac()`: gdyby ktoś dołożył tam
 *     czwarty warunek (albo zdjął jeden z trzech), pomiar liczyłby dalej
 *     swoje i nikt by tego nie zauważył. Ten test przy takiej zmianie
 *     oblewa.
 *
 *  3. PUSTA BAZA (`test_pusta_baza…`) — bo procent liczony z zera kont to
 *     dzielenie przez zero, a `migrate:fresh` i środowisko preview są
 *     przypadkami normalnymi, nie awarią.
 *
 * ZAŁOŻENIE, KTÓRE TEN PLIK CELOWO UTRWALA: konto z pustym
 * `email_verified_at` DOSTAJE DZIŚ LINK DO ZALOGOWANIA. Na tym stoi cała
 * usterka z issue #317 i dlatego stoi to tutaj jako asercja, a nie jako
 * zdanie w dokumencie — zdanie w dokumencie zdąży się zestarzeć.
 */
class PomiarKontBezPotwierdzeniaTest extends TestCase
{
    use RefreshDatabase;

    /** Licznik żądań — służy tylko do rozdzielenia adresów IP, patrz `wyslijFormularz()`. */
    private int $kolejneZadanie = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to za
        // „poczta nie działa" — formularz odpowiadałby wtedy komunikatem
        // o braku poczty i nie doszłoby do wysyłki. Ten sam zabieg co
        // w `LogowanieLinkiemTest`.
        config(['mail.default' => 'smtp']);
    }

    // ------------------------------------------------------------------
    //  1. Kontrola dodatnia: populacja, w której znamy każdą liczbę
    // ------------------------------------------------------------------

    public function test_pomiar_na_populacji_o_znanym_skladzie_podaje_dokladnie_te_liczby(): void
    {
        // ── TRZY KONTA Z POTWIERDZONYM ADRESEM ────────────────────────
        // Gospodarz jest tu po to, żeby treść do skomentowania i do
        // „Ugotowałem" miała autora, którego NIE liczymy — inaczej fabryki
        // dołożyłyby po cichu kolejne konta (każde `Post::factory()` bez
        // autora tworzy własnego) i mianownik procentu przestałby się
        // zgadzać z tym, co tu widać.
        $gospodarz = User::factory()->create();
        User::factory()->count(2)->create();

        $wpisGospodarza = Post::factory()->create(['author_id' => $gospodarz->getKey()]);
        $przepisGospodarza = Recipe::factory()->create(['author_id' => $gospodarz->getKey()]);

        // ── TRZYNAŚCIE KONT BEZ POTWIERDZONEGO ADRESU ─────────────────
        // Dwie puste rejestracje: konto jest, nie zrobiło nic.
        $pusty1 = User::factory()->unverified()->create(['ostatnio_widziany_at' => now()]);
        User::factory()->unverified()->create();

        // Cztery żywe — po jednym na każdy rodzaj wpisu z issue #317.
        $zWpisem = User::factory()->unverified()->create(['ostatnio_widziany_at' => now()]);
        Post::factory()->create(['author_id' => $zWpisem->getKey()]);

        $zPrzepisem = User::factory()->unverified()->create();
        Recipe::factory()->create(['author_id' => $zPrzepisem->getKey()]);

        $zKomentarzem = User::factory()->unverified()->create();
        Comment::factory()->create([
            'author_id' => $zKomentarzem->getKey(),
            'post_id' => $wpisGospodarza->getKey(),
        ]);

        $zUgotowaniem = User::factory()->unverified()->create();
        CookedEvent::factory()->create([
            'user_id' => $zUgotowaniem->getKey(),
            'recipe_id' => $przepisGospodarza->getKey(),
        ]);

        // Zawieszone LICZY SIĘ do kosztu: `suspended` nie jest
        // w `STATUSY_ZAMKNIETEGO_KONTA`, więc link dostaje dziś normalnie.
        User::factory()->unverified()->create(['status' => User::STATUS_SUSPENDED]);

        // Trzy konta zamknięte — linku nie dostają już dziś, więc odpadają.
        // `erased` wymaga `data_erased_at` (CHECK w bazie) i jest tu
        // najważniejszym z trzech: `EraseAccountData` samo zeruje
        // `email_verified_at`, czyli konto po anonimizacji ZAWSZE wpada
        // w naiwne `whereNull(...)`.
        User::factory()->unverified()->create(['status' => User::STATUS_BANNED]);
        User::factory()->unverified()->create(['status' => User::STATUS_PENDING_DELETE]);
        User::factory()->unverified()->create([
            'status' => User::STATUS_ERASED,
            'data_erased_at' => now(),
        ]);

        // Konto zalążkowe z pliku (D-025) i dwa konta obsługi serwisu.
        User::factory()->unverified()->create(['is_seeded' => true]);
        User::factory()->unverified()->create(['role' => User::ROLE_MODERATOR]);
        User::factory()->unverified()->create(['role' => User::ROLE_ADMIN]);

        $wynik = (new KontaBezPotwierdzonegoAdresu)->policz();

        // ── LICZBY, KTÓRE MUSZĄ WYJŚĆ ─────────────────────────────────
        $this->assertSame(16, $wynik->kontaWszystkie, '3 potwierdzone + 13 bez potwierdzenia.');
        $this->assertSame(13, $wynik->bezPotwierdzeniaWszystkie);

        $this->assertSame(3, $wynik->bezPotwierdzeniaZamkniete, 'zablokowane + w trakcie usuwania + usunięte');
        $this->assertSame(1, $wynik->bezPotwierdzeniaZalazkowe);
        $this->assertSame(2, $wynik->bezPotwierdzeniaObsluga, 'moderator + administrator');

        // 13 − 3 − 1 − 2 = 7: dwie puste rejestracje, cztery żywe, jedno zawieszone.
        $this->assertSame(7, $wynik->dotknieci);
        $this->assertSame(4, $wynik->dotknieciZywi);
        $this->assertSame(3, $wynik->dotknieciPuscy(), 'dwie puste rejestracje + zawieszone bez treści');

        $this->assertSame(1, $wynik->dotknieciZWpisem);
        $this->assertSame(1, $wynik->dotknieciZPrzepisem);
        $this->assertSame(1, $wynik->dotknieciZKomentarzem);
        $this->assertSame(1, $wynik->dotknieciZUgotowaniem);

        $this->assertSame(2, $wynik->dotknieciWidzianiKiedykolwiek, 'jedna pusta rejestracja + jedno konto z wpisem');

        // 13/16 = 81,25 → 81,3 ; 7/16 = 43,75 → 43,8
        $this->assertSame(81.3, $wynik->procentBezPotwierdzenia());
        $this->assertSame(43.8, $wynik->procentDotknietych());

        // Komenda ma wypisać te same liczby, co policzyła klasa domenowa —
        // bez tego pomiar byłby poprawny, a właściciel czytałby co innego.
        $this->artisan('kuking:konta-bez-potwierdzenia')
            ->expectsOutputToContain('Wszystkich kont w bazie: 16')
            ->expectsOutputToContain('Bez potwierdzonego adresu (samo `email_verified_at IS NULL`): 13 — 81,3% wszystkich kont')
            ->expectsOutputToContain('KONTA, KTÓRYM ZAMKNIĘCIE TEJ DROGI NAPRAWDĘ ZABIERA WEJŚCIE: 7 — 43,8% wszystkich kont')
            ->expectsOutputToContain('z tego ŻYWE (mają wpis, przepis, komentarz albo „Ugotowałem”): 4')
            ->expectsOutputToContain('z tego PUSTE REJESTRACJE (nie zrobiły nic): 3')
            ->assertExitCode(0);

        // KONTROLA DODATNIA DO ASERCJI „NIE LICZYMY TREŚCI SKASOWANEJ":
        // bez tego dwa ostatnie zdania komentarza klasy byłyby obietnicą bez
        // pokrycia. Po miękkim skasowaniu jedynego wpisu konto przestaje być
        // żywe — i widać to na LICZBIE, nie na braku wyjątku.
        Post::query()->where('author_id', $zWpisem->getKey())->delete();

        $poSkasowaniu = (new KontaBezPotwierdzonegoAdresu)->policz();

        $this->assertSame(0, $poSkasowaniu->dotknieciZWpisem);
        $this->assertSame(3, $poSkasowaniu->dotknieciZywi);
        $this->assertSame(7, $poSkasowaniu->dotknieci, 'Skasowanie treści nie zabiera konta z liczby kosztu.');

        // A kontrola dodatnia do samego `$pusty1`: to konto jest w liczbie
        // kosztu, ale nie w liczbie żywych — i nie dlatego, że pomiar
        // czegoś nie znalazł, tylko dlatego, że nic tam nie ma.
        $this->assertSame(0, $pusty1->posts()->count() + $pusty1->comments()->count());
    }

    // ------------------------------------------------------------------
    //  2. Zgodność pomiaru z tym, komu DZIŚ naprawdę wychodzi list
    // ------------------------------------------------------------------

    /**
     * Pomiar odejmuje trzy kategorie, twierdząc, że „linku i tak nie
     * dostają". To twierdzenie jest sprawdzalne tylko jednym sposobem:
     * wysłaniem formularza i policzeniem listów.
     *
     * NAJWAŻNIEJSZA ASERCJA W TYM PLIKU jest tu pierwsza i jest asercją
     * DODATNIĄ: konto z pustym `email_verified_at` list DOSTAJE. Bez niej
     * cztery asercje „nie dostał" przechodziłyby także wtedy, gdyby poczta
     * nie działała wcale (`docs/PULAPKI_TESTOW.md` §4).
     */
    public function test_pomiar_zgadza_sie_z_tym_komu_dzis_wolno_wyslac_link(): void
    {
        Notification::fake();

        // DOTKNIĘTY — i to jest usterka z issue #317 na żywo: adres
        // niepotwierdzony, a list z wejściem na konto idzie.
        $niepotwierdzony = User::factory()->unverified()->create(['email' => 'ofiara@example.com']);
        $this->wyslijFormularz('ofiara@example.com');
        Notification::assertSentTo($niepotwierdzony, LinkDoLogowania::class);

        // ODJĘTE KATEGORIE — każda osobno, bo każda wychodzi z INNEJ gałęzi
        // `wolnoWyslac()` i wspólna asercja nie odróżniłaby ich od siebie.
        $zamkniete = [
            'zablokowany@example.com' => ['status' => User::STATUS_BANNED],
            'usuwany@example.com' => ['status' => User::STATUS_PENDING_DELETE],
            'usuniety@example.com' => ['status' => User::STATUS_ERASED, 'data_erased_at' => now()],
            'moderator@example.com' => ['role' => User::ROLE_MODERATOR],
            'admin@example.com' => ['role' => User::ROLE_ADMIN],
        ];

        foreach ($zamkniete as $adres => $pola) {
            $konto = User::factory()->unverified()->create([...$pola, 'email' => $adres]);

            $this->wyslijFormularz($adres);

            Notification::assertNotSentTo($konto, LinkDoLogowania::class);
        }

        // Konto zalążkowe odejmujemy z innego powodu niż pozostałe: link by
        // do niego POSZEDŁ (`wolnoWyslac()` o `is_seeded` nie pyta), tylko
        // nie ma tam człowieka, który by go przeczytał. Ta asercja pilnuje,
        // żeby nikt nie „poprawił" tego komentarza na nieprawdziwy.
        $zalazkowe = User::factory()->unverified()->create([
            'email' => 'zalazek@example.com',
            'is_seeded' => true,
        ]);

        $this->wyslijFormularz('zalazek@example.com');

        Notification::assertSentTo($zalazkowe, LinkDoLogowania::class);

        // A teraz to samo policzone pomiarem: jeden dotknięty (ofiara),
        // pięć odjętych ze statusu i roli, jedno zalążkowe.
        $wynik = (new KontaBezPotwierdzonegoAdresu)->policz();

        $this->assertSame(7, $wynik->bezPotwierdzeniaWszystkie);
        $this->assertSame(3, $wynik->bezPotwierdzeniaZamkniete);
        $this->assertSame(2, $wynik->bezPotwierdzeniaObsluga);
        $this->assertSame(1, $wynik->bezPotwierdzeniaZalazkowe);
        $this->assertSame(1, $wynik->dotknieci);
    }

    // ------------------------------------------------------------------
    //  3. Pusta baza
    // ------------------------------------------------------------------

    public function test_pusta_baza_daje_zera_a_nie_dzielenie_przez_zero(): void
    {
        $wynik = (new KontaBezPotwierdzonegoAdresu)->policz();

        $this->assertSame(0, $wynik->kontaWszystkie);
        $this->assertSame(0, $wynik->bezPotwierdzeniaWszystkie);
        $this->assertSame(0, $wynik->dotknieci);
        $this->assertSame(0, $wynik->dotknieciPuscy());
        $this->assertSame(0.0, $wynik->procentBezPotwierdzenia());
        $this->assertSame(0.0, $wynik->procentDotknietych());

        $this->artisan('kuking:konta-bez-potwierdzenia')->assertExitCode(0);
    }

    /**
     * KAŻDE WYSŁANIE Z INNEGO ADRESU IP — bo trasa `POST /logowanie/link`
     * ma limit 5 próśb na godzinę na adres IP (`limits.login_link`, D-056),
     * a ten test wysyła formularz siedem razy. Bez tego siódme żądanie
     * odbijało się o limiter i test oblewał na braku listu, sugerując
     * usterkę w pomiarze tam, gdzie jej nie było.
     *
     * Rozdzielamy IP, a nie czyścimy cache: wyczyszczenie licznika
     * zamiotłoby razem z nim limit liczony po adresie e-mail, czyli
     * zabezpieczenie, którego ten test nie ma prawa wyłączać po drodze.
     */
    private function wyslijFormularz(string $adres): void
    {
        $this->kolejneZadanie++;

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$this->kolejneZadanie])
            ->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }
}
