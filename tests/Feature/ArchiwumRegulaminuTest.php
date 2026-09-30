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
 * Wszystkie wersje regulaminu (#2220, kryterium 4; decyzja właściciela
 * z 30.09.2026 „Budujemy”, wiersz w D-333).
 *
 * Przed tą zmianą `/regulamin` pokazywał wyłącznie bieżące brzmienie:
 * wiersz `dziennik_zgod.wersja_regulaminu` = „2026-09-26” (#2217) mówił,
 * KTÓRĄ wersję ktoś zaakceptował, ale jej treści nie dało się nigdzie
 * przeczytać ani pobrać.
 *
 * Plik czyta pliki archiwum jako tekst (porównanie z `regulamin.md`, data
 * w nagłówku) — kontrola dodatnia w `scripts/kontrole-negatywne-alfa08.py`.
 */
final class ArchiwumRegulaminuTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1. Strażnik: bieżąca wersja ma swój plik w archiwum
    // -----------------------------------------------------------------

    public function test_biezaca_wersja_regulaminu_ma_w_archiwum_plik_identyczny_z_regulaminem(): void
    {
        $biezaca = (string) config('kuking.zgody.wersja_regulaminu');
        $plik = ArchiwumDokumentu::regulamin()->plik($biezaca);

        $this->assertNotNull(
            $plik,
            "Brak resources/legal/archiwum/regulamin-{$biezaca}.md. Zmieniła się data regulaminu "
            ."(kuking.zgody.wersja_regulaminu = {$biezaca}) — skopiuj resources/legal/regulamin.md "
            ."do resources/legal/archiwum/regulamin-{$biezaca}.md. Plików starszych wersji nie ruszaj.",
        );

        $this->assertSame(
            (string) file_get_contents(resource_path('legal/regulamin.md')),
            (string) file_get_contents($plik),
            "resources/legal/archiwum/regulamin-{$biezaca}.md różni się od resources/legal/regulamin.md. "
            .'Poprawka bez zmiany daty: skopiuj regulamin.md do pliku bieżącej wersji. Zmiana, o której '
            .'ludzie mają się dowiedzieć: podbij datę w nagłówku i w konfiguracji i dodaj NOWY plik.',
        );
    }

    public function test_poprzednia_wersja_z_konfiguracji_tez_jest_w_archiwum(): void
    {
        // Przy zmianie istotnej (D-327) dziennik zgód zapisuje `poprzednia` —
        // ten adres też musi prowadzić do treści.
        $poprzednia = WersjaDokumentu::regulamin()->poprzednia;

        if ($poprzednia === null) {
            $this->markTestSkipped('Konfiguracja nie wskazuje poprzedniej wersji regulaminu.');
        }

        $this->assertNotNull(
            ArchiwumDokumentu::regulamin()->plik($poprzednia),
            "kuking.zgody.zmiana_regulaminu.poprzednia = {$poprzednia}, a w resources/legal/archiwum/ nie ma "
            ."regulamin-{$poprzednia}.md. Odtwórz ten plik z historii gita.",
        );
    }

    public function test_kazdy_plik_archiwum_niesie_w_naglowku_date_ze_swojej_nazwy(): void
    {
        $wersje = ArchiwumDokumentu::regulamin()->wersje();

        // Kontrola: pusta pętla niczego nie dowodzi.
        $this->assertContains('2026-09-07', $wersje);
        $this->assertContains('2026-09-26', $wersje);
        $this->assertContains('2026-09-30', $wersje);

        foreach ($wersje as $data) {
            // `assertTrue`, nie `assertStringContainsString`: ten drugi wypisuje
            // cały dokument przed komunikatem i przyczyna ginie w wyjściu.
            $this->assertTrue(
                str_contains((string) ArchiwumDokumentu::regulamin()->tresc($data), 'opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($data)),
                "Plik regulamin-{$data}.md ma w nagłówku inną datę niż w nazwie. Nagłówek ma mówić "
                .'„opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($data).'”.',
            );
        }
    }

    // -----------------------------------------------------------------
    // 2. Strony: lista, jedna wersja, pobranie
    // -----------------------------------------------------------------

    public function test_regulamin_prowadzi_do_pobrania_i_wszystkich_wersji(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('href="'.route('terms.version.download', config('kuking.zgody.wersja_regulaminu')).'"', false)
            ->assertSee('href="'.route('terms.versions').'"', false)
            ->assertSee('Wszystkie wersje regulaminu');
    }

    public function test_lista_wersji_pokazuje_kazda_date_z_odnosnikami_bez_indeksowania(): void
    {
        $odpowiedz = $this->get('/regulamin/wersje')->assertOk();

        foreach (['2026-09-07' => '7 września 2026', '2026-09-26' => '26 września 2026', '2026-09-30' => '30 września 2026'] as $data => $slownie) {
            $odpowiedz->assertSee('Wersja z '.$slownie)
                ->assertSee('href="'.url("/regulamin/wersje/{$data}").'"', false)
                ->assertSee('href="'.url("/regulamin/wersje/{$data}/pobierz").'"', false);
        }

        $odpowiedz->assertSee('Obowiązuje')
            ->assertSee('name="robots" content="noindex, follow"', false)
            ->assertDontSee('<script', false);
    }

    public function test_wczesniejsza_wersja_mowi_ze_nie_obowiazuje_i_pokazuje_swoja_tresc(): void
    {
        $this->get('/regulamin/wersje/2026-09-07')
            ->assertOk()
            ->assertSee('To jest wcześniejsza wersja regulaminu, z 7 września 2026. Już nie obowiązuje.')
            ->assertSee('opisuje stan serwisu na 7 września 2026')
            ->assertDontSee('Wymagania techniczne')
            ->assertSee('href="'.route('terms').'"', false)
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_obecna_wersja_mowi_ze_obowiazuje(): void
    {
        $this->get(route('terms.version', '2026-09-30'))
            ->assertOk()
            ->assertSee('To jest obecna wersja regulaminu, z 30 września 2026. Obowiązuje.');
    }

    public function test_pobranie_daje_plik_tekstowy_z_trescia_wersji(): void
    {
        $odpowiedz = $this->get('/regulamin/wersje/2026-09-26/pobierz')->assertOk();

        $this->assertSame('text/plain; charset=utf-8', $odpowiedz->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="regulamin-kuking-2026-09-26.txt"', $odpowiedz->headers->get('Content-Disposition'));
        $this->assertSame('noindex', $odpowiedz->headers->get('X-Robots-Tag'));
        $this->assertSame(ArchiwumDokumentu::regulamin()->tresc('2026-09-26'), $odpowiedz->getContent());
    }

    public function test_nieistniejaca_albo_bledna_data_daje_404(): void
    {
        $this->get('/regulamin/wersje/2026-09-08')->assertNotFound();
        $this->get('/regulamin/wersje/2026-09-08/pobierz')->assertNotFound();
        $this->get('/regulamin/wersje/2026-13-45')->assertNotFound();
        $this->get('/regulamin/wersje/..%2Fregulamin')->assertNotFound();
        $this->assertNull(ArchiwumDokumentu::regulamin()->plik('../regulamin'));
    }

    // -----------------------------------------------------------------
    // 3. Okres przejściowy przy zmianie istotnej (D-327)
    // -----------------------------------------------------------------

    public function test_przy_zmianie_istotnej_poprzednia_obowiazuje_a_nowa_dopiero_wejdzie(): void
    {
        config()->set('kuking.zgody.wersja_regulaminu', '2026-09-30');
        config()->set('kuking.zgody.zmiana_regulaminu', ['istotna' => true, 'poprzednia' => '2026-09-26', 'obowiazuje_od' => null]);
        $this->travelTo('2026-10-02 12:00:00');

        $this->assertSame(
            'To jest nowa wersja regulaminu, z 30 września 2026. Zacznie obowiązywać 14 października 2026.',
            ArchiwumDokumentu::regulamin()->opisWersji('2026-09-30'),
        );
        $this->assertSame(
            'To jest wersja regulaminu z 26 września 2026. Obowiązuje do 13 października 2026 włącznie, a od 14 października 2026 zastąpi ją nowa wersja.',
            ArchiwumDokumentu::regulamin()->opisWersji('2026-09-26'),
        );
        $this->assertSame(
            'To jest wcześniejsza wersja regulaminu, z 7 września 2026. Już nie obowiązuje.',
            ArchiwumDokumentu::regulamin()->opisWersji('2026-09-07'),
        );
    }

    public function test_lista_wersji_przy_okresie_przejsciowym_nie_nazywa_nowej_wersji_obowiazujaca(): void
    {
        config()->set('kuking.zgody.wersja_regulaminu', '2026-09-30');
        config()->set('kuking.zgody.zmiana_regulaminu', ['istotna' => true, 'poprzednia' => '2026-09-26', 'obowiazuje_od' => null]);
        $this->travelTo('2026-10-02 12:00:00');

        $html = $this->get('/regulamin/wersje')->assertOk()->getContent();

        $this->assertSame(1, preg_match('~Wersja z 30 września 2026\s*<span class="badge[^"]*">([^<]*)</span>~u', $html, $nowa));
        $this->assertSame('Nowa — od 14 października 2026', $nowa[1]);
        $this->assertSame(1, preg_match('~Wersja z 26 września 2026\s*<span class="badge[^"]*">([^<]*)</span>~u', $html, $stara));
        $this->assertSame('Obowiązuje', $stara[1]);
        $this->assertStringNotContainsString('>Obecna<', $html);
        $this->assertSame(1, substr_count($html, '>Obowiązuje<'));

        // Po wejściu w życie nowa wersja obowiązuje.
        $this->travelTo('2026-10-14 08:00:00');
        $html = $this->get('/regulamin/wersje')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~Wersja z 30 września 2026\s*<span class="badge[^"]*">([^<]*)</span>~u', $html, $nowa));
        $this->assertSame('Obowiązuje', $nowa[1]);
        $this->assertStringNotContainsString('Nowa —', $html);
    }

    // -----------------------------------------------------------------
    // 4. Dziennik zgód wskazuje brzmienie, które da się przeczytać
    // -----------------------------------------------------------------

    public function test_wersja_z_dziennika_zgod_prowadzi_do_tresci_ktora_zaakceptowano(): void
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
            ->where('cel', WpisZgody::CEL_REGULAMIN)
            ->value('wersja_regulaminu');

        $this->get(route('terms.version', $wersja))
            ->assertOk()
            ->assertSee('opisuje stan serwisu na '.ArchiwumDokumentu::dataSlownie($wersja));
    }
}
