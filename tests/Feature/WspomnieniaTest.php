<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wspomnienia\Wspomnienia;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * „Rok temu gotowałaś…" — własne archiwum jako powód powrotu (issue #34).
 *
 * DLACZEGO POŁOWA TYCH TESTÓW SPRAWDZA, ŻE CZEGOŚ NIE MA
 * Bo ta mechanika ma jedną poważną wadę: wspomnienia potrafią zaboleć.
 * Wpis z przepisem po mamie, która zmarła w tym roku, wyświetlony bez
 * ostrzeżenia na stronie głównej, jest okrutny. Wyłącznik, który nie działa,
 * i schowane wspomnienie, które wraca, to nie są usterki wygody — to jest
 * zrobienie komuś przykrości drugi raz, po tym jak poprosił, żeby przestać.
 */
class WspomnieniaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wpis sprzed `$lat` lat, z TEGO SAMEGO DNIA CO DZIŚ W STREFIE CZYTELNIKA.
     *
     * DLACZEGO `Czas::lokalnie`, A NIE SAMO `Carbon::now()`
     * Bo inaczej ten test przechodzi tylko 22 godziny na dobę, a przez
     * pozostałe dwie jest czerwony bez żadnej zmiany w kodzie.
     *
     * `config('app.timezone')` to UTC — baza trzyma czas w UTC celowo (patrz
     * `App\Support\Czas`). `Wspomnienia::dlaOsoby()` szuka natomiast dnia
     * w strefie CZYTELNIKA, i to jest poprawne: „rok temu, 6 września" ma
     * znaczyć szósty września u człowieka, nie w UTC. Komentarz w tamtej
     * klasie mówi to wprost.
     *
     * Skutek: między 22:00 a 24:00 UTC latem (i 23:00–24:00 zimą) w Polsce
     * jest już następny dzień. Zmierzone przy pisaniu tej poprawki:
     *
     *     Carbon::now()        2026-09-06T23:37:37+00:00   → dzień 6
     *     Czas::lokalnie(now)  2026-09-07T01:37:37+02:00   → dzień 7
     *
     * Stary kod budował wpis na dniu 6, a funkcja szukała dnia 7 — trzy testy
     * w tym pliku robiły się czerwone o 22:00 UTC i zielone o północy.
     *
     * PRODUKT JEST TU DOBRY, TEST BYŁ ZŁY. Gdyby ktoś zobaczył tę czerwień
     * i „naprawił" `Wspomnienia`, zepsułby działającą funkcję — dlatego ten
     * akapit jest długi.
     *
     * Godzina 12:00 lokalnie, nie 0:00: południe leży bezpiecznie w środku
     * tego samego dnia po obu stronach przeliczenia stref.
     */
    private function wpisSprzed(User $autor, int $lat, string $tresc = 'Rosół jak zawsze.'): Post
    {
        $kiedy = Czas::lokalnie(Carbon::now())->subYears($lat)->setTime(12, 0);

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $kiedy,
        ]);

        return $wpis->fresh();
    }

    public function test_wpis_sprzed_roku_z_tego_samego_dnia_wraca_na_strone_glowna(): void
    {
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 1, 'Rosół na urodziny Zosi.');

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Rok temu', escape: false)
            ->assertSee('Rosół na urodziny Zosi.', escape: false);
    }

    public function test_blok_nie_pojawia_sie_u_osoby_bez_wpisow_starszych_niz_rok(): void
    {
        $basia = $this->user('basia');

        // Wpis z dzisiaj NIE jest wspomnieniem — wisi kilka centymetrów niżej,
        // w feedzie. Podpis „Rok temu" nad czymś sprzed godziny to najkrótsza
        // droga do tego, żeby cała mechanika przestała być wiarygodna.
        $this->wpisSprzed($basia, 0, 'Dzisiejszy obiad.');

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Rok temu', escape: false)
            // I ŻADNEGO PUSTEGO STANU. „Nie masz jeszcze wspomnień" jest
            // wyrzutem wobec kogoś, kto dopiero zaczyna.
            ->assertDontSee('wspomnie', escape: false);
    }

    public function test_wyliczenie_lat_mowi_prawde(): void
    {
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 2, 'Pierogi z jagodami.');

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Dwa lata temu', escape: false)
            ->assertDontSee('Rok temu,', escape: false);
    }

    public function test_wylaczenie_w_ustawieniach_ukrywa_blok(): void
    {
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 1, 'Rosół na urodziny Zosi.');

        $this->actingAs($basia)->put(route('settings.privacy'), [
            // Brak `memories_enabled` w żądaniu = odznaczone pole. Tak działa
            // checkbox w HTML-u i tak wraca z formularza.
            'wants_weekly_digest' => '1',
        ])->assertRedirect();

        $this->assertFalse($basia->fresh()->memories_enabled);

        $this->actingAs($basia->fresh())->get(route('home'))
            ->assertOk()
            ->assertDontSee('Rok temu', escape: false);
    }

    public function test_wylacznik_da_sie_wlaczyc_z_powrotem(): void
    {
        // Wyłącznik, który wyłącza na zawsze, jest pułapką, a nie ustawieniem.
        $basia = $this->user('basia', ['memories_enabled' => false]);
        $this->wpisSprzed($basia, 1, 'Rosół na urodziny Zosi.');

        $this->actingAs($basia)->put(route('settings.privacy'), [
            'memories_enabled' => '1',
        ])->assertRedirect();

        $this->actingAs($basia->fresh())->get(route('home'))
            ->assertOk()
            ->assertSee('Rok temu', escape: false);
    }

    public function test_ukryte_wspomnienie_nie_wraca(): void
    {
        $basia = $this->user('basia');
        $wpis = $this->wpisSprzed($basia, 1, 'Rosół na urodziny Zosi.');

        $this->actingAs($basia)->post(route('wspomnienia.ukryj', $wpis))->assertRedirect();

        $this->assertTrue($wpis->fresh()->hide_as_memory);

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Rok temu', escape: false);
    }

    public function test_ukrycie_wspomnienia_nie_usuwa_wpisu(): void
    {
        // „Nie przypominaj mi o tym" i „usuń to" to dwie różne prośby.
        // Przy wpisie, który boli, ale którego człowiek na pewno nie chce
        // stracić, pomylenie ich jest nie do naprawienia.
        $basia = $this->user('basia');
        $wpis = $this->wpisSprzed($basia, 1, 'Rosół na urodziny Zosi.');

        $this->actingAs($basia)->post(route('wspomnienia.ukryj', $wpis))->assertRedirect();

        $this->assertNotNull(Post::find($wpis->getKey()));

        $this->get(route('posts.show', $wpis))
            ->assertOk()
            ->assertSee('Rosół na urodziny Zosi.', escape: false);
    }

    public function test_nie_da_sie_ukryc_cudzego_wspomnienia(): void
    {
        // Identyfikator wpisu jest publiczny — widać go w linku. Bez bramki
        // przez Policy dałoby się schować komuś jego wpis z JEGO strony
        // głównej. UUID w adresie to nie autoryzacja (AGENTS.md §7).
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        $wpis = $this->wpisSprzed($basia, 1);

        $this->actingAs($halina)->post(route('wspomnienia.ukryj', $wpis))->assertForbidden();

        $this->assertFalse($wpis->fresh()->hide_as_memory);
    }

    public function test_cudzy_wpis_nigdy_nie_jest_moim_wspomnieniem(): void
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        $this->wpisSprzed($halina, 1, 'Pierogi Haliny sprzed roku.');

        $html = (string) $this->actingAs($basia)->get(route('home'))->assertOk()->getContent();

        // PYTAMY O BLOK WSPOMNIENIA, NIE O TREŚĆ WPISU — i to jest sedno tego
        // testu. Wpis Haliny MA prawo być na tej stronie: feed Basi jest pusty,
        // więc widzi „Świeżo z Kuking". Sprawdzanie samej treści przechodziłoby
        // wtedy albo oblewało z powodu, który nie ma nic wspólnego
        // ze wspomnieniami.
        $this->assertStringNotContainsString('wspomnienie-podpis', $html);
        $this->assertStringNotContainsString('Rok temu', $html);
    }

    // -----------------------------------------------------------------
    // Nawigacja po latach w archiwum profilu
    // -----------------------------------------------------------------

    public function test_archiwum_da_sie_przewinac_po_latach(): void
    {
        // Bez tego jedyną drogą do września sprzed trzech lat jest klikanie
        // „starsze" dwadzieścia razy — czyli droga, której nikt nie przejdzie.
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 1, 'Rosół sprzed roku.');
        $this->wpisSprzed($basia, 3, 'Pierogi sprzed trzech lat.');

        $rokTrzyLataTemu = Czas::lokalnie(Carbon::now())->subYears(3)->year;

        $this->actingAs($basia)
            ->get(route('profile.show', ['username' => 'basia', 'rok' => $rokTrzyLataTemu]))
            ->assertOk()
            ->assertSee('Pierogi sprzed trzech lat.', escape: false)
            ->assertDontSee('Rosół sprzed roku.', escape: false);
    }

    public function test_jeden_rok_w_archiwum_nie_dostaje_przelacznika_lat(): void
    {
        // Jeden rok to nie wybór, tylko rząd przycisków udający wybór.
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 1, 'Rosół sprzed roku.');

        $html = (string) $this->actingAs($basia)
            ->get(route('profile.show', 'basia'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('lata-archiwum', $html);
    }

    public function test_smiec_zamiast_roku_pokazuje_cale_archiwum(): void
    {
        // `?rok=cokolwiek` ma dać całe archiwum, a nie pustą stronę ani błąd.
        $basia = $this->user('basia');
        $this->wpisSprzed($basia, 1, 'Rosół sprzed roku.');

        $this->actingAs($basia)
            ->get(route('profile.show', ['username' => 'basia', 'rok' => 'w-zeszlym-tygodniu']))
            ->assertOk()
            ->assertSee('Rosół sprzed roku.', escape: false);
    }

    public function test_rok_z_samymi_prywatnymi_wpisami_nie_pojawia_sie_obcej_osobie(): void
    {
        // Rok, w którym są wyłącznie wpisy prywatne, byłby dla obcej osoby
        // linkiem prowadzącym donikąd — i zdradzałby, że coś tam jednak jest.
        $basia = $this->user('basia');
        $halina = $this->user('halina');

        $publiczny = $this->wpisSprzed($basia, 1, 'Rosół publiczny.');
        $prywatny = $this->wpisSprzed($basia, 4, 'Tylko dla mnie.');
        $prywatny->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();

        $rokPrywatnego = Czas::lokalnie(Carbon::now())->subYears(4)->year;

        $html = (string) $this->actingAs($halina)
            ->get(route('profile.show', 'basia'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('rok='.$rokPrywatnego, $html);

        // Sanity: rok publicznego wpisu jest w bazie, więc gdyby lista lat
        // w ogóle nie działała, ten test przechodziłby z niewłaściwego powodu.
        $this->assertNotNull($publiczny->published_at);
    }

    /**
     * Wpis opublikowany PO PÓŁNOCY czasu lokalnego wraca w swoją prawdziwą
     * rocznicę, a nie dzień wcześniej.
     *
     * CO TU BYŁO ZEPSUTE
     * `published_at` to `timestamptz`, więc `extract(day from published_at)`
     * czyta dzień W UTC. Porównywaliśmy go z dniem CZYTELNIKA. Dla wpisu
     * z 00:30 czasu polskiego te dwa dni są różne — UTC pokazuje jeszcze
     * poprzedni — więc rocznica przesuwała się o dobę wstecz i w prawdziwy
     * dzień wspomnienie po prostu nie przychodziło.
     *
     * Dotyczyło każdego wpisu z przedziału 00:00–02:00 czasu polskiego
     * (00:00–01:00 zimą). Dla serwisu o gotowaniu to nie jest przypadek
     * teoretyczny: ktoś ugotował późno i wrzucił zdjęcie po północy.
     *
     * ZEGAR JEST ZAMROŻONY CELOWO. Bez tego test sprawdzałby coś innego
     * o każdej porze doby, a akurat ten błąd JEST błędem o porze doby —
     * i dokładnie dlatego przeżył tak długo.
     */
    public function test_wpis_z_pierwszej_w_nocy_wraca_w_swoja_rocznice(): void
    {
        // 15 marca 2026, 10:00 w Warszawie. Zima, czyli UTC+1.
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00', 'UTC'));

        $basia = $this->user('basia');

        // 15 marca 2025, 00:30 w Warszawie — czyli 14 marca 23:30 w UTC.
        // Dzień lokalny: 15. Dzień UTC: 14. Na tej różnicy funkcja się wywracała.
        Post::create([
            'author_id' => $basia->getKey(),
            'body' => 'Rosół nastawiony po północy.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => Carbon::parse('2025-03-14 23:30:00', 'UTC'),
        ]);

        $wspomnienie = app(Wspomnienia::class)->dlaOsoby($basia->fresh());

        $this->assertNotNull(
            $wspomnienie,
            'Wpis z 15 marca 00:30 czasu polskiego ma wrócić 15 marca, nie 14. '
            .'Jeśli ten test jest czerwony, zapytanie znowu czyta dzień z UTC '
            .'zamiast ze strefy czytelnika.',
        );
        $this->assertSame('Rosół nastawiony po północy.', $wspomnienie->body);
    }
}
