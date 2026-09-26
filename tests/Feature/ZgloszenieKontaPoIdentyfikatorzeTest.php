<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zgłoszenie KONTA wiąże się z UUID osoby, nie z nazwą (issue #1599).
 *
 * Wcześniej `ReportController::resolveTarget()` szukał celu po `username`,
 * a odnośnik „Zgłoś" na profilu niósł nazwę. Po zmianie nazwy i zajęciu
 * zwolnionej przez kogoś innego otwarty formularz zgłaszał NOWEGO
 * właściciela nazwy — osobę, której zgłaszający nigdy nie widział.
 */
class ZgloszenieKontaPoIdentyfikatorzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_zmiana_nazwy_miedzy_otwarciem_a_wyslaniem_nie_zglasza_nowego_wlasciciela_nazwy(): void
    {
        $ania = $this->user('ania', ['display_name' => 'Ania']);
        $widz = $this->user('widz');

        // Prawdziwa droga człowieka: profil → „Zgłoś" → formularz.
        $odnosnik = $this->odnosnikZgloszeniaZProfilu($widz, 'ania');
        $akcja = $this->akcjaFormularza($this->actingAs($widz)->get($odnosnik)->assertOk()->getContent());

        // Między otwarciem formularza a wysłaniem Ania zmienia nazwę,
        // a zwolnioną zajmuje ktoś inny.
        Profile::where('user_id', $ania->getKey())->update(['username' => 'ania_nowa']);
        $zenek = $this->user('ania', ['display_name' => 'Zenek']);

        $this->actingAs($widz)->post($akcja, ['reason' => array_key_first(Report::REASONS)])
            ->assertRedirect();

        $this->assertSame(1, Report::where('target_type', 'user')->count());
        $this->assertSame(1, Report::where('target_type', 'user')->where('target_id', $ania->getKey())->count());
        $this->assertSame(0, Report::where('target_id', $zenek->getKey())->count());
    }

    /** Kontrola dodatnia: stary odnośnik po nazwie nadal otwiera formularz, a zapis idzie po UUID. */
    public function test_stary_odnosnik_po_nazwie_otwiera_formularz_ktory_zapisuje_po_uuid(): void
    {
        $ania = $this->user('ania', ['display_name' => 'Ania']);
        $widz = $this->user('widz');

        $html = $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'user', 'id' => 'ania']))
            ->assertOk()->assertSee('profil osoby Ania')->getContent();

        $akcja = $this->akcjaFormularza($html);
        $this->assertSame(route('reports.store', ['type' => 'user', 'id' => $ania->getKey()]), $akcja);

        $this->actingAs($widz)->post($akcja, ['reason' => array_key_first(Report::REASONS)])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Report::where('target_type', 'user')->where('target_id', $ania->getKey())->count());
    }

    public function test_wyslanie_pod_nazwa_nie_zapisuje_zgloszenia_i_zachowuje_tekst(): void
    {
        $this->user('ania', ['display_name' => 'Ania']);
        $widz = $this->user('widz');

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'user', 'id' => 'ania']), [
                'reason' => array_key_first(Report::REASONS),
                'details' => 'Wyłudza pieniądze w wiadomościach.',
            ])
            ->assertRedirect(route('reports.create', ['type' => 'user', 'id' => 'ania']))
            ->assertSessionHasErrors(['reason' => 'Sprawdź, czy to na pewno ta osoba, i wyślij zgłoszenie jeszcze raz. Wpisany tekst nie zginął.'])
            ->assertSessionHasInput('details', 'Wyłudza pieniądze w wiadomościach.');

        $this->assertSame(0, Report::count());
    }

    /** UUID w adresie to nie autoryzacja — Policy nadal rozstrzyga widoczność. */
    public function test_uuid_konta_niewidocznego_dla_zglaszajacego_daje_404(): void
    {
        $ania = $this->user('ania');
        $widz = $this->user('widz');
        app(BlockUser::class)->handle($ania, $widz);

        $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'user', 'id' => $ania->getKey()]))
            ->assertNotFound();
        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'user', 'id' => $ania->getKey()]), ['reason' => array_key_first(Report::REASONS)])
            ->assertNotFound();

        $this->assertSame(0, Report::count());
    }

    public function test_uuid_bez_konta_daje_404(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'user', 'id' => '00000000-0000-4000-8000-000000000000']))
            ->assertNotFound();
    }

    private function odnosnikZgloszeniaZProfilu(User $widz, string $username): string
    {
        $xpath = $this->xpath($this->actingAs($widz)->get(route('profile.show', $username))->assertOk()->getContent());
        $prefiks = url('/zglos/user/');
        foreach ($xpath->query('//a[normalize-space(.)="Zgłoś"]/@href') as $href) {
            if (str_starts_with($href->nodeValue, $prefiks)) {
                return $href->nodeValue;
            }
        }
        $this->fail('Profil nie ma odnośnika „Zgłoś" do zgłoszenia konta.');
    }

    private function akcjaFormularza(string $html): string
    {
        $akcja = $this->xpath($html)->query('//form[contains(@action,"/zglos/user/")]/@action')->item(0)?->nodeValue;
        $this->assertNotNull($akcja, 'Brak formularza zgłoszenia konta.');

        return $akcja;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
