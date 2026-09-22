<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Wróć" w formularzu zgłoszenia ma prowadzić do zgłaszanej treści — nie do
 * siebie samego (issue #795).
 *
 * CO BYŁO ZEPSUTE
 * `resources/views/pages/report.blade.php` budowało „Wróć" przez
 * `url()->previous()`. Formularz ma DWA wejścia GET z rzędu: pierwsze przy
 * otwarciu, drugie przy odświeżeniu po odrzuconym POST (`back()` w
 * `ReportController::store()`). Laravel zapisuje URL bieżącego GET jako
 * „poprzedni" DOPIERO PO jego obsłużeniu — więc w chwili renderowania błędu
 * `_previous.url` niesie jeszcze adres PIERWSZEGO wejścia na TEN SAM
 * formularz, ustawiony przy jego otwarciu. „Wróć" prowadził więc do
 * formularza, nie do wpisu/przepisu/profilu, którego dotyczyło zgłoszenie.
 *
 * Poprawka liczy cel wprost z AUTORYZOWANEGO obiektu (`ReportController::
 * wracajDo()`), nie z Referera — który jest niezaufany i może wskazywać obcy
 * host albo (jak tutaj) sam formularz.
 */
class WrocZFormularzaZgloszeniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wroc_po_bledzie_walidacji_prowadzi_do_wpisu_a_nie_do_formularza(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $adresFormularza = route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]);

        // Krok 1: otwarcie formularza z prawdziwej strony wpisu — dokładnie
        // to ustawia (błędnie, na starym kodzie) `_previous.url` na sam
        // formularz przy jego WŁASNYM GET.
        $this->actingAs($widz)->from(route('posts.show', $wpis))->get($adresFormularza)->assertOk();

        // Krok 2: odrzucony POST (brak wybranego powodu) — `back()` wraca na
        // formularz, tak jak powinien.
        $this->actingAs($widz)->from($adresFormularza)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), [])
            ->assertSessionHasErrors('reason');

        // Krok 3: odświeżony formularz z błędem — TU jest sedno #795. Na
        // starym kodzie `url()->previous()` wskazywał ten sam formularz.
        $html = (string) $this->actingAs($widz)->get($adresFormularza)->assertOk()->getContent();

        // Patrzymy konkretnie na odnośnik „Wróć", nie na KAŻDE wystąpienie
        // adresu formularza — `<link rel="canonical">` w `<head>` legalnie
        // wskazuje na formularz (to jest jego własny, prawdziwy adres) i nie
        // ma nic wspólnego z nawigacją „Wróć".
        $this->assertStringContainsString(
            'href="'.route('posts.show', $wpis).'">Wróć</a>',
            $html,
            '„Wróć" ma prowadzić do zgłaszanego wpisu.',
        );
        $this->assertStringNotContainsString(
            'href="'.$adresFormularza.'">Wróć</a>',
            $html,
            '„Wróć" nie może prowadzić do samego formularza zgłoszenia.',
        );
    }

    public function test_wroc_dziala_takze_bez_referera(): void
    {
        // Brak Referera (np. formularz otwarty wprost, bez nagłówka) —
        // `url()->previous()` spadłby wtedy na fallback (stronę główną).
        // Cel liczony z obiektu ma działać identycznie, niezależnie od tego,
        // co wysłała przeglądarka.
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);

        $html = (string) $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->getKey()]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('recipes.show', $przepis).'"', $html);
    }

    public function test_wroc_z_komentarza_prowadzi_do_wpisu_pod_ktorym_stoi(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $komentarz = Comment::factory()->create(['post_id' => $wpis->getKey(), 'author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('posts.show', $wpis).'"',
            $html,
            '„Wróć" ze zgłoszenia komentarza ma prowadzić do wpisu, pod którym ten komentarz stoi.',
        );
    }

    public function test_wroc_z_ugotowalem_prowadzi_do_karty_ugotowania(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        $ugotowanie = CookedEvent::factory()->create(['user_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'cooked_event', 'id' => $ugotowanie->getKey()]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('cooked.show', $ugotowanie).'"', $html);
    }

    public function test_wroc_ze_zgloszenia_osoby_prowadzi_do_jej_profilu(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        Profile::where('user_id', $autor->getKey())->update(['username' => 'zglaszana_osoba']);

        $html = (string) $this->actingAs($widz)
            ->get(route('reports.create', ['type' => 'user', 'id' => 'zglaszana_osoba']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('profile.show', 'zglaszana_osoba').'">Wróć</a>',
            $html,
        );
    }
}
