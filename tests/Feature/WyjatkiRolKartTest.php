<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TRZY WYJĄTKI Z INWENTARZA RÓL KART — ŻEBY NIE WRÓCIŁY.
 *
 * Rozdzielenie klasy `.card` na sześć warstw (D-125 … D-128) zostawiło trzy
 * miejsca opisane w `docs/design/ROLE_KART.md` jako nierozstrzygnięte. Ten
 * plik pilnuje stanu, w jakim każde z nich zostało domknięte:
 *
 *  1. `/ugotowane/{id}/wyszlo` — cudze wykonanie „Ugotowałem" stoi na SEKCJI,
 *     a to samo wykonanie na własnym ekranie `/ugotowane/{id}` jest KARTĄ
 *     treści. To nie jest niekonsekwencja, tylko D-128: rolę powierzchni
 *     nadaje MIEJSCE, a nie obiekt. Ekran celebracji pokazuje się raz w życiu
 *     wykonania (drugie wejście przekierowuje na `cooked.show`) i rzeczą do
 *     zrobienia jest na nim podziękowanie — ono ma panel.
 *
 *  2. Puste stany w panelu moderacji idą przez `<x-empty-state>`, nie przez
 *     akapit z klasą warstwy (rozstrzygnięcie właściciela, issue #367).
 *
 *  3. Zwinięty `<details class="panel-formularza">` na `/zeszyt` nie niesie
 *     mocnej obwódki, bo w tym stanie nie ma czego wypełnić (D-126).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO JEDEN TEST CZYTA ARKUSZ, A DWA POZOSTAŁE HTML
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo tam leży to, co każdy z nich naprawdę mierzy.
 *
 * Wyjątki 1 i 2 to decyzje zapisane W WIDOKU: która klasa warstwy stoi przy
 * którym bloku i czy pusty stan jest komponentem. To widać wyłącznie
 * w wyrenderowanym HTML-u — grep po plikach `.blade.php` nie odróżnia gałęzi
 * `@if`, która się renderuje, od tej, która w danym stanie milczy, ani
 * znacznika od komentarza (`ROLE_KART.md:36-70`).
 *
 * Wyjątek 3 leży W ARKUSZU i nie da się go sprawdzić w HTML-u: w markupie
 * klasa jest jedna (`panel-formularza`) w obu stanach, a różnicę robi
 * selektor `:not([open])`. Blade nie ma tu czego wybrać — atrybut `open`
 * przestawia człowiek kliknięciem, już po wyjściu odpowiedzi z serwera.
 * Test czyta ŹRÓDŁOWY `resources/css/tokens.css`, a nie zbudowany arkusz,
 * bo `/public/build` jest w `.gitignore` — w repozytorium go nie ma, więc
 * asercja na nim byłaby asercją na stanie cudzego katalogu roboczego.
 *
 * CZEGO TEN PLIK NIE DOWODZI: że reguła przetrwała build i że przeglądarka
 * rzeczywiście przełącza warstwę. Tego nie sprawdzi test PHP-owy. Zmierzone
 * osobno w Chromium (`npm run build` + `php artisan serve`), przy 390 i przy
 * 1512 px: zwinięty blok ma obwódkę `rgb(228, 218, 203)`, cień `none`
 * i wcięcie 20 px, rozwinięty — `rgb(138, 122, 99)`, cień karty i 24 px.
 * Przed tą regułą oba stany były identyczne.
 */
class WyjatkiRolKartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WYJĄTEK 1 — TO SAMO WYKONANIE, DWA MIEJSCA, DWIE WARSTWY (D-128).
     *
     * Asercja „na ekranie celebracji nie ma karty treści" jest asercją
     * NEGATYWNĄ i sama z siebie przeszłaby także wtedy, gdyby ekran nie
     * wyrenderował niczego (pułapka 4 z `docs/PULAPKI_TESTOW.md`). Dlatego
     * w tym samym teście stoi kontrola dodatnia, i to mocna: TO SAMO
     * wykonanie pobrane spod `/ugotowane/{id}` MA kartę treści. Para
     * „tu karty nie ma, a tam jest" dowodzi, że mechanizm klasy `card`
     * działa, a jego brak na ekranie celebracji jest decyzją, nie awarią.
     */
    #[Test]
    public function test_wykonanie_na_ekranie_celebracji_jest_sekcja_a_na_wlasnym_ekranie_karta(): void
    {
        $autorPrzepisu = $this->user('autorka');
        $kucharz = $this->user('kucharz', ['display_name' => 'Halina']);
        $przepis = Recipe::factory()->create([
            'author_id' => $autorPrzepisu->getKey(),
            'title' => 'Rosół',
        ]);

        $wykonanie = app(RecordCookedEvent::class)
            ->handle($kucharz, $przepis, note: 'Wyszło pięknie, dziękuję!');

        $celebracja = $this->actingAs($autorPrzepisu)
            ->get(route('cooked.celebrate', $wykonanie))
            ->assertOk()
            ->getContent();

        // Kontrola dodatnia nr 1: ekran naprawdę się wyrenderował, i to
        // w gałęzi z notatką. Bez tego wszystkie liczby niżej mierzyłyby
        // pustą stronę.
        $this->assertStringContainsString(
            'Wyszło pięknie, dziękuję!',
            $celebracja,
            'Ekran „Komuś wyszło" nie pokazał notatki kucharza — dalsze liczby '.
            'nie opisywałyby tego ekranu, tylko jego brak.',
        );

        $this->assertSame(
            1,
            $this->ilePowierzchni($celebracja, 'sekcja-strony'),
            'Wiadomość „komuś wyszło" zeszła z warstwy sekcji. Ekran celebracji '.
            'pokazuje się raz w życiu wykonania i jest POTWIERDZENIEM — stałym '.
            'domem tego wykonania jest `/ugotowane/{id}` (D-128).',
        );

        $this->assertSame(
            1,
            $this->ilePowierzchni($celebracja, 'panel-formularza'),
            'Podziękowanie straciło własny panel. To jedyna rzecz, którą można '.
            'na tym ekranie zrobić, więc to ona ma mocną obwódkę.',
        );

        $this->assertSame(
            0,
            $this->ilePowierzchni($celebracja, 'card'),
            'Na ekranie celebracji pojawiła się karta treści. Jeśli ktoś podniósł '.
            'tam wykonanie „bo warstwa 1 wymienia wykonanie wprost" — to jest '.
            'argument z OBIEKTU, a D-128 rozstrzyga rolę MIEJSCEM.',
        );

        // Kontrola dodatnia nr 2: to samo wykonanie na swoim własnym ekranie.
        $wlasnyEkran = $this->actingAs($autorPrzepisu)
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Wyszło pięknie, dziękuję!',
            $wlasnyEkran,
            'Ekran `/ugotowane/{id}` nie pokazał notatki — kontrola dodatnia '.
            'mierzy pustą stronę, więc niczego nie kontroluje.',
        );

        $this->assertGreaterThanOrEqual(
            1,
            $this->ilePowierzchni($wlasnyEkran, 'card'),
            'To samo wykonanie przestało być kartą treści na swoim własnym '.
            'ekranie. Wtedy zero kart na ekranie celebracji nie dowodzi '.
            'niczego — klasa `card` po prostu zniknęła z serwisu.',
        );
    }

    /**
     * WYJĄTEK 2 — PUSTY STAN PANELU JEST KOMPONENTEM, NIE AKAPITEM.
     *
     * Dwa sąsiednie ekrany panelu robiły to samo dwoma mechanizmami:
     * `<p class="sekcja-strony">` tutaj, `<x-empty-state>` w „Tagach
     * promowanych". Właściciel rozstrzygnął: ujednolicić (issue #367).
     *
     * Kontrola dodatnia: obok asercji „nie ma akapitu z warstwą" stoi
     * asercja, że pusty stan NAPRAWDĘ się pokazał — z własnym tytułem.
     * Bez niej test przechodziłby też wtedy, gdyby ekran oddał 403 albo
     * gdyby gałąź pustego stanu w ogóle przestała się renderować.
     */
    #[Test]
    public function test_puste_stany_panelu_ida_przez_komponent_a_nie_przez_akapit_z_warstwa(): void
    {
        $moderator = $this->moderator();

        // Lista kont nigdy nie jest pusta (jest na niej sam moderator), więc
        // pusty stan wywołuje szukanie ciągu, którego nie ma.
        $konta = $this->actingAs($moderator)
            ->get(route('admin.users', ['szukaj' => 'nie-ma-takiego-konta-zzz']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'empty-state',
            $konta,
            'Pusty wynik szukania w „Użytkownikach" nie idzie przez '.
            '`<x-empty-state>`. Dwa kształty pustego stanu w jednym panelu to '.
            'dwie rzeczy do nauczenia się zamiast jednej (issue #367).',
        );

        $this->assertStringContainsString(
            'Nie znaleźliśmy takiego konta',
            $konta,
            'Pusty stan „Użytkowników" nie pokazał swojego tytułu — dalsza '.
            'asercja mierzyłaby ekran, którego nie ma.',
        );

        $this->assertSame(
            0,
            $this->ileAkapitowZWarstwa($konta),
            'W „Użytkownikach" wrócił akapit z klasą warstwy powierzchni. '.
            'Pusty stan ma być komponentem.',
        );

        $wiadomosci = $this->actingAs($moderator)
            ->get(route('admin.contact'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'empty-state',
            $wiadomosci,
            'Pusta kolejka „Wiadomości" nie idzie przez `<x-empty-state>`.',
        );

        $this->assertStringContainsString(
            'Nic nowego',
            $wiadomosci,
            'Pusty stan „Wiadomości" nie pokazał swojego tytułu.',
        );

        $this->assertSame(
            0,
            $this->ileAkapitowZWarstwa($wiadomosci),
            'W „Wiadomościach" wrócił akapit z klasą warstwy powierzchni.',
        );
    }

    /**
     * WYJĄTEK 3 — ZWINIĘTY PANEL NIE OBIECUJE PÓL (D-126).
     *
     * Mocna obwódka to ta sama obwódka, którą mają pola formularza — panel
     * mówi nią „tu się coś wpisuje". Zwinięty `<details>` na `/zeszyt` nie
     * pokazuje ani jednego pola, więc przez większość czasu życia tego ekranu
     * obietnica otaczała sam przycisk.
     *
     * Test sprawdza REGUŁĘ W ARKUSZU, bo w markupie klasa jest w obu stanach
     * ta sama — powód wyłożony w docbloku klasy.
     */
    #[Test]
    public function test_zwiniety_details_schodzi_z_panelu_na_warstwe_sekcji(): void
    {
        $arkusz = (string) file_get_contents(base_path('resources/css/tokens.css'));

        // Pułapka 2 z `docs/PULAPKI_TESTOW.md`: test czytający plik przechodzi
        // także wtedy, gdy pliku nie ma albo ścieżka się zmieniła. Kotwica
        // sprawdza, że czytamy TEN arkusz, a nie pustkę.
        $this->assertStringContainsString(
            '.panel-formularza {',
            $arkusz,
            'W `resources/css/tokens.css` nie ma klasy `.panel-formularza` — '.
            'plik się przeniósł albo warstwy zostały przepisane gdzie indziej.',
        );

        preg_match(
            '/details\.panel-formularza:not\(\[open\]\) \{(.*?)\n  \}/s',
            $arkusz,
            $regula,
        );

        $this->assertNotEmpty(
            $regula,
            'Zniknęła reguła `details.panel-formularza:not([open])`. Zwinięty '.
            '„Załóż nowy zeszyt" znów otacza sam przycisk mocną obwódką '.
            'kontrolki, choć nie ma w nim ani jednego pola (D-126).',
        );

        foreach (['border-color', 'box-shadow', 'padding'] as $wlasciwosc) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($wlasciwosc, '/').':\s*var\(--warstwa-sekcja-/',
                $regula[1],
                "Zwinięty `<details>` ustawia `{$wlasciwosc}` inaczej niż tokenem ".
                '`--warstwa-sekcja-*`. Wtedy powstaje siódma, połowiczna '.
                'sygnatura zamiast istniejącej warstwy 3.',
            );
        }

        // Kontrola dodatnia: rozwinięty panel MA zostać panelem. Gdyby ktoś
        // zdjął mocną obwódkę z samej klasy `.panel-formularza`, powyższe
        // asercje dalej byłyby zielone, a obietnicy nie byłoby już nigdzie.
        $this->assertMatchesRegularExpression(
            '/\n  \.panel-formularza \{[^}]*border:\s*1px solid var\(--warstwa-panel-obwodka\)/s',
            $arkusz,
            'Klasa `.panel-formularza` przestała brać obwódkę z tokenu panelu. '.
            'Zejście zwiniętego stanu na sekcję ma sens tylko wtedy, gdy stan '.
            'rozwinięty dalej jest panelem.',
        );
    }

    /**
     * WYJĄTEK 3, STRONA WIDOKU — OBIE GAŁĘZIE STANU NAPRAWDĘ POWSTAJĄ.
     *
     * Reguła w arkuszu opisuje dwa stany `<details>`. Ten test sprawdza, że
     * oba istnieją na żywym ekranie: po zwykłym wejściu blok jest ZWINIĘTY,
     * a po nieudanej walidacji ROZWINIĘTY (żeby człowiek nie wracał na stronę,
     * na której pozornie nic się nie stało).
     *
     * To jest jednocześnie kontrola dodatnia do reguły z poprzedniego testu:
     * bez gałęzi `open` selektor `:not([open])` opisywałby jedyny możliwy stan
     * i nie rozróżniałby niczego.
     */
    /**
     * CZWARTE OGRANICZENIE Z `ROLE_KART.md` — SPŁACONE, I DOKUMENT MA TO MÓWIĆ.
     *
     * Sekcja „Czego ten dokument nadal nie rozstrzyga" przez jakiś czas
     * twierdziła, że automat dostępności „nie wchodzi na trzy ekrany
     * z ramkami": dwa ekrany panelu moderacji (403 na koncie demo) i drugi
     * krok logowania (żadne konto demo nie ma 2FA). Przestało to być prawdą,
     * a zdanie zostało — czyli mówiło ludziom, żeby nie szukali pomiaru tam,
     * gdzie on już jest. To dokładnie ta klasa nieprawdy, którą opisuje D-165:
     * deklaracja wyglądająca na rozstrzygnięcie zatrzymuje szukanie.
     *
     * Dokument twierdzi teraz coś odwrotnego — że te ekrany SĄ mierzone —
     * i ten test porównuje to twierdzenie ze `scripts/dostepnosc.mjs`.
     * Gdyby ktoś zdjął któryś ekran z listy `EKRANY`, dokument zacząłby
     * kłamać w drugą stronę, a test robi się wtedy czerwony.
     *
     * KONTROLA PUSTEGO SKANU: gdyby wyrażenie przestało cokolwiek znajdować
     * (zmiana zapisu listy w skrypcie), zbiór adresów byłby pusty i każda
     * asercja „jest na liście" oblałaby się bez sensownego komunikatu.
     * Dlatego najpierw stoi próg.
     */
    #[Test]
    public function test_dokument_nie_twierdzi_ze_ekran_jest_niemierzony_gdy_automat_go_mierzy(): void
    {
        $skrypt = file_get_contents(base_path('scripts/dostepnosc.mjs'));

        $this->assertNotFalse($skrypt, 'Nie ma `scripts/dostepnosc.mjs`.');

        preg_match_all(
            "/\{\s*nazwa:\s*'[^']*',\s*adres:\s*'([^']+)'(.*?)\}/s",
            $skrypt,
            $trafienia,
            PREG_SET_ORDER,
        );

        $mierzone = [];

        foreach ($trafienia as $wpis) {
            $mierzone[$wpis[1]] = $wpis[2];
        }

        // Próg, nie `assertNotEmpty`: skan, który znajduje dwa ekrany zamiast
        // czterdziestu, też jest zepsuty, a `assertNotEmpty` by go przepuścił.
        $this->assertGreaterThan(
            25,
            count($mierzone),
            'Z `scripts/dostepnosc.mjs` dało się odczytać tylko '.count($mierzone).' ekranów. '.
            'To jest usterka TEGO TESTU (zmienił się zapis listy `EKRANY`), nie skryptu — '.
            'popraw wyrażenie, zamiast zdejmować asercje niżej.',
        );

        /*
         * Ekrany, o których `ROLE_KART.md` mówi dziś, że automat na nie wchodzi,
         * razem ze sposobem wejścia. Sposób jest częścią twierdzenia: wejście
         * do panelu bez `moderator: true` znaczyłoby, że 2FA zostało obejście,
         * a to jest granica, której ten automat nie przekracza.
         */
        $obiecane = [
            '/admin/uzytkownicy' => 'moderator: true',
            '/admin/zgloszenia' => 'moderator: true',
            '/admin/sygnaly' => 'moderator: true',
            '/admin/kolaz-powitalny' => 'moderator: true',
            '/logowanie/kod' => 'przedKodem2FA: true',
        ];

        foreach ($obiecane as $adres => $kontekst) {
            $this->assertArrayHasKey(
                $adres,
                $mierzone,
                "`docs/design/ROLE_KART.md` mówi, że automat dostępności mierzy ekran „{$adres}”, ".
                'a nie ma go na liście `EKRANY` w `scripts/dostepnosc.mjs`. '.
                'Albo wróć z ekranem na listę, albo popraw dokument — ale nie zostawiaj zdania, '.
                'które każe nie szukać pomiaru tam, gdzie go nie ma (D-165).',
            );

            $this->assertStringContainsString(
                $kontekst,
                $mierzone[$adres],
                "Ekran „{$adres}” jest mierzony, ale nie w kontekście „{$kontekst}”. ".
                'Dokument opisuje sposób wejścia jako część twierdzenia: wejście do panelu bez '.
                '`moderator: true` znaczyłoby, że weryfikacja dwuetapowa została obejście, '.
                'a tego ten automat nie robi.',
            );
        }

        /*
         * Druga strona tej samej reguły: dokument nie może wrócić do zdania,
         * które właśnie przestało być prawdą. Sprawdzamy PO SEKCJI, nie po
         * całym pliku — cytat „spłacone" w tej sekcji jest w cudzysłowie
         * i ma prawo brzmieć dokładnie tak jak stara nieprawda.
         */
        $dokument = file_get_contents(base_path('docs/design/ROLE_KART.md'));

        $this->assertNotFalse($dokument, 'Nie ma `docs/design/ROLE_KART.md`.');

        $this->assertStringContainsString(
            'Warstwy tych ekranów są więc dziś **zmierzone w przeglądarce**',
            $dokument,
            'Z `ROLE_KART.md` zniknęło zdanie mówiące, że te ekrany są zmierzone. '.
            'Jeśli przestały być — zdejmij je też z listy `obiecane` w tym teście. '.
            'Jeśli nie — przywróć zdanie: zniknięcie ograniczenia bez śladu wygląda '.
            'dokładnie tak samo jak ograniczenie, o którym nikt nie pamiętał.',
        );
    }

    #[Test]
    public function test_zeszyt_ma_oba_stany_details_zwiniety_i_rozwiniety(): void
    {
        $osoba = $this->user('zbieraczka');

        $zwykleWejscie = $this->actingAs($osoba)
            ->get(route('collections.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Załóż nowy zeszyt',
            $zwykleWejscie,
            'Na `/zeszyt` nie ma „Załóż nowy zeszyt" — test mierzy ekran, '.
            'którego nie ma.',
        );

        $this->assertSame(
            1,
            $this->ilePowierzchni($zwykleWejscie, 'panel-formularza'),
            'Na `/zeszyt` nie ma dokładnie jednego panelu formularza.',
        );

        $this->assertStringNotContainsString(
            'panel-formularza mt-8" open',
            $zwykleWejscie,
            'Po zwykłym wejściu na `/zeszyt` formularz jest rozwinięty. Wtedy '.
            'reguła `:not([open])` nigdy się nie włącza.',
        );

        // Nieudana walidacja: pusta nazwa zeszytu. Błędy lecą do sesji, więc
        // kolejne GET na tym samym kliencie renderuje gałąź `open`.
        $this->actingAs($osoba)
            ->from(route('collections.index'))
            ->post(route('collections.store'), ['name' => '', 'visibility' => 'private'])
            ->assertRedirect(route('collections.index'));

        $poBledzie = $this->actingAs($osoba)
            ->get(route('collections.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'panel-formularza mt-8" open',
            $poBledzie,
            'Po nieudanej walidacji „Załóż nowy zeszyt" zostaje zwinięty — '.
            'człowiek wraca na stronę, na której pozornie nic się nie stało, '.
            'a jego tekst siedzi pod przyciskiem.',
        );
    }

    /**
     * Liczy wystąpienia klasy jako OSOBNEGO SŁOWA w atrybucie `class`.
     *
     * `\b` nie wystarczy: myślnik jest dla niego granicą słowa, więc `\bcard\b`
     * łapie także `post-card-head` i `recipe-card-miniatura`. Na tym polegał
     * błąd pierwszego pomiaru w `ROLE_KART.md`.
     */
    private function ilePowierzchni(string $html, string $klasa): int
    {
        preg_match_all('/class="([^"]*)"/', $html, $trafienia);

        $ile = 0;

        foreach ($trafienia[1] as $atrybut) {
            $klasy = preg_split('/\s+/', trim($atrybut)) ?: [];

            // Blok prawej szyny jest wołany jako `class="card szyna-blok"`
            // i to jest warstwa 4, nie karta treści w kolumnie głównej.
            if (in_array('szyna-blok', $klasy, true)) {
                continue;
            }

            if (in_array($klasa, $klasy, true)) {
                $ile++;
            }
        }

        return $ile;
    }

    /**
     * Liczy akapity niosące klasę warstwy powierzchni — czyli dokładnie ten
     * kształt pustego stanu, który zastąpił komponent.
     */
    private function ileAkapitowZWarstwa(string $html): int
    {
        preg_match_all('/<p[^>]*class="([^"]*)"/', $html, $trafienia);

        $ile = 0;

        foreach ($trafienia[1] as $atrybut) {
            $klasy = preg_split('/\s+/', trim($atrybut)) ?: [];

            foreach (['sekcja-strony', 'card', 'panel-formularza'] as $warstwa) {
                if (in_array($warstwa, $klasy, true)) {
                    $ile++;

                    break;
                }
            }
        }

        return $ile;
    }
}
