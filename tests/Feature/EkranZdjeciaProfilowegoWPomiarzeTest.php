<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Profile;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * `/ustawienia/zdjecie` wchodzi do pomiaru — i to w tym stanie, który pękał.
 *
 * CO BYŁO NIE TAK
 * ---------------
 * Ekranu nie było na liście `scripts/dostepnosc.mjs`, a przy 320 px
 * i czcionce przeglądarki 200% wyjeżdżał w bok. Zmierzone 12 września,
 * stan „to jest Twoje zdjęcie", okno 320 px:
 *
 *     bez powiększania             scrollWidth 320   bez przepełnienia
 *     tekst 140%                   scrollWidth 320   bez przepełnienia
 *     czcionka przeglądarki 200%   scrollWidth 333   PRZEPEŁNIENIE (13 px)
 *
 * Winowajcą był dokładnie jeden element: akapit `.zdjecie-profilowe-opis`
 * (prawa krawędź 333 px przy oknie 320). Jest elementem flex przy
 * `align-items: flex-start`, więc jego szerokość to `fit-content`, a
 * `fit-content` nie schodzi poniżej szerokości MINIMALNEJ — czyli poniżej
 * najdłuższego słowa, które przy 32 px pisma ma 252 px.
 *
 * DLACZEGO SAMO DOPISANIE ADRESU NIC BY NIE DAŁO
 * ----------------------------------------------
 * Ekran ma trzy stany i różnią się nie zdaniem, tylko układem. W stanie
 * „nie masz jeszcze swojego zdjęcia" nie ma ani obrazka 88 px obok akapitu,
 * ani całej sekcji „Usunięcie zdjęcia" — i ten stan zmierzony przy 320 px
 * i czcionce 200% daje `scrollWidth` 320, czyli NIE przepełnia. A właśnie
 * w nim ekran byłby mierzony, bo `DemoSeeder` nie dawał kontu automatu
 * żadnego zdjęcia. Strażnik pilnowałby wtedy nie tej rzeczy — pułapki 2 i 4
 * z `docs/PULAPKI_TESTOW.md`.
 *
 * CZEGO TEN TEST NIE ROBI
 * -----------------------
 * Nie mierzy przepełnienia. Czy strona wyjeżdża w bok, wie wyłącznie
 * przeglądarka — i to mierzy `scripts/dostepnosc.mjs`, który chodzi z ręki
 * i w CI, nie w `php artisan test`. Ten test pilnuje rzeczy, której tamten
 * pomiar pilnować nie może: żeby PRÓBKA nie wróciła do łatwego stanu i żeby
 * reguła, która przepełnienie zamknęła, nie zniknęła po cichu.
 */
class EkranZdjeciaProfilowegoWPomiarzeTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** Konto, którym loguje się automat — `KONTO_ZALOGOWANE` w skrypcie. */
    private const KONTO_AUTOMATU = 'ania';

    #[Test]
    public function test_dane_demo_daja_kontu_automatu_gotowe_zdjecie_z_prawdziwego_pliku(): void
    {
        $this->seed(DemoSeeder::class);

        $profil = Profile::query()->where('username', self::KONTO_AUTOMATU)->first();

        $this->assertNotNull(
            $profil,
            'W danych demo nie ma konta „'.self::KONTO_AUTOMATU.'". To nim loguje się '.
            '`scripts/dostepnosc.mjs` — bez niego nie ma czyjego zdjęcia mierzyć.',
        );

        $zdjecie = $profil->avatar;

        $this->assertNotNull(
            $zdjecie,
            'Konto „'.self::KONTO_AUTOMATU.'" nie ma zdjęcia profilowego. Automat mierzyłby '.
            'ekran `/ustawienia/zdjecie` w stanie „nie masz jeszcze swojego zdjęcia" — '.
            'jedynym z trzech, który NIE przepełniał.',
        );

        $this->assertTrue(
            $zdjecie->isReady(),
            'Zdjęcie konta automatu nie jest gotowe. Widok stawia wtedy zdanie '.
            '„Twoje nowe zdjęcie się przygotowuje" i inicjał zamiast obrazka — czyli '.
            'znowu inny układ niż ten, o który chodzi.',
        );

        // PRAWDZIWY PLIK, NIE SAM WIERSZ. Bez wariantu `Media::url()` oddaje
        // `icons/kuking-mark.svg` (`viewBox 0 0 64 64`) — dane, które wyglądają
        // na prawdziwe i nie są.
        $wariant = $zdjecie->wariantDoSerwowania('thumb');

        $this->assertNotNull(
            $wariant,
            'Zdjęcie konta automatu nie ma ani jednego wariantu. `Media::url()` podstawia '.
            'wtedy znak Kuking o `viewBox 0 0 64 64` — mierzony byłby kwadrat 64 × 64 '.
            'podstawiony za zdjęcie.',
        );

        $this->assertTrue(
            Storage::disk($zdjecie->variantsDisk())->exists($wariant['klucz']),
            'Wariant zdjęcia konta automatu nie ma pliku na dysku ('.$wariant['klucz'].'). '.
            '`MediaController` odpowie 404, a „prawdziwe zdjęcie" będzie pustą ramką.',
        );

        // WYMIARY Z PLIKU, NIE Z RĘKI. Liczby wpisane obok pliku o innych
        // wymiarach są dokładnie tym rodzajem danych demonstracyjnych, które
        // mylą pomiar układu: przeglądarka układa po `width`/`height`.
        $wymiary = getimagesize(
            Storage::disk($zdjecie->variantsDisk())->path($wariant['klucz']),
        );

        $this->assertNotFalse($wymiary, 'Plik wariantu nie jest obrazem.');
        $this->assertSame(
            [$zdjecie->width, $zdjecie->height],
            [$wymiary[0], $wymiary[1]],
            'Wymiary w bazie nie zgadzają się z wymiarami pliku.',
        );
        $this->assertSame(Media::STATUS_READY, $zdjecie->status);
    }

    #[Test]
    public function test_ekran_stoi_w_stanie_to_jest_twoje_zdjecie_a_bez_zdjecia_w_innym(): void
    {
        $this->seed(DemoSeeder::class);

        $profil = Profile::query()->where('username', self::KONTO_AUTOMATU)->firstOrFail();

        $tresc = $this->trescEkranu(
            $this->actingAs($profil->user)->get('/ustawienia/zdjecie')->assertOk()->getContent() ?: '',
        );

        // W TREŚCI EKRANU, nie w całym dokumencie — pułapka 1b: to samo zdanie
        // stoi w `<title>` i w `<meta>`, więc asercja na `$html` przeszłaby
        // także wtedy, gdyby ekran przestał cokolwiek pokazywać.
        $this->assertStringContainsString('To jest Twoje zdjęcie', $tresc);
        $this->assertStringContainsString('danger-zone', $tresc);
        $this->assertStringContainsString('zdjecie-profilowe-opis', $tresc);

        /*
         * KONTROLA DODATNIA DO POWYŻSZEGO (pułapka 4). Same asercje „widać X"
         * nie dowodzą, że X pojawia się Z POWODU zdjęcia — mogłyby stać na
         * ekranie zawsze. Dopiero para „ze zdjęciem widać, bez zdjęcia nie"
         * pokazuje, że to zdjęcie robi tu różnicę, czyli że dosianie go
         * naprawdę zmienia mierzony układ.
         */
        $profil->update(['avatar_media_id' => null]);

        $bezZdjecia = $this->trescEkranu(
            $this->actingAs($profil->user->fresh())->get('/ustawienia/zdjecie')->assertOk()->getContent() ?: '',
        );

        $this->assertStringNotContainsString('To jest Twoje zdjęcie', $bezZdjecia);
        $this->assertStringNotContainsString('danger-zone', $bezZdjecia);
        $this->assertStringContainsString('Nie masz jeszcze swojego zdjęcia', $bezZdjecia);
    }

    #[Test]
    public function test_automat_ma_ten_ekran_na_liscie_i_sprawdza_jego_stan(): void
    {
        $skrypt = (string) file_get_contents(base_path('scripts/dostepnosc.mjs'));

        $this->assertGreaterThan(
            2000,
            substr_count($skrypt, "\n"),
            'Czytamy podejrzanie krótki plik — to na pewno automat dostępności?',
        );

        $this->assertMatchesRegularExpression(
            '/\{\s*nazwa:\s*\x27zdjęcie profilowe\x27,\s*adres:\s*\x27\/ustawienia\/zdjecie\x27,\s*'.
            'zalogowany:\s*true\s*\}/u',
            $skrypt,
            'Ekranu `/ustawienia/zdjecie` nie ma na liście EKRANY. Brak ekranu na liście '.
            'niczego nie psuje: raport wygląda na kompletny i świeci na zielono.',
        );

        // Sam adres nie wystarcza — kod 200 dostaje się także w stanie
        // „nie masz jeszcze zdjęcia". Bez tego sprawdzenia automat wpisałby
        // „✓" dla ekranu, którego trudnej połowy nie widział.
        $this->assertStringContainsString(
            '.zdjecie-profilowe-stan img.avatar',
            $skrypt,
            'Automat nie sprawdza, czy ekran zdjęcia stoi w stanie „to jest Twoje zdjęcie". '.
            'Bez tego mierzy stan uboższy o obrazek i o sekcję usunięcia — czyli jedyny '.
            'z trzech, który nie przepełniał.',
        );
    }

    #[Test]
    public function test_regula_ktora_zamknela_przepelnienie_zostaje(): void
    {
        $arkusz = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.zdjecie-profilowe-opis\s*\{(?:[^}]|\}(?!\s*\n))*?overflow-wrap:\s*anywhere\s*;/u',
            $arkusz,
            'Z `.zdjecie-profilowe-opis` zniknęło `overflow-wrap: anywhere`. To ono, '.
            'i nic innego, zamyka przepełnienie z 12 września: globalne '.
            '`overflow-wrap: break-word` z `tokens.css` łamie już ułożony wiersz, ale '.
            'NIE zmniejsza szerokości minimalnej, więc najdłuższe słowo dalej dyktuje '.
            'szerokość akapitu (zmierzone: 333 px w oknie 320 px).',
        );
    }
}
