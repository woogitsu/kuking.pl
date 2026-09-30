<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Po błędzie formularz usunięcia konta stoi otwarty (#2244).
 *
 * Formularz siedzi w `<details>` na stronie „Twoje dane”. Po złym haśle
 * strona wracała z podsumowaniem błędów, ale sekcja była zamknięta: pole,
 * którego dotyczył błąd, było schowane, a odnośnik z podsumowania prowadził
 * do niewidocznego pola. Test idzie przez prawdziwy POST z tej strony
 * i czyta końcowy HTML po przekierowaniu.
 */
class UsuniecieKontaFormularzPoBledzieTest extends TestCase
{
    use RefreshDatabase;

    /** Sekcja `<details>` z formularzem usunięcia konta. */
    private function sekcjaUsuniecia(string $html): DOMElement
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);

        $sekcje = $xpath->query('//details[.//form[@action="'.route('settings.data.delete').'"]]');
        $this->assertSame(1, $sekcje->length, 'Na stronie nie ma sekcji <details> z formularzem usunięcia konta.');

        $sekcja = $sekcje->item(0);
        $this->assertInstanceOf(DOMElement::class, $sekcja);

        return $sekcja;
    }

    /** @return list<string> Cele odnośników z podsumowania błędów, bez „#”. */
    private function celePodsumowania(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($dom);

        $cele = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]//a') as $a) {
            $cele[] = ltrim((string) $a->getAttribute('href'), '#');
        }

        return $cele;
    }

    private function poBledzie(User $kto, array $dane): string
    {
        return (string) $this->actingAs($kto)
            ->from(route('settings.data'))
            ->followingRedirects()
            ->post(route('settings.data.delete'), $dane)
            ->assertOk()
            ->getContent();
    }

    public function test_bez_bledu_sekcja_usuniecia_jest_zamknieta(): void
    {
        // Kontrola: `open` ma być warunkiem błędu, a nie stałym atrybutem —
        // akcja nieodwracalna nie stoi rozwinięta przy zwykłym wejściu.
        $html = (string) $this->actingAs($this->user('basia'))->get(route('settings.data'))->assertOk()->getContent();

        $this->assertFalse($this->sekcjaUsuniecia($html)->hasAttribute('open'));
    }

    public function test_po_zlym_hasle_sekcja_jest_otwarta_a_podsumowanie_prowadzi_do_pola_w_niej(): void
    {
        $basia = $this->user('basia');

        $html = $this->poBledzie($basia, ['password' => 'zle-haslo', 'confirm' => '1']);

        $sekcja = $this->sekcjaUsuniecia($html);
        $this->assertTrue($sekcja->hasAttribute('open'), 'Po złym haśle formularz usunięcia konta został schowany.');
        $this->assertStringContainsString('Wpisz poprawne hasło, żeby potwierdzić usunięcie konta.', $html);

        $this->assertContains('f-password', $this->celePodsumowania($html));
        $pole = (new DOMXPath($sekcja->ownerDocument))->query('.//input[@id="f-password"]', $sekcja);
        $this->assertSame(1, $pole->length, 'Odnośnik z podsumowania nie prowadzi do pola hasła w otwartej sekcji.');

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
    }

    public function test_po_braku_potwierdzenia_sekcja_jest_otwarta(): void
    {
        $html = $this->poBledzie($this->user('basia'), ['password' => 'haslo-testowe-123']);

        $this->assertTrue($this->sekcjaUsuniecia($html)->hasAttribute('open'));
        $this->assertContains('f-confirm', $this->celePodsumowania($html));
    }

    public function test_blad_haczyka_zakresu_prowadzi_do_istniejacego_pola(): void
    {
        // Pole ma id `f-usun-tresci` (z myślnikiem); domyślny cel z nazwy
        // pola byłby `#f-usun_tresci`, którego na stronie nie ma.
        $html = $this->poBledzie($this->user('basia'), [
            'password' => 'haslo-testowe-123', 'confirm' => '1', 'usun_tresci' => 'nie-wiem',
        ]);

        $sekcja = $this->sekcjaUsuniecia($html);
        $this->assertTrue($sekcja->hasAttribute('open'));
        $this->assertContains('f-usun-tresci', $this->celePodsumowania($html));
        $this->assertSame(1, (new DOMXPath($sekcja->ownerDocument))->query('.//input[@id="f-usun-tresci"]', $sekcja)->length);
    }
}
