<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cooking\Actions\RecordCookedEvent;
use App\Domain\Media\DostepDoZdjecia;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KARENCJA USUNIĘCIA KONTA CHOWA WYKONANIE TAKŻE POD BEZPOŚREDNIM ADRESEM.
 *
 * SKĄD TO ZNALEZISKO (G01, audyt zewnętrzny z 8 września 2026)
 * Audyt zmierzył uruchomieniem, że wykonanie osoby w stanie `pending_delete`
 * znika z list i galerii, ale `cooked.show` oddaje je anonimowo dalej:
 * notatkę, zdjęcie i nazwę. Potwierdziłem to czytaniem kodu przed poprawką.
 *
 * DLACZEGO TO NIE JEST DROBIAZG — RÓŻNICA MIĘDZY BANEM A KARENCJĄ
 * `CookedEventPolicy::view()` CELOWO nie patrzy na status kucharza i dla
 * konta ZBANOWANEGO jest to przemyślana decyzja (D-018/D-022: treść zostaje,
 * znika tylko wyróżnienie). Ale ta sama cisza obejmowała po drodze
 * `pending_delete`, gdzie serwis obiecuje coś dokładnie przeciwnego —
 * `settings/data.blade.php` mówi człowiekowi: „Konto zniknie ze strony
 * OD RAZU". Ban jest karą wymierzoną przez nas; karencja jest decyzją tej
 * osoby, podjętą na podstawie tego zdania.
 *
 * CO TEN PLIK PILNUJE — CZTERY WŁASNOŚCI, NIE JEDNA
 * Sama asercja „obcy dostaje odmowę" jest bezwartościowa bez kontroli, że
 * przed zgłoszeniem usunięcia dostawał 200: inaczej test byłby zielony także
 * wtedy, gdyby trasa była zepsuta dla wszystkich i zawsze. Dlatego są tu
 * cztery testy, w tym jeden, który celowo NIE POZWALA naprawić za dużo.
 */
class KarencjaUsunieciaChowaWykonanieTest extends TestCase
{
    use RefreshDatabase;

    public function test_kontrola_przed_zgloszeniem_usuniecia_obcy_widzi_wykonanie(): void
    {
        [, $wykonanie] = $this->wykonanieZeZdjeciem();

        $this->get(route('cooked.show', $wykonanie))
            ->assertOk();
    }

    public function test_w_karencji_obcy_nie_widzi_wykonania_pod_bezposrednim_adresem(): void
    {
        [$kucharz, $wykonanie, $zdjecie] = $this->wykonanieZeZdjeciem();

        $kucharz->markForDeletion();

        $this->get(route('cooked.show', $wykonanie))
            ->assertForbidden();

        // ZDJĘCIE OSOBNO, BO TO OSOBNA ŚCIEŻKA DOSTĘPU. Notatkę oddaje
        // kontroler, a bajty zdjęcia — `DostepDoZdjecia`. Obie pytają tej
        // samej Policy, ale gdyby kiedyś przestały, człowiek w karencji
        // dowiedziałby się o tym z cudzego linku do swojego obiadu.
        $this->assertFalse(
            app(DostepDoZdjecia::class)->moze(null, $zdjecie),
            'Zdjęcie z wykonania osoby w karencji usunięcia konta nadal przechodzi kontrolę '
            .'dostępu dla niezalogowanego.',
        );
    }

    /**
     * KUCHARZ MUSI WIDZIEĆ SWOJE — I TO NIE JEST UPRZEJMOŚĆ.
     *
     * Karencja istnieje po to, żeby dało się zmienić zdanie. Człowiek, który
     * rozważa powrót, ma prawo zobaczyć, co odzyskuje. Gdyby poprawka
     * odcięła także jego, „przez N dni możesz jeszcze zmienić zdanie"
     * stałoby się wyborem w ciemno.
     */
    public function test_w_karencji_kucharz_nadal_widzi_wlasne_wykonanie(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->markForDeletion();

        $this->actingAs($kucharz->fresh())
            ->get(route('cooked.show', $wykonanie))
            ->assertOk();
    }

    public function test_po_cofnieciu_usuniecia_wykonanie_wraca_dla_wszystkich(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->markForDeletion();
        $kucharz->fresh()->cancelDeletion();

        $this->get(route('cooked.show', $wykonanie))
            ->assertOk();
    }

    /**
     * GRANICA POPRAWKI — TEN TEST MA PILNOWAĆ, ŻEBY NIE NAPRAWIĆ ZA DUŻO.
     *
     * Kuszące jest zamienić warunek na `dostepnyJakoAutor()` i „załatwić
     * wszystkie statusy naraz". To byłaby cicha zmiana D-018/D-022:
     * rozstrzygnięcie, ile z historii ZBANOWANEGO konta zostaje publiczne,
     * jest decyzją produktową, której nikt jeszcze nie podjął. Dopóki nie
     * zapadnie, zbanowany kucharz ma przechodzić tędy tak samo jak przedtem.
     */
    public function test_zbanowany_kucharz_przechodzi_tak_samo_jak_przedtem(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->ban();

        $this->get(route('cooked.show', $wykonanie))
            ->assertOk();
    }

    /**
     * Wykonanie ze zdjęciem pod opublikowanym, publicznym przepisem.
     *
     * @return array{0: User, 1: CookedEvent, 2: Media}
     */
    private function wykonanieZeZdjeciem(): array
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharka');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'title' => 'Rosół na niedzielę',
        ]);

        $wykonanie = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $przepis,
            note: 'Wyszedł klarowny, robię znowu.',
        );

        $zdjecie = Media::factory()->create(['owner_id' => $kucharz->getKey()]);
        $wykonanie->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$kucharz, $wykonanie->fresh(), $zdjecie];
    }
}
