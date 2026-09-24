<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresja z przeglądu #926 (D-253): zdanie w miejscu „Obserwuj” mówi,
 * CZYJE konto jest zawieszone.
 *
 * Po dołożeniu do warunku `can('follow')` gałąź „nie można obserwować”
 * łapała każdy powód odmowy, a zdanie zawsze mówiło o koncie oglądanym.
 * Zawieszona osoba czytała więc na KAŻDYM aktywnym profilu i przy każdej
 * osobie na liście „To konto jest teraz zawieszone” — nieprawdę
 * o cudzym koncie, bez słowa o własnym.
 */
class ZawieszenieKomunikatObserwowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const WLASNE = 'Twoje konto jest zawieszone — do czasu zdjęcia zawieszenia nie możesz obserwować.';

    private const WLASNE_OBSERWUJE_PROFIL = 'Obserwujesz tę osobę. Twoje konto jest zawieszone — do czasu zdjęcia zawieszenia nie możesz przestać obserwować.';

    private const WLASNE_OBSERWUJE_LISTA = 'Obserwujesz. Twoje konto jest zawieszone — do czasu zdjęcia zawieszenia nie możesz przestać obserwować.';

    private const CUDZE_PROFIL = 'To konto jest teraz zawieszone. Nie można go obserwować, dopóki zawieszenie nie zostanie zdjęte.';

    private const CUDZE_LISTA = 'Konto zawieszone — nie można teraz obserwować.';

    public function test_profil_zawieszony_ogladajacy_na_aktywnym_profilu_slyszy_o_wlasnym_koncie(): void
    {
        $this->user('autor');
        $widz = $this->user('widz');
        $widz->suspend();

        $html = $this->actingAs($widz)->get(route('profile.show', 'autor'))->assertOk()->getContent();

        $this->assertStringContainsString(self::WLASNE, $html);
        $this->assertStringNotContainsString(self::CUDZE_PROFIL, $html);
    }

    public function test_profil_zawieszony_obserwujacy_slyszy_ze_obserwuje_i_nie_moze_tego_zmienic(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        app(FollowUser::class)->handle($widz, $autor);
        $widz->suspend();

        $html = $this->actingAs($widz)->get(route('profile.show', 'autor'))->assertOk()->getContent();

        $this->assertStringContainsString(self::WLASNE_OBSERWUJE_PROFIL, $html);
        $this->assertStringNotContainsString(self::CUDZE_PROFIL, $html);
    }

    public function test_profil_aktywny_ogladajacy_zawieszonego_wlasciciela_slyszy_o_tamtym_koncie(): void
    {
        $autor = $this->user('autor');
        $autor->suspend();
        $widz = $this->user('widz');

        $html = $this->actingAs($widz)->get(route('profile.show', 'autor'))->assertOk()->getContent();

        $this->assertStringContainsString(self::CUDZE_PROFIL, $html);
        $this->assertStringNotContainsString('Twoje konto jest zawieszone', $html);
    }

    public function test_profil_przy_blokadzie_nie_mowi_o_zawieszeniu_w_zadna_strone(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');

        // Obie strony relacji. Profil w blokadzie daje 403
        // (`UserPolicy::viewProfile`), więc zdanie o zawieszeniu nie ma
        // gdzie się pojawić — a gdyby ta bramka kiedyś zelżała, test pokaże,
        // że komunikat o zawieszeniu nie wyszedł przy aktywnych kontach.
        app(BlockUser::class)->handle($widz, $autor);
        $response = $this->actingAs($widz)->get(route('profile.show', 'autor'))->assertForbidden();
        $this->assertStringNotContainsString('zawieszone', $response->getContent());

        $response = $this->actingAs($autor)->get(route('profile.show', 'widz'))->assertForbidden();
        $this->assertStringNotContainsString('zawieszone', $response->getContent());
    }

    public function test_lista_zawieszony_ogladajacy_przy_aktywnej_osobie_slyszy_o_wlasnym_koncie(): void
    {
        [$gospodarz, $osoba] = $this->listaZOsoba();
        $widz = $this->user('widz');
        $widz->suspend();

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString(route('profile.show', 'osoba'), $html);
        $this->assertStringContainsString(self::WLASNE, $html);
        $this->assertStringNotContainsString(self::CUDZE_LISTA, $html);
    }

    public function test_lista_zawieszony_obserwujacy_slyszy_ze_obserwuje(): void
    {
        [$gospodarz, $osoba] = $this->listaZOsoba();
        $widz = $this->user('widz');
        app(FollowUser::class)->handle($widz, $osoba);
        $widz->suspend();

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString(self::WLASNE_OBSERWUJE_LISTA, $html);
        $this->assertStringNotContainsString(self::CUDZE_LISTA, $html);
        $this->assertStringNotContainsString(route('social.unfollow', 'osoba'), $html);
    }

    public function test_lista_aktywny_ogladajacy_przy_zawieszonej_osobie_slyszy_o_tamtym_koncie(): void
    {
        [$gospodarz, $osoba] = $this->listaZOsoba();
        $osoba->suspend();
        $widz = $this->user('widz');

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString(route('profile.show', 'osoba'), $html);
        $this->assertStringContainsString(self::CUDZE_LISTA, $html);
        $this->assertStringNotContainsString('Twoje konto jest zawieszone', $html);
        $this->assertStringNotContainsString(route('social.follow', 'osoba'), $html);
    }

    public function test_lista_przy_blokadzie_nie_pokazuje_osoby_ani_zdania_o_zawieszeniu(): void
    {
        [$gospodarz, $osoba] = $this->listaZOsoba();
        $widz = $this->user('widz');
        app(BlockUser::class)->handle($osoba, $widz);

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('profile.show', 'osoba'), $html);
        $this->assertStringNotContainsString('zawieszone', $html);
    }

    public function test_lista_aktywny_ogladajacy_przy_aktywnej_osobie_ma_przycisk(): void
    {
        $this->listaZOsoba();
        $widz = $this->user('widz');

        $html = $this->actingAs($widz)->get(route('social.followers', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString(route('social.follow', 'osoba'), $html);
        $this->assertStringNotContainsString('zawieszone', $html);
    }

    /** @return array{User, User} */
    private function listaZOsoba(): array
    {
        $gospodarz = $this->user('gospodarz');
        $osoba = $this->user('osoba');
        app(FollowUser::class)->handle($osoba, $gospodarz);

        return [$gospodarz, $osoba];
    }
}
