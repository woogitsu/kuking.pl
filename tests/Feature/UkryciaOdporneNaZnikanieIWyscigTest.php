<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Hide;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Przegląd łańcucha #1781 — poprawki „Ukryj ten wpis" / „Ukryj tę osobę"
 * (issue #1810, D-278).
 *
 * Kontrole ujemne (każda czerwieni wskazany test):
 *  - `$ukrycie->post->body` bez sprawdzenia w widoku listy → 500 w
 *    `test_usuniety_ukryty_wpis_nie_psuje_listy_ukrytych`;
 *  - `widoczneWpisy()` zwracające wszystkie identyfikatory → treść wpisu
 *    prywatnego i od blokującej osoby na liście i w eksporcie;
 *  - `insertOrIgnore` zamienione na zwykły zapis modelu → wyjątek unikalności
 *    w `test_podwojny_klik_ukrycia_nie_daje_bledu`;
 *  - `bezKursora()` zdjęte z odpowiedzi → przekierowanie z kursorem;
 *  - `->endOfDay()` zdjęte z `Hide::domyslnyTermin()` → godzina kliknięcia.
 */
class UkryciaOdporneNaZnikanieIWyscigTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, string $tresc, array $inne = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'published_at' => now()->subMinutes(5),
            ...$inne,
        ]);
    }

    public function test_usuniety_ukryty_wpis_nie_psuje_listy_ukrytych(): void
    {
        $widz = $this->user('widz');
        $wpis = $this->wpis($this->user('autorka'), 'Bigos do usunięcia');
        $this->actingAs($widz)->post(route('posts.hide', $wpis));
        $ukrycie = Hide::query()->firstOrFail();

        // Autorka usuwa wpis — soft delete, wiersz `hides` zostaje.
        $wpis->delete();
        $this->assertNotNull($ukrycie->fresh(), 'Kontrola: ukrycie usuniętego wpisu zostaje w bazie.');

        $this->get(route('settings.hidden'))
            ->assertOk()
            ->assertSee('Ten wpis jest już niedostępny.')
            ->assertDontSee('Bigos do usunięcia')
            ->assertSee('Przywróć')
            ->assertDontSee('Zostaw ukryte');

        $this->delete(route('settings.hidden.restore', $ukrycie))->assertSessionHasNoErrors();
        $this->assertSame(0, Hide::query()->count());
    }

    public function test_lista_i_eksport_nie_pokazuja_tresci_wpisu_ktorego_widz_juz_nie_widzi(): void
    {
        $widz = $this->user('widz');
        $jawny = $this->wpis($this->user('jawna'), 'Pierogi widoczne dalej');
        $prywatny = $this->wpis($this->user('zamyka'), 'Zupa zamknięta później');
        $blokujaca = $this->user('blokujaca', ['display_name' => 'Pani Blokująca']);
        $odBlokujacej = $this->wpis($blokujaca, 'Placek od blokującej');

        $this->actingAs($widz);
        foreach ([$jawny, $prywatny, $odBlokujacej] as $wpis) {
            $this->post(route('posts.hide', $wpis))->assertSessionHasNoErrors();
        }
        $this->assertSame(3, Hide::query()->count());

        // Po ukryciu: wpis staje się prywatny, a autorka blokuje widza.
        $prywatny->forceFill(['visibility' => Post::VISIBILITY_PRIVATE])->save();
        $blokujaca->blocking()->attach($widz->getKey());

        $this->get(route('settings.hidden'))
            ->assertOk()
            ->assertSee('Pierogi widoczne dalej')
            ->assertDontSee('Zupa zamknięta później')
            ->assertDontSee('Placek od blokującej')
            ->assertDontSee('Pani Blokująca')
            ->assertSee('Ten wpis jest już niedostępny.');

        $paczka = app(CollectUserExportData::class)->handle($widz->fresh(), new ExportPhotoPlan($widz->fresh()), now());
        $wpisy = collect($paczka['ukryte'])->where('co', 'wpis')->pluck('wpis')->keyBy('adres');

        $this->assertSame('Pierogi widoczne dalej', $wpisy[route('posts.show', $jawny->id)]['poczatek']);
        $this->assertTrue($wpisy[route('posts.show', $jawny->id)]['dostepny']);
        foreach ([$prywatny, $odBlokujacej] as $wpis) {
            $this->assertNull($wpisy[route('posts.show', $wpis->id)]['poczatek'], 'Eksport wydaje treść wpisu niewidocznego dla widza.');
            $this->assertFalse($wpisy[route('posts.show', $wpis->id)]['dostepny']);
        }
    }

    /**
     * Dwa kliknięcia naraz: oba żądania nie znajdują wiersza, oba wstawiają.
     * Symulujemy to wstawieniem „cudzego" wiersza tuż przed `INSERT` akcji.
     */
    public function test_podwojny_klik_ukrycia_nie_daje_bledu(): void
    {
        $widz = $this->user('widz');
        $wpis = $this->wpis($this->user('autorka'), 'Kapusta klikana dwa razy');
        $wstawiony = false;

        DB::connection()->beforeExecuting(function (string $sql) use (&$wstawiony, $widz, $wpis): void {
            if ($wstawiony || ! str_starts_with(strtolower(ltrim($sql)), 'insert into "hides"')) {
                return;
            }

            $wstawiony = true;
            DB::table('hides')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $widz->getKey(),
                'post_id' => $wpis->getKey(),
                'hidden_until' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($widz)->from(route('home'))
            ->post(route('posts.hide', $wpis))
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertTrue($wstawiony, 'Kontrola: wyścig naprawdę zaszedł.');
        $this->assertSame(1, Hide::query()->count());
    }

    public function test_drugi_klik_nie_skraca_ukrycia_na_stale(): void
    {
        $widz = $this->user('widz');
        $wpis = $this->wpis($this->user('autorka'), 'Na stałe');
        $this->actingAs($widz)->post(route('posts.hide', $wpis));
        $ukrycie = Hide::query()->firstOrFail();
        $this->patch(route('settings.hidden.keep', $ukrycie));

        $this->post(route('posts.hide', $wpis))->assertSessionHasNoErrors();

        $this->assertNull($ukrycie->fresh()->hidden_until);
        $this->assertSame(1, Hide::query()->count());
    }

    public function test_po_ukryciu_wraca_na_pierwsza_strone_listy_bez_kursora(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        $wpis = $this->wpis($autorka, 'Wpis z dalszej strony');

        $this->actingAs($widz)
            ->from(route('discover').'?cursor=abc&stan=1790000000&widok=lista')
            ->post(route('posts.hide', $wpis))
            ->assertRedirect(route('discover').'?widok=lista');

        $this->post(route('social.hide', $autorka->profile->username), [
            'oczekiwany_id' => $autorka->getKey(),
            'wroc' => '/odkryj?cursor=abc&stan=1790000000',
        ])->assertRedirect('/odkryj');
    }

    public function test_ukrycie_trwa_do_konca_dnia_z_komunikatu(): void
    {
        // 00:30 w Warszawie 27 września = 22:30 UTC dzień wcześniej.
        $this->travelTo(Carbon::parse('2026-09-26 22:30:00', 'UTC'));
        $widz = $this->user('widz');
        $wpis = $this->wpis($this->user('autorka'), 'Do końca dnia');

        $this->actingAs($widz)->post(route('posts.hide', $wpis))
            ->assertSessionHas('status', 'Ukryliśmy ten wpis tylko dla Ciebie do '
                .Czas::data(Carbon::parse('2026-10-27 12:00:00', 'Europe/Warsaw')).'. Inni widzą go jak dotąd.');

        $termin = Hide::query()->firstOrFail()->hidden_until;
        $this->assertSame('2026-10-27 23:59:59', Czas::lokalnie($termin)->format('Y-m-d H:i:s'));
    }

    public function test_pokaz_na_zwinietej_karcie_jest_przyciskiem_48_px(): void
    {
        $widz = $this->user('widz');
        $wpis = $this->wpis($this->user('autorka'), 'Zwinięta karta');
        $this->actingAs($widz)->post(route('posts.hide', $wpis));

        $html = (string) $this->get(route('posts.show', $wpis))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $linki = (new \DOMXPath($dom))->query('//article[@data-wpis-ukryty]//a[contains(@href, "pokaz=1")]');

        $this->assertSame(1, $linki->length, 'Kontrola: zwinięta karta ma „Pokaż”.');
        $this->assertContains('btn', preg_split('/\s+/', (string) $linki->item(0)->getAttribute('class')), '„Pokaż” bez klasy przycisku — cel mniejszy niż 48 px.');
    }

    public function test_odmowa_ukrycia_widac_na_strumieniu(): void
    {
        $widz = $this->user('widz');
        $wlasny = $this->wpis($widz, 'Mój własny obiad');

        $this->actingAs($widz)->from(route('home'))->followingRedirects()
            ->post(route('posts.hide', $wlasny))
            ->assertOk()
            ->assertSee('data-blad-akcji="ukrycie"', false)
            ->assertSee('Własnego wpisu nie ukrywasz');
    }
}
