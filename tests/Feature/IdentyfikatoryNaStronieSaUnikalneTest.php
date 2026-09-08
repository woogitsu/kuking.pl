<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Żaden `id` nie powtarza się na tej samej stronie.
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * `/ustawienia/bezpieczenstwo` miało `input#f-password` DWA RAZY: „Nowe
 * hasło" w formularzu zmiany hasła i „Wpisz swoje hasło" w formularzu
 * wylogowania innych urządzeń. Oba pola nazywają się `password`, a
 * `x-field` wyprowadzał `id` z nazwy.
 *
 * SKUTEK NIE BYŁ KOSMETYCZNY. Etykieta „Wpisz swoje hasło" ma
 * `for="f-password"`, więc kliknięcie jej przenosiło fokus do PIERWSZEGO
 * pola o tym identyfikatorze — 740 px wyżej, w innym formularzu. Człowiek
 * klikał podpis, strona skakała do góry, kursor mrugał w polu „Nowe
 * hasło", a pierwszy przycisk pod ręką brzmiał „Zmień hasło".
 * Zduplikowany `f-password-help` psuł przy okazji `aria-describedby`:
 * czytnik ekranu czytał przy drugim polu podpowiedź pierwszego.
 *
 * DLACZEGO TEST OGÓLNY, A NIE JEDNA ASERCJA NA JEDNEJ STRONIE
 * Bo to nie jest usterka JEDNEGO ekranu, tylko własność `x-field`:
 * identyfikator wyprowadzony z nazwy załamuje się wszędzie tam, gdzie
 * jedna strona ma dwa formularze z polem o tej samej nazwie. Następny
 * taki ekran powstanie, gdy nikt nie będzie o tym pamiętał — i wtedy ma
 * paść ten test, a nie użytkownik.
 */
class IdentyfikatoryNaStronieSaUnikalneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function powtorzoneIdentyfikatory(string $html): array
    {
        preg_match_all('/\sid="([^"]+)"/', $html, $trafienia);

        $liczba = array_count_values($trafienia[1]);

        return array_values(array_keys(array_filter($liczba, fn (int $ile): bool => $ile > 1)));
    }

    /**
     * Ekrany z formularzami, czyli te, na których ten błąd może wystąpić.
     *
     * @return array<string, array{0: string}>
     */
    public static function ekrany(): array
    {
        return [
            'bezpieczeństwo' => ['settings.security'],
            'profil' => ['settings.profile'],
            'prywatność' => ['settings.privacy'],
            'dostępność' => ['settings.accessibility'],
            'dane' => ['settings.data'],
        ];
    }

    /**
     * Kontrola metody: na sztucznym HTML-u z duplikatem wykrywacz działa.
     *
     * Bez tego „zero duplikatów" na prawdziwych stronach mogłoby znaczyć,
     * że wyrażenie regularne nic nie łapie.
     */
    public function test_kontrola_wykrywacz_znajduje_duplikat(): void
    {
        $this->assertSame(
            ['f-password'],
            $this->powtorzoneIdentyfikatory(
                '<input id="f-password"><input id="f-inne"><input id="f-password">',
            ),
        );
    }

    #[DataProvider('ekrany')]
    public function test_ekran_nie_ma_powtorzonych_identyfikatorow(string $trasa): void
    {
        $odpowiedz = $this->actingAs($this->user('sprawdzam'))->get(route($trasa));

        $odpowiedz->assertOk();

        $powtorzone = $this->powtorzoneIdentyfikatory($odpowiedz->getContent());

        $this->assertSame(
            [],
            $powtorzone,
            "Na ekranie {$trasa} powtarza się identyfikator: ".implode(', ', $powtorzone)
            .'. Etykieta `for` trafia wtedy w PIERWSZE pole o tym `id`, nie w to, przy którym stoi.',
        );
    }
}
