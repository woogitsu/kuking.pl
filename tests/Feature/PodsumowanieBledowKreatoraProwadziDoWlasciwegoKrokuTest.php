<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Issue #747 — podsumowanie błędów kreatora odsyłało do pól z kroku, który
 * nie jest aktualnie wyrenderowany. `href="#f-..."` prowadzi donikąd, gdy
 * cel nie istnieje w bieżącym HTML: `back()` cofa krok BEZWARUNKOWO
 * (`saveDraft()` woła `validateRows(changeStep: false)`), więc błąd z kroku
 * 3 potrafi zostać widoczny, gdy człowiek patrzy już na krok 2.
 *
 * Test sprawdza logikę po stronie komponentu Livewire — `stepForKey()`,
 * `jumpToError()` i poprawkę `publish()` — czyli DOKŁADNIE to, co decyduje
 * o docelowym kroku i o tym, że przeglądarka dostanie prośbę o fokus.
 * Nie zastępuje to próby w prawdziwej przeglądarce (klik, przełączenie
 * kroku, fokus na polu) — tej w tej sesji nie wykonano.
 */
class PodsumowanieBledowKreatoraProwadziDoWlasciwegoKrokuTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    public function test_powrot_z_bledem_kroku_trzeciego_zostawia_blad_widoczny_z_kroku_drugiego(): void
    {
        $user = $this->user('powrot_kreator');

        $component = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('title', 'Zupa na regresję')
            ->call('next')
            ->assertSet('step', 2)
            ->call('next')
            ->assertSet('step', 3)
            ->set('steps.0.instruction', str_repeat('a', 4001))
            ->call('back');

        // `back()` schodzi na krok 2 bezwarunkowo, mimo że błąd dotyczy
        // pola z kroku 3 — to jest dokładny scenariusz ze zgłoszenia.
        $component->assertSet('step', 2)
            ->assertHasErrors('steps.0.instruction');

        // Podsumowanie błędów w tym stanie MUSI wiedzieć, że pole jest na
        // kroku 3, nie na kroku 2 — inaczej link byłby zwykłym `<a href>`
        // do celu, którego w HTML w ogóle nie ma.
        $this->assertSame(3, $this->krokDla($component, 'steps.0.instruction'));

        // Kliknięcie takiego odnośnika w prawdziwym widoku wywołuje
        // `jumpToError()` — przełącza krok i prosi przeglądarkę o fokus na
        // właściwym polu.
        $component->call('jumpToError', 'steps.0.instruction')
            ->assertSet('step', 3)
            ->assertDispatched('kreator-fokus-pole', pole: 'f-steps-0-instruction');
    }

    public function test_stepforkey_mapuje_klucze_bledow_na_kroki(): void
    {
        $user = $this->user('mapa_krokow');

        $component = Livewire::actingAs($user)->test(self::COMPONENT);

        $this->assertSame(1, $this->krokDla($component, 'title'));
        $this->assertSame(1, $this->krokDla($component, 'heroPhoto'));
        $this->assertSame(2, $this->krokDla($component, 'ingredients.0.text'));
        $this->assertSame(3, $this->krokDla($component, 'steps'));
        $this->assertSame(3, $this->krokDla($component, 'steps.2.instruction'));
        $this->assertSame(3, $this->krokDla($component, 'steps.0.photo'));
        $this->assertSame(4, $this->krokDla($component, 'publikacja'));
    }

    /**
     * `storePendingPhotos()` wywołane z `publish()` w PRAWDZIWEJ przeglądarce
     * niemal zawsze zastaje już przetworzony plik: Livewire wysyła wybrane
     * zdjęcie od razu przy wyborze, więc `updated()` → `saveDraft()` →
     * `storePendingPhotos()` kończy sprawę (błędem albo sukcesem) w OSOBNYM
     * żądaniu, zanim ktokolwiek kliknie „Opublikuj". Testowe `->set()`
     * odtwarza dokładnie ten sam, osobny cykl request/response — a więc NIE
     * da się nim odtworzyć stanu „plik wciąż czeka w $steps w chwili
     * wywołania publish()", zmierzone osobno w tej sesji.
     *
     * Dlatego oba testy niżej wywołują `publish()` wprost na instancji
     * komponentu, z plikiem wstrzykniętym bezpośrednio do publicznej
     * właściwości (`$steps`/`$heroPhoto`) — jedyny sposób na uruchomienie
     * PRAWDZIWEGO kodu `publish()` w stanie opisanym w issue #747, bez
     * przechodzenia przez serializację Livewire, która ten stan zaciera.
     */
    public function test_odrzucone_zdjecie_kroku_przy_publikacji_wraca_na_krok_trzeci_nie_pierwszy(): void
    {
        Storage::fake('public');
        $user = $this->user('odrzucone_zdjecie_kroku');

        // Rozmiar i rozszerzenie przechodzą walidację uploadu Livewire
        // (config/livewire.php); treść nie jest prawdziwym zdjęciem, więc
        // StoreUploadedImage::handle() odrzuci go dopiero po stronie Kuking.
        $zepsuty = UploadedFile::fake()->create('krok.jpg', 5);

        $c = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('title', 'Przepis z odrzuconym zdjęciem kroku')
            ->set('steps.0.instruction', 'Podsmaż cebulę.');

        [$krok, $bledy] = $this->opublikujZeZdjeciem($c, 'krok', $zepsuty);

        // Błąd wisi przy `steps.0.photo`, które jest TYLKO na kroku 3.
        // Stałe „krok 1" z issue #747 pokazywałoby puste zdjęcie główne
        // i żaden widoczny komunikat błędu.
        $this->assertSame(3, $krok, 'Błąd steps.0.photo istnieje tylko na kroku 3.');
        $this->assertTrue($bledy->has('steps.0.photo'));
    }

    public function test_odrzucone_zdjecie_glowne_przy_publikacji_nadal_wraca_na_krok_pierwszy(): void
    {
        Storage::fake('public');
        $user = $this->user('odrzucone_zdjecie_glowne');

        $zepsuty = UploadedFile::fake()->create('danie.jpg', 5);

        $c = Livewire::actingAs($user)
            ->test(self::COMPONENT)
            ->set('title', 'Przepis z odrzuconym zdjęciem głównym')
            ->set('steps.0.instruction', 'Podsmaż cebulę.');

        [$krok, $bledy] = $this->opublikujZeZdjeciem($c, 'glowne', $zepsuty);

        // Kontrola: ta ścieżka MIAŁA już działać poprawnie przed poprawką —
        // zdjęcie główne jest jedynym polem na kroku 1, więc stała „krok 1"
        // przypadkiem trafiała. Test pilnuje, żeby refaktor jej nie popsuł.
        $this->assertSame(1, $krok);
        $this->assertTrue($bledy->has('heroPhoto'));
    }

    /**
     * Kreator to anonimowa klasa komponentu z pliku Blade — analiza nie ma
     * skąd znać jego metod i pól, więc test sprawdza je jawnie, zanim ich
     * użyje (issue #1731). Zmiana nazwy w komponencie daje wtedy zdanie,
     * a nie „Call to undefined method".
     */
    private function krokDla(Testable $component, string $klucz): int
    {
        $kreator = $component->instance();

        if (! method_exists($kreator, 'stepForKey')) {
            self::fail('Kreator nie ma już metody stepForKey() — ten test pilnuje metody, której nie ma.');
        }

        return $kreator->stepForKey($klucz);
    }

    /**
     * Wstawia plik wprost do instancji kreatora i publikuje; oddaje krok,
     * na którym kreator stanął, i błędy — z tego samego powodu co `krokDla()`
     * pola i metoda są sprawdzane jawnie.
     *
     * Wprost do instancji, a nie przez `->set()`: `set()` na polu pliku
     * przechodzi przez upload Livewire'a i wtedy krok po publikacji wychodzi
     * inny (sprawdzone) — a te testy pilnują właśnie ścieżki `publish()`.
     *
     * @param  'krok'|'glowne'  $gdzie
     * @return array{0: mixed, 1: MessageBag}
     */
    private function opublikujZeZdjeciem(Testable $component, string $gdzie, UploadedFile $plik): array
    {
        $kreator = $component->instance();

        if (! property_exists($kreator, 'step') || ! property_exists($kreator, 'steps')
            || ! property_exists($kreator, 'heroPhoto') || ! method_exists($kreator, 'publish')) {
            self::fail('Kreator nie ma już pól step, steps, heroPhoto albo metody publish() — te testy pilnują czegoś, czego nie ma.');
        }

        if ($gdzie === 'glowne') {
            $kreator->heroPhoto = $plik;
        } else {
            $kreator->steps[0]['photo'] = $plik;
        }

        $kreator->publish();

        return [$kreator->step, $kreator->getErrorBag()];
    }
}
