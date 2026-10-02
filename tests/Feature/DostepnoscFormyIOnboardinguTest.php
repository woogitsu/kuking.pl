<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dostępność z audytu GPT-6 Astra: UX-001 (#2405) i UX-002 (#2406).
 *
 * #2405 — błąd walidacji wyboru formy jest programowo powiązany z każdym radiem
 * (stabilny id, `aria-describedby` z pomocą i błędem, `aria-invalid`), a ekran
 * „Gotowe” w onboardingu ma podsumowanie błędów, na które pada fokus.
 *
 * #2406 — onboarding oznacza bieżący krok: grupa z nazwą i dokładnie jeden
 * `aria-current="step"` na stronie.
 */
final class DostepnoscFormyIOnboardinguTest extends TestCase
{
    use RefreshDatabase;

    private const ID_BLEDU = 'f-form_of_address-error';

    public function test_blad_wyboru_formy_jest_powiazany_z_kazdym_radiem_w_ustawieniach(): void
    {
        $this->sprawdzPowiazanieBledu(fn (User $osoba) => $this->followingRedirects()->actingAs($osoba)
            ->from(route('settings.profile'))
            ->put(route('settings.form_of_address'), []));
    }

    public function test_blad_wyboru_formy_jest_powiazany_z_kazdym_radiem_w_onboardingu(): void
    {
        $this->sprawdzPowiazanieBledu(fn (User $osoba) => $this->followingRedirects()->actingAs($osoba)
            ->from(route('onboarding.done'))
            ->post(route('onboarding.form_of_address'), []));
    }

    public function test_bez_bledu_radia_wskazuja_tylko_pomoc_i_nie_sa_niepoprawne(): void
    {
        $osoba = $this->user('basia');
        $xpath = $this->xpath((string) $this->actingAs($osoba)->get(route('onboarding.done'))->assertOk()->getContent());

        $radia = $this->elementy($xpath, '//input[@type="radio" and @name="form_of_address"]');
        $this->assertSame(3, count($radia));
        foreach ($radia as $radio) {
            $this->assertSame('forma-zwracania-pomoc', $radio->getAttribute('aria-describedby'));
            $this->assertFalse($radio->hasAttribute('aria-invalid'));
        }
        $this->assertSame(0, $xpath->query('//*[@id="'.self::ID_BLEDU.'"]')->length);
    }

    public function test_onboarding_oznacza_dokladnie_jeden_biezacy_krok_w_nazwanej_grupie(): void
    {
        $osoba = $this->user('basia');
        $trasy = ['onboarding.interests' => 1, 'onboarding.people' => 2, 'onboarding.done' => 3];

        foreach ($trasy as $trasa => $numer) {
            $xpath = $this->xpath((string) $this->actingAs($osoba)->get(route($trasa))->assertOk()->getContent());

            $biezace = $this->elementy($xpath, '//*[@aria-current="step"]');
            $this->assertSame(1, count($biezace), "Strona {$trasa} ma ".count($biezace).' elementów z aria-current="step", powinien być jeden.');
            $this->assertSame("Krok {$numer} z 3", trim((string) preg_replace('/\s+/', ' ', $biezace[0]->textContent)));

            $grupa = $this->elementy($xpath, '//*[@role="group" and contains(@class,"wizard-steps")]');
            $this->assertSame(1, count($grupa), "Strona {$trasa} nie ma grupy kroków z role=\"group\".");
            $this->assertNotSame('', trim($grupa[0]->getAttribute('aria-label')), "Grupa kroków na {$trasa} nie ma nazwy.");
            $this->assertSame(1, count($this->elementy($xpath, '//*[@role="group" and contains(@class,"wizard-steps")]//*[@aria-current="step"]')), "Bieżący krok na {$trasa} stoi poza grupą kroków.");
        }
    }

    /** @param callable(User): TestResponse<Response> $wyslij */
    private function sprawdzPowiazanieBledu(callable $wyslij): void
    {
        $osoba = $this->user('basia');
        // Wysyłka pustego formularza i powrót na stronę formularza z błędem w sesji.
        $odpowiedz = $wyslij($osoba)->assertOk();
        $xpath = $this->xpath((string) $odpowiedz->getContent());

        $bledy = $this->elementy($xpath, '//*[@id="'.self::ID_BLEDU.'"]');
        $this->assertSame(1, count($bledy), 'Komunikat błędu wyboru formy musi mieć jeden stabilny id.');
        $this->assertStringContainsString('Zaznacz jedną z trzech odpowiedzi', $bledy[0]->textContent);

        $radia = $this->elementy($xpath, '//input[@type="radio" and @name="form_of_address"]');
        $this->assertSame(3, count($radia));
        foreach ($radia as $radio) {
            $opisy = preg_split('/\s+/', trim($radio->getAttribute('aria-describedby')));
            $this->assertContains('forma-zwracania-pomoc', $opisy, 'Radio utraciło powiązanie z pomocą.');
            $this->assertContains(self::ID_BLEDU, $opisy, 'Radio nie wskazuje komunikatu błędu przez aria-describedby.');
            $this->assertSame('true', $radio->getAttribute('aria-invalid'), 'Radio nie ma aria-invalid="true".');
        }

        // Podsumowanie błędów (na nie pada fokus, resources/js/app.js) linkuje do pierwszego radia.
        $this->assertSame(1, $xpath->query('//*[contains(@class,"error-summary") and @tabindex="-1"]//a[@href="#f-form_of_address"]')->length);
        $this->assertSame(1, $xpath->query('//input[@id="f-form_of_address" and @type="radio"]')->length);
    }

    /** @return list<DOMElement> */
    private function elementy(DOMXPath $xpath, string $zapytanie): array
    {
        $wynik = [];
        foreach ($xpath->query($zapytanie) ?: [] as $wezel) {
            if ($wezel instanceof DOMElement) {
                $wynik[] = $wezel;
            }
        }

        return $wynik;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }
}
