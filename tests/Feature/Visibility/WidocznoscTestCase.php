<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Macierz widoczności: każdy stan treści × każdy typ obserwatora (issue #41).
 *
 * DLACZEGO TO JEST OSOBNA KLASA, A NIE KILKA ZWYKŁYCH TESTÓW
 * Wyciek prywatnej treści **nie wywala testu**. Widok cicho pokazuje za dużo,
 * odpowiedź ma status 200, a asercja „czy strona się otwiera" przechodzi.
 * Trzeba testować NIEOBECNOŚĆ treści, a tego nikt nie pisze z własnej woli dla
 * każdej kombinacji — bo kombinacji jest piętnaście na typ treści.
 *
 * Dlatego macierz jest generowana, nie przepisywana ręcznie. Dodanie nowego
 * modelu z widocznością to jedna klasa potomna z trzema metodami, a nie
 * piętnaście testów napisanych od zera — i, co ważniejsze, nie da się przy tym
 * „zapomnieć" o niewygodnej kombinacji.
 *
 * TRZY DROGI WYCIEKU
 * Treść może wyciec przez każdą z nich NIEZALEŻNIE i naprawienie jednej nie
 * naprawia pozostałych:
 *
 *   1. WIDOK  — bezpośrednie wejście na adres treści (Policy),
 *   2. LISTA  — profil, feed, tablica (zapytanie w kontrolerze),
 *   3. SZUKAJ — wyszukiwarka (osobne zapytanie, własne filtry).
 *
 * Policy pilnująca widoku nie pilnuje listy. Zapytanie listy nie pilnuje
 * wyszukiwarki. Każda z tych dróg ma tu własne testy.
 *
 * KANONICZNA TABELA PRAWDY
 *
 *   widz            | public | followers | private
 *   ----------------|--------|-----------|--------
 *   autor           |   ✓    |     ✓     |    ✓
 *   obserwujący     |   ✓    |     ✓     |    ✗
 *   obcy            |   ✓    |     ✗     |    ✗
 *   zablokowany     |   ✗    |     ✗     |    ✗
 *   niezalogowany   |   ✓    |     ✗     |    ✗
 *
 * Blokada ma pierwszeństwo przed wszystkim innym i działa w OBIE strony —
 * nieważne, kto kogo zablokował (`AGENTS.md` §4).
 */
abstract class WidocznoscTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $autor;

    protected User $obserwujacy;

    protected User $obcy;

    protected User $zablokowany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->obserwujacy = $this->user('obserwujaca');
        $this->obcy = $this->user('obca');
        $this->zablokowany = $this->user('zablokowana');

        app(FollowUser::class)->handle($this->obserwujacy, $this->autor);

        // Blokadę zakłada AUTOR. Test sprawdza też stronę odwrotną — patrz
        // test_blokada_dziala_w_obie_strony.
        app(BlockUser::class)->handle($this->autor, $this->zablokowany);
    }

    /**
     * Wartości widoczności obsługiwane przez ten typ treści.
     *
     * Zeszyty mają tylko `public` i `private` (CHECK w bazie), wpisy
     * i przepisy mają dodatkowo `followers`.
     *
     * @return list<string>
     */
    abstract protected function widocznosci(): array;

    /** Tworzy treść autora (`$this->autor`) o podanej widoczności. */
    abstract protected function utworz(string $widocznosc): Model;

    /** Adres strony tej treści. */
    abstract protected function adres(Model $tresc): string;

    /**
     * Kanoniczna tabela prawdy.
     *
     * @return array<string, array<string, bool>>
     */
    protected function tabelaPrawdy(): array
    {
        return [
            'autor' => ['public' => true, 'followers' => true, 'private' => true],
            'obserwujący' => ['public' => true, 'followers' => true, 'private' => false],
            'obcy' => ['public' => true, 'followers' => false, 'private' => false],
            'zablokowany' => ['public' => false, 'followers' => false, 'private' => false],
            'niezalogowany' => ['public' => true, 'followers' => false, 'private' => false],
        ];
    }

    /** @return array<string, ?User> */
    protected function widzowie(): array
    {
        return [
            'autor' => $this->autor,
            'obserwujący' => $this->obserwujacy,
            'obcy' => $this->obcy,
            'zablokowany' => $this->zablokowany,
            'niezalogowany' => null,
        ];
    }

    // -----------------------------------------------------------------
    // Droga 1: WIDOK
    // -----------------------------------------------------------------

    public function test_macierz_widoku(): void
    {
        foreach ($this->widocznosci() as $widocznosc) {
            $tresc = $this->utworz($widocznosc);
            $adres = $this->adres($tresc);

            foreach ($this->widzowie() as $ktoNazwa => $kto) {
                $powinienWidziec = $this->tabelaPrawdy()[$ktoNazwa][$widocznosc];

                // `actingAs()` utrzymuje zalogowanie na KOLEJNE żądania w tym
                // samym teście. Bez jawnego wylogowania przypadek
                // „niezalogowany" dziedziczyłby użytkownika z poprzedniej
                // iteracji pętli i cicho sprawdzałby coś zupełnie innego —
                // czyli dokładnie ten rodzaj fałszywej zieleni, przed którym
                // ma chronić ta macierz.
                if ($kto === null) {
                    Auth::logout();
                    $zadanie = $this->get($adres);
                } else {
                    $zadanie = $this->actingAs($kto)->get($adres);
                }

                $opis = sprintf(
                    '%s / widoczność „%s" / widz „%s" — oczekiwano %s, dostano HTTP %d.',
                    class_basename($tresc),
                    $widocznosc,
                    $ktoNazwa,
                    $powinienWidziec ? 'DOSTĘPU' : 'ODMOWY',
                    $zadanie->getStatusCode(),
                );

                // Świadomie NIE używamy tu $zadanie->assertOk(): metody
                // TestResponse nie przyjmują własnego komunikatu, więc przy
                // piętnastu kombinacjach nie dałoby się poznać, KTÓRA komórka
                // macierzy padła. A to jest cała wartość tego testu.
                if ($powinienWidziec) {
                    $this->assertSame(200, $zadanie->getStatusCode(), $opis);
                } else {
                    $this->assertContains(
                        $zadanie->getStatusCode(),
                        [403, 404, 302],
                        $opis,
                    );
                }
            }
        }
    }

    public function test_blokada_dziala_w_obie_strony(): void
    {
        // Wyżej blokuje autor. Tu sprawdzamy sytuację odwrotną: to CZYTELNIK
        // zablokował autora. Treść ma zniknąć tak samo — inaczej „zablokowałam
        // tę osobę" znaczyłoby tylko „ona mnie nie zobaczy".
        $blokujacy = $this->user('blokujaca');
        app(BlockUser::class)->handle($blokujacy, $this->autor);

        $tresc = $this->utworz('public');

        $this->actingAs($blokujacy)
            ->get($this->adres($tresc))
            ->assertStatus(403);
    }
}
