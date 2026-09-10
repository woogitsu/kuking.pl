<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Liczebnik odmienia `App\Support\Odmiana`, nie inflektor angielski ani
 * rzut na `int` (issue #38, przy okazji przenoszenia PR #254 do #288).
 *
 * DWA NIEZALEŻNE MIEJSCA, TA SAMA CHOROBA
 *
 * 1. Wątek komentarzy (`components/comment-thread.blade.php`) odmieniał
 *    polskie „minutę" przez `Illuminate\Support\Str::plural()` — inflektor
 *    ANGIELSKI, który dla nieznanego mu słowa dokłada „s":
 *
 *        Str::plural('minutę', 3)  →  „minutęs"
 *
 *    Czyli „Możesz poprawić jeszcze przez 3 minutęs." — na stronie wpisu,
 *    przy każdym własnym komentarzu młodszym niż piętnaście minut.
 *
 * 2. Podgląd kreatora przepisu (`components/recipe-wizard.blade.php`,
 *    ekran „tak zobaczą to inni") liczył `(int) $servings.' porcji'`, czyli
 *    „1 porcji", „2 porcji" i, przy połowie porcji, „0 porcji" — dokładnie
 *    to, co `Recipe::servingsLabel()` naprawiło już na stronie przepisu
 *    (audyt A28), tylko w drugim miejscu, drugą kopią kodu.
 *
 * Naprawa w obu miejscach jest ta sama: policz TYM SAMYM kodem, co ekran,
 * który wynik pokazuje naprawdę — `Odmiana::rzeczownik()` wprost albo,
 * dla porcji, `Recipe::servingsLabel()` na niezapisanym modelu (ten sam
 * wzorzec, którego już używa `previewTimerLabel()` obok).
 */
class OdmianaLiczebnikaWTekstachTest extends TestCase
{
    use RefreshDatabase;

    /** Wycinek HTML-a odpowiadający jednej sekcji ekranu — patrz
     *  `docs/PULAPKI_TESTOW.md` #1: assercja na całym HTML-u łapie to samo
     *  słowo skądinąd (licznik powiadomień, prawa szyna, stopka…). */
    private function wycinek(string $html, string $od, string $do): string
    {
        $start = mb_strpos($html, $od);
        $this->assertNotFalse($start, "Nie znalazłem znacznika początku „{$od}” w HTML-u.");

        $koniec = mb_strpos($html, $do, $start);
        $koniec = $koniec === false ? mb_strlen($html) : $koniec;

        return mb_substr($html, $start, $koniec - $start);
    }

    /**
     * Trzy liczby dobrane tak, żeby pokryć TRZY formy polskiej odmiany
     * naraz i żeby żadna z nich nie przeszłaby przez naiwne „liczba % 10":
     *
     *   - 14 pozostałych minut (komentarz sprzed 1 minuty) → „minut" (WIELE
     *     — nastka, mimo końcówki „4", która osobno brałaby formę „kilka"),
     *   - 3 pozostałe minuty (sprzed 12 minut) → „minuty" (KILKA),
     *   - 1 pozostała minuta (sprzed 14 minut) → „minutę" (JEDEN).
     *
     * `Str::plural('minutę', $n)` dla KAŻDEJ z tych trzech wartości pisało
     * „minutęs" (sprawdzone w konsoli: `Str::plural` nie zna w ogóle formy
     * „jeden" bez dodatkowego argumentu ani wyjątku na nastki).
     */
    public static function liczbyMinutProvider(): array
    {
        return [
            'nastka (14) bierze formę „wiele", nie „kilka"' => [1, 14, 'minut'],
            'kilka (3)' => [12, 3, 'minuty'],
            'jeden (1)' => [14, 1, 'minutę'],
        ];
    }

    #[DataProvider('liczbyMinutProvider')]
    public function test_licznik_minut_do_poprawki_komentarza_odmienia_poprawnie(
        int $minutTemu,
        int $oczekiwanePozostale,
        string $oczekiwanaForma,
    ): void {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Komentarz testowy do odmiany liczebnika.',
            'created_at' => now()->subMinutes($minutTemu),
        ]);

        $html = $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent();

        // Wycinamy sekcję TEGO komentarza — od jego treści do przycisku
        // zapisu poprawki — żeby nie złapać przypadkiem innej liczby z reszty
        // strony (`docs/PULAPKI_TESTOW.md` #1).
        $sekcja = $this->wycinek($html, 'Komentarz testowy do odmiany liczebnika.', 'Zapisz poprawkę');

        $this->assertStringNotContainsString(
            's.</p>',
            $sekcja,
            'Ślad błędu Str::plural (dokładana angielska końcówka „s") wrócił.',
        );

        $this->assertStringContainsString(
            "Możesz poprawić jeszcze przez {$oczekiwanePozostale} {$oczekiwanaForma}.",
            $sekcja,
        );
    }

    // -----------------------------------------------------------------
    // Podgląd liczby porcji w kreatorze przepisu
    // -----------------------------------------------------------------

    public static function porcjeProvider(): array
    {
        return [
            // Str::plural/(int) pisało tu „1 porcji" — niepoprawne po polsku.
            'jedna porcja' => ['1', '1 porcja'],
            // (int) 2 = 2, ale osobna kopia odmiany obok mogłaby się rozjechać
            // z `Recipe::servingsLabel()` przy następnej zmianie reguły —
            // dlatego test pilnuje także tej wartości, nie tylko przypadków,
            // które kiedyś wprost zawiodły.
            'dwie porcje' => ['2', '2 porcje'],
            // (int) 0.5 obcinał połówkę do zera: „0 porcji" — nieprawda
            // o samym sobie, bo pole w ogóle nie było puste.
            'pół porcji' => ['0.5', '0,5 porcji'],
        ];
    }

    #[DataProvider('porcjeProvider')]
    public function test_podglad_porcji_w_kreatorze_liczy_tym_samym_kodem_co_strona_przepisu(
        string $wpisanaWartosc,
        string $oczekiwanaEtykieta,
    ): void {
        $basia = $this->user('basia');

        $ekran = Livewire::actingAs($basia)
            ->test('recipe-wizard')
            ->set('title', 'Testowy przepis')
            ->set('servings', $wpisanaWartosc)
            ->set('step', 4);

        // Wycinamy sekcję „Przepis bez nazwy"/podglądu, nie całą stronę —
        // pole liczby porcji istnieje też w kroku 1 (formularz wpisywania),
        // więc assertSee na całym renderze złapałoby też SUROWĄ wartość pola,
        // nie tylko przeliczoną etykietę (`docs/PULAPKI_TESTOW.md` #1).
        $html = $ekran->html();
        $start = mb_strpos($html, 'naglowek-podgladu');
        $this->assertNotFalse($start, 'Nie znalazłem sekcji podglądu w renderze kreatora.');
        $sekcjaPodgladu = mb_substr($html, $start, 600);

        $this->assertStringContainsString(
            '<span class="badge">'.$oczekiwanaEtykieta.'</span>',
            $sekcjaPodgladu,
            "Podgląd porcji ma pokazywać „{$oczekiwanaEtykieta}”, tak jak strona przepisu.",
        );
    }
}
