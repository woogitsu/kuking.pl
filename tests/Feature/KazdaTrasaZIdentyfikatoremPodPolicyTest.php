<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\PendingEmailChange;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * „UUID W ADRESIE TO NIE AUTORYZACJA" (AGENTS.md §7) — POMIAR ŻĄDANIEM HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CZYM TEN PLIK RÓŻNI SIĘ OD `AutoryzacjaTrasZWiazaniemModeluTest` (#457)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Tamten plik ma dwie warstwy: SKAN po tablicy tras („czy bramka w ogóle
 * istnieje") i garść testów behawioralnych na dwunastu trasach. Ten domyka
 * dwie dziury, które tamten zostawia, i obie zostały ZMIERZONE, a nie
 * oszacowane:
 *
 *  1. SKAN NIE WIDZI CZĘŚCI TRAS. Uznaje trasę za „biorącą model z adresu"
 *     tylko wtedy, gdy metoda kontrolera ma parametr typowany klasą modelu
 *     ALBO woła `findOrFail()`/`firstOrFail()` we WŁASNYM ciele. Zmierzone
 *     12.09.2026 na `origin/main`: z 73 tras z parametrem w adresie skan
 *     widzi 54. Poza nim zostaje 10 tras, które identyfikator obiektu
 *     przyjmują — sześć tras profilu (`Profile::poNazwie()`, bez
 *     `firstOrFail`), `reports.create`, `reports.store` (model wczytuje
 *     PRYWATNA metoda `resolveTarget()`, więc `findOrFail` nie stoi w ciele
 *     metody trasy), `verification.verify` i `settings.email.confirm`
 *     (`->first()`, nie `firstOrFail()`). Zdjęcie `authorize()` z każdej
 *     z tych dziesięciu nie zapala tam ani jednego czerwonego przebiegu.
 *
 *  2. TESTY BEHAWIORALNE NIE MIAŁY ANI GOŚCIA, ANI OSOBY ZABLOKOWANEJ
 *     i obejmowały 12 tras z 64. Tutaj każda trasa z identyfikatorem
 *     dostaje PIĘĆ prawdziwych żądań HTTP: właściciel, obca osoba
 *     zalogowana, osoba zablokowana przez właściciela, moderator i gość.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  JAK CZYTAĆ TABELĘ PRZYPADKÓW
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Każdy wiersz to jedna trasa i pięć oczekiwań — po jednym na rolę.
 * Wartości NIE SĄ oszacowane z lektury Policy: wszystkie pochodzą
 * z pomiaru, a każda, która wyszła inaczej, niż się spodziewano, została
 * sprawdzona w kodzie z osobna.
 *
 *  - `self::WOLNO`  — wejście ma się udać. To jest KONTROLA DODATNIA
 *    (`docs/PULAPKI_TESTOW.md` pułapka 4): bez niej cały plik przechodziłby
 *    także wtedy, gdyby serwis odmawiał wszystkim wszystkiego.
 *  - `self::ODMOWA` — 403 albo 404, a dla gościa na trasie za `auth`
 *    także przekierowanie na logowanie. Nigdy 200 i nigdy 500.
 *  - `self::ODMOWA_CICHA` — odpowiedź NIEODRÓŻNIALNA od „takiego linku nie
 *    ma": przekierowanie na zwykły ekran z neutralnym komunikatem. Ma
 *    dokładnie jedno zastosowanie (`settings.email.confirm`) i towarzyszy
 *    mu osobny test sprawdzający, że adres e-mail naprawdę się nie zmienił
 *    — samo „302 na ustawienia" nie jest dowodem odmowy.
 *
 * CZEGO TEN PLIK NIE MIERZY: treści odpowiedzi. Odpowiada na pytanie „czy
 * drzwi się otwierają", nie „co widać po wejściu". Filtrowanie zawartości
 * list (kto wychodzi w wynikach, czyj wpis wchodzi do feedu) pilnują testy
 * widoczności, nie ten.
 */
class KazdaTrasaZIdentyfikatoremPodPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const WOLNO = 'wolno';

    private const ODMOWA = 'odmowa';

    private const ODMOWA_CICHA = 'odmowa cicha';

    /**
     * Trasy z parametrem w adresie, które NIE przyjmują identyfikatora
     * obiektu — więc nie ma przy nich czego pilnować Policy.
     *
     * Parametr jest tu JEDNORAZOWYM HASŁEM z listu, nie wskazaniem cudzej
     * treści: kto go ma, ten ma prawo wejść, i na tym polega cały mechanizm.
     * Tokeny są losowe (64 znaki `[A-Za-z0-9]`), w bazie leżą wyłącznie jako
     * HMAC-SHA256 (`App\Support\Skrot`), a ważność pilnuje wiersz, nie adres.
     *
     * @var array<string, string>
     */
    private const BEZ_IDENTYFIKATORA_OBIEKTU = [
        'password.reset' => 'Parametr {token} to jednorazowy token resetu hasła, nie identyfikator obiektu.',
        'login.link.confirm' => 'Parametr {token} to jednorazowy token logowania linkiem (D-056).',
        'zaproszenie.pokaz' => 'Parametr {token} to jednorazowy token zaproszenia do rejestracji.',
    ];

    /**
     * Trasy z parametrem, których nie obsługuje nasz kontroler.
     *
     * `storage.local` i `storage.local.upload` rejestruje SAM Laravel dla
     * dysku `local` z `'serve' => true` (`FilesystemServiceProvider::serveFiles`).
     * Tą trasą wychodzą bajty z `storage/app/private` — czyli m.in. PACZKA
     * RODO, bo `kuking.exports.disk` to lokalnie i w testach `local`.
     * Sam identyfikator paczki jest zgadywalny w stopniu, o który nie warto
     * się spierać (`eksporty/<id konta>/<id paczki>-…`), więc
     * ochroną nie może być to, że nikt nie zna ścieżki — i nie jest:
     * `ServeFile` wymaga podpisu, bo dysk `local` nie ma `visibility =>
     * 'public'`. TO JEST WARUNEK, KTÓRY WOLNO ZGUBIĆ JEDNĄ LINIJKĄ
     * W `config/filesystems.php`, więc stoi pod testem
     * (`test_trasa_plikowa_frameworku_nie_wydaje_paczki_rodo_bez_podpisu`).
     *
     * Trasy Livewire'a serwują jego własne zasoby (komponent CSS/JS,
     * podgląd pliku tymczasowego) i nie biorą identyfikatora naszej treści.
     *
     * @var array<string, string>
     */
    private const NIE_NASZ_KONTROLER = [
        'storage.local' => 'Trasa frameworku (dysk local, serve). Wymaga podpisu — osobny test niżej.',
        'storage.local.upload' => 'Trasa frameworku (dysk local, serve). Wymaga podpisu z ?upload=true.',
        'livewire.preview-file' => 'Podgląd pliku tymczasowego Livewire, poza naszą przestrzenią nazw.',
    ];

    /** @var array<string, User> */
    private array $osoby = [];

    /** @var list<array{trasa: string, opis: string, metoda: string, url: string, dane: array<string, mixed>, oczekiwania: array<string, string>}> */
    private array $przypadki = [];

    public function test_wlasciciel_wchodzi_na_swoje(): void
    {
        $this->zmierz('wlasciciel');
    }

    public function test_obca_zalogowana_osoba_nie_wchodzi_przez_sam_identyfikator(): void
    {
        $this->zmierz('obcy');
    }

    public function test_osoba_zablokowana_przez_wlasciciela_nie_wchodzi(): void
    {
        $this->zmierz('zablokowany');
    }

    public function test_moderator_wchodzi_tam_i_tylko_tam_gdzie_ma_wchodzic(): void
    {
        $this->zmierz('moderator');
    }

    public function test_gosc_nie_wchodzi_przez_sam_identyfikator(): void
    {
        $this->zmierz('gosc');
    }

    /**
     * SPIS PRZYPADKÓW POKRYWA KAŻDĄ TRASĘ Z IDENTYFIKATOREM.
     *
     * To jest asercja, przez którą ten plik nie zgnije: nowa trasa
     * z parametrem w adresie oblewa ten test, dopóki ktoś nie dopisze jej
     * do tabeli razem z oczekiwaniami albo nie wpisze jej na jedną z dwóch
     * jawnych list wyżej — z powodem (AGENTS.md §7, punkt 6 zlecenia).
     *
     * Asercja na minimalną liczbę tras jest OBOWIĄZKOWA
     * (`docs/PULAPKI_TESTOW.md` pułapka 2): skan, który nie znalazł żadnej
     * trasy, wygląda dokładnie tak samo jak skan, który znalazł wszystkie.
     */
    public function test_spis_przypadkow_pokrywa_kazda_trase_z_parametrem_w_adresie(): void
    {
        $this->zbudujSwiat();

        $pokryte = [];

        foreach ($this->przypadki as $przypadek) {
            $pokryte[$przypadek['trasa']] = true;
        }

        $zParametrem = 0;
        $niepokryte = [];

        foreach (Route::getRoutes() as $trasa) {
            if (! str_contains($trasa->uri(), '{')) {
                continue;
            }

            $zParametrem++;

            $nazwa = $trasa->getName() ?? $trasa->uri();

            if (isset($pokryte[$nazwa])
                || array_key_exists($nazwa, self::BEZ_IDENTYFIKATORA_OBIEKTU)
                || array_key_exists($nazwa, self::NIE_NASZ_KONTROLER)) {
                continue;
            }

            // Trasy Livewire'a bez nazwy (komponent CSS/JS) — rozpoznajemy je
            // po adresie, bo nazwy nie mają i mieć nie będą.
            if (str_starts_with($trasa->uri(), 'livewire')) {
                continue;
            }

            $niepokryte[] = $nazwa.' ('.$trasa->getActionName().')';
        }

        $this->assertGreaterThanOrEqual(70, $zParametrem,
            'Skan nie czyta tablicy tras — zero tras z parametrem wygląda jak komplet. Policzono: '.$zParametrem);

        $this->assertGreaterThanOrEqual(64, count($pokryte),
            'Tabela przypadków zmalała poniżej stanu z 12.09.2026 (64 trasy). Skasowana trasa to zgoda, '
            .'która nie została nigdzie zapisana. Pokrytych: '.count($pokryte));

        $this->assertSame([], $niepokryte,
            "Trasa bierze coś z adresu i nikt nie zmierzył, kto przez nią wchodzi.\n"
            ."AGENTS.md §7: UUID w adresie NIE JEST autoryzacją.\n"
            .'Dopisz ją do tabeli przypadków w tym pliku razem z oczekiwaniami dla pięciu ról, albo — jeśli '
            ."parametr nie jest identyfikatorem obiektu — na listę BEZ_IDENTYFIKATORA_OBIEKTU razem z POWODEM.\n"
            .'Trasy bez pomiaru: '.implode(', ', $niepokryte));
    }

    /**
     * PACZKA RODO NIE WYCHODZI TRASĄ FRAMEWORKU.
     *
     * `config/filesystems.php` daje dyskowi `local` `'serve' => true`, więc
     * Laravel rejestruje `GET /storage/{path}` bez żadnego naszego
     * middleware. Na tym dysku leżą paczki z danymi konta
     * (`kuking.exports.disk` === `local` lokalnie i w testach). Jedyne, co
     * tej trasy pilnuje, to brak `visibility => 'public'` w konfiguracji
     * dysku — wtedy `ServeFile` wymaga podpisu. Dopisanie tam kiedykolwiek
     * `'visibility' => 'public'` otworzyłoby cały prywatny magazyn i nie
     * wywaliłoby żadnego innego testu w tym repozytorium.
     *
     * KONTROLA DODATNIA jest w tym samym teście (pułapka 4): ta sama paczka
     * MUSI dać się pobrać właścicielowi przez `settings.data.download`,
     * inaczej „404 dla obcego" znaczyłoby tylko tyle, że pliku nie ma.
     */
    public function test_trasa_plikowa_frameworku_nie_wydaje_paczki_rodo_bez_podpisu(): void
    {
        $this->zbudujSwiat();

        $adres = '/storage/'.$this->paczka->object_key;

        foreach (['wlasciciel', 'obcy', 'gosc'] as $rola) {
            $this->zaloguj($rola);

            $odpowiedz = $this->get($adres);

            $this->assertContains($odpowiedz->getStatusCode(), [403, 404],
                "Paczka RODO wyszła trasą `storage.local` bez podpisu ({$rola}, kod "
                .$odpowiedz->getStatusCode().'). Sprawdź `visibility` dysku `local` w config/filesystems.php.');

            $this->assertStringNotContainsString('TAJNA-ZAWARTOSC-PACZKI', (string) $odpowiedz->getContent(),
                'Bajty paczki wyszły trasą `storage.local`.');
        }

        // KONTROLA DODATNIA: właściciel pobiera tę samą paczkę własną trasą.
        $this->zaloguj('wlasciciel');

        $odpowiedz = $this->get(URL::temporarySignedRoute(
            'settings.data.download',
            now()->addHour(),
            ['export' => $this->paczka->getKey()],
        ));

        $odpowiedz->assertOk();
        $this->assertSame('TAJNA-ZAWARTOSC-PACZKI', $odpowiedz->streamedContent(),
            'Właściciel nie pobrał własnej paczki — powyższe odmowy nie dowodzą wtedy niczego.');
    }

    /**
     * `settings.email.confirm` ODMAWIA CICHO — i „cicho" ma tu znaczyć
     * „nic się nie stało", a nie „nie wiadomo, co się stało".
     *
     * Kontroler odpowiada obcemu tym samym przekierowaniem co na link
     * nieistniejący, więc samo 302 nie jest dowodem odmowy. Dowodem jest
     * dopiero to, że adres e-mail właściciela się nie zmienił — i że
     * zmienia się, gdy ten sam link kliknie on sam (kontrola dodatnia).
     */
    public function test_cudzy_link_potwierdzajacy_zmiane_adresu_niczego_nie_zmienia(): void
    {
        $this->zbudujSwiat();

        $adres = URL::signedRoute('settings.email.confirm', ['zmiana' => $this->zmianaAdresu->getKey()]);
        $staryAdres = (string) $this->osoby['wlasciciel']->email;

        foreach (['obcy', 'zablokowany', 'moderator'] as $rola) {
            $this->zaloguj($rola);
            $this->get($adres);

            $this->assertSame($staryAdres, (string) $this->osoby['wlasciciel']->fresh()->email,
                "Obca osoba ({$rola}) potwierdziła cudzą zmianę adresu e-mail przez sam identyfikator.");
        }

        // KONTROLA DODATNIA: właściciel tym samym linkiem zmienia adres.
        $this->zaloguj('wlasciciel');
        $this->get($adres);

        $this->assertSame('nowy@example.com', (string) $this->osoby['wlasciciel']->fresh()->email,
            'Właściciel nie potwierdził własnej zmiany adresu — powyższe „nic się nie zmieniło" '
            .'przechodziłoby wtedy także przy trasie zepsutej dla wszystkich.');
    }

    /**
     * Rozstrzyganie odwołań wymaga roli `admin`, nie samego `moderator`
     * (`UserPolicy::resolveAppeals`, A-4). W tabeli wyżej moderator ma tam
     * ODMOWĘ — bez tej kontroli dodatniej nie byłoby wiadomo, czy trasa
     * w ogóle działa dla kogokolwiek.
     */
    public function test_odwolanie_rozstrzyga_administrator_a_nie_kazdy_moderator(): void
    {
        $this->zbudujSwiat();

        $this->actingAs($this->admin());

        $this->from(route('home'))
            ->post(route('admin.appeals.resolve', $this->odwolanie), [
                'decision' => 'upheld',
                'note' => 'Podtrzymuję decyzję po ponownym sprawdzeniu.',
            ])
            ->assertRedirect();
    }

    /**
     * Jeden przebieg tabeli dla jednej roli.
     *
     * Kolejność ról nie ma znaczenia, bo KAŻDA trasa zmieniająca stan
     * dostaje w `zbudujSwiat()` własny, osobny zasób. Wcześniejsza wersja
     * tego pomiaru dzieliła zasoby i dała fałszywe 404 na `wspomnienia.ukryj`
     * — wpis był już skasowany przez `posts.destroy` kilka wierszy wyżej.
     * To jest ten sam rodzaj pomyłki co pułapka 8b: czerwień prawdziwa,
     * tylko z innej warstwy.
     */
    private function zmierz(string $rola): void
    {
        $this->zbudujSwiat();
        $this->zaloguj($rola);

        $sprawdzone = 0;

        foreach ($this->przypadki as $przypadek) {
            $oczekiwane = $przypadek['oczekiwania'][$rola];

            $odpowiedz = $this->from(route('home'))
                ->{$przypadek['metoda']}($przypadek['url'], $przypadek['dane']);

            $kod = $odpowiedz->getStatusCode();
            $cel = $kod === 302 ? (string) $odpowiedz->headers->get('Location') : '';
            $naLogowanie = $kod === 302 && str_contains($cel, route('login'));

            $gdzie = $przypadek['trasa'].' ('.$przypadek['opis'].'), rola '.$rola.', kod '.$kod;

            $this->assertNotSame(500, $kod,
                "Trasa oddała błąd serwera zamiast decyzji: {$gdzie}. "
                .'Odmowa to 403 albo 404 — nigdy 500.');

            if ($oczekiwane === self::ODMOWA) {
                $this->assertTrue(in_array($kod, [403, 404], true) || $naLogowanie,
                    "Wejście przez sam identyfikator: {$gdzie}, a miała być odmowa.\n"
                    .'AGENTS.md §7: UUID w adresie NIE JEST autoryzacją — każde wejście na cudzą treść przez Policy.');
            } elseif ($oczekiwane === self::WOLNO) {
                $this->assertFalse(in_array($kod, [401, 403, 404, 419], true) || $naLogowanie,
                    "Odmowa tam, gdzie wejście ma być możliwe: {$gdzie}.\n"
                    .'To jest kontrola dodatnia (pułapka 4) — bez niej cały ten plik przechodziłby '
                    .'także wtedy, gdyby serwis odmawiał wszystkim wszystkiego.');
            } else {
                $this->assertSame(302, $kod,
                    "Spodziewana cicha odmowa (przekierowanie nieodróżnialne od nieistniejącego linku): {$gdzie}.");
            }

            $sprawdzone++;
        }

        // Pułapka 2: przebieg, który nie sprawdził ani jednej trasy,
        // wygląda dokładnie tak samo jak przebieg, który sprawdził wszystkie.
        $this->assertGreaterThanOrEqual(64, $sprawdzone,
            "Tabela przypadków nie została wczytana — ten przebieg niczego nie mierzy. Sprawdzono: {$sprawdzone}");
    }

    private function zaloguj(string $rola): void
    {
        $this->app['auth']->forgetGuards();

        if ($rola !== 'gosc') {
            $this->actingAs($this->osoby[$rola]);
        }
    }

    private Post $wpis;

    private DataExport $paczka;

    private PendingEmailChange $zmianaAdresu;

    private Appeal $odwolanie;

    /**
     * Świat pomiaru: pięć ról i po jednym zasobie na każdą trasę, która coś
     * zmienia. Zasób współdzielony między trasą czytającą a kasującą
     * potrafi zamienić „403" w „404" i odwrotnie, a wtedy test mierzy
     * kolejność wierszy w tabeli, nie autoryzację.
     */
    private function zbudujSwiat(): void
    {
        if ($this->przypadki !== []) {
            return;
        }

        // Limity zapytań są tu wyłączone ŚWIADOMIE: ten plik mierzy
        // autoryzację, a nie limity, a pięć ról razy kilkadziesiąt tras
        // przekracza `kuking.limits.comment` (10 na minutę) na samym
        // komentowaniu. Limitów pilnują osobne testy
        // (`LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`,
        // `ZdjeciaLimitZapytanTest`).
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');

        $wlasciciel = $this->user('wlascicielka');
        $obcy = $this->user('obcaosoba');
        $zablokowany = $this->user('zablokowana');
        $moderator = $this->moderator();
        $przedmiot = $this->user('obserwowana');

        $wlasciciel->blocking()->attach($zablokowany->getKey());

        $this->osoby = [
            'wlasciciel' => $wlasciciel,
            'obcy' => $obcy,
            'zablokowany' => $zablokowany,
            'moderator' => $moderator,
        ];

        $wpis = $this->wpis = Post::factory()->private()->create(['author_id' => $wlasciciel->getKey()]);
        config(['kuking.questions.enabled' => true]);
        $pytanie = Post::factory()->question()->private()->create(['author_id' => $wlasciciel->getKey()]);
        $wpisPubliczny = Post::factory()->create(['author_id' => $wlasciciel->getKey()]);
        $wpisDoKasacji = Post::factory()->private()->create(['author_id' => $wlasciciel->getKey()]);
        $wpisDoWspomnien = Post::factory()->create(['author_id' => $wlasciciel->getKey()]);
        $wpisBezOdpowiedzi = Post::factory()->create(['author_id' => $wlasciciel->getKey()]);

        // Osobny wpis dla „Zdejmij z urzędu” (G31) — udany POST go zdejmuje.
        $wpisZUrzedu = Post::factory()->create(['author_id' => $wlasciciel->getKey()]);

        $przepis = Recipe::factory()->create(['author_id' => $wlasciciel->getKey()]);
        $przepisPrywatny = Recipe::factory()->create(['author_id' => $wlasciciel->getKey(), 'visibility' => 'private']);
        $przepisDoKasacji = Recipe::factory()->create(['author_id' => $wlasciciel->getKey()]);

        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $wlasciciel->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);
        $wykonanieDoKasacji = CookedEvent::factory()->create([
            'user_id' => $wlasciciel->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $komentarz = Comment::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'post_id' => $wpisPubliczny->getKey(),
        ]);
        $komentarzDoKasacji = Comment::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'post_id' => $wpisPubliczny->getKey(),
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Prywatny zeszyt',
            'visibility' => 'private',
        ]);
        $zeszytDoKasacji = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt na próbę',
            'visibility' => 'private',
        ]);

        // Zeszyty osoby, której konto PRZESTAŁO być aktywne (issue #1092).
        // Właścicielem jest tu ktoś SPOZA pięciu ról tabeli — żadna z nich
        // nie jest właścicielem tych dwóch zeszytów, więc wejście może dać
        // wyłącznie Policy, nigdy sam identyfikator w adresie.
        $zbanowany = $this->user('zbanowanyzeszytowy');
        $zeszytZbanowanegoPrywatny = Collection::create([
            'owner_id' => $zbanowany->getKey(),
            'name' => 'Prywatny zeszyt zbanowanego',
            'visibility' => 'private',
        ]);
        $zeszytZbanowanegoPubliczny = Collection::create([
            'owner_id' => $zbanowany->getKey(),
            'name' => 'Publiczny zeszyt zbanowanego',
            'visibility' => 'public',
        ]);
        $zbanowany->ban();

        $zgloszenie = $this->zgloszenie($wlasciciel, $wpisPubliczny);
        $zgloszenieDoDecyzji = $this->zgloszenie($wlasciciel, $wpis);
        $zgloszenieDoPrzywrocenia = $this->zgloszenie($wlasciciel, $wpisDoWspomnien);

        $decyzja = $this->decyzja($moderator, $wlasciciel, $wpisPubliczny);
        $decyzjaDoOdwolania = $this->decyzja($moderator, $wlasciciel, $wpis);

        $this->odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $wlasciciel->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Proszę o ponowne rozpatrzenie tej sprawy.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $wiadomosc = ContactMessage::factory()->create(['user_id' => $wlasciciel->getKey()]);
        $wiadomoscDoZmiany = ContactMessage::factory()->create(['user_id' => $wlasciciel->getKey()]);
        $wiadomoscDoOdpowiedzi = ContactMessage::factory()->create(['user_id' => $wlasciciel->getKey()]);

        $this->paczka = DataExport::create([
            'user_id' => $wlasciciel->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/'.$wlasciciel->getKey().'/abcd1234-kuking-moje-dane-2026-09-12.zip',
            'bytes' => 21,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        Storage::disk('local')->put((string) $this->paczka->object_key, 'TAJNA-ZAWARTOSC-PACZKI');

        $powiadomienie = Notification::create([
            'user_id' => $wlasciciel->getKey(),
            'actor_id' => $obcy->getKey(),
            'type' => 'post_commented',
            'data' => ['post_id' => (string) $wpisPubliczny->getKey()],
        ]);

        // Zdjęcie przypięte do wpisu PRYWATNEGO — najdroższy przeciek
        // w całym serwisie (AGENTS.md §7, skan odręcznej kartki).
        $zdjecie = Media::factory()->create(['owner_id' => $wlasciciel->getKey()]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 1]);

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $wariant) {
            Storage::disk('public')->put($wariant['key'], 'bajty-zdjecia');
        }

        $tag = Tag::factory()->create();
        $tagPromowany = Tag::factory()->create();
        $tagPromowanyDoKasacji = Tag::factory()->create();
        TagPromotion::create(['tag_id' => $tagPromowany->getKey(), 'position' => 1]);
        TagPromotion::create(['tag_id' => $tagPromowanyDoKasacji->getKey(), 'position' => 2]);

        $this->zmianaAdresu = new PendingEmailChange;
        $this->zmianaAdresu->user_id = $wlasciciel->getKey();
        $this->zmianaAdresu->new_email = 'nowy@example.com';
        $this->zmianaAdresu->created_at = now();
        $this->zmianaAdresu->expires_at = now()->addDay();
        $this->zmianaAdresu->save();

        $W = self::WOLNO;
        $O = self::ODMOWA;
        $C = self::ODMOWA_CICHA;

        // Kolejność kolumn: wlasciciel, obcy, zablokowany, moderator, gosc.
        $this->przypadki = [];

        $dodaj = function (string $trasa, string $opis, string $metoda, string $url, array $dane, array $role): void {
            $this->przypadki[] = [
                'trasa' => $trasa,
                'opis' => $opis,
                'metoda' => $metoda,
                'url' => $url,
                'dane' => $dane,
                'oczekiwania' => array_combine(
                    ['wlasciciel', 'obcy', 'zablokowany', 'moderator', 'gosc'],
                    $role,
                ),
            ];
        };

        // ─── PROFIL I RELACJE ────────────────────────────────────────────
        // Profil jest treścią PUBLICZNĄ z założenia — zamknięcie go
        // odcięłoby każdy podpis pod przepisem od jego autora. Blokada
        // ma pierwszeństwo i wycina zablokowanego (UserPolicy::viewProfile).
        $dodaj('profile.show', 'profil publiczny', 'get',
            route('profile.show', $wlasciciel->profile->username), [], [$W, $W, $O, $W, $W]);
        $dodaj('social.followers', 'lista obserwujących', 'get',
            route('social.followers', $wlasciciel->profile->username), [], [$W, $W, $O, $W, $W]);
        $dodaj('social.following', 'lista obserwowanych', 'get',
            route('social.following', $wlasciciel->profile->username), [], [$W, $W, $O, $W, $W]);
        // Właściciel dostaje tu odmowę, bo nikt nie obserwuje samego siebie
        // (`UserPolicy::follow`), a nie dlatego, że trasa jest zamknięta.
        $dodaj('social.follow', 'obserwowanie właściciela', 'post',
            route('social.follow', $wlasciciel->profile->username), [], [$O, $W, $O, $W, $O]);
        // Trzy trasy niżej zapisują się w relacji OSOBY DZIAŁAJĄCEJ, a nie
        // w danych osoby z adresu — dlatego celem jest tu ktoś trzeci,
        // wspólny dla wszystkich ról. Nie ma tu cudzej treści do ochrony
        // i dlatego nie ma przy nich `authorize()`.
        $dodaj('social.unfollow', 'przestaję obserwować kogoś trzeciego', 'delete',
            route('social.unfollow', $przedmiot->profile->username), [], [$W, $W, $W, $W, $O]);
        $dodaj('social.block', 'blokuję kogoś trzeciego', 'post',
            route('social.block', $przedmiot->profile->username), [], [$W, $W, $W, $W, $O]);
        $dodaj('social.unblock', 'odblokowuję kogoś trzeciego', 'delete',
            route('social.unblock', $przedmiot->profile->username), [], [$W, $W, $W, $W, $O]);

        // ─── PANEL MODERACJI ─────────────────────────────────────────────
        // 404, nie 403: `EnsureUserIsModerator` celowo nie potwierdza
        // nikomu, że panel w ogóle istnieje.
        $dodaj('admin.users.show', 'karta konta', 'get',
            route('admin.users.show', $wlasciciel), [], [$O, $O, $O, $W, $O]);
        $dodaj('admin.contact.show', 'wiadomość do operatora', 'get',
            route('admin.contact.show', $wiadomosc), [], [$O, $O, $O, $W, $O]);
        $dodaj('admin.contact.update', 'zmiana stanu wiadomości', 'post',
            route('admin.contact.update', $wiadomoscDoZmiany), ['stan' => 'zamknieta'], [$O, $O, $O, $W, $O]);
        $dodaj('admin.contact.reply', 'odpowiedź na wiadomość', 'post',
            route('admin.contact.reply', $wiadomoscDoOdpowiedzi), ['tresc' => 'Odpowiadamy na pytanie.'],
            [$O, $O, $O, $W, $O]);
        $dodaj('admin.unanswered.reply', 'odpowiedź pod wpisem bez odpowiedzi', 'post',
            route('admin.unanswered.reply', $wpisBezOdpowiedzi), ['body' => 'Odpowiedź moderatora dla tej osoby.'],
            [$O, $O, $O, $W, $O]);
        $dodaj('admin.tag-promotions.update', 'zmiana promocji tagu', 'put',
            route('admin.tag-promotions.update', $tagPromowany), ['position' => 3], [$O, $O, $O, $W, $O]);
        $dodaj('admin.tag-promotions.destroy', 'zdjęcie promocji tagu', 'delete',
            route('admin.tag-promotions.destroy', $tagPromowanyDoKasacji), [], [$O, $O, $O, $W, $O]);
        $dodaj('admin.reports.decide', 'decyzja w sprawie zgłoszenia', 'post',
            route('admin.reports.decide', $zgloszenieDoDecyzji), ['action' => 'none', 'reason_code' => 'brak-naruszenia'],
            [$O, $O, $O, $W, $O]);
        $dodaj('admin.reports.restore', 'przywrócenie treści', 'post',
            route('admin.reports.restore', $zgloszenieDoPrzywrocenia), [], [$O, $O, $O, $W, $O]);
        // „Zdejmij z urzędu” (G31, D-251): wyłącznie moderacja, przez
        // `removeExOfficio` — autor własnej treści tędy nie wchodzi.
        $dodaj('admin.z-urzedu.create', 'zdjęcie z urzędu — formularz', 'get',
            route('admin.z-urzedu.create', ['typ' => 'post', 'id' => $wpisPubliczny->getKey()]), [], [$O, $O, $O, $W, $O]);
        $dodaj('admin.z-urzedu.store', 'zdjęcie z urzędu', 'post',
            route('admin.z-urzedu.store', ['typ' => 'post', 'id' => $wpisZUrzedu->getKey()]),
            ['reason_code' => 'spam-reklama', 'user_message' => 'Wpis jest reklamą, nie ma nic wspólnego z gotowaniem.'],
            [$O, $O, $O, $W, $O]);
        // Moderator ma tu ODMOWĘ świadomie: rozstrzyga administrator
        // (`UserPolicy::resolveAppeals`, A-4). Kontrola dodatnia dla admina
        // stoi w osobnym teście wyżej.
        //
        // Ten wiersz mierzy bramkę KONTROLERA i był zielony także wtedy,
        // gdy rola nie była sprawdzana nigdzie indziej (#1087) — czyli gdy
        // ta sama czynność wykonana z komendy, kolejki albo nowego
        // endpointu obchodziła regułę A-4 w całości. Domenową stronę tej
        // bramki mierzy `RolaRozstrzygajacegoOdwolanieStoiWDomenieTest`,
        // bo żądaniem HTTP na tę trasę nie da się jej zobaczyć.
        $dodaj('admin.appeals.resolve', 'rozstrzygnięcie odwołania', 'post',
            route('admin.appeals.resolve', $this->odwolanie), ['decision' => 'upheld', 'note' => 'Notatka.'],
            [$O, $O, $O, $O, $O]);

        // ─── ODWOŁANIA I ZGŁOSZENIA ──────────────────────────────────────
        // Moderator NIE ogląda tu cudzych spraw — ma własny ekran, szerszy
        // (`AppealController::sprawdzWlascicielaSprawy`, `ReportPolicy`).
        $dodaj('appeals.show', 'moje odwołanie od decyzji', 'get',
            route('appeals.show', $decyzja), [], [$W, $O, $O, $O, $O]);
        $dodaj('appeals.store', 'złożenie odwołania', 'post',
            route('appeals.store', $decyzjaDoOdwolania), ['body' => 'Proszę o ponowne rozpatrzenie tej sprawy.'],
            [$W, $O, $O, $O, $O]);
        $dodaj('reports.mine.show', 'karta mojego zgłoszenia', 'get',
            route('reports.mine.show', $zgloszenie), [], [$W, $O, $O, $O, $O]);
        // Autoryzacją jest podpisany link z listu — bez podpisu nie wchodzi
        // nikt, także zgłaszający.
        $dodaj('appeals.reporter', 'odwołanie zgłaszającego bez podpisu', 'get',
            route('appeals.reporter', $zgloszenie), [], [$O, $O, $O, $O, $O]);
        // Formularz zgłoszenia jest ORACLE ISTNIENIA, jeśli otworzy się na
        // treści, której zgłaszający nie ma prawa widzieć — stąd 404
        // nieodróżnialne od „nie ma takiej treści" (`ReportContent::authorize`).
        $dodaj('reports.create', 'zgłoszenie prywatnego wpisu — formularz', 'get',
            route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]), [], [$W, $O, $O, $O, $O]);
        $dodaj('reports.store', 'zgłoszenie prywatnego wpisu — zapis', 'post',
            route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'],
            [$W, $O, $O, $O, $O]);

        // ─── KONTO ───────────────────────────────────────────────────────
        $dodaj('verification.verify', 'potwierdzenie cudzego adresu e-mail', 'get',
            URL::signedRoute('verification.verify', [
                'id' => $wlasciciel->getKey(),
                'hash' => sha1((string) $wlasciciel->email),
            ]), [], [$W, $O, $O, $O, $O]);
        $dodaj('notifications.open', 'otwarcie cudzego powiadomienia', 'post',
            route('notifications.open', $powiadomienie), [], [$W, $O, $O, $O, $O]);
        $dodaj('settings.data.download', 'pobranie paczki RODO', 'get',
            URL::temporarySignedRoute('settings.data.download', now()->addHour(), ['export' => $this->paczka->getKey()]),
            [], [$W, $O, $O, $O, $O]);
        // Podpisany link jest tu JEDYNĄ autoryzacją i tak ma być: wypisanie
        // się z podsumowania musi działać bez logowania (`OdnosnikWypisania`).
        // Sam identyfikator konta w adresie nie wystarcza — patrz wiersze
        // „bez podpisu" niżej.
        $dodaj('podsumowanie.wypisz', 'wypisanie z podsumowania (podpisany link)', 'get',
            URL::signedRoute('podsumowanie.wypisz', ['user' => $wlasciciel->getKey()]), [], [$W, $W, $W, $W, $W]);
        $dodaj('podsumowanie.wracam', 'powrót do podsumowania (podpisany link)', 'get',
            URL::signedRoute('podsumowanie.wracam', ['user' => $wlasciciel->getKey()]), [], [$W, $W, $W, $W, $W]);
        $dodaj('settings.email.confirm', 'potwierdzenie zmiany adresu', 'get',
            URL::signedRoute('settings.email.confirm', ['zmiana' => $this->zmianaAdresu->getKey()]), [],
            [$W, $C, $C, $C, $O]);

        // ─── WPISY ───────────────────────────────────────────────────────
        $dodaj('posts.show', 'wpis prywatny', 'get',
            route('posts.show', $wpis), [], [$W, $O, $O, $O, $O]);
        $dodaj('questions.show', 'pytanie prywatne', 'get',
            route('questions.show', $pytanie), [], [$W, $O, $O, $O, $O]);
        $dodaj('posts.edit', 'edycja wpisu', 'get',
            route('posts.edit', $wpis), [], [$W, $O, $O, $O, $O]);
        $dodaj('posts.update', 'zapis wpisu', 'put',
            route('posts.update', $wpis), ['body' => 'Nowa treść wpisu.'], [$W, $O, $O, $O, $O]);
        $dodaj('posts.comment', 'komentarz pod prywatnym wpisem', 'post',
            route('posts.comment', $wpis), ['body' => 'Komentarz do wpisu.'], [$W, $O, $O, $O, $O]);
        // „Dopisz przepis” (#1334): formularz pokazuje zdjęcie PRYWATNEGO
        // wpisu — tylko autorowi, nigdy moderatorowi ani obcemu.
        $dodaj('recipes.create.from-post', 'formularz przepisu ze zdjęciem prywatnego wpisu', 'get',
            route('recipes.create.from-post', $wpis), [], [$W, $O, $O, $O, $O]);
        $dodaj('posts.media.edit', 'układ zdjęć wpisu', 'get',
            route('posts.media.edit', $wpis), [], [$W, $O, $O, $O, $O]);
        $dodaj('posts.media.update', 'zapis układu zdjęć', 'post',
            route('posts.media.update', $wpis), ['kolejnosc' => []], [$W, $O, $O, $O, $O]);
        $dodaj('collections.save-post', 'zapisanie prywatnego wpisu do zeszytu', 'post',
            route('collections.save-post', $wpis), [], [$W, $O, $O, $O, $O]);
        // ŚWIADOMY WYJĄTEK: wyjęcie z WŁASNEGO zeszytu chodzi po kolekcjach
        // osoby zalogowanej, więc cudzy identyfikator nie ma czego usunąć.
        // Treść, której już nie wolno oglądać, tym bardziej musi dać się
        // wyjąć z zeszytu.
        $dodaj('collections.unsave-post', 'wyjęcie wpisu z własnego zeszytu', 'delete',
            route('collections.unsave-post', $wpis), [], [$W, $W, $W, $W, $O]);
        $dodaj('wspomnienia.ukryj', 'ukrycie wspomnienia', 'post',
            route('wspomnienia.ukryj', $wpisDoWspomnien), [], [$W, $O, $O, $O, $O]);
        // Moderator NIE kasuje tędy cudzej treści (issue #932) — tylko
        // decyzją „Usuń" w panelu, z rejestrem i odwołaniem. To samo przy
        // `recipes.destroy`, `cooked.destroy` i `comments.destroy` niżej.
        $dodaj('posts.destroy', 'usunięcie wpisu', 'delete',
            route('posts.destroy', $wpisDoKasacji), [], [$W, $O, $O, $O, $O]);

        // ─── PRZEPISY ────────────────────────────────────────────────────
        $dodaj('recipes.show', 'przepis prywatny', 'get',
            route('recipes.show', $przepisPrywatny), [], [$W, $O, $O, $O, $O]);
        $dodaj('recipes.edit', 'edycja przepisu', 'get',
            route('recipes.edit', $przepis), [], [$W, $O, $O, $O, $O]);
        $dodaj('recipes.details', 'szczegóły przepisu', 'get',
            route('recipes.details', $przepis), [], [$W, $O, $O, $O, $O]);
        $dodaj('recipes.update', 'zapis przepisu', 'put',
            route('recipes.update', $przepis), ['title' => 'Nowy tytuł przepisu'], [$W, $O, $O, $O, $O]);
        $dodaj('recipes.comment', 'komentarz pod prywatnym przepisem', 'post',
            route('recipes.comment', $przepisPrywatny), ['body' => 'Komentarz do przepisu.'], [$W, $O, $O, $O, $O]);
        $dodaj('cooking.show', 'tryb gotowania z prywatnego przepisu', 'get',
            route('cooking.show', $przepisPrywatny), [], [$W, $O, $O, $O, $O]);
        $dodaj('cooking.zaznacz', 'odhaczenie kroku w prywatnym przepisie', 'post',
            route('cooking.zaznacz', $przepisPrywatny), ['krok' => 1, 'stan' => '1'], [$W, $O, $O, $O, $O]);
        $dodaj('cooking.restart', 'reset odhaczeń prywatnego przepisu', 'post',
            route('cooking.restart', $przepisPrywatny), [], [$W, $O, $O, $O, $O]);
        $dodaj('cooked.create', 'formularz „Ugotowałem" przy prywatnym przepisie', 'get',
            route('cooked.create', $przepisPrywatny), [], [$W, $O, $O, $O, $O]);
        $dodaj('cooked.store', 'zapis „Ugotowałem" przy prywatnym przepisie', 'post',
            route('cooked.store', $przepisPrywatny), ['note' => 'Wyszło świetnie.'], [$W, $O, $O, $O, $O]);
        $dodaj('collections.save', 'zapisanie prywatnego przepisu do zeszytu', 'post',
            route('collections.save', $przepisPrywatny), [], [$W, $O, $O, $O, $O]);
        // ŚWIADOMY WYJĄTEK — jak przy `collections.unsave-post` wyżej.
        $dodaj('collections.unsave', 'wyjęcie przepisu z własnego zeszytu', 'delete',
            route('collections.unsave', $przepisPrywatny), [], [$W, $W, $W, $W, $O]);
        $dodaj('recipes.destroy', 'usunięcie przepisu', 'delete',
            route('recipes.destroy', $przepisDoKasacji), [], [$W, $O, $O, $O, $O]);

        // ─── WYKONANIA („Ugotowałem") ────────────────────────────────────
        $dodaj('cooked.show', 'wykonanie publicznego przepisu', 'get',
            route('cooked.show', $wykonanie), [], [$W, $W, $O, $W, $W]);
        $dodaj('cooked.comment', 'komentarz pod wykonaniem', 'post',
            route('cooked.comment', $wykonanie), ['body' => 'Gratulacje dla kucharza.'], [$W, $W, $O, $W, $O]);
        // Węższe niż `view` świadomie: ekran „Komuś wyszło" należy do autora
        // przepisu, nie do każdego widza (`CookedEventPolicy::celebrate`).
        $dodaj('cooked.celebrate', 'ekran „Komuś wyszło"', 'get',
            route('cooked.celebrate', $wykonanie), [], [$W, $O, $O, $O, $O]);
        $dodaj('cooked.thank', 'podziękowanie za wykonanie', 'post',
            route('cooked.thank', $wykonanie), ['body' => 'Dziękuję za ugotowanie.'], [$W, $O, $O, $O, $O]);
        $dodaj('cooked.destroy', 'usunięcie wykonania', 'delete',
            route('cooked.destroy', $wykonanieDoKasacji), [], [$W, $O, $O, $O, $O]);

        // ─── KOMENTARZE ──────────────────────────────────────────────────
        $dodaj('comments.update', 'poprawienie komentarza', 'put',
            route('comments.update', $komentarz), ['body' => 'Poprawiona treść komentarza.'], [$W, $O, $O, $O, $O]);
        $dodaj('comments.destroy', 'usunięcie komentarza', 'delete',
            route('comments.destroy', $komentarzDoKasacji), [], [$W, $O, $O, $O, $O]);

        // ─── ZESZYTY ─────────────────────────────────────────────────────
        $dodaj('collections.show', 'prywatny zeszyt', 'get',
            route('collections.show', $zeszyt), [], [$W, $O, $O, $O, $O]);
        $dodaj('collections.destroy', 'usunięcie zeszytu', 'delete',
            route('collections.destroy', $zeszytDoKasacji), [], [$W, $O, $O, $O, $O]);
        // ZMIANA STATUSU WŁAŚCICIELA MA ZAWĘŻAĆ, NIGDY NIE ROZSZERZAĆ (#1092).
        //
        // Dwa wiersze na tej samej trasie, różniące się WYŁĄCZNIE flagą
        // widoczności — i to jest cały pomiar. Przed poprawką
        // `CollectionPolicy::view()` pytała o status właściciela PRZED
        // flagą, więc oba wiersze wychodziły tak samo: moderator wchodził
        // też na PRYWATNY. Czyli zbanowanie właściciela otwierało
        // moderatorowi zeszyt, którego przy koncie aktywnym nie widział
        // (wiersz `collections.show` wyżej: moderator ma tam ODMOWĘ).
        //
        // Wiersz PUBLICZNY jest tu kontrolą dodatnią: gdyby poprawka
        // zamknęła tę trasę wszystkim, moderator przestałby widzieć treść,
        // za którą to konto zbanował — i prywatny wiersz byłby zielony
        // z zupełnie niewłaściwego powodu.
        $dodaj('collections.show', 'prywatny zeszyt osoby zbanowanej', 'get',
            route('collections.show', $zeszytZbanowanegoPrywatny), [], [$O, $O, $O, $O, $O]);
        $dodaj('collections.show', 'publiczny zeszyt osoby zbanowanej', 'get',
            route('collections.show', $zeszytZbanowanegoPubliczny), [], [$O, $O, $O, $W, $O]);
        // Edycja zeszytu (#777) — nazwa, opis i widoczność. O własnym
        // zeszycie decyduje wyłącznie jego właściciel, także moderator nie
        // przestawia cudzej widoczności (`CollectionPolicy::update()`).
        $dodaj('collections.edit', 'formularz edycji zeszytu', 'get',
            route('collections.edit', $zeszyt), [], [$W, $O, $O, $O, $O]);
        $dodaj('collections.update', 'zapis edycji zeszytu', 'patch',
            route('collections.update', $zeszyt),
            ['name' => 'Zeszyt po zmianie', 'description' => 'Opis po zmianie.', 'visibility' => 'private'],
            [$W, $O, $O, $O, $O]);

        // ─── TAGI ────────────────────────────────────────────────────────
        // Tag jest wspólną nawigacją serwisu, nie czyjąś własnością
        // (`docs/FEATURES.md`); obserwowanie zapisuje się w relacji osoby
        // zalogowanej.
        $dodaj('tags.show', 'strona tagu', 'get',
            route('tags.show', $tag), [], [$W, $W, $W, $W, $W]);
        $dodaj('tags.follow', 'obserwowanie tagu', 'post',
            route('tags.follow', $tag), [], [$W, $W, $W, $W, $O]);
        $dodaj('tags.unfollow', 'przestaję obserwować tag', 'delete',
            route('tags.unfollow', $tag), [], [$W, $W, $W, $W, $O]);

        // ─── ZDJĘCIA ─────────────────────────────────────────────────────
        // Najdroższy przeciek w serwisie: bajty zdjęcia z PRYWATNEGO wpisu.
        // Bramką nie jest `authorize()`, tylko `DostepDoZdjecia` pytające
        // `Gate` o Policy RODZICA — dlatego ta trasa musi być zmierzona
        // żądaniem, a nie odhaczona w skanie po nazwie funkcji.
        $dodaj('media.show', 'zdjęcie z prywatnego wpisu', 'get',
            route('media.show', ['media' => $zdjecie, 'wariant' => 'feed']), [], [$W, $O, $O, $W, $O]);

        // ─── TRASY, NA KTÓRYCH SAM IDENTYFIKATOR NIE WYSTARCZA ───────────
        // Te same trzy trasy co wyżej, tylko BEZ podpisu. Bez nich wiersze
        // „z podpisem" nie dowodzą niczego: przechodziłyby także wtedy,
        // gdyby middleware `signed` zniknął z trasy.
        $this->przypadki[] = [
            'trasa' => 'podsumowanie.wypisz',
            'opis' => 'wypisanie z podsumowania BEZ podpisu',
            'metoda' => 'get',
            'url' => route('podsumowanie.wypisz', $wlasciciel),
            'dane' => [],
            'oczekiwania' => array_combine(
                ['wlasciciel', 'obcy', 'zablokowany', 'moderator', 'gosc'],
                [$O, $O, $O, $O, $O],
            ),
        ];
        $this->przypadki[] = [
            'trasa' => 'podsumowanie.wracam',
            'opis' => 'powrót do podsumowania BEZ podpisu',
            'metoda' => 'get',
            'url' => route('podsumowanie.wracam', $wlasciciel),
            'dane' => [],
            'oczekiwania' => array_combine(
                ['wlasciciel', 'obcy', 'zablokowany', 'moderator', 'gosc'],
                [$O, $O, $O, $O, $O],
            ),
        ];
        $this->przypadki[] = [
            'trasa' => 'settings.data.download',
            'opis' => 'pobranie paczki RODO BEZ podpisu',
            'metoda' => 'get',
            'url' => route('settings.data.download', $this->paczka),
            'dane' => [],
            'oczekiwania' => array_combine(
                ['wlasciciel', 'obcy', 'zablokowany', 'moderator', 'gosc'],
                [$O, $O, $O, $O, $O],
            ),
        ];
    }

    private function zgloszenie(User $zglaszajacy, Post $cel): Report
    {
        return Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => (string) $cel->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function decyzja(User $moderator, User $ukarany, Post $cel): ModerationAction
    {
        return ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) $cel->getKey(),
            'subject_user_id' => $ukarany->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);
    }
}
