<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Support\Komunikat;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Odmowa nie wygląda jak sukces (issue #988).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Layout rysował każde `session('status')` jako tę samą zieloną plakietkę
 * `.flash` — także „Logowanie linkiem jest teraz wyłączone" albo „nic nie
 * usunęliśmy". Tekst mówił „nie", wygląd mówił „udało się".
 *
 * Test czyta DOM (klasa rodzaju, widoczna etykieta, rola), nie same
 * stringi w sesji — sesja była poprawna także przed poprawką.
 *
 * Kontrola ujemna: `rodzajZSesji()` oddające zawsze `SUKCES` albo
 * przywrócenie gołego `->with('status', …)` przy wyłączonym logowaniu
 * linkiem oblewa scenę odmowy.
 */
class RodzajKomunikatuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{rodzaj: string, klasa: string, etykieta: string, tresc: string, rola: string} */
    private function plakietka(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $ramki = (new DOMXPath($dom))->query('//div[contains(concat(" ", @class, " "), " flash-ramka ")]');
        $this->assertSame(1, $ramki->length, 'Na stronie ma być dokładnie jedna plakietka komunikatu.');

        /** @var DOMElement $ramka */
        $ramka = $ramki->item(0);
        $xpath = new DOMXPath($dom);
        $etykieta = $xpath->query('.//p[@class="flash-etykieta"]', $ramka);
        $tresc = $xpath->query('.//p[@class="flash"]', $ramka);
        $this->assertSame(1, $etykieta->length, 'Plakietka nie ma widocznej etykiety rodzaju.');
        $this->assertSame(1, $tresc->length, 'Plakietka nie ma akapitu z treścią.');

        $kontener = $ramka->parentNode;
        $this->assertInstanceOf(DOMElement::class, $kontener);
        $this->assertSame('polite', $kontener->getAttribute('aria-live'), 'Komunikaty straciły obszar aria-live.');

        return [
            'rodzaj' => $ramka->getAttribute('data-rodzaj-komunikatu'),
            'klasa' => $ramka->getAttribute('class'),
            'etykieta' => trim($etykieta->item(0)->textContent),
            'tresc' => trim($tresc->item(0)->textContent),
            'rola' => $ramka->getAttribute('role'),
        ];
    }

    /** Kontrakt: każdy rodzaj ma własną klasę, etykietę słowną i rolę. */
    public function test_kazdy_rodzaj_ma_klase_etykiete_i_role(): void
    {
        $oczekiwane = [
            Komunikat::SUKCES => ['flash-ramka-sukces', 'Gotowe', ''],
            Komunikat::INFORMACJA => ['flash-ramka-informacja', 'Informacja', ''],
            Komunikat::BLAD => ['flash-ramka-blad', 'Nie udało się', 'alert'],
        ];

        foreach ($oczekiwane as $rodzaj => [$klasa, $etykieta, $rola]) {
            $html = $this->withSession(['status' => 'Treść próbna.', Komunikat::KLUCZ_RODZAJU => $rodzaj])
                ->get(route('login'))->assertOk()->getContent();

            $p = $this->plakietka($html);
            $this->assertSame($rodzaj, $p['rodzaj']);
            $this->assertStringContainsString($klasa, $p['klasa'], "Rodzaj {$rodzaj}: zła klasa wyglądu.");
            $this->assertSame($etykieta, $p['etykieta'], "Rodzaj {$rodzaj}: zła widoczna etykieta.");
            $this->assertSame($rola, $p['rola'], "Rodzaj {$rodzaj}: zła rola.");
            $this->assertSame('Treść próbna.', $p['tresc']);
        }

        // Etykiety różnią się słowem, nie tylko kolorem (WCAG 1.4.1).
        $this->assertCount(3, array_unique(Komunikat::ETYKIETY));
    }

    /** Stare wywołania bez rodzaju i śmieć w sesji wyglądają jak dotąd — sukces. */
    public function test_brak_albo_nieznany_rodzaj_to_sukces(): void
    {
        foreach ([[], [Komunikat::KLUCZ_RODZAJU => 'zielony'], [Komunikat::KLUCZ_RODZAJU => ['blad']]] as $dodatek) {
            $html = $this->withSession(['status' => 'Zapisane.'] + $dodatek)->get(route('login'))->getContent();

            $this->assertSame(Komunikat::SUKCES, $this->plakietka($html)['rodzaj']);
        }
    }

    /** Prawdziwa odmowa z drogi logowania: wyłączony link nie świeci na zielono. */
    public function test_odmowa_logowania_linkiem_ma_rodzaj_bledu(): void
    {
        config(['kuking.login_link.wlaczone' => false]);

        $this->post(route('login.link.send'), ['email' => 'ktos@example.test'])
            ->assertRedirect(route('login'));

        $p = $this->plakietka($this->get(route('login'))->assertOk()->getContent());

        $this->assertSame(Komunikat::BLAD, $p['rodzaj']);
        $this->assertSame('Nie udało się', $p['etykieta']);
        $this->assertSame('alert', $p['rola']);
        $this->assertStringStartsWith('Logowanie linkiem jest teraz wyłączone.', $p['tresc']);
    }

    /** Stan, który nie wymaga naprawy, to informacja — nie sukces i nie błąd. */
    public function test_anulowanie_bez_zamowionej_zmiany_to_informacja(): void
    {
        $basia = $this->user('basia_komunikat');

        $this->actingAs($basia)->post(route('settings.email.cancel'))->assertRedirect(route('settings.email'));

        $p = $this->plakietka($this->actingAs($basia)->get(route('settings.email'))->assertOk()->getContent());

        $this->assertSame(Komunikat::INFORMACJA, $p['rodzaj']);
        $this->assertStringStartsWith('Nie było czego anulować', $p['tresc']);
    }

    /**
     * Kontrola dodatnia: sukces z przeczeniem w treści zostaje sukcesem —
     * rodzaju nie zgadujemy po słowie „nie".
     */
    public function test_sukces_z_przeczeniem_zostaje_sukcesem(): void
    {
        $basia = $this->user('basia_tag');
        $tag = Tag::factory()->create();

        $this->actingAs($basia)->from(route('home'))->delete(route('tags.unfollow', $tag))->assertRedirect(route('home'));

        $p = $this->plakietka($this->actingAs($basia)->get(route('home'))->assertOk()->getContent());

        $this->assertSame(Komunikat::SUKCES, $p['rodzaj']);
        $this->assertSame('Gotowe', $p['etykieta']);
        $this->assertStringStartsWith('Nie obserwujesz już tagu', $p['tresc']);
    }
}
