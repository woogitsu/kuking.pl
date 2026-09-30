<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zgody\ArchiwumDokumentu;
use App\Domain\Zgody\WersjaDokumentu;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Wszystkie wersje polityki prywatności (#2220; decyzja właściciela
 * z 30.09.2026, D-333: archiwum polityki tylko z czystą historią —
 * 25, 29 i 30 września 2026).
 *
 * Przed tą zmianą `/prywatnosc` pokazywała wyłącznie bieżące brzmienie:
 * wiersz `dziennik_zgod.wersja_polityki` = „2026-09-29” (D-072) mówił,
 * KTÓRĄ wersję ktoś widział przy zgodzie, ale jej treści nie dało się nigdzie
 * przeczytać ani pobrać. Wcześniejsze daty (2026-09-10) mają w historii gita
 * niespójne nagłówki, więc nie mają pliku — adres mówi, że wydajemy je na
 * prośbę, kanałem, który polityka już podaje.
 *
 * Plik czyta pliki archiwum jako tekst (porównanie z `polityka-prywatnosci.md`,
 * data w nagłówku) — kontrola dodatnia w `scripts/kontrole-negatywne-alfa08.py`.
 */
final class ArchiwumPolitykiTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1. Strażnik: bieżąca wersja ma swój plik w archiwum
    // -----------------------------------------------------------------

    public function test_biezaca_wersja_polityki_ma_w_archiwum_plik_identyczny_z_polityka(): void
    {
        $biezaca = (string) config('kuking.zgody.wersja_polityki');
        $plik = ArchiwumDokumentu::polityka()->plik($biezaca);

        $this->assertNotNull(
            $plik,
            "Brak resources/legal/archiwum/polityka-prywatnosci-{$biezaca}.md. Zmieniła się data polityki "
            ."(kuking.zgody.wersja_polityki = {$biezaca}) — skopiuj resources/legal/polityka-prywatnosci.md "
            ."do resources/legal/archiwum/polityka-prywatnosci-{$biezaca}.md. Plików starszych wersji nie ruszaj.",
        );

        $this->assertSame(
            (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md')),
            (string) file_get_contents($plik),
            "resources/legal/archiwum/polityka-prywatnosci-{$biezaca}.md różni się od resources/legal/polityka-prywatnosci.md. "
            .'Poprawka bez zmiany daty: skopiuj polityka-prywatnosci.md do pliku bieżącej wersji. Zmiana, o której '
            .'ludzie mają się dowiedzieć: podbij datę w nagłówku i w konfiguracji i dodaj NOWY plik.',
        );
    }

    public function test_poprzednia_wersja_polityki_z_konfiguracji_tez_jest_w_archiwum(): void
    {
        $poprzednia = WersjaDokumentu::polityka()->poprzednia;

        if ($poprzednia === null) {
            $this->markTestSkipped('Konfiguracja nie wskazuje poprzedniej wersji polityki.');
        }

        $this->assertNotNull(
            ArchiwumDokumentu::polityka()->plik($poprzednia),
            "kuking.zgody.zmiana_polityki.poprzednia = {$poprzednia}, a w resources/legal/archiwum/ nie ma "
            ."polityka-prywatnosci-{$poprzednia}.md. Odtwórz ten plik z historii gita.",
        );
    }

    public function test_archiwum_polityki_ma_dokladnie_wersje_z_czysta_historia(): void
    {
        // Decyzja właściciela z 30.09.2026: 25, 29 i 30 września — nic
        // wcześniejszego (niespójne nagłówki przy `wersja_polityki` 2026-09-10).
        $this->assertSame(
            ['2026-09-30', '2026-09-29', '2026-09-25'],
            ArchiwumDokumentu::polityka()->wersje(),
        );
    }

    public function test_kazdy_plik_archiwum_polityki_niesie_w_naglowku_date_ze_swojej_nazwy(): void
    {
        foreach (ArchiwumDokumentu::polityka()->wersje() as $data) {
            $this->assertTrue(
                str_contains((string) ArchiwumDokumentu::polityka()->tresc($data), 'opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($data)),
                "Plik polityka-prywatnosci-{$data}.md ma w nagłówku inną datę niż w nazwie. Nagłówek ma mówić "
                .'„opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($data).'”.',
            );
        }
    }

    public function test_archiwum_regulaminu_nie_miesza_sie_z_archiwum_polityki(): void
    {
        $this->assertNotContains('2026-09-25', ArchiwumDokumentu::regulamin()->wersje());
        $this->assertNotContains('2026-09-07', ArchiwumDokumentu::polityka()->wersje());
    }

    // -----------------------------------------------------------------
    // 2. Strony: polityka, lista, jedna wersja, pobranie
    // -----------------------------------------------------------------

    public function test_polityka_prowadzi_do_pobrania_i_wszystkich_wersji(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('href="'.route('privacy.version.download', config('kuking.zgody.wersja_polityki')).'"', false)
            ->assertSee('Pobierz politykę (plik tekstowy)')
            ->assertSee('href="'.route('privacy.versions').'"', false)
            ->assertSee('Wszystkie wersje polityki');
    }

    public function test_lista_wersji_polityki_pokazuje_kazda_date_i_mowi_o_starszych_na_prosbe(): void
    {
        $odpowiedz = $this->get('/prywatnosc/wersje')->assertOk();

        foreach (['2026-09-25' => '25 września 2026', '2026-09-29' => '29 września 2026', '2026-09-30' => '30 września 2026'] as $data => $slownie) {
            $odpowiedz->assertSee('Wersja z '.$slownie)
                ->assertSee('href="'.url("/prywatnosc/wersje/{$data}").'"', false)
                ->assertSee('href="'.url("/prywatnosc/wersje/{$data}/pobierz").'"', false);
        }

        $odpowiedz->assertSee('Obecna')
            ->assertSee('Wersje polityki prywatności sprzed 25 września 2026')
            ->assertSee('Wcześniejsze brzmienie wydajemy na prośbę')
            ->assertSee('name="robots" content="noindex, follow"', false)
            ->assertDontSee('<script', false);
    }

    public function test_kanal_prosby_to_adresy_ktore_polityka_juz_podaje(): void
    {
        $polityka = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $odpowiedz = $this->get('/prywatnosc/wersje')->assertOk();

        foreach ([config('kuking.podmiot.email'), config('kuking.community.contact_email')] as $adres) {
            $this->assertStringContainsString("**{$adres}**", $polityka, "Polityka nie podaje adresu {$adres} — strona archiwum nie może wskazywać kanału, którego polityka nie zna.");
            $odpowiedz->assertSee('href="mailto:'.$adres.'"', false);
        }
    }

    public function test_wczesniejsza_wersja_polityki_mowi_ze_nie_obowiazuje_i_pokazuje_swoja_tresc(): void
    {
        $this->get('/prywatnosc/wersje/2026-09-25')
            ->assertOk()
            ->assertSee('To jest wcześniejsza wersja polityki prywatności, z 25 września 2026. Już nie obowiązuje.')
            ->assertSee('opisuje stan serwisu na 25 września 2026')
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_obecna_wersja_polityki_mowi_ze_obowiazuje(): void
    {
        $this->get(route('privacy.version', '2026-09-30'))
            ->assertOk()
            ->assertSee('To jest obecna wersja polityki prywatności, z 30 września 2026. Obowiązuje.');
    }

    public function test_pobranie_polityki_daje_plik_tekstowy_z_trescia_wersji(): void
    {
        $odpowiedz = $this->get('/prywatnosc/wersje/2026-09-29/pobierz')->assertOk();

        $this->assertSame('text/plain; charset=utf-8', $odpowiedz->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="polityka-prywatnosci-kuking-2026-09-29.txt"', $odpowiedz->headers->get('Content-Disposition'));
        $this->assertSame('noindex', $odpowiedz->headers->get('X-Robots-Tag'));
        $this->assertSame(ArchiwumDokumentu::polityka()->tresc('2026-09-29'), $odpowiedz->getContent());
    }

    public function test_data_miedzy_wersjami_albo_bledna_daje_zwykle_404(): void
    {
        // 26 września nie było wersji polityki — to nie jest „starsza wersja”.
        $this->get('/prywatnosc/wersje/2026-09-26')->assertNotFound()->assertDontSee('wydajemy na prośbę');
        $this->get('/prywatnosc/wersje/2026-10-01')->assertNotFound()->assertDontSee('wydajemy na prośbę');
        $this->get('/prywatnosc/wersje/2026-02-30')->assertNotFound()->assertDontSee('wydajemy na prośbę');
        $this->get('/prywatnosc/wersje/..%2Fpolityka-prywatnosci')->assertNotFound();
        $this->assertNull(ArchiwumDokumentu::polityka()->plik('../polityka-prywatnosci'));
    }

    // -----------------------------------------------------------------
    // 3. Dziennik zgód: każda zapisana wersja prowadzi do treści albo prośby
    // -----------------------------------------------------------------

    public function test_wersja_polityki_z_dziennika_zgod_prowadzi_do_tresci(): void
    {
        Notification::fake();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);

        $this->post(route('register'), [
            'display_name' => 'Nowa Osoba', 'username' => 'nowaosoba',
            'email' => 'nowaosoba@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));

        $osoba = User::query()->where('email', 'nowaosoba@example.test')->firstOrFail();
        $wersja = (string) WpisZgody::query()
            ->where('user_id', $osoba->getKey())
            ->value('wersja_polityki');

        $this->assertGreaterThanOrEqual('2026-09-25', $wersja);
        $this->get(route('privacy.version', $wersja))
            ->assertOk()
            ->assertSee('opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($wersja));
    }

    public function test_wersja_polityki_sprzed_archiwum_z_dziennika_zgod_mowi_o_wydaniu_na_prosbe(): void
    {
        // 2026-09-10 to jedyna starsza wartość `kuking.zgody.wersja_polityki`
        // w historii gita — taki wiersz może leżeć w dzienniku zgód.
        foreach (['/prywatnosc/wersje/2026-09-10', '/prywatnosc/wersje/2026-09-10/pobierz'] as $adres) {
            $this->get($adres)
                ->assertNotFound()
                ->assertSee('Polityka prywatności — wersja z 10 września 2026')
                ->assertSee('Wcześniejsze brzmienie wydajemy na prośbę')
                ->assertSee('href="mailto:'.config('kuking.podmiot.email').'"', false)
                ->assertSee('href="'.route('privacy.versions').'"', false)
                ->assertSee('name="robots" content="noindex, follow"', false);
        }
    }

    public function test_regulamin_nie_wydaje_starszych_wersji_na_prosbe(): void
    {
        // Archiwum regulaminu jest pełne od pierwszej wersji (7 września 2026).
        $this->assertFalse(ArchiwumDokumentu::regulamin()->wydawanaNaProsbe('2026-09-01'));
        $this->get('/regulamin/wersje/2026-09-01')->assertNotFound()->assertDontSee('wydajemy na prośbę');
    }
}
