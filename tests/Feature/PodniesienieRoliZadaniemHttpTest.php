<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PRÓBA ATAKU PRAWDZIWYM ŻĄDANIEM: czy da się podnieść sobie `role` albo
 * `status`, albo podstawić cudzego właściciela, doklejając pola do formularza.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO ŻĄDANIE, A NIE PRZEGLĄD KODU
 * ────────────────────────────────────────────────────────────────────────
 *
 * `SecurityTest` i `AdresEmailPozaMasowymPrzypisaniemTest` mierzą MODEL:
 * `$user->update([...])` nie ustawia `role`. To jest dowód, że `$fillable`
 * działa — ale `$fillable` jest tylko JEDNĄ z barier. Obok niej stoją:
 * lista reguł walidacji (co w ogóle wchodzi do `$data`), kształt wywołania
 * (`update($data)` kontra `update(['jedno' => $data['jedno']])`)
 * i `forceFill()`, które `$fillable` OMIJA w całości.
 *
 * Test, który pyta tylko model, przejdzie także wtedy, gdy kontroler weźmie
 * `forceFill($request->all())` — bo model nigdy się o tym nie dowie. Dlatego
 * ten plik nie ogląda kodu: wysyła POST/PUT na trasę produkcyjną, z polami
 * doklejonymi do poprawnego formularza, i po każdym żądaniu pyta BAZĘ.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KONTROLA DODATNIA — PUŁAPKA 4 Z `docs/PULAPKI_TESTOW.md`
 * ────────────────────────────────────────────────────────────────────────
 *
 * „Nic się nie zmieniło" przechodzi też wtedy, gdy żądanie w ogóle nie
 * doszło: 419 na braku CSRF, 403 z Policy, 429 z limitu, literówka
 * w adresie. Dlatego każde żądanie jest sprawdzane dwukrotnie:
 *
 *  1. `assertNieOdbite()` — kod odpowiedzi nie jest 403/404/419/429/5xx,
 *     czyli żądanie DOSZŁO do kontrolera;
 *  2. osobny test `test_kontrola_dodatnia_...` pokazuje, że tą samą drogą
 *     poprawne pole NAPRAWDĘ się zapisuje.
 *
 * Bez tej pary zielony wynik znaczyłby tylko tyle, że aplikacja nie
 * odpowiada.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK NIE DOWODZI — I CO Z TEGO WYNIKA (zmierzone 12.09.2026)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ten test i `WrazliweKolumnyPozaMasowymPrzypisaniemTest` mierzą DWIE RÓŻNE
 * warstwy i żaden nie zastępuje drugiego. Zmierzone dwoma sabotażami:
 *
 * | Sabotaż | Skan modeli | Ten plik |
 * |---|---|---|
 * | `status` dopisany do `User::$fillable` | CZERWONY | zielony |
 * | `forceFill($request->only(['role','status']))` w kontrolerze ustawień | zielony | CZERWONY |
 *
 * Pierwszy wiersz: dopisanie kolumny do `$fillable` nie otwiera żadnej
 * trasy, dopóki jakiś kontroler jej nie przekaże — więc atak przez HTTP nic
 * nie widzi. Drugi wiersz: `forceFill()` omija `$fillable` w całości, więc
 * skan modeli nic nie widzi. Usunięcie któregokolwiek z tych plików zostawia
 * połowę reguły bez pomiaru.
 */
class PodniesienieRoliZadaniemHttpTest extends TestCase
{
    use RefreshDatabase;

    /** Hasło napastnika — sprawdzamy, że żaden atak go nie podmienia. */
    private const HASLO_NAPASTNIKA = 'oryginalne-haslo-basi-123';

    /** Hasło, które napastnik próbuje sobie (albo ofierze) wstawić. */
    private const HASLO_NAPASTNIKA_PODSTAWIONE = 'podstawione-haslo-999';

    /**
     * Pola doklejane do KAŻDEGO poprawnego formularza.
     *
     * Lista nie jest przepisana z palca — każda pozycja to jedna
     * z czterech kategorii kolumn wrażliwych opisanych
     * w `WrazliweKolumnyPozaMasowymPrzypisaniemTest`:
     *
     *  - stan konta i treści: `role`, `status`, `previous_status`;
     *  - poświadczenia: `password`, `email`, `email_verified_at`,
     *    `remember_token`, `two_factor_secret`, `two_factor_confirmed_at`;
     *  - klucze właściciela: `user_id`, `author_id`, `owner_id`, `actor_id`,
     *    `reporter_id`, `curator_id`, `moderator_id`, `subject_user_id`,
     *    `editor_id`, `blocker_id`;
     *  - rozstrzygnięcia moderacji: `resolved_by`, `decided_by`,
     *    `handled_by`, `resolved_at`, `decided_at`, `handled_at`.
     *
     * @return array<string, mixed>
     */
    private function dodatki(User $ofiara): array
    {
        return [
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_BANNED,
            'previous_status' => User::STATUS_ACTIVE,

            'password' => self::HASLO_NAPASTNIKA_PODSTAWIONE,
            'email' => 'przejete@example.test',
            'email_verified_at' => '2026-01-01 00:00:00',
            'remember_token' => 'zetonnapastnika',
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => '2026-01-01 00:00:00',

            'user_id' => $ofiara->getKey(),
            'author_id' => $ofiara->getKey(),
            'owner_id' => $ofiara->getKey(),
            'actor_id' => $ofiara->getKey(),
            'reporter_id' => $ofiara->getKey(),
            'curator_id' => $ofiara->getKey(),
            'moderator_id' => $ofiara->getKey(),
            'subject_user_id' => $ofiara->getKey(),
            'editor_id' => $ofiara->getKey(),
            'blocker_id' => $ofiara->getKey(),

            'resolved_by' => $ofiara->getKey(),
            'decided_by' => $ofiara->getKey(),
            'handled_by' => $ofiara->getKey(),
            'resolved_at' => '2026-01-01 00:00:00',
            'decided_at' => '2026-01-01 00:00:00',
            'handled_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * Żądanie DOSZŁO do kontrolera. Bez tego cały plik mierzyłby wyłącznie
     * to, czy aplikacja odpowiada (pułapka 4).
     */
    private function assertNieOdbite(TestResponse $odpowiedz, string $gdzie): void
    {
        $this->assertNotContains(
            $odpowiedz->getStatusCode(),
            [403, 404, 419, 429, 500, 503],
            "Żądanie na {$gdzie} zostało odbite kodem {$odpowiedz->getStatusCode()}, "
            .'więc ten atak niczego nie zmierzył — a „nic się nie zmieniło" wyglądałoby '
            .'dokładnie tak samo jak przy skutecznej obronie (pułapka 4).',
        );
    }

    /** Napastnik: zwykłe, aktywne konto z profilem. */
    private function napastnik(): User
    {
        return $this->user('basia_napastniczka', [
            'password' => Hash::make(self::HASLO_NAPASTNIKA),
            'email' => 'basia@example.test',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * Konto napastnika nie ruszyło się ani o krok — rola, stan, adres,
     * potwierdzenie adresu, hasło i 2FA.
     */
    private function assertKontoNietkniete(User $napastnik, string $gdzie): void
    {
        $swiezy = $napastnik->fresh();

        $this->assertSame(User::ROLE_USER, $swiezy->role, "ZNALEZISKO: {$gdzie} podniosło rolę do administratora.");
        $this->assertSame(User::STATUS_ACTIVE, $swiezy->status, "ZNALEZISKO: {$gdzie} przestawiło stan konta.");
        $this->assertSame('basia@example.test', $swiezy->email, "ZNALEZISKO: {$gdzie} podmieniło adres e-mail.");
        $this->assertTrue(
            Hash::check(self::HASLO_NAPASTNIKA, (string) $swiezy->password),
            "ZNALEZISKO: {$gdzie} podmieniło hasło przez masowe przypisanie.",
        );
        $this->assertNull($swiezy->two_factor_secret, "ZNALEZISKO: {$gdzie} ustawiło sekret 2FA z formularza.");
    }

    /**
     * ATAK 1-8: wszystkie trasy przyjmujące dane profilu i ustawień.
     */
    public function test_zadna_trasa_profilu_i_ustawien_nie_podnosi_roli_ani_stanu_konta(): void
    {
        $napastnik = $this->napastnik();
        $ofiara = $this->user('ofiara');
        $dodatki = $this->dodatki($ofiara);

        $proby = [
            'PUT /ustawienia/profil' => ['put', '/ustawienia/profil', [
                'display_name' => 'Basia',
                'username' => 'basia_napastniczka',
                'bio' => 'Gotuję od zawsze.',
            ]],
            'PUT /ustawienia/czytelnosc' => ['put', '/ustawienia/czytelnosc', [
                'text_scale' => config('kuking.text.scales')[0],
            ]],
            'PUT /ustawienia/prywatnosc' => ['put', '/ustawienia/prywatnosc', [
                'wants_weekly_digest' => '1',
                'memories_enabled' => '1',
            ]],
            'POST /motyw' => ['post', '/motyw', [
                'theme' => config('kuking.theme.options')[0],
            ]],
            'PUT /ustawienia/tagi' => ['put', '/ustawienia/tagi', [
                'tags' => [],
            ]],
            'POST /witaj/zainteresowania' => ['post', '/witaj/zainteresowania', [
                'tags' => [],
            ]],
            'POST /witaj/ludzie' => ['post', '/witaj/ludzie', [
                'follow' => [],
            ]],
            'POST /napisz-do-nas' => ['post', '/napisz-do-nas', [
                'kind' => ContactMessage::KIND_INNE,
                'message' => 'Dzień dobry, mam pytanie o zeszyt.',
                'contact_email' => 'basia@example.test',
            ]],
        ];

        foreach ($proby as $nazwa => [$metoda, $adres, $formularz]) {
            $odpowiedz = $this->actingAs($napastnik)->{$metoda}($adres, array_merge($dodatki, $formularz));

            $this->assertNieOdbite($odpowiedz, $nazwa);
            $this->assertKontoNietkniete($napastnik, $nazwa);
        }

        // PUŁAPKA 2: pętla, która nie wykonała ani jednej próby, wygląda
        // identycznie jak pętla, która wykonała wszystkie i wszystkie się
        // obroniły.
        $this->assertGreaterThanOrEqual(
            8,
            count($proby),
            'Lista prób się skurczyła — ten test nie atakuje już tego, co obiecuje.',
        );
    }

    /**
     * ATAK 9-15: trasy tworzące i edytujące treść. Tu stawką nie jest rola,
     * tylko klucz WŁAŚCICIELA i stan moderacyjny: gdyby `author_id` dało się
     * przysłać z formularza, każdy mógłby wpisywać treści na cudze konto —
     * a gdyby dało się przysłać `status`, ukryty przez moderatora wpis
     * wracałby na stronę jednym polem.
     */
    public function test_trasy_tresci_nie_pozwalaja_podstawic_cudzego_autora_ani_stanu(): void
    {
        $napastnik = $this->napastnik();
        $ofiara = $this->user('ofiara');
        $dodatki = $this->dodatki($ofiara);

        $wykonanych = 0;

        // ── 9. Nowy wpis: `author_id` ofiary + `status`/`visibility`.
        $odpowiedz = $this->actingAs($napastnik)->post('/dodaj/zdjecie', array_merge($dodatki, [
            'body' => 'Dziś rosół.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /dodaj/zdjecie');
        $wykonanych++;

        $wpis = Post::query()->latest('id')->first();
        $this->assertNotNull($wpis, 'Wpis nie powstał — atak niczego nie zmierzył.');
        $this->assertSame(
            $napastnik->getKey(),
            $wpis->author_id,
            'ZNALEZISKO: `author_id` przyszedł z formularza — wpis zapisał się na cudze konto.',
        );

        // ── 10. Edycja wpisu: podmiana autora i stanu.
        $odpowiedz = $this->actingAs($napastnik)->put('/wpisy/'.$wpis->getKey(), array_merge($dodatki, [
            'body' => 'Dziś jednak żurek.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]));
        $this->assertNieOdbite($odpowiedz, 'PUT /wpisy/{post}');
        $wykonanych++;

        $wpis->refresh();
        $this->assertSame($napastnik->getKey(), $wpis->author_id, 'ZNALEZISKO: edycja wpisu podmieniła autora.');
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->status, 'ZNALEZISKO: edycja wpisu przestawiła stan moderacyjny.');

        // ── 11. Cudzy wpis ukryty przez moderatora: czy `status` wraca polem.
        $cudzyUkryty = Post::factory()->create([
            'author_id' => $ofiara->getKey(),
            'status' => Post::STATUS_HIDDEN,
        ]);
        $odpowiedz = $this->actingAs($napastnik)->put('/wpisy/'.$cudzyUkryty->getKey(), array_merge($dodatki, [
            'body' => 'Przejmuję ten wpis.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]));
        $wykonanych++;
        $this->assertSame(403, $odpowiedz->getStatusCode(), 'Cudzy wpis ma odbić się o Policy, a nie o `$fillable`.');
        $this->assertSame(
            Post::STATUS_HIDDEN,
            $cudzyUkryty->fresh()->status,
            'ZNALEZISKO: ukryty wpis wrócił na stronę.',
        );

        // ── 12. Nowy przepis: `author_id` ofiary + `status=published` przy szkicu.
        $odpowiedz = $this->actingAs($napastnik)->post('/dodaj/przepis', array_merge($dodatki, [
            'action' => 'draft',
            'title' => 'Rosół na niedzielę',
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'ingredients' => [['text' => 'kura']],
            'steps' => [['instruction' => 'Gotuj trzy godziny.']],
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /dodaj/przepis');
        $wykonanych++;

        $przepis = Recipe::query()->where('title', 'Rosół na niedzielę')->first();
        $this->assertNotNull($przepis, 'Przepis nie powstał — atak niczego nie zmierzył.');
        $this->assertSame($napastnik->getKey(), $przepis->author_id, 'ZNALEZISKO: przepis zapisał się na cudze konto.');
        $this->assertSame(
            Recipe::STATUS_DRAFT,
            $przepis->status,
            'ZNALEZISKO: `status=published` z formularza opublikował przepis zapisany jako szkic.',
        );

        // ── 13. Zeszyt: `owner_id` ofiary.
        $odpowiedz = $this->actingAs($napastnik)->post('/zeszyt', array_merge($dodatki, [
            'name' => 'Na święta',
            'visibility' => 'private',
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /zeszyt');
        $wykonanych++;

        $zeszyt = Collection::query()->where('name', 'Na święta')->first();
        $this->assertNotNull($zeszyt, 'Zeszyt nie powstał — atak niczego nie zmierzył.');
        $this->assertSame($napastnik->getKey(), $zeszyt->owner_id, 'ZNALEZISKO: zeszyt założył się na cudzym koncie.');

        // ── 14. Komentarz: `author_id` ofiary + `status`.
        $cudzyWpis = Post::factory()->create(['author_id' => $ofiara->getKey()]);
        $odpowiedz = $this->actingAs($napastnik)->post('/wpisy/'.$cudzyWpis->getKey().'/komentarz', array_merge($dodatki, [
            'body' => 'Wygląda pysznie.',
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /wpisy/{post}/komentarz');
        $wykonanych++;

        $komentarz = Comment::query()->latest('id')->first();
        $this->assertNotNull($komentarz, 'Komentarz nie powstał — atak niczego nie zmierzył.');
        $this->assertSame($napastnik->getKey(), $komentarz->author_id, 'ZNALEZISKO: komentarz podpisał się cudzym kontem.');
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->status, 'ZNALEZISKO: komentarz przyszedł z własnym stanem.');

        // ── 15. „Ugotowałem": `user_id` ofiary.
        $cudzyPrzepis = Recipe::factory()->create(['author_id' => $ofiara->getKey()]);
        $odpowiedz = $this->actingAs($napastnik)->post('/przepisy/'.$cudzyPrzepis->slug.'/ugotowalem', array_merge($dodatki, [
            'would_make_again' => '1',
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /przepisy/{recipe}/ugotowalem');
        $wykonanych++;

        $ugotowane = CookedEvent::query()->latest('id')->first();
        $this->assertNotNull($ugotowane, 'Wykonanie nie powstało — atak niczego nie zmierzył.');
        $this->assertSame($napastnik->getKey(), $ugotowane->user_id, 'ZNALEZISKO: „Ugotowałem" zapisało się na cudze konto.');

        // ── 16. Zgłoszenie: `status=resolved` i `resolved_by`.
        $odpowiedz = $this->actingAs($napastnik)->post('/zglos/post/'.$cudzyWpis->getKey(), array_merge($dodatki, [
            'reason' => 'spam',
            'details' => 'Reklama suplementów.',
        ]));
        $this->assertNieOdbite($odpowiedz, 'POST /zglos/{type}/{id}');
        $wykonanych++;

        $zgloszenie = Report::query()->latest('id')->first();
        $this->assertNotNull($zgloszenie, 'Zgłoszenie nie powstało — atak niczego nie zmierzył.');
        $this->assertSame($napastnik->getKey(), $zgloszenie->reporter_id, 'ZNALEZISKO: zgłoszenie podpisało się cudzym kontem.');
        $this->assertNotSame(
            Report::STATUS_RESOLVED,
            $zgloszenie->status,
            'ZNALEZISKO: zgłoszenie wpadło do bazy od razu jako załatwione — nikt by go nie zobaczył.',
        );
        $this->assertNull($zgloszenie->resolved_by, 'ZNALEZISKO: `resolved_by` przyszło z formularza.');

        $this->assertKontoNietkniete($napastnik, 'trasy treści');

        $this->assertGreaterThanOrEqual(
            8,
            $wykonanych,
            'Wykonano mniej prób, niż ten test obiecuje — reszta przestała się wykonywać (pułapka 2).',
        );
    }

    /**
     * ATAK 17: rejestracja. Jedyna trasa, na której konto DOPIERO POWSTAJE
     * — czyli jedyna, na której `role` nie musi być podnoszone, wystarczy
     * je od razu podać.
     */
    public function test_rejestracja_nie_zaklada_konta_z_rola_administratora(): void
    {
        $ofiara = $this->user('ofiara');

        $odpowiedz = $this->post('/register', array_merge($this->dodatki($ofiara), [
            'display_name' => 'Nowa Basia',
            'username' => 'nowa_basia',
            'email' => 'nowa@example.test',
            'password' => 'zielonapietruszkarano',
            'password_confirmation' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]));

        $this->assertNieOdbite($odpowiedz, 'POST /register');

        $nowe = User::query()->where('email', 'nowa@example.test')->first();

        $this->assertNotNull($nowe, 'Konto nie powstało — atak niczego nie zmierzył.');
        $this->assertSame(User::ROLE_USER, $nowe->role, 'ZNALEZISKO: rejestracja z polem `role` zakłada administratora.');
        $this->assertSame(User::STATUS_ACTIVE, $nowe->status, 'ZNALEZISKO: rejestracja przyjmuje `status` z formularza.');
        $this->assertNull($nowe->email_verified_at, 'ZNALEZISKO: rejestracja przyjmuje potwierdzenie adresu z formularza.');
        $this->assertNull($nowe->two_factor_secret, 'ZNALEZISKO: rejestracja przyjmuje sekret 2FA z formularza.');
    }

    /**
     * KONTROLA DODATNIA CAŁEGO PLIKU (pułapka 4).
     *
     * Wszystkie testy wyżej mówią „nic się nie zmieniło". Ten mówi, że tą
     * samą drogą, tym samym żądaniem, POPRAWNE pole zmienia się naprawdę —
     * czyli że aplikacja w ogóle przyjmuje te formularze, a zieleń wyżej
     * nie bierze się z tego, że każde żądanie odbija się o cokolwiek.
     */
    public function test_kontrola_dodatnia_poprawne_pola_zapisuja_sie_ta_sama_droga(): void
    {
        $napastnik = $this->napastnik();
        $ofiara = $this->user('ofiara');
        $dodatki = $this->dodatki($ofiara);

        $skala = config('kuking.text.scales')[1] ?? config('kuking.text.scales')[0];

        $this->actingAs($napastnik)
            ->put('/ustawienia/czytelnosc', array_merge($dodatki, ['text_scale' => $skala]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $skala,
            $napastnik->fresh()->text_scale,
            'Rozmiar tekstu się nie zapisał — czyli żądania z tego pliku nie dochodzą '
            .'do kontrolera i cała reszta niczego nie mierzy.',
        );

        $this->actingAs($napastnik)
            ->put('/ustawienia/profil', array_merge($dodatki, [
                'display_name' => 'Basia z Podkarpacia',
                'username' => 'basia_napastniczka',
                'bio' => 'Rosół w każdą niedzielę.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Basia z Podkarpacia',
            $napastnik->fresh()->profile->display_name,
            'Profil się nie zapisał — patrz wyżej.',
        );
    }
}
