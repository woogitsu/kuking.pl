<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ROZDZIELENIE RÓL KLASY `.card` — TEST, KTÓRY PILNUJE, ŻEBY NIE ZLAŁY SIĘ
 * Z POWROTEM.
 *
 * Do 11 września 2026 klasa `.card` niosła 126 różnych ról naraz: była
 * jednocześnie kartą wpisu, sekcją strony, blokiem prawej szyny, panelem
 * formularza, ramką z wyjaśnieniem i kaflem, w który się klika. Na
 * `/napisz-do-nas` wyglądało to tak, że karta „Chodzi o czyjś wpis?",
 * formularz i karta „Co się stanie dalej" miały ten sam kolor, ten sam cień
 * i ten sam promień — choć tylko jedna z tych trzech rzeczy czegokolwiek
 * wymagała. Gdy wszystko na ekranie ma tę samą rangę, nic jej nie ma.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEN PLIK SPRAWDZA I ARKUSZ, I HTML
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo osobno żadna z tych rzeczy nie wystarcza. Tokeny warstw w arkuszu bez
 * klas w widokach to definicje, których nikt nie używa. Klasy w widokach bez
 * różnic w arkuszu to stan sprzed zmiany, tylko pod innymi nazwami — ktoś
 * mógłby ustawić `--warstwa-pomocnicza-cien` na `var(--shadow-card)` i trzy
 * powierzchnie na `/napisz-do-nas` znowu wyglądałyby identycznie, a każdy
 * test liczący klasy dalej świeciłby na zielono.
 *
 * Czego ten test NIE dowodzi: że hierarchia wygląda dobrze. Tego nie sprawdzi
 * żadna maszyna. Liczby o powierzchniach na ekranie zbiera
 * `scripts/warstwy-pomiar.mjs` w przeglądarce, a decyzję podejmuje człowiek.
 *
 * Pełny inwentarz ról: `docs/design/ROLE_KART.md`.
 */
class WarstwyPowierzchniTest extends TestCase
{
    use RefreshDatabase;

    private function arkuszTokenow(): string
    {
        return (string) file_get_contents(base_path('resources/css/tokens.css'));
    }

    /**
     * Wartość tokenu warstwy, tak jak stoi w arkuszu (bez rozwijania `var()`).
     */
    private function token(string $nazwa): string
    {
        preg_match('/--warstwa-'.preg_quote($nazwa, '/').':\s*([^;]+);/', $this->arkuszTokenow(), $trafienie);

        $this->assertNotEmpty(
            $trafienie,
            "W `resources/css/tokens.css` nie ma tokenu `--warstwa-{$nazwa}`. ".
            'Warstwy powierzchni mają być opisane tokenami, nie wartościami w klasach '.
            '(`docs/design/ROLE_KART.md`).',
        );

        return trim($trafienie[1]);
    }

    /**
     * RAMKA POMOCNICZA MUSI ZOSTAĆ WGŁĘBIONA I BEZ CIENIA.
     *
     * To jest jedyna warstwa, która idzie w drugą stronę niż wszystkie inne,
     * i cała różnica między „to jest do wypełnienia" a „to jest wyjaśnienie
     * obok" stoi na tych dwóch wartościach.
     */
    #[Test]
    public function test_ramka_pomocnicza_jest_wglebiona_i_nie_unosi_sie(): void
    {
        $this->assertSame(
            'var(--color-surface-sunken)',
            $this->token('pomocnicza-tlo'),
            'Ramka pomocnicza przestała być wgłębiona. Wtedy wyjaśnienie na '.
            '`/napisz-do-nas` znów wygląda tak samo jak formularz obok niego.',
        );

        $this->assertSame(
            'none',
            $this->token('pomocnicza-cien'),
            'Ramka pomocnicza dostała cień, czyli zaczęła się unosić tak samo '.
            'jak karta treści i panel formularza.',
        );
    }

    /**
     * PANEL FORMULARZA ODRÓŻNIA SIĘ OD KARTY TREŚCI MOCNĄ OBWÓDKĄ.
     *
     * Tą samą, którą mają pola formularza — panel i jego wnętrze mówią wtedy
     * jednym językiem. Zejście na `--color-border` zrównałoby panel z kartą.
     */
    #[Test]
    public function test_panel_formularza_ma_mocna_obwodke(): void
    {
        $this->assertSame(
            'var(--color-border-strong)',
            $this->token('panel-obwodka'),
            'Panel formularza stracił mocną obwódkę i wygląda teraz jak karta treści.',
        );

        $this->assertNotSame(
            $this->token('tresc-obwodka'),
            $this->token('panel-obwodka'),
            'Panel formularza i karta treści mają tę samą obwódkę — dwie role, jeden wygląd.',
        );
    }

    /**
     * SEKCJA I SZYNA STOJĄ PŁASKO, KARTA TREŚCI SIĘ UNOSI.
     *
     * To jest rozstrzygnięcie sprzed tej zmiany, przeniesione do tokenów:
     * szyna nie powtarza kolumny głównej. Karta wpisu unosi się, bo jest
     * treścią, po którą ktoś przyszedł.
     */
    #[Test]
    public function test_karta_tresci_unosi_sie_a_sekcja_i_szyna_nie(): void
    {
        $this->assertNotSame('none', $this->token('tresc-cien'), 'Karta treści straciła cień.');
        $this->assertSame('none', $this->token('sekcja-cien'), 'Sekcja strony dostała cień.');
        $this->assertSame('none', $this->token('szyna-cien'), 'Blok szyny dostał cień.');
    }

    /**
     * PROMIEŃ SZYNY RÓWNY PROMIENIOWI KARTY WPISU.
     *
     * Rozstrzygnięte wcześniej i nie do cofnięcia: dwa różne promienie
     * w jednym rzędzie czyta się jako niedokończone, a nie jako hierarchię.
     * Zewnętrzny audyt zalecał kiedyś zmniejszenie promienia szyny — to jest
     * cofnięcie świadomej poprawki i ten test ma je zatrzymać.
     */
    #[Test]
    public function test_szyna_ma_ten_sam_promien_co_karta_wpisu(): void
    {
        $this->assertSame(
            $this->token('tresc-promien'),
            $this->token('szyna-promien'),
            'Blok szyny ma inny promień niż karta wpisu. Oba stoją w jednym '.
            'rzędzie na szerokim ekranie i różnica czyta się jako usterka.',
        );
    }

    /**
     * WSZYSTKIE SZEŚĆ KLAS BIERZE WARTOŚCI WYŁĄCZNIE Z TOKENÓW.
     *
     * Bez tego tryb ciemny i ciemny pas na stronie powitalnej (`.blok-ciemny`)
     * wymagałyby sześciu osobnych nadpisań każdej klasy — i pierwsze z nich
     * zostałoby pominięte.
     */
    #[Test]
    public function test_klasy_warstw_nie_maja_wartosci_na_sztywno(): void
    {
        $arkusz = $this->arkuszTokenow();

        foreach (['panel-formularza' => 'panel', 'sekcja-strony' => 'sekcja', 'ramka-pomocnicza' => 'pomocnicza', 'kafel-akcji' => 'kafel'] as $klasa => $warstwa) {
            preg_match('/\n  \.'.preg_quote($klasa, '/').' \{(.*?)\n  \}/s', $arkusz, $trafienie);

            $this->assertNotEmpty($trafienie, "W `tokens.css` nie ma klasy `.{$klasa}`.");

            foreach (['background-color', 'border-radius', 'box-shadow', 'padding'] as $wlasciwosc) {
                $this->assertMatchesRegularExpression(
                    '/'.preg_quote($wlasciwosc, '/').':\s*var\(--warstwa-'.preg_quote($warstwa, '/').'-/',
                    $trafienie[1],
                    "`.{$klasa}` ustawia `{$wlasciwosc}` inaczej niż tokenem `--warstwa-{$warstwa}-*`.",
                );
            }
        }
    }

    /**
     * EKRAN, OD KTÓREGO SIĘ ZACZĘŁO.
     *
     * `/napisz-do-nas` miał trzy powierzchnie o identycznym wyglądzie:
     * wyjaśnienie, formularz i „Co się stanie dalej". Po zmianie ma mieć
     * DOKŁADNIE JEDEN panel formularza i co najmniej dwie ramki pomocnicze,
     * i ani jednej karty treści — bo nie ma tu żadnej cudzej treści.
     *
     * Mierzymy ekran GOŚCIA, bo dla gościa ten formularz ma o jedno pole
     * więcej i to jest inny ekran niż dla zalogowanego (D-099).
     */
    #[Test]
    public function test_napisz_do_nas_ma_jeden_panel_i_zadnej_karty_tresci(): void
    {
        $html = $this->get('/napisz-do-nas')->assertOk()->getContent();

        $this->assertSame(
            1,
            $this->ilePowierzchni($html, 'panel-formularza'),
            'Na `/napisz-do-nas` nie ma dokładnie jednego panelu formularza. '.
            'Ten ekran ma jedną rzecz do wypełnienia i ma to być widać.',
        );

        $this->assertGreaterThanOrEqual(
            2,
            $this->ilePowierzchni($html, 'ramka-pomocnicza'),
            'Zniknęły ramki pomocnicze („Chodzi o czyjś wpis?", „Co się stanie dalej"). '.
            'Jeśli wróciły na warstwę karty, ekran jest znów płaski.',
        );

        // `bezSzyny`: prawa szyna tego ekranu jest wołana jako
        // `class="card szyna-blok"` i to jest warstwa 4, nie karta treści.
        // Gdyby test tego nie odjął, mierzyłby obecność szyny zamiast
        // hierarchii w kolumnie głównej.
        $this->assertSame(
            0,
            $this->ilePowierzchni($html, 'card', bezSzyny: true),
            'Na `/napisz-do-nas` pojawiła się karta treści w kolumnie głównej, '.
            'a nie ma tu żadnej cudzej treści.',
        );
    }

    /**
     * LOGOWANIE — JEDEN PANEL, A DROGI RÓWNOLEGŁE NIE SĄ ZDEGRADOWANE.
     *
     * D-056 rozstrzyga, że logowanie linkiem jest drogą RÓWNORZĘDNĄ z hasłem,
     * dla części naszych ludzi podstawową. Ramka pomocnicza jest wgłębiona
     * i mówi „to jest obok głównej rzeczy" — postawienie tam tego bloku byłoby
     * cofnięciem tamtej decyzji w warstwie wizualnej. Stąd `sekcja-strony`.
     */
    #[Test]
    public function test_logowanie_ma_jeden_panel_a_droga_linkiem_nie_jest_wglebiona(): void
    {
        config()->set('kuking.login_link.wlaczone', true);

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertSame(
            1,
            $this->ilePowierzchni($html, 'panel-formularza'),
            'Ekran logowania nie ma dokładnie jednego panelu formularza.',
        );

        $this->assertGreaterThanOrEqual(
            1,
            $this->ilePowierzchni($html, 'sekcja-strony'),
            'Blok „Nie pamiętasz hasła? Nie musisz go wpisywać" zszedł z warstwy sekcji. '.
            'Jeśli trafił do ramki pomocniczej, droga równorzędna z D-056 została '.
            'wizualnie zdegradowana do przypisu.',
        );

        $this->assertSame(
            0,
            $this->ilePowierzchni($html, 'ramka-pomocnicza'),
            'Na ekranie logowania pojawiła się wgłębiona ramka pomocnicza — '.
            'sprawdź, czy to nie któraś z dróg wejścia (D-056).',
        );
    }

    /**
     * PANEL FORMULARZA NIE OBIECUJE PÓL, KTÓRYCH NIE MA.
     *
     * Mocna obwódka panelu to ta sama obwódka, którą mają pola — więc panel
     * mówi „tu się coś wpisuje". Odzyskany formularz po odbiciu limitu (429)
     * albo po wygaśnięciu sesji (419) bywa zbudowany z samych pól UKRYTYCH:
     * krótkie wartości (poniżej 60 znaków, bez znaku nowej linii) wracają
     * jako `<input type="hidden">`, bo człowiek nie ma czego w nich
     * poprawiać. Wtedy blok jest sekcją, nie panelem.
     *
     * To jest ta sama zasada co zakaz martwego przycisku (D-053), tylko
     * w warstwie powierzchni — i tym gorsza, że ekran mówi w tym samym
     * czasie „Twój tekst jest na miejscu".
     *
     * Mierzymy przez PRAWDZIWE odbicie limitu, a nie przez konstruktor:
     * `OdzyskanyFormularz::zZadania()` zgadza trasę po nazwie i poza trasami
     * treści nie odkłada niczego, więc żądanie zbudowane w próżni dałoby
     * pustkę i test przechodziłby, nie sprawdzając niczego.
     */
    #[Test]
    public function test_odzyskany_formularz_bez_widocznych_pol_nie_jest_panelem(): void
    {
        // 16 znaków, bez nowej linii — czyli poniżej progu „długiego" pola.
        $krotki = $this->odbityKomentarz('Wygląda pysznie!');

        $this->assertSame(
            0,
            $this->ilePowierzchni($krotki, 'panel-formularza'),
            'Krótki komentarz wraca jako pole UKRYTE, więc na ekranie 429 nie ma czego '.
            'wypełnić — a blok ma mocną obwódkę panelu, czyli obiecuje formularz.',
        );

        $this->assertSame(
            1,
            $this->ilePowierzchni($krotki, 'sekcja-strony'),
            'Blok z samym przyciskiem „Wyślij jeszcze raz" ma być sekcją.',
        );

        $dlugi = $this->odbityKomentarz(
            'Robiłam to wczoraj z połową porcji rozmarynu i wyszło znacznie łagodniej.',
        );

        $this->assertSame(
            1,
            $this->ilePowierzchni($dlugi, 'panel-formularza'),
            'Długi tekst wraca jako widoczne `<textarea>` — to jest panel formularza.',
        );
    }

    /**
     * Odbija limit komentarzy (10 na minutę, `config/kuking.php`) i zwraca
     * HTML ekranu 429 z odzyskanym formularzem.
     */
    private function odbityKomentarz(string $tekst): string
    {
        $autor = $this->user();

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $odpowiedz = null;

        for ($i = 0; $i <= 10; $i++) {
            $odpowiedz = $this->actingAs($autor)
                ->post(route('posts.comment', $post), ['body' => $tekst]);
        }

        $odpowiedz->assertStatus(429);

        return $odpowiedz->getContent();
    }

    /**
     * KOMUNIKAT ZWROTNY MA SWOJĄ WŁASNĄ ROLĘ I POKAZUJE SIĘ RAZ.
     *
     * `/ustawienia/2fa` wypisywało `session('status')` drugi raz, obok tego,
     * które robi `x-layout`. Ten sam tekst pojawiał się dwa razy, a czytnik
     * ekranu ogłaszał go dwukrotnie.
     */
    #[Test]
    public function test_komunikat_zwrotny_w_ustawieniach_2fa_pokazuje_sie_raz(): void
    {
        $html = $this->actingAs($this->user())
            ->withSession(['status' => 'Weryfikacja dwuetapowa jest wyłączona.'])
            ->get(route('settings.two_factor.edit'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            mb_substr_count($html, 'Weryfikacja dwuetapowa jest wyłączona.'),
            'Komunikat zwrotny pokazuje się na tym ekranie więcej niż raz. '.
            'Wypisuje go `x-layout` jako `.flash` — widok nie ma go powtarzać.',
        );
    }

    /**
     * Liczy wystąpienia klasy jako OSOBNEGO SŁOWA w atrybucie `class`.
     *
     * `\b` nie wystarczy: myślnik jest dla niego granicą słowa, więc
     * `\bcard\b` łapie także `post-card-head` i `recipe-card-miniatura`.
     * Na tym właśnie polegał błąd w pierwszym pomiarze — surowe 135 zamiast
     * rzeczywistych 126.
     */
    private function ilePowierzchni(string $html, string $klasa, bool $bezSzyny = false): int
    {
        preg_match_all('/class="([^"]*)"/', $html, $trafienia);

        $ile = 0;

        foreach ($trafienia[1] as $atrybut) {
            $klasy = preg_split('/\s+/', trim($atrybut)) ?: [];

            if ($bezSzyny && in_array('szyna-blok', $klasy, true)) {
                continue;
            }

            if (in_array($klasa, $klasy, true)) {
                $ile++;
            }
        }

        return $ile;
    }
}
