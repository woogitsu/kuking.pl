<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ta sama klasa błędu co #793, tylko przy zwykłym obserwowaniu.
 *
 * Nazwa użytkownika w adresie `/@nazwa/obserwuj` to NIE AUTORYZACJA: da się
 * ją zwolnić zmianą w Ustawieniach i od razu ponownie zająć, bo
 * `UsernameNotTaken` sprawdza tylko AKTUALNE zajęcie, nie historię. Stary,
 * wciąż otwarty formularz „Obserwuj”/„Przestań obserwować” po takiej zmianie
 * trafia w kogoś INNEGO, niż widział człowiek, który formularz otworzył —
 * po cichu, bez błędu na ekranie.
 *
 * #793 zamknęło to WYŁĄCZNIE dla blokad (`oczekiwany_id` +
 * `SocialController::assertToTaSamaOsoba()`) i zostawiło obserwowanie
 * właścicielowi jako osobną decyzję o zakresie. Waga jest niższa niż przy
 * blokadzie — obserwowanie da się cofnąć jednym kliknięciem i nie zostawia
 * śladu w „Zablokowanych osobach” — ale skutek jest ten sam: człowiek
 * zaczyna obserwować obcą osobę i nie ma jak się o tym dowiedzieć.
 *
 * DWIE POŁOWY TEGO TESTU MUSZĄ STAĆ RAZEM. Sam warunek w kontrolerze jest
 * martwym kodem, dopóki formularze nie niosą `oczekiwany_id` — a same pola
 * w formularzach niczego nie bronią, dopóki kontroler ich nie czyta.
 * Dlatego niżej są zarówno testy żądań, jak i kontrole dodatnie na to, że
 * KAŻDY ekran z przyciskiem „Obserwuj” to pole naprawdę wysyła.
 */
class ObserwowaniePoZmianieNazwyUzytkownikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_formularz_obserwowania_nie_obserwuje_nowego_wlasciciela_nazwy(): void
    {
        $basia = $this->user('basia');
        $pierwotny = $this->user('do_obserwowania');

        // Formularz „Obserwuj” wyrenderował się, gdy `do_obserwowania`
        // wskazywało na $pierwotny — stąd ukryte pole `oczekiwany_id`.
        $oczekiwanyId = $pierwotny->getKey();

        // Pierwotna osoba zwalnia nazwę, a inna ją od razu zajmuje —
        // dokładnie tak, jak zwykła zmiana nazwy w Ustawieniach.
        Profile::query()->where('user_id', $pierwotny->getKey())->update(['username' => 'juz_nie_ta_sama']);
        $nowyWlasciciel = $this->user('do_obserwowania');

        $odpowiedz = $this->actingAs($basia)->post(route('social.follow', 'do_obserwowania'), [
            'oczekiwany_id' => $oczekiwanyId,
        ]);

        $odpowiedz->assertSessionHasErrors('follow');

        $this->assertFalse($basia->fresh()->isFollowing($nowyWlasciciel->fresh()));
        $this->assertFalse($basia->fresh()->isFollowing($pierwotny->fresh()));
    }

    public function test_stary_formularz_przestania_obserwowania_nie_dotyka_nowego_wlasciciela_nazwy(): void
    {
        $basia = $this->user('basia');
        $obserwowany = $this->user('zajety_login');

        $basia->following()->attach($obserwowany->getKey(), ['created_at' => now()]);

        $oczekiwanyId = $obserwowany->getKey();

        // Obserwowana osoba zmienia nazwę, a ktoś inny wchodzi na jej miejsce.
        Profile::query()->where('user_id', $obserwowany->getKey())->update(['username' => 'stara_nazwa']);
        $nowyWlasciciel = $this->user('zajety_login');

        // Zanim „Przestań obserwować” zacznie trafiać w kogokolwiek innego:
        // widz obserwuje nowego właściciela nazwy (tak może być — poznał go
        // niezależnie). Bez tej pułapki test przechodziłby także wtedy, gdyby
        // kontroler po prostu nic nie robił.
        $basia->following()->attach($nowyWlasciciel->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->actingAs($basia)->delete(route('social.unfollow', 'zajety_login'), [
            'oczekiwany_id' => $oczekiwanyId,
        ]);

        $odpowiedz->assertSessionHasErrors('follow');

        // Obie relacje zostają nietknięte: formularz miał zdjąć obserwowanie
        // pierwotnej osoby, a nie kogoś, kto akurat zajął zwolnioną nazwę.
        $this->assertTrue($basia->fresh()->isFollowing($obserwowany->fresh()));
        $this->assertTrue($basia->fresh()->isFollowing($nowyWlasciciel->fresh()));
    }

    public function test_zadanie_bez_oczekiwanego_id_dziala_jak_dawniej(): void
    {
        // Wsteczna zgodność, tak samo jak przy blokadzie (#793): starsze
        // wywołania i istniejące testy nie wysyłają `oczekiwany_id`,
        // a `UserPolicy::follow()` i tak broni samej akcji.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->actingAs($basia)->post(route('social.follow', 'marek'))->assertSessionDoesntHaveErrors();

        $this->assertTrue($basia->fresh()->isFollowing($marek->fresh()));
    }

    public function test_swieze_klikniecie_z_poprawnym_oczekiwanym_id_dziala(): void
    {
        // Kontrola dodatnia: gdyby warunek odmawiał ZAWSZE, cały ten plik
        // przechodziłby na zielono przy całkowicie zepsutym obserwowaniu.
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $this->actingAs($basia)
            ->post(route('social.follow', 'marek'), ['oczekiwany_id' => $marek->getKey()])
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue($basia->fresh()->isFollowing($marek->fresh()));

        $this->actingAs($basia)
            ->delete(route('social.unfollow', 'marek'), ['oczekiwany_id' => $marek->getKey()])
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($basia->fresh()->isFollowing($marek->fresh()));
    }

    public function test_czlowiek_slyszy_co_sie_stalo_zamiast_golego_bledu(): void
    {
        $basia = $this->user('basia');
        $pierwotny = $this->user('zmienna_nazwa');
        $oczekiwanyId = $pierwotny->getKey();

        Profile::query()->where('user_id', $pierwotny->getKey())->update(['username' => 'inna_nazwa']);
        $this->user('zmienna_nazwa');

        $this->actingAs($basia)
            ->post(route('social.follow', 'zmienna_nazwa'), ['oczekiwany_id' => $oczekiwanyId])
            ->assertSessionHasErrors(['follow' => 'Ta nazwa użytkownika należy teraz do innej osoby. Odśwież stronę i spróbuj ponownie.']);
    }

    public function test_profil_niesie_oczekiwany_id_w_obu_formularzach_relacji(): void
    {
        $basia = $this->user('basia');
        $gospodarz = $this->user('gospodarz');

        $html = $this->actingAs($basia)->get(route('profile.show', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('social.follow', 'gospodarz').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$gospodarz->getKey().'"', $html);

        // Drugi stan tego samego ekranu — „Przestań obserwować”.
        $basia->following()->attach($gospodarz->getKey(), ['created_at' => now()]);

        $html = $this->actingAs($basia)->get(route('profile.show', 'gospodarz'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('social.unfollow', 'gospodarz').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$gospodarz->getKey().'"', $html);
    }

    public function test_lista_relacji_niesie_oczekiwany_id(): void
    {
        $basia = $this->user('basia');
        $gospodarz = $this->user('gospodarz');
        $ktos = $this->user('ktos');

        $ktos->following()->attach($gospodarz->getKey(), ['created_at' => now()]);

        $html = $this->actingAs($basia)
            ->get(route('social.followers', 'gospodarz'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('social.follow', 'ktos').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$ktos->getKey().'"', $html);
    }

    public function test_tablica_poznaj_niesie_oczekiwany_id(): void
    {
        $basia = $this->user('basia');
        $kucharz = $this->user('kucharz');

        Post::factory()->create([
            'author_id' => $kucharz->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $html = $this->actingAs($basia)->get(route('discover'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('social.follow', 'kucharz').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$kucharz->getKey().'"', $html);
    }

    public function test_strona_przepisu_niesie_oczekiwany_id(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor_przepisu');

        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $html = $this->actingAs($basia)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('social.follow', 'autor_przepisu').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$autor->getKey().'"', $html);

        $basia->following()->attach($autor->getKey(), ['created_at' => now()]);

        $html = $this->actingAs($basia)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('social.unfollow', 'autor_przepisu').'"', $html);
        $this->assertStringContainsString('name="oczekiwany_id" value="'.$autor->getKey().'"', $html);
    }

    public function test_onboarding_nie_obserwuje_nowego_wlasciciela_zwolnionej_nazwy(): void
    {
        // Onboarding to PIĄTY formularz wskazujący osobę nazwą użytkownika —
        // i jedyny, który robi to hurtem (`follow[]`). Jedno żądanie tworzy
        // tam relacje z wieloma osobami naraz, więc pomyłka jest tu nie
        // pojedyncza, tylko seryjna.
        $basia = $this->user('basia');
        $pierwotny = $this->user('polecany');
        $oczekiwanyId = $pierwotny->getKey();

        Profile::query()->where('user_id', $pierwotny->getKey())->update(['username' => 'stara_nazwa_polecanego']);
        $nowyWlasciciel = $this->user('polecany');

        $this->actingAs($basia)->post(route('onboarding.people'), [
            'follow' => ['polecany'],
            'oczekiwani' => ['polecany' => $oczekiwanyId],
        ])->assertRedirect();

        $this->assertFalse($basia->fresh()->isFollowing($nowyWlasciciel->fresh()));
        $this->assertFalse($basia->fresh()->isFollowing($pierwotny->fresh()));
    }

    public function test_onboarding_obserwuje_gdy_nazwa_nie_zmienila_wlasciciela(): void
    {
        // Kontrola dodatnia do testu wyżej — inaczej „nie obserwuje nikogo”
        // zaliczałoby też onboarding zepsuty do zera.
        $basia = $this->user('basia');
        $polecany = $this->user('polecany');

        $this->actingAs($basia)->post(route('onboarding.people'), [
            'follow' => ['polecany'],
            'oczekiwani' => ['polecany' => $polecany->getKey()],
        ])->assertRedirect();

        $this->assertTrue($basia->fresh()->isFollowing($polecany->fresh()));
    }

    public function test_onboarding_niesie_oczekiwany_id_przy_kazdym_zaznaczeniu(): void
    {
        $basia = $this->user('basia');
        $kucharz = $this->user('kucharz');

        Post::factory()->create([
            'author_id' => $kucharz->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $html = $this->actingAs($basia)->get(route('onboarding.people'))->assertOk()->getContent();

        $this->assertStringContainsString('name="follow[]" value="kucharz"', $html);
        $this->assertStringContainsString('name="oczekiwani[kucharz]" value="'.$kucharz->getKey().'"', $html);
    }
}
