<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WyslijLinkDoLogowania;
use App\Http\Controllers\Settings\SciagawkaController;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * „Ściągawka do wydruku” (F4, research z 30.09.2026): jedna kartka o WŁASNYM
 * koncie — adres, nazwa użytkownika, zasłonięty e-mail, jak wejść bez hasła,
 * jak dodać zdjęcie, gdzie powiększyć tekst. Bez hasła, bez linku logowania,
 * bez kodu i bez pełnego e-maila — kartka leży na stole.
 *
 * Asercje prywatności czytają CAŁY dokument, nie tylko kartkę: to, czego
 * nie wolno wydrukować, nie może stać nigdzie na tej stronie (ekran też
 * idzie na drukarkę, gdy arkusz druku się nie wczyta).
 */
class SciagawkaDoWydrukuTest extends TestCase
{
    use RefreshDatabase;

    private function kartka(string $html): string
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $wezel = (new DOMXPath($dokument))->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' sciagawka ')]")->item(0);
        $this->assertNotNull($wezel, 'Na stronie nie ma kartki `.sciagawka` — test nie sprawdziłby niczego.');

        return (string) $dokument->saveHTML($wezel);
    }

    public function test_kartka_ma_dane_do_wejscia_i_trzy_kroki(): void
    {
        $halina = $this->user('halina', ['email' => 'halina.nowak@example.com']);

        $html = (string) $this->actingAs($halina)->get(route('settings.sciagawka'))->assertOk()->getContent();
        $kartka = $this->kartka($html);

        $this->assertStringContainsString(e(rtrim((string) config('app.url'), '/')), $kartka);
        $this->assertStringContainsString('<dd>halina</dd>', $kartka);
        $this->assertStringContainsString('h…@example.com', $kartka);
        $this->assertStringContainsString('Jak wejść bez hasła', $kartka);
        $this->assertStringContainsString('„Opublikuj”', $kartka);
        $this->assertStringContainsString('Ustawienia → Czytelność', $kartka);
    }

    public function test_na_stronie_nie_ma_hasla_tokenu_ani_pelnego_emaila(): void
    {
        $halina = $this->user('halina', ['email' => 'halina.nowak@example.com', 'password' => Hash::make('TajneHaslo-2026')]);

        // Aktywny link logowania na koncie: gdyby kartka go pokazywała,
        // ktoś z kartki wszedłby na konto bez hasła.
        config(['kuking.login_link.wlaczone' => true]);
        Mail::fake();
        app(WyslijLinkDoLogowania::class)->handle('halina.nowak@example.com', '127.0.0.1');
        $tokeny = DB::table('login_link_tokens')->where('user_id', $halina->getKey())->pluck('token_hash')->all();
        $this->assertNotEmpty($tokeny, 'Link logowania nie powstał — test nie sprawdziłby, że go nie widać.');

        $html = (string) $this->actingAs($halina)->get(route('settings.sciagawka'))->assertOk()->getContent();

        $this->assertStringNotContainsString('halina.nowak@example.com', $html, 'Pełny e-mail konta stoi na stronie ściągawki.');
        $this->assertStringNotContainsString('TajneHaslo-2026', $html);
        $this->assertStringNotContainsString((string) $halina->fresh()->password, $html);
        $this->assertStringNotContainsString('/logowanie/link/', $html, 'Na ściągawce jest link logowania z tokenem.');
        foreach ($tokeny as $skrot) {
            $this->assertStringNotContainsString((string) $skrot, $html);
        }
    }

    public function test_gosc_nie_dostaje_kartki(): void
    {
        $this->get(route('settings.sciagawka'))->assertRedirect(route('login'));
    }

    public function test_kartka_jest_zawsze_o_wlasnym_koncie(): void
    {
        // Brak identyfikatora w adresie — dopisany parametr nie przełącza konta.
        $this->user('basia', ['email' => 'basia@example.com']);
        $zofia = $this->user('zofia', ['email' => 'zofia@example.com']);

        $kartka = $this->kartka((string) $this->actingAs($zofia)
            ->get(route('settings.sciagawka', ['user' => 'basia', 'username' => 'basia']))
            ->assertOk()->getContent());

        $this->assertStringContainsString('<dd>zofia</dd>', $kartka);
        $this->assertStringNotContainsString('basia', $kartka);
    }

    public function test_przycisk_drukuj_bez_skryptu_prowadzi_do_instrukcji(): void
    {
        $halina = $this->user('halina');
        $cel = route('settings.sciagawka', ['druk' => 1]).'#jak-wydrukowac';

        $this->actingAs($halina)->get(route('settings.sciagawka'))
            ->assertOk()
            ->assertSee('href="'.e($cel).'" rel="nofollow" data-drukuj-przepis>Wydrukuj ściągawkę</a>', false)
            ->assertDontSee('id="jak-wydrukowac"', false);

        $this->actingAs($halina)->get(route('settings.sciagawka', ['druk' => 1]))
            ->assertOk()
            ->assertSee('Jak wydrukować tę kartkę:')
            ->assertSee('<kbd>Ctrl</kbd> i <kbd>P</kbd>', false);
    }

    public function test_bez_linku_logowania_kartka_mowi_o_przypomnieniu_hasla(): void
    {
        config(['kuking.login_link.wlaczone' => false]);

        $kartka = $this->kartka((string) $this->actingAs($this->user('halina'))
            ->get(route('settings.sciagawka'))->assertOk()->getContent());

        $this->assertStringContainsString('„Nie pamiętam hasła”', $kartka);
        $this->assertStringNotContainsString('Wyślij mi link do zalogowania', $kartka);
    }

    public function test_do_kartki_prowadza_ustawienia_i_ekran_gotowe(): void
    {
        $halina = $this->user('halina');
        $odnosnik = 'href="'.route('settings.sciagawka').'">Wydrukuj ściągawkę</a>';

        $this->actingAs($halina)->get(route('settings.index'))->assertOk()->assertSee($odnosnik, false);
        $this->actingAs($halina)->get(route('onboarding.done'))->assertOk()->assertSee($odnosnik, false);
    }

    public function test_arkusz_druku_obejmuje_sciagawke_duzym_drukiem(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/wydruk-przepisu.css'));
        $druk = substr($css, (int) strpos($css, '@media print'));

        // Rama kartki (belki, przyciski, formularze) wspólna z przepisem, nie kopia.
        $this->assertStringContainsString('body:has(.przepis-uklad, .sciagawka) main :is(.btn, button, form)', $druk, 'Ściągawka nie dzieli z przepisem ramy kartki w druku.');
        // Duży druk: treść co najmniej 16 pt.
        $this->assertStringContainsString('body:has(.sciagawka) .sciagawka * {'."\n".'    font-size: max(calc(16pt * var(--druk-skala)), 1em);', $druk, 'Ściągawka nie ma w druku progu 16 pt.');
    }

    public function test_zasloniecie_emaila(): void
    {
        $this->assertSame('a…@example.com', SciagawkaController::zakryjEmail('anna.kowalska@example.com'));
        $this->assertSame('ż…@przykład.pl', SciagawkaController::zakryjEmail('żaneta@przykład.pl'));
        $this->assertSame('…', SciagawkaController::zakryjEmail('bez-malpy'));
        $this->assertSame('…', SciagawkaController::zakryjEmail('@example.com'));
    }
}
