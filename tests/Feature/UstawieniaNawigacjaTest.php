<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Żaden ekran ustawień nie może być ślepym zaułkiem.
 *
 * CO BYŁO NIE TAK
 * W nawigacji jest JEDEN wpis „Ustawienia" i prowadzi na „Czytelność".
 * Pozostałe ekrany dało się osiągnąć wyłącznie przez odnośniki wpisywane
 * ręcznie na dole poszczególnych stron — a te były rozdane losowo:
 * „Czytelność" wymieniała trzy inne ekrany, „Prywatność" dwa, a „Profil",
 * „Tematy", „Twoje dane" i „Bezpieczeństwo" ani jednego. Z tych czterech
 * wychodziło się tylko przyciskiem „wstecz" w przeglądarce.
 *
 * Dla osoby, która nie traktuje przeglądarki jak przedłużenia ręki, ślepy
 * zaułek kończy się tak samo jak zepsuty odnośnik: rezygnacją
 * (docs/UX_50_PLUS.md).
 *
 * DLACZEGO TO MUSI BYĆ TEST, A NIE KOMPONENT I DOBRA WOLA
 * Komponent rozwiązuje problem dzisiaj. Nowy ekran ustawień jutro powstanie
 * przez skopiowanie któregoś istniejącego pliku — i albo ktoś pamięta
 * o dopisaniu go do listy, albo ekran znowu wisi bez dojścia. Ten test
 * pilnuje obu stron: że każdy ekran POKAZUJE spis i że każdy ekran JEST
 * w spisie.
 */
class UstawieniaNawigacjaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wszystkie ekrany ustawień. Nowy dopisz TUTAJ — brak wpisu w tej liście
     * jest tak samo błędem jak brak odnośnika na stronie.
     *
     * `settings.topics` → `settings.tags` (D-021): `x-ustawienia-nawigacja`
     * przestała linkować do „Twoich tematów", więc ten test — który sprawdza
     * dokładnie to, co widać w nawigacji — musi śledzić tę samą zmianę.
     * Trasa `settings.topics` fizycznie istnieje jeszcze do kolejnego etapu
     * D-021 (usunięcie Tematów), ale nie jest już częścią tego spisu.
     *
     * @return list<string>
     */
    private function ekrany(): array
    {
        return [
            'settings.profile',
            'settings.avatar',
            'settings.accessibility',
            'settings.tags',
            'settings.email',
            'settings.security',
            'settings.two_factor.edit',
            'settings.privacy',
            'settings.data',
        ];
    }

    public function test_z_kazdego_ekranu_ustawien_da_sie_dojsc_do_kazdego_innego(): void
    {
        $user = $this->user('ustawienia');

        foreach ($this->ekrany() as $obecny) {
            $html = (string) $this->actingAs($user)
                ->get(route($obecny))
                ->assertOk()
                ->getContent();

            foreach ($this->ekrany() as $inny) {
                if ($inny === $obecny) {
                    continue;
                }

                $this->assertStringContainsString(
                    'href="'.route($inny).'"',
                    $html,
                    "Ekran {$obecny} nie prowadzi do {$inny} — to ślepy zaułek.",
                );
            }
        }
    }

    public function test_biezacy_ekran_nie_jest_odnosnikiem_do_samego_siebie(): void
    {
        $user = $this->user('ustawienia');

        foreach ($this->ekrany() as $obecny) {
            $html = (string) $this->actingAs($user)
                ->get(route($obecny))
                ->assertOk()
                ->getContent();

            // Odnośnik prowadzący tam, gdzie już jesteśmy, wygląda jak
            // działający i nie robi nic — to najgorszy rodzaj przycisku.
            // Zamiast niego ma być pogrubiona nazwa z `aria-current`,
            // czyli ta sama informacja dla oka i dla czytnika ekranu.
            $this->assertStringContainsString('aria-current="page"', $html, $obecny);

            // Sam adres pojawia się w formularzach (`action`), więc pytamy
            // wprost o odnośnik w spisie, a nie o obecność adresu w HTML-u.
            $this->assertStringNotContainsString(
                '<a href="'.route($obecny).'">',
                $html,
                "Ekran {$obecny} ma w spisie odnośnik do samego siebie.",
            );
        }
    }

    public function test_spis_mowi_po_co_jest_kazdy_ekran(): void
    {
        // „Prywatność" i „Twoje dane" brzmią dla wielu osób tak samo, a różnią
        // się tym, że jedno decyduje o widoczności, a drugie kasuje konto.
        // Sama nazwa ekranu to za mało, żeby wybrać dobrze za pierwszym razem.
        $html = (string) $this->actingAs($this->user('ustawienia'))
            ->get(route('settings.accessibility'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kto widzi Twoje treści', $html);
        $this->assertStringContainsString('usunięcie konta', $html);
        $this->assertStringContainsString('wylogowanie z innych urządzeń', $html);
    }
}
