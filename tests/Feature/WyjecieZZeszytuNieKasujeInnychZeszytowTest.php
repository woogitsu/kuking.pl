<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Usuń z zeszytu" zdejmuje z JEDNEGO zeszytu, a nie ze wszystkich (issue #775).
 *
 * CO SIĘ DZIAŁO
 * `SaveRecipeToCollection::remove()` chodziło po wszystkich zeszytach osoby
 * i kasowało wiersz `collection_items` w każdym z nich, a ekran przepisu
 * wysyłał DELETE bez `collection_id`, więc węższego zachowania nie dało się
 * nawet poprosić. Razem z wierszem szła kolumna `note` — notatka własna,
 * której nie ma skąd odtworzyć: ta tabela nie ma miękkiego kasowania ani
 * historii. Jedno kliknięcie, pięć zeszytów, pięć notatek w błoto, bez
 * jednego zdania ostrzeżenia. To jest wprost sprzeczne z zasadą „poprawne
 * dane nigdy nie znikają" (AGENTS.md).
 *
 * CZEGO PILNUJE TEN PLIK
 * Nie tego, że „coś zwraca przekierowanie" — tego, co ZOSTAJE W BAZIE po
 * prawdziwym żądaniu. Każda scena sprawdza wiersze i notatki w zeszytach,
 * których nikt nie kazał ruszać.
 */
class WyjecieZZeszytuNieKasujeInnychZeszytowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SCENA GŁÓWNA — wyjęcie z jednego zeszytu zostawia resztę nietkniętą.
     *
     * Przed poprawką ten test padał na pierwszej asercji o zeszycie „B":
     * zostawało w nim 0 wierszy zamiast 1.
     */
    public function test_wyjecie_ze_wskazanego_zeszytu_zostawia_pozostale_z_notatkami(): void
    {
        [$basia, $przepis, $zeszyty] = $this->przepisWTrzechZeszytach();

        $this->actingAs($basia)
            ->from(route('recipes.show', $przepis->slug))
            ->delete(route('collections.unsave', $przepis->slug), [
                'collection_id' => $zeszyty['A']->getKey(),
            ])
            ->assertRedirect();

        // Zniknął DOKŁADNIE z tego, który wskazano.
        $this->assertSame(0, $zeszyty['A']->recipes()->whereKey($przepis->getKey())->count(),
            'Przepis został we wskazanym zeszycie — wyjęcie nie zadziałało wcale.');

        // I został w pozostałych — RAZEM Z NOTATKAMI, bo o notatki tu chodzi.
        $this->assertSame(1, $zeszyty['B']->recipes()->whereKey($przepis->getKey())->count(),
            'Wyjęcie z zeszytu „A" skasowało przepis także z „B" — to jest #775.');
        $this->assertSame(1, $zeszyty['C']->recipes()->whereKey($przepis->getKey())->count(),
            'Wyjęcie z zeszytu „A" skasowało przepis także z „C" — to jest #775.');

        $this->assertSame('mniej soli', $this->notatka($zeszyty['B'], $przepis),
            'Notatka w zeszycie „B" zginęła przy wyjmowaniu z innego zeszytu.');
        $this->assertSame('dla Ani bez orzechów', $this->notatka($zeszyty['C'], $przepis),
            'Notatka w zeszycie „C" zginęła przy wyjmowaniu z innego zeszytu.');
    }

    /**
     * SCENA DRUGA — wyjęcie szerokie zostaje możliwe, ale MÓWI, ile zabrało.
     *
     * Stare zdanie brzmiało „Usunięte z zeszytu." — w liczbie pojedynczej,
     * o operacji na wszystkich zeszytach naraz.
     */
    public function test_wyjecie_bez_wskazania_zeszytu_ujawnia_zakres(): void
    {
        [$basia, $przepis] = $this->przepisWTrzechZeszytach();

        $odpowiedz = $this->actingAs($basia)
            ->from(route('recipes.show', $przepis->slug))
            ->delete(route('collections.unsave', $przepis->slug));

        $odpowiedz->assertRedirect();

        $odpowiedz->assertSessionHas('status', function (string $tekst): bool {
            return str_contains($tekst, 'ze wszystkich Twoich zeszytów')
                && str_contains($tekst, '3')
                && str_contains($tekst, 'Nie usunęliśmy go z serwisu');
        });

        // Zakres naprawdę był szeroki — zdanie nie jest na wyrost.
        $this->assertSame(0, $this->wierszeOsoby($basia, $przepis));
    }

    /**
     * SCENA TRZECIA — droga powrotu wraca Z NOTATKAMI, do tych samych zeszytów.
     *
     * To jest ta asercja, dla której cała poprawka istnieje. Samo „zapisz
     * ponownie" dałoby jeden wiersz w zeszycie domyślnym i pustą notatkę.
     */
    public function test_droga_powrotu_przywraca_wszystkie_zeszyty_i_notatki(): void
    {
        [$basia, $przepis, $zeszyty] = $this->przepisWTrzechZeszytach();

        $this->actingAs($basia)
            ->from(route('recipes.show', $przepis->slug))
            ->delete(route('collections.unsave', $przepis->slug))
            ->assertRedirect();

        $this->assertSame(0, $this->wierszeOsoby($basia, $przepis), 'Scena nie zaczyna się od pustego stanu.');

        // Przycisk powrotu rysuje `layout.blade.php` z `status_powrot`;
        // klikamy dokładnie to, co on wysyła — sam adres i token.
        $powrot = $this->adresPowrotu($basia, $przepis);

        $this->assertSame(route('collections.save', $przepis->slug), $powrot,
            'Komunikat po wyjęciu nie daje przycisku powrotu.');

        $this->actingAs($basia)->post($powrot)->assertRedirect();

        $this->assertSame(1, $zeszyty['A']->recipes()->whereKey($przepis->getKey())->count());
        $this->assertSame(1, $zeszyty['B']->recipes()->whereKey($przepis->getKey())->count());
        $this->assertSame(1, $zeszyty['C']->recipes()->whereKey($przepis->getKey())->count());

        $this->assertSame('bez cukru', $this->notatka($zeszyty['A'], $przepis));
        $this->assertSame('mniej soli', $this->notatka($zeszyty['B'], $przepis));
        $this->assertSame('dla Ani bez orzechów', $this->notatka($zeszyty['C'], $przepis));
    }

    /**
     * SCENA CZWARTA — ekran przepisu wskazuje zeszyt, gdy jest jeden.
     *
     * Bez tego pola poprawka w akcji nie ma jak zadziałać z poziomu strony:
     * formularz wysyłałby DELETE bez zakresu i wpadał w gałąź szeroką.
     */
    public function test_ekran_przepisu_wysyla_collection_id_gdy_zeszyt_jest_jeden(): void
    {
        $basia = $this->user('basia_jeden');
        $przepis = Recipe::factory()->create();

        $this->actingAs($basia)->post(route('collections.save', $przepis->slug))->assertRedirect();
        $zeszyt = $basia->defaultCollection();

        $formularz = $this->formularzWyjecia(
            $this->actingAs($basia)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent(),
            $przepis,
        );

        $this->assertNotNull($formularz, 'Ekran przepisu nie daje formularza wyjęcia.');

        $pole = (new DOMXPath($formularz->ownerDocument))
            ->query('.//input[@name="collection_id"]', $formularz)?->item(0);

        $this->assertInstanceOf(DOMElement::class, $pole,
            'Formularz wyjęcia nie wskazuje zeszytu, choć zeszyt jest dokładnie jeden.');
        $this->assertSame((string) $zeszyt->getKey(), $pole->getAttribute('value'));
    }

    /**
     * SCENA PIĄTA — przy kilku zeszytach zakres stoi NAD przyciskiem.
     *
     * Ujawnienie po fakcie nie wystarcza: człowiek ma wiedzieć, zanim kliknie.
     */
    public function test_ekran_przepisu_ostrzega_o_zakresie_gdy_zeszytow_jest_wiecej(): void
    {
        [$basia, $przepis] = $this->przepisWTrzechZeszytach();

        $html = $this->actingAs($basia)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ze wszystkich Twoich zeszytów', $html,
            'Ekran nie mówi, że przycisk zdejmie przepis ze wszystkich zeszytów.');

        // I nie udaje, że wyjmuje z jednego wskazanego.
        $formularz = $this->formularzWyjecia($html, $przepis);
        $this->assertNotNull($formularz);
        $this->assertNull(
            (new DOMXPath($formularz->ownerDocument))->query('.//input[@name="collection_id"]', $formularz)?->item(0),
            'Formularz wskazuje jeden zeszyt, choć przepis leży w trzech.',
        );
    }

    /**
     * SCENA SZÓSTA — granica nie zrobiła się luźniejsza.
     *
     * `collection_id` z cudzego zeszytu nie może ruszyć ani cudzego wiersza,
     * ani — po cichu — wszystkich własnych (AGENTS.md §7).
     */
    public function test_cudzy_collection_id_nie_rusza_niczego(): void
    {
        [$basia, $przepis] = $this->przepisWTrzechZeszytach();
        $obca = $this->user('obca_775');
        $cudzy = $obca->collections()->create(['name' => 'Cudzy', 'visibility' => 'private']);

        $this->actingAs($basia)
            ->from(route('recipes.show', $przepis->slug))
            ->delete(route('collections.unsave', $przepis->slug), ['collection_id' => $cudzy->getKey()])
            ->assertSessionHasErrors('collection_id');

        $this->assertSame(3, $this->wierszeOsoby($basia, $przepis),
            'Cudzy identyfikator zeszytu zdjął przepis z własnych zeszytów Basi.');
    }

    /**
     * Basia, przepis i TRZY zeszyty, każdy z inną notatką.
     *
     * @return array{0: User, 1: Recipe, 2: array<string, Collection>}
     */
    private function przepisWTrzechZeszytach(): array
    {
        $basia = $this->user('basia_775');
        $przepis = Recipe::factory()->create();

        $notatki = ['A' => 'bez cukru', 'B' => 'mniej soli', 'C' => 'dla Ani bez orzechów'];
        $zeszyty = [];

        foreach ($notatki as $nazwa => $notatka) {
            $zeszyt = $basia->collections()->create(['name' => $nazwa, 'visibility' => 'private']);
            $zeszyt->recipes()->attach($przepis->getKey(), ['note' => $notatka, 'created_at' => now()]);
            $zeszyty[$nazwa] = $zeszyt;
        }

        return [$basia, $przepis, $zeszyty];
    }

    /** Notatka z pivotu — czytana z bazy, nie z modelu w pamięci. */
    private function notatka(Collection $zeszyt, Recipe $przepis): ?string
    {
        $wartosc = DB::table('collection_items')
            ->where('collection_id', $zeszyt->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->value('note');

        return $wartosc === null ? null : (string) $wartosc;
    }

    /** Ile wierszy tego przepisu leży w zeszytach TEJ osoby. */
    private function wierszeOsoby(User $osoba, Recipe $przepis): int
    {
        return (int) DB::table('collection_items')
            ->join('collections', 'collections.id', '=', 'collection_items.collection_id')
            ->where('collections.owner_id', $osoba->getKey())
            ->where('collection_items.recipe_id', $przepis->getKey())
            ->count();
    }

    /** Adres, który wysyła przycisk „Przywróć do zeszytu" z komunikatu. */
    private function adresPowrotu(User $osoba, Recipe $przepis): ?string
    {
        $html = $this->actingAs($osoba)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $formularz = $this->element($html, '//form[@class="flash-powrot"]');

        return $formularz?->getAttribute('action');
    }

    /**
     * Formularz WYJĘCIA — rozstrzyga ukryte `_method=DELETE`.
     *
     * Zapis i wyjęcie mają ten sam adres i różni je wyłącznie metoda HTTP,
     * więc selektor po samym `action` trafiałby w przycisk „Zapisuję".
     */
    private function formularzWyjecia(string $html, Recipe $przepis): ?DOMElement
    {
        return $this->element($html, sprintf(
            '//form[@action="%s"][.//input[@name="_method"][translate(@value, "delete", "DELETE")="DELETE"]]',
            route('collections.unsave', $przepis->slug),
        ));
    }

    private function element(string $html, string $wyrazenie): ?DOMElement
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $trafienie = (new DOMXPath($dokument))->query($wyrazenie)?->item(0);

        return $trafienie instanceof DOMElement ? $trafienie : null;
    }
}
