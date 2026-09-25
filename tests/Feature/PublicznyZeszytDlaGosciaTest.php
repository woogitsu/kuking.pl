<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zeszyt „Wszyscy" otwiera się bez konta (issue #965).
 *
 * Formularz nazywa widoczność `public` „Wszyscy", ekran pisze „Ten zeszyt
 * widzą wszyscy", a `CollectionPolicy::view(?User)` od dawna obsługuje gościa.
 * Tylko trasa `GET /zeszyt/{collection}` stała w grupie `auth` — więc rodzina
 * bez konta i robot wyszukiwarki lądowali na logowaniu, zanim Policy w ogóle
 * zdążyła zapytać o widoczność. Obietnica „wszyscy" miała ukryty warunek.
 *
 * Kontrola ujemna: ponowne wstawienie trasy do grupy `auth` zmienia 200 gościa
 * w przekierowanie na logowanie — pierwszy test oblewa.
 */
class PublicznyZeszytDlaGosciaTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $widocznosc, string $tytul): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $widocznosc,
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
        ]);
    }

    public function test_gosc_otwiera_publiczny_zeszyt_i_widzi_tylko_publiczne_przepisy(): void
    {
        $wlascicielka = $this->user('zeszytdlawszystkich');
        $zeszyt = Collection::create([
            'owner_id' => $wlascicielka->getKey(),
            'name' => 'Niedzielne obiady',
            'visibility' => 'public',
        ]);
        $zeszyt->recipes()->attach([
            $this->przepis($wlascicielka, 'public', 'Rosół babci Hani')->getKey(),
            $this->przepis($wlascicielka, 'private', 'Sekretny sernik')->getKey(),
        ]);

        $odpowiedz = $this->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('Niedzielne obiady')
            ->assertSee('Rosół babci Hani')
            ->assertDontSee('Sekretny sernik')
            // Publiczny zeszyt ma trafić do indeksu: bez `noindex` w meta
            // i w nagłówku, za to z opisem dla wyszukiwarki.
            ->assertDontSee('name="robots" content="noindex', false)
            ->assertSee('<meta name="description"', false)
            // Żadnej akcji za logowaniem — zamiast niej jasna droga.
            ->assertSee('Zaloguj się')
            ->assertSee(route('login'), false)
            ->assertDontSee('Edytuj zeszyt')
            ->assertDontSee('Usuń ten zeszyt');

        $this->assertStringNotContainsString(
            'noindex',
            (string) $odpowiedz->headers->get('X-Robots-Tag'),
            'Przedrostek `zeszyt` w ApplySecurityHeaders nie może dokładać noindex zeszytowi „Wszyscy".',
        );
    }

    public function test_gosc_nie_otwiera_prywatnego_zeszytu(): void
    {
        $wlascicielka = $this->user('zeszytprywatny');
        $zeszyt = Collection::create([
            'owner_id' => $wlascicielka->getKey(),
            'name' => 'Tylko moje notatki',
            'visibility' => 'private',
        ]);

        $this->get(route('collections.show', $zeszyt))
            ->assertForbidden()
            ->assertDontSee('Tylko moje notatki');
    }

    public function test_gosc_nie_otwiera_domyslnego_zeszytu(): void
    {
        $wlascicielka = $this->user('zeszytdomyslny');
        $zeszyt = $wlascicielka->defaultCollection();

        // KONTROLA: domyślny zeszyt naprawdę jest prywatny.
        $this->assertFalse($zeszyt->isPublic());

        $this->get(route('collections.show', $zeszyt))->assertForbidden();
    }

    public function test_gosc_nie_otwiera_publicznego_zeszytu_osoby_zbanowanej(): void
    {
        $wlascicielka = $this->user('zeszytzbanowany');
        $zeszyt = Collection::create([
            'owner_id' => $wlascicielka->getKey(),
            'name' => 'Zeszyt po banie',
            'visibility' => 'public',
        ]);
        $wlascicielka->ban();

        $this->get(route('collections.show', $zeszyt))->assertForbidden();
    }

    public function test_zapisy_i_zmiany_zeszytu_nadal_wymagaja_logowania(): void
    {
        $wlascicielka = $this->user('zeszytmutacje');
        $zeszyt = Collection::create([
            'owner_id' => $wlascicielka->getKey(),
            'name' => 'Nie do ruszenia',
            'visibility' => 'public',
        ]);

        $this->get(route('collections.index'))->assertRedirect(route('login'));
        $this->get(route('collections.edit', $zeszyt))->assertRedirect(route('login'));
        $this->patch(route('collections.update', $zeszyt), ['name' => 'Zmienione'])->assertRedirect(route('login'));
        $this->delete(route('collections.destroy', $zeszyt))->assertRedirect(route('login'));

        $this->assertDatabaseHas('collections', ['id' => $zeszyt->getKey(), 'name' => 'Nie do ruszenia']);
    }

    public function test_wlascicielka_dalej_widzi_akcje_i_nie_widzi_zachety_do_logowania(): void
    {
        $wlascicielka = $this->user('zeszytwlascicielka');
        $zeszyt = Collection::create([
            'owner_id' => $wlascicielka->getKey(),
            'name' => 'Moje ciasta',
            'visibility' => 'private',
        ]);

        $this->actingAs($wlascicielka)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->assertSee('Edytuj zeszyt')
            ->assertDontSee('data-rola="zeszyt-gosc"', false)
            // Prywatny zeszyt nadal ma noindex w widoku.
            ->assertSee('name="robots" content="noindex', false);
    }
}
