<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reguły z `docs/brand/COPY_STYLE.md` na ŚCIEŻKACH UŻYTKOWNIKA (issue #38,
 * druga część — po PR #235).
 *
 * PO CO DRUGI PLIK OBOK `TekstyWedlugCopyStyleTest`
 * Tamten plik pilnuje czterech reguł i jest dobry. Rzecz w tym, że dwie
 * z nich pilnuje WĘŻEJ, niż mówi dokument, i przez tę szczelinę przeszła
 * cała garść tekstów, które ta zmiana naprawia:
 *
 *   1. Wzór na ukośnik rodzajowy (`\p{L}+(?:ła|łeś|ał|eś)/\p{L}+`) wymaga
 *      PEŁNEGO słowa po ukośniku i jednej z czterech końcówek przed nim.
 *      Nie łapie więc ani „prosiłaś/eś" (po ukośniku sam sufiks), ani
 *      „zalogowana/y" (imiesłów, nie czas przeszły), ani „Zrobiłam/zrobiłem"
 *      (końcówka „am"), ani „sam/sama". Dokładnie te formy zostały
 *      w ustawieniach konta, na karcie „Ugotowałem" i w komunikacie
 *      kontrolera — a §2 zabrania ich wszystkich jednym zdaniem, bo żadnej
 *      nie da się przeczytać na głos.
 *   2. Odmiany liczebnika nie pilnowało nic. `App\Support\Odmiana` istnieje
 *      od dawna, ale wątek komentarzy odmieniał „minutę" przez
 *      `Illuminate\Support\Str::plural()`, czyli inflektorem ANGIELSKIM,
 *      i pisał człowiekowi „Możesz poprawić jeszcze przez 3 minutęs".
 *      Podgląd kreatora przepisu pisał „1 porcji" i „2 porcji".
 *
 * Zamiast rozluźniać tamte asercje, ten plik dokłada osobne — węższe wzory
 * z `TekstyWedlugCopyStyleTest` mają dalej stać na straży tego, co złapały,
 * a nowe reguły są tu, obok uzasadnienia, dlaczego są szersze.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE SPRAWDZA
 * Form ŻEŃSKICH bez ukośnika („co tu wrzuciłaś", „co wtedy gotowałaś").
 * Dwie z nich są w `docs/brand/COPY_STYLE.md` §6 jako GOTOWY TEKST DO
 * WKLEJENIA (list o eksporcie danych, pusty stan własnego archiwum) —
 * test, który by ich zabraniał, kłóciłby się z dokumentem wiążącym.
 * Zmiana tej decyzji należy do dokumentu, nie do testu.
 */
class TekstyNaSciezkachUzytkownikaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trzy postacie konstrukcji, która każe czytającemu wybrać rodzaj.
     *
     * A — pełna forma, po ukośniku sam SUFIKS: „prosiłaś/eś",
     *     „zostałaś/eś", „zalogowana/y", „dostałeś/aś".
     *
     * B — dwie formy tego samego słowa: „ugotowała/ugotował",
     *     „Zrobiłam/zrobiłem", „sam/sama". Wzór trzyma się wspólnego
     *     rdzenia (`\1`), dlatego nie zapala się na „wpisy/przepisy"
     *     ani na „min/max". Odgrodzenia po bokach (`(?<![\/.\-\p{L}])`,
     *     `(?![\/.\-])`) są tam po jednym konkretnym fałszywym trafieniu:
     *     bez nich adres `facebook.com/sharer/sharer.php` wygląda jak
     *     dwie formy słowa „sharer".
     *
     * C — sufiks w nawiasie: „podjął(-ęła)", „zrobił(a)". Nawias jest tu
     *     tylko innym zapisem tego samego wyboru rodzaju, a §2 mówi
     *     o KONSTRUKCJI, nie o znaku interpunkcyjnym. Lista sufiksów
     *     w nawiasie jest zamknięta, żeby wzór nie zapalał się na kodzie
     *     w rodzaju `rotate(-90)`.
     *
     * @var array<string, string>
     */
    private const WZORY_RODZAJOWE = [
        'sufiks po ukośniku („prosiłaś/eś")' => '/\p{L}{3,}(?:ał|ała|ałem|ałam|łeś|łaś|ła|ł|na|ny|ana|any|ona|ony)\s*\/\s*(?:eś|aś|am|em|a|y|i|ą|ę)\b/u',
        'dwie formy słowa („ugotowała/ugotował")' => '/(?<![\/.\-\p{L}])(\p{L}{3,})(?:\p{L}{0,4})?\s*\/\s*\1\p{L}{0,4}(?![\/.\-])/iu',
        'sufiks w nawiasie („podjął(-ęła)")' => '/\p{L}{3,}\(-?(?:a|ą|ę|ła|łam|łem|aś|eś|ęła|ęły|em|am)\)/u',
    ];

    // ---------------------------------------------------------------
    // 1. Konstrukcja nie każe wybierać rodzaju
    // ---------------------------------------------------------------

    /**
     * Widoki poza panelem moderacji: `pages/**`, `components/**`, `auth/**`,
     * `mail/**`, `errors/**`. Panel (`pages/admin/**`) ma własny reżim
     * i osobne zlecenia — nie jest ścieżką użytkownika.
     */
    public function test_widoki_uzytkownika_nie_kaza_wybierac_rodzaju(): void
    {
        $winowajcy = [];

        foreach ($this->widokiUzytkownika() as $plik) {
            $tresc = $this->bezKomentarzyBlade((string) file_get_contents($plik));

            foreach (explode("\n", $tresc) as $numer => $linia) {
                foreach (self::WZORY_RODZAJOWE as $nazwa => $wzor) {
                    if (preg_match($wzor, $linia) === 1) {
                        $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' ['.$nazwa.'] → '.trim($linia);
                    }
                }
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienieRodzaju($winowajcy));
    }

    /**
     * Napisy składane w PHP — komunikaty flash, błędy walidacji, wyjątki
     * `BladDlaCzlowieka`, powiadomienia. Połowa tych form nie siedziała
     * w widoku, tylko w kontrolerze („dowie się, że podziękowałaś/eś").
     *
     * Skan idzie po NAPISACH z `token_get_all()`, nie `grep`-em po pliku:
     * komentarze w tym repozytorium cytują złe formy wprost, żeby wyjaśnić,
     * co i dlaczego zostało zmienione.
     */
    public function test_komunikaty_z_php_nie_kaza_wybierac_rodzaju(): void
    {
        $winowajcy = [];

        foreach ($this->plikiPhpProduktu() as $plik) {
            foreach ($this->napisyZPliku($plik) as $numerLinii => $napis) {
                foreach (self::WZORY_RODZAJOWE as $nazwa => $wzor) {
                    if (preg_match($wzor, $napis) === 1) {
                        $winowajcy[] = $this->skrot($plik).':'.$numerLinii.' ['.$nazwa.'] → '.trim($napis);
                    }
                }
            }
        }

        $this->assertSame([], $winowajcy, $this->wyjasnienieRodzaju($winowajcy));
    }

    /**
     * To samo na ŻYWO, na ekranach ustawień konta — bo tam siedziała
     * większość tych form, a statyczny skan nie widzi tekstu, który
     * dokłada komponent albo `@include`.
     */
    public function test_ustawienia_konta_na_zywo_nie_kaza_wybierac_rodzaju(): void
    {
        $basia = $this->user('basia');

        $ekrany = [
            'ustawienia/prywatnosc' => 'settings.privacy',
            'ustawienia/twoje-dane' => 'settings.data',
            'ustawienia/bezpieczenstwo' => 'settings.security',
            'ustawienia/e-mail' => 'settings.email',
            'ustawienia/czytelnosc' => 'settings.accessibility',
            'ustawienia/profil' => 'settings.profile',
        ];

        foreach ($ekrany as $nazwa => $trasa) {
            $html = $this->actingAs($basia)->get(route($trasa))->assertOk()->getContent();

            foreach (self::WZORY_RODZAJOWE as $opisWzoru => $wzor) {
                $this->assertSame(
                    0,
                    preg_match($wzor, $html),
                    "Ekran „{$nazwa}” każe wybrać rodzaj ({$opisWzoru}). "
                    .'docs/brand/COPY_STYLE.md §2: zamiast szukać żeńskiej formy, zmieniamy '
                    .'konstrukcję zdania.',
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // 2. Liczebnik odmienia się po polsku
    // ---------------------------------------------------------------

    /**
     * Angielski inflektor na polskim rzeczowniku daje „3 minutęs".
     *
     * Zakaz jest całkowity, a nie „tylko dla polskich słów": interfejs
     * Kuking jest w całości po polsku (AGENTS.md §11), więc każde użycie
     * `Str::plural()` w widoku albo w kodzie produktu jest albo tym błędem,
     * albo o krok od niego. Odmianę liczy `App\Support\Odmiana`.
     */
    public function test_polskich_rzeczownikow_nie_odmienia_angielski_inflektor(): void
    {
        $winowajcy = [];

        foreach (array_merge($this->widokiUzytkownika(), $this->plikiPhpProduktu()) as $plik) {
            $tresc = (string) file_get_contents($plik);

            foreach (explode("\n", $tresc) as $numer => $linia) {
                if (preg_match('/Str::(plural|singular)\s*\(/', $linia) === 1) {
                    $winowajcy[] = $this->skrot($plik).':'.($numer + 1).' → '.trim($linia);
                }
            }
        }

        $this->assertSame(
            [],
            $winowajcy,
            "Angielski inflektor na polskim rzeczowniku:\n".implode("\n", $winowajcy)."\n"
            .'`Str::plural(\'minutę\', 3)` zwraca „minutęs". Polski ma trzy formy '
            .'i wyjątek na nastki — liczy je `App\Support\Odmiana::rzeczownik()`, '
            .'napisana i przetestowana raz (tests/Unit/OdmianaTest.php).',
        );
    }

    /**
     * Okno na poprawkę komentarza — trzy formy, jedna po drugiej.
     *
     * Sprawdzane na WYRENDEROWANEJ stronie wpisu, nie na samej klasie
     * `Odmiana`: ta ma własny test jednostkowy, a rzecz, która się zepsuła,
     * była w widoku — w tym, CZYM widok odmienia.
     */
    public function test_okno_na_poprawke_komentarza_odmienia_minuty(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        // Okno ma 15 minut, więc pozostały czas wybieramy wiekiem komentarza:
        // świeży → 15 („minut"), 13 minut → 2 („minuty"), 14 → 1 („minutę").
        $oczekiwane = [
            0 => 'przez 15 minut.',
            13 => 'przez 2 minuty.',
            14 => 'przez 1 minutę.',
        ];

        foreach ($oczekiwane as $wiekWMinutach => $zdanie) {
            $komentarz = Comment::factory()->create([
                'author_id' => $basia->getKey(),
                'post_id' => $post->getKey(),
                'body' => 'Wyszło znakomicie',
                'created_at' => now()->subMinutes($wiekWMinutach),
            ]);

            $this->actingAs($basia)
                ->get(route('posts.show', $post))
                ->assertOk()
                ->assertSee($zdanie, escape: false);

            $komentarz->forceDelete();
        }
    }

    /**
     * Podgląd kreatora przepisu nazywa się „tak zobaczą to inni" — więc
     * liczba porcji ma tam brzmieć dokładnie tak, jak zabrzmi na stronie
     * przepisu (`Recipe::servingsLabel()`, audyt A28).
     */
    public function test_podglad_kreatora_odmienia_porcje_tak_jak_strona_przepisu(): void
    {
        $basia = $this->user('basia');

        $oczekiwane = [
            '1' => '1 porcja',
            '2' => '2 porcje',
            '5' => '5 porcji',
            '12' => '12 porcji',
            '0.5' => '0,5 porcji',
        ];

        foreach ($oczekiwane as $wpisane => $zdanie) {
            Livewire::actingAs($basia)
                ->test('recipe-wizard')
                ->set('title', 'Rosół babci Zofii')
                ->set('servings', $wpisane)
                ->set('step', 4)
                ->assertSee($zdanie);
        }
    }

    // ---------------------------------------------------------------
    // 3. Zero emoji — na żywo, także w komunikatach
    // ---------------------------------------------------------------

    /**
     * `TekstyWedlugCopyStyleTest` skanuje pod emoji PLIKI: widoki
     * i napisy z `app/`. Ten test patrzy na gotowy HTML ścieżki, którą
     * naprawdę idzie człowiek — a tam trafia też tekst, którego w żadnym
     * z tych dwóch miejsc nie ma:
     *
     *   - komunikat flash po opublikowaniu wpisu (dwa różne, zależnie
     *     od tego, czy to pierwszy wpis — dlatego test publikuje dwa),
     *   - podsumowanie błędów zbudowane z komunikatów walidacji,
     *   - wartość z `config/kuking.php` wstawiona w zdanie („Czyta je Ula").
     *
     * Ta ostatnia jest powodem, dla którego ten test jest osobny, a nie
     * czwartym wzorem w tamtym pliku: emoji w konfiguracji nie widzi ani
     * skan widoków, ani skan `app/`.
     */
    public function test_zywe_ekrany_i_komunikaty_sciezki_uzytkownika_nie_maja_emoji(): void
    {
        $basia = $this->user('basia');

        $kroki = [
            'rejestracja' => fn () => $this->get(route('register')),
            'logowanie' => fn () => $this->get(route('login')),
            'błąd rejestracji' => fn () => $this->followingRedirects()
                ->from(route('register'))
                ->post(route('register'), ['username' => 'nie ma takiej nazwy']),
            'błąd logowania' => fn () => $this->followingRedirects()
                ->from(route('login'))
                ->post(route('login'), ['login' => 'basia', 'password' => 'nie to haslo']),
            'dodawanie zdjęcia' => fn () => $this->actingAs($basia)->get(route('posts.create')),
            'pusty wpis odrzucony' => fn () => $this->followingRedirects()
                ->from(route('posts.create'))
                ->actingAs($basia)
                ->post(route('posts.store'), ['visibility' => 'public']),
            'pierwszy wpis opublikowany' => fn () => $this->followingRedirects()
                ->from(route('posts.create'))
                ->actingAs($basia)
                ->post(route('posts.store'), ['body' => 'Rosół na niedzielę', 'visibility' => 'public']),
            // Drugi wpis, bo flash jest inny niż przy pierwszym
            // („Opublikowane. Dziękujemy." kontra „To Twój pierwszy wpis").
            'kolejny wpis opublikowany' => fn () => $this->followingRedirects()
                ->from(route('posts.create'))
                ->actingAs($basia)
                ->post(route('posts.store'), ['body' => 'Żurek na zakwasie', 'visibility' => 'public']),
            'napisz do nas' => fn () => $this->actingAs($basia)->get(route('kontakt')),
        ];

        foreach ($kroki as $nazwa => $krok) {
            $html = $krok()->assertOk()->getContent();

            $this->assertSame(
                0,
                preg_match('/\p{Extended_Pictographic}/u', $html),
                "Emoji na ekranie „{$nazwa}”. docs/brand/COPY_STYLE.md §4: zero emoji "
                .'w tekstach interfejsu. Sprawdź też komunikat flash i plik językowy — '
                .'ten test patrzy na gotowy HTML, nie na sam widok.',
            );
        }
    }

    // ---------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------

    private function wyjasnienieRodzaju(array $winowajcy): string
    {
        return "Konstrukcja każąca wybrać rodzaj:\n".implode("\n", $winowajcy)."\n"
            .'docs/brand/COPY_STYLE.md §2: „zamiast szukać żeńskiej formy, zmieniamy '
            .'konstrukcję zdania". Działa imiesłów („ugotowane z Twojego przepisu"), '
            .'czas teraźniejszy („nie Ty prosisz o zmianę"), strona bierna bez podmiotu '
            .'(„Twoje konto zostało zalogowane") albo skreślenie słowa („decydujesz” '
            .'zamiast „sam decydujesz”). Ukośnika i nawiasu nie da się przeczytać na głos, '
            .'a to jest pierwszy test z §1 dokumentu.';
    }

    /** @return list<string> */
    private function widokiUzytkownika(): array
    {
        $widoki = $this->pliki(resource_path('views'), '.blade.php');

        // `pages/admin/**` to panel moderacji — inny reżim i inne zlecenia.
        return array_values(array_filter(
            $widoki,
            fn (string $plik): bool => ! str_starts_with($plik, resource_path('views/pages/admin').'/'),
        ));
    }

    /**
     * Kod produktu BEZ `app/Console`: komendy artisan piszą do terminala
     * właściciela, nie na ekran użytkownika (to samo rozstrzygnięcie
     * i to samo uzasadnienie co w `TekstyWedlugCopyStyleTest`).
     *
     * @return list<string>
     */
    private function plikiPhpProduktu(): array
    {
        return array_values(array_filter(
            $this->pliki(app_path(), '.php'),
            fn (string $plik): bool => ! str_starts_with($plik, app_path('Console').'/'),
        ));
    }

    private function bezKomentarzyBlade(string $tresc): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);
    }

    /** @return array<int, string> numer linii => napis */
    private function napisyZPliku(string $plik): array
    {
        $napisy = [];

        foreach (token_get_all((string) file_get_contents($plik)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $napisy[$token[2]] = $token[1];
            }
        }

        return $napisy;
    }

    /** @return list<string> */
    private function pliki(string $katalog, string $rozszerzenie): array
    {
        if (! is_dir($katalog)) {
            return [];
        }

        $znalezione = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog)) as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), $rozszerzenie)) {
                $znalezione[] = $plik->getPathname();
            }
        }

        sort($znalezione);

        return $znalezione;
    }

    private function skrot(string $sciezka): string
    {
        return str_replace(base_path().'/', '', $sciezka);
    }
}
