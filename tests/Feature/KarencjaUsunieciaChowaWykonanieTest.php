<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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
 * `CookedEventPolicy::view()` nie patrzyła wtedy na status kucharza — dla
 * konta ZBANOWANEGO świadomie, do czasu decyzji produktowej (zapadła w D-261,
 * audyt A5-07). Ale ta sama cisza obejmowała po drodze
 * `pending_delete`, gdzie serwis obiecuje coś dokładnie przeciwnego —
 * `settings/data.blade.php` mówi człowiekowi: „Konto zniknie ze strony
 * OD RAZU". Ban jest karą wymierzoną przez nas; karencja jest decyzją tej
 * osoby, podjętą na podstawie tego zdania.
 *
 * CO TEN PLIK PILNUJE — CZTERY WŁASNOŚCI, NIE JEDNA
 * Sama asercja „obcy dostaje odmowę" jest bezwartościowa bez kontroli, że
 * przed zgłoszeniem usunięcia dostawał 200: inaczej test byłby zielony także
 * wtedy, gdyby trasa była zepsuta dla wszystkich i zawsze. Dlatego są tu
 * kilka testów. Od D-261 (audyt A5-07) tę samą granicę ma konto
 * ZBANOWANE — patrz test na końcu pliku.
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
     * CO Z SAMYM KUCHARZEM — ZAŁOŻYŁEM ŹLE I TEST MNIE POPRAWIŁ.
     *
     * Pisząc tę poprawkę, zakładałem, że człowiek w karencji dalej chodzi po
     * serwisie i musi widzieć swoje treści, „bo ma N dni na zmianę zdania".
     * Nieprawda: `EnsureAccountIsActive` WYLOGOWUJE go przy pierwszym
     * żądaniu i odsyła na stronę logowania z instrukcją, jak cofnąć
     * usunięcie. Do polityki dostępu ta osoba w ogóle nie dociera.
     *
     * Ten test zostaje w tym pliku właśnie dlatego, że pytanie „a co widzi
     * sam kucharz" jest naturalne i ktoś zada je znowu. Odpowiedź brzmi:
     * nic nie widzi, bo go tu nie ma — i to jest projekt, nie usterka.
     * Droga powrotna prowadzi przez stronę cofnięcia usunięcia, nie przez
     * przeglądanie własnych wpisów.
     */
    public function test_w_karencji_kucharz_jest_wylogowany_a_nie_wpuszczany(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->markForDeletion();

        $odpowiedz = $this->actingAs($kucharz->fresh())
            ->get(route('cooked.show', $wykonanie));

        $odpowiedz->assertRedirect(route('login'));

        // `assertGuest()` bez argumentu — jego parametr to nazwa STRAŻNIKA,
        // nie komunikat. Wpisanie tam zdania po polsku pytałoby o strażnika
        // o tej nazwie i test padłby z zupełnie innego powodu.
        $this->assertGuest();

        // Sam fakt wylogowania nie wystarcza — człowiek musi wiedzieć,
        // DLACZEGO i co zrobić dalej (UX_50_PLUS.md: komunikat błędu mówi,
        // co zrobić, a nie co się stało).
        // SESJA ODDAJE TO RAZ JAKO OBIEKT, RAZ JAKO TABLICĘ — i to nie jest
        // teoria: pierwsza wersja tego testu padła na `first() on array`.
        // Ten sam rozdział robi już `PodrobionyNaglowekProxyTest`; biorę
        // stamtąd wzorzec, zamiast wymyślać drugi.
        $bledy = $odpowiedz->getSession()->get('errors');

        $tekst = match (true) {
            $bledy instanceof ViewErrorBag, $bledy instanceof MessageBag => (string) $bledy->first('login'),
            // JSON_UNESCAPED_SLASHES, bo szukamy ADRESU. Bez tego flagi
            // json_encode zamienia `http://` na `http:\/\/` i porównanie
            // z route() nigdy nie trafia — sprawdzone, tak właśnie padło.
            is_array($bledy) => (string) json_encode($bledy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => '',
        };

        $this->assertNotSame('', $tekst, 'Po wylogowaniu nie ma żadnego komunikatu.');

        $this->assertStringContainsString(
            route('account.delete.cancel'),
            $tekst,
            'Komunikat po wylogowaniu nie wskazuje strony cofnięcia usunięcia konta.',
        );
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
     * ZBANOWANY KUCHARZ — TA SAMA GRANICA (audyt A5-07, D-261).
     *
     * Do D-261 ten test pilnował czegoś odwrotnego: że zbanowany kucharz
     * przechodzi pod bezpośrednim adresem „tak samo jak przedtem", dopóki
     * decyzja produktowa nie zapadnie. Zapadła w wariancie bezpieczniejszym:
     * wykonanie zbanowanej osoby znika pod adresem tak samo jak z galerii,
     * a jej profil i przepisy — 403. Zdjęcie osobno, bo to osobna ścieżka.
     */
    public function test_zbanowany_kucharz_znika_takze_pod_bezposrednim_adresem(): void
    {
        [$kucharz, $wykonanie, $zdjecie] = $this->wykonanieZeZdjeciem();

        $kucharz->ban();

        $this->get(route('cooked.show', $wykonanie))
            ->assertForbidden();
        $this->actingAs($this->user('ktosobcy'))->get(route('cooked.show', $wykonanie))
            ->assertForbidden();
        $this->assertFalse(
            app(DostepDoZdjecia::class)->moze(null, $zdjecie),
            'Zdjęcie z wykonania zbanowanej osoby nadal przechodzi kontrolę dostępu dla gościa.',
        );
    }

    /**
     * Decyzja właściciela do D-261 (25.09.2026): „Nie, komentarze zostają”.
     * Obcy nie otworzy wykonania zbanowanej osoby, ale komentarz przez
     * `cooked.comment` dalej przechodzi (`CookedEventPolicy::comment()`).
     */
    public function test_zbanowany_kucharz_nie_zamyka_komentowania(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->ban();
        $obcy = $this->user('komentujacy');

        $this->actingAs($obcy)->get(route('cooked.show', $wykonanie))
            ->assertForbidden();
        $this->actingAs($obcy)->post(route('cooked.comment', $wykonanie), ['body' => 'Gratulacje mimo wszystko.'])
            ->assertRedirect();

        $this->assertSame(1, $wykonanie->comments()->where('author_id', $obcy->getKey())->count());
    }

    /** Kontrola dodatnia: moderator zagląda z urzędu, a po zdjęciu bana wykonanie wraca. */
    public function test_zbanowane_wykonanie_widzi_moderator_a_po_zdjeciu_bana_wszyscy(): void
    {
        [$kucharz, $wykonanie] = $this->wykonanieZeZdjeciem();

        $kucharz->ban();

        $this->actingAs($this->moderator())->get(route('cooked.show', $wykonanie))
            ->assertOk();

        $kucharz->fresh()->reinstate();
        auth()->logout();

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
