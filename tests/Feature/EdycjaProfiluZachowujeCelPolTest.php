<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edycja profilu nie gubi programowego celu pól — issue #949 (WCAG 1.3.5).
 *
 * CO BYŁO ŹLE
 * Rejestracja i dokończenie logowania Google/Facebook oznaczają pola
 * `display_name` i `username` tokenami `name` i `username`. Ten sam człowiek
 * edytujący te same dane w `/ustawienia/profil` dostawał pola BEZ tokenów —
 * przeglądarka i narzędzia wspomagające przestawały rozpoznawać, co to za pole.
 *
 * CZEGO PILNUJE TEN PLIK
 *  1. Oba pola w ustawieniach profilu mają token i jest on TAKI SAM jak na
 *     rejestracji — porównanie dwóch wyrenderowanych stron, nie wpisany
 *     na sztywno napis po jednej stronie.
 *  2. Token należy do standardowej listy WCAG „Input Purposes" — dowolny
 *     ciąg (np. literówka „usrename") nie przejdzie.
 *  3. Kontrola dodatnia: na obu stronach pola w ogóle istnieją, więc brak
 *     tokenu nie może „przejść" przez brak pola.
 *
 * KONTROLA UJEMNA (wykonana ręcznie przy #949): usunięcie
 * `autocomplete="username"` z `pages/settings/profile.blade.php` wywraca
 * test pierwszy.
 */
class EdycjaProfiluZachowujeCelPolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tokeny z WCAG 2.2 §7 „Input Purposes for User Interface Components".
     * Wystarczy podzbiór danych osoby — pełna lista nie jest tu potrzebna,
     * a zbyt szeroka osłabiłaby asercję.
     */
    private const TOKENY_WCAG = [
        'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name',
        'honorific-suffix', 'nickname', 'username', 'email', 'new-password',
        'current-password', 'one-time-code',
    ];

    public function test_ustawienia_profilu_oznaczaja_pola_jak_rejestracja(): void
    {
        $ustawienia = $this->tokeny(
            (string) $this->actingAs($this->user('basia_949'))
                ->get(route('settings.profile'))->assertOk()->getContent(),
        );

        auth()->logout();

        $rejestracja = $this->tokeny(
            (string) $this->get(route('register'))->assertOk()->getContent(),
        );

        foreach (['display_name', 'username'] as $pole) {
            $this->assertArrayHasKey($pole, $ustawienia, "Na /ustawienia/profil brak pola {$pole}.");
            $this->assertArrayHasKey($pole, $rejestracja, "Na rejestracji brak pola {$pole}.");

            $this->assertNotSame('', $ustawienia[$pole], "Pole {$pole} w ustawieniach profilu nie ma autocomplete.");
            $this->assertContains($ustawienia[$pole], self::TOKENY_WCAG, "Token {$ustawienia[$pole]} nie jest standardowy.");
            $this->assertSame($rejestracja[$pole], $ustawienia[$pole], "Pole {$pole} znaczy co innego niż na rejestracji.");
        }

        $this->assertSame('name', $ustawienia['display_name']);
        $this->assertSame('username', $ustawienia['username']);
    }

    /** @return array<string, string> nazwa pola => wartość autocomplete ('' gdy brak) */
    private function tokeny(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $wynik = [];
        foreach (self::elementyDom((new DOMXPath($dom))->query('//input[@name="display_name" or @name="username"]')) as $input) {
            $wynik[$input->getAttribute('name')] = $input->getAttribute('autocomplete');
        }

        return $wynik;
    }
}
