<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tags\FiltrWulgaryzmow;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * MARTWE HASŁA W FILTRZE — POMIAR I DOWÓD, ŻE JUŻ ICH NIE MA
 *
 * `FiltrWulgaryzmow` porównuje token PO transliteracji `Str::ascii()`, czyli
 * po usunięciu polskich znaków. Dwa hasła na liście były zapisane Z tymi
 * znakami — `pedał` i `jebnięty` — więc porównanie nie mogło ich trafić
 * NIGDY: do sprawdzenia dochodził `pedal` i `jebniety`, a na liście stała
 * wersja z ogonkiem. Zmierzone wprost:
 *
 *     Str::ascii('pedał')    => 'pedal'
 *     Str::ascii('jebnięty') => 'jebniety'
 *
 * Lista wyglądała więc na dłuższą, niż była: 48 haseł, z których 46
 * działało. (Wiadomość commita, która to naprawiła, mówi „40 haseł,
 * z których 38" — policzyłem wtedy pozycje z oka, a nie odbiciem stałej.
 * Prawdziwa liczba to 48, sprawdzalna jednym wywołaniem
 * `getConstant('SLOWA')`.) To jest ta sama klasa błędu co martwa stała
 * progu podobieństwa w wyszukiwaniu — kod deklarował ochronę, której
 * nie miał.
 *
 * Ten test nie pilnuje dwóch konkretnych słów, a NIEZMIENNIKA: żadne hasło
 * na liście nie może różnić się od własnej postaci po transliteracji.
 * Dzięki temu następne hasło dopisane z ogonkiem — a lista jest ręcznie
 * utrzymywana i będzie rosła — zapali się tutaj, zanim ktoś zdąży uwierzyć,
 * że filtr go blokuje.
 */
final class FiltrWulgaryzmowBezMartwychHaselTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function slowa(): array
    {
        /** @var list<string> $slowa */
        $slowa = (new ReflectionClass(FiltrWulgaryzmow::class))->getConstant('SLOWA');

        return $slowa;
    }

    public function test_kontrola_filtr_w_ogole_dziala(): void
    {
        // Bez tej asercji cały test mógłby przechodzić na filtrze, który
        // nie blokuje niczego.
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kurwa'));
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('zupa kurwa pomidorowa'));
    }

    public function test_zadne_haslo_nie_jest_martwe(): void
    {
        $slowa = $this->slowa();

        $this->assertNotEmpty($slowa, 'Lista haseł nie może być pusta — inaczej reszta tego testu jest bez treści.');

        $martwe = [];

        foreach ($slowa as $haslo) {
            $poTransliteracji = mb_strtolower(Str::ascii($haslo));

            if ($haslo !== $poTransliteracji) {
                $martwe[] = $haslo.' (do porównania dochodzi: '.$poTransliteracji.')';
            }
        }

        $this->assertSame([], $martwe, 'Hasła zapisane inaczej niż ich postać po Str::ascii() nie zablokują nigdy niczego.');
    }

    public function test_slowo_z_polskim_znakiem_naprawde_jest_blokowane(): void
    {
        // Pomiar sprzed naprawy: oba te wywołania zwracały false, mimo że
        // słowa stały na liście.
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('pedał'));
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('jebnięty'));

        // I ta sama forma bez ogonków, bo to jedno i to samo słowo.
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('pedal'));
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('jebniety'));
    }

    public function test_kontrola_z_drugiej_strony_niewinne_tagi_przechodza(): void
    {
        // Gdyby naprawa poszła w stronę dopasowania fragmentu zamiast całego
        // tokenu, te trzy zapaliłyby się natychmiast. Dwa pierwsze to
        // prawdziwe fałszywe trafienia z historii tego projektu.
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('konfitura'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kuchnia łęczycka'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('pedałowanie na rowerze'));
    }
}
