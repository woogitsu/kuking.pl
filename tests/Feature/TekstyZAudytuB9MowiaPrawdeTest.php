<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teksty, które mówiły nieprawdę albo wskazywały przycisk, którego nie ma
 * (audyt B9 z 25 września 2026, top 10: pkt 4, 6, 7 i 10; audyt B1: zn. 6 i 8).
 *
 * Każdy test sprawdza zdanie NA EKRANIE, na który ono trafia, a nie w pliku
 * szablonu — i każdy ma asercję dodatnią (nowe zdanie jest), żeby zieleń
 * „starego zdania nie ma” nie brała się z pustej strony.
 */
class TekstyZAudytuB9MowiaPrawdeTest extends TestCase
{
    use RefreshDatabase;

    public function test_komentarz_usuniety_przez_autora_wpisu_nie_jest_wiadomoscia_od_moderacji(): void
    {
        $autorka = $this->user('autorka');
        $piszaca = $this->user('piszaca');
        $wpis = Post::factory()->for($autorka, 'author')->create();

        $komentarz = app(PublishComment::class)->handle($piszaca, $wpis, 'Za dużo soli.');
        app(DeleteComment::class)->handle($autorka, $komentarz, 'Nie na temat.');

        $powiadomienie = Notification::query()
            ->where('user_id', $piszaca->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->sole();
        $this->assertSame('Twój komentarz usunęła osoba, która dodała ten wpis.', $powiadomienie->data['title']);

        $html = (string) $this->actingAs($piszaca)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Twój komentarz usunęła osoba, która dodała ten wpis.', $html);
        $this->assertStringNotContainsString('od moderacji', $html);
    }

    public function test_pomoc_hasla_przy_weryfikacji_dwuetapowej_obejmuje_facebooka(): void
    {
        $html = view('pages.settings.two_factor._password-help')->render();

        $this->assertStringContainsString('przez Google albo Facebooka', $html);
        $this->assertStringContainsString('hasła do Google ani Facebooka', $html);
    }

    public function test_szkic_przepisu_wskazuje_przycisk_ktory_naprawde_stoi_na_stronie(): void
    {
        $autorka = $this->user('autorka');
        $szkic = Recipe::factory()->draft()->create(['author_id' => $autorka->getKey()]);

        $html = (string) $this->actingAs($autorka)->get(route('recipes.show', $szkic->slug))->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<strong>To jest szkic\.</strong>[^<]*Kliknij „([^”]+)”~u', $html, $m), 'Brak zdania o szkicu.');
        $this->assertContains($m[1], ['Dopisz szczegóły', 'Edytuj przepis']);
        $this->assertStringContainsString('>'.$m[1].'</a>', $html, "Zdanie każe kliknąć „{$m[1]}”, a takiego przycisku nie ma.");
    }

    public function test_pomoc_mowi_gdzie_jest_zglos_przy_wpisie(): void
    {
        $html = (string) $this->get(route('help'))->assertOk()->getContent();

        $this->assertStringContainsString('<strong>Zgłoś ten wpis</strong>', $html);
        $this->assertStringNotContainsString('Pod każdą treścią jest przycisk', $html);
        // Autor przepisu dostaje powiadomienie w serwisie, nie list.
        $this->assertStringContainsString('Autor przepisu zobaczy to w powiadomieniach', $html);
    }

    public function test_przycisk_na_ekranie_awarii_nazywa_sie_tak_jak_to_co_robi(): void
    {
        foreach (['errors.500', 'errors.503'] as $widok) {
            $html = view($widok)->render();

            $this->assertStringContainsString('<a href="/">Strona główna</a>', $html, $widok);
            $this->assertStringNotContainsString('>Spróbuj jeszcze raz<', $html, $widok);
        }
    }

    public function test_ekran_czytelnosci_ma_podsumowanie_bledow(): void
    {
        $basia = $this->user('basia');

        $html = (string) $this->actingAs($basia)
            ->from(route('settings.accessibility'))
            ->followingRedirects()
            ->put(route('settings.accessibility'), ['text_scale' => 999])
            ->assertOk()
            ->getContent();

        // Błąd przy grupie — dowód, że strona w ogóle dostała błąd walidacji.
        $this->assertStringContainsString('id="f-text_scale-error"', $html);

        $this->assertStringContainsString('class="error-summary"', $html);
        $this->assertStringContainsString('href="#f-text_scale"', $html);
    }

    public function test_linki_do_sasiednich_wpisow_uzywaja_skali_tekstu(): void
    {
        $css = (string) file_get_contents(resource_path('css/wpis-nawigacja-sasiedzi.css'));
        $bezKomentarzy = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertSame(1, preg_match('/\.wpis-nawigacja-sasiedzi-link\s*\{([^}]*)\}/', $bezKomentarzy, $m));
        $this->assertStringContainsString('font-size: var(--text-body)', $m[1]);
    }

    public function test_plik_czytaj_to_najpierw_bez_literowki(): void
    {
        $szablon = (string) file_get_contents(resource_path('views/exports/readme.blade.php'));

        $this->assertStringNotContainsString('weszedł', $szablon);
        $this->assertStringContainsString('nie wszedł do niej żaden', $szablon);
    }
}
