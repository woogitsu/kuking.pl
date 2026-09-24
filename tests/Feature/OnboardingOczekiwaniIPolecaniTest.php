<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Krok „Kogo chcesz obserwować?” — dwie reszty po wcześniejszych poprawkach:
 *
 *  - #1340: po błędzie walidacji zaznaczenia wracają z `old()`, ale nazwa,
 *    która w międzyczasie zmieniła właściciela, nie może wrócić zaznaczona
 *    przy nowej osobie (para nazwa–identyfikator z #793);
 *  - #1299: osoba z wyników wyszukiwania nie powtarza się w polecanych,
 *    a wykluczenie idzie przed limitem SQL, więc lista polecanych nie traci
 *    miejsca.
 */
class OnboardingOczekiwaniIPolecaniTest extends TestCase
{
    use RefreshDatabase;

    private function autorZWpisem(string $nazwa, string $kiedy, string $imie = 'Testowa osoba'): User
    {
        $user = $this->user($nazwa, ['display_name' => $imie]);

        Post::factory()->create([
            'author_id' => $user->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => $kiedy,
        ]);

        return $user;
    }

    /** @return list<string> nazwy z pól `follow[]` w wycinku HTML-a */
    private function nazwyWSekcji(string $html, string $od, string $do): array
    {
        $start = mb_strpos($html, $od);
        $this->assertNotFalse($start, "Nie znalazłem sekcji „{$od}”.");
        $koniec = mb_strpos($html, $do, $start);
        $wycinek = mb_substr($html, $start, ($koniec === false ? mb_strlen($html) : $koniec) - $start);
        preg_match_all('/name="follow\[\]" value="([^"]+)"/', $wycinek, $m);

        return $m[1];
    }

    /** POST z 21 osobami (limit 20) — w tym `halina` z identyfikatorem widzianym na ekranie. */
    private function odrzuconyWybor(User $viewer, User $halina): void
    {
        $this->actingAs($viewer)->get(route('onboarding.people'))->assertOk();
        $names = ['halina'];
        $oczekiwani = ['halina' => (string) $halina->getKey()];
        for ($i = 0; $i < 20; $i++) {
            $oczekiwani['kucharz_'.$i] = (string) $this->user('kucharz_'.$i)->getKey();
            $names[] = 'kucharz_'.$i;
        }

        $this->from(route('onboarding.people'))->post(route('onboarding.people'), [
            'follow' => $names,
            'oczekiwani' => $oczekiwani,
            'selection' => session('onboarding.selection.token'),
        ])->assertRedirect(route('onboarding.people'))->assertSessionHasErrors('follow');
    }

    public function test_po_bledzie_walidacji_nazwa_nowego_wlasciciela_nie_wraca_zaznaczona(): void
    {
        $viewer = $this->user('widz');
        $halina = $this->user('halina');
        $this->odrzuconyWybor($viewer, $halina);

        // Między odrzuceniem a powrotem nazwa przechodzi na inne konto.
        Profile::query()->where('user_id', $halina->getKey())->update(['username' => 'halina_stara']);
        $nowa = $this->user('halina');

        $html = $this->get(route('onboarding.people'))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="halina"', $html);
        $this->assertStringNotContainsString('value="'.$nowa->getKey().'"', $html);
        $this->assertStringContainsString('Nazwa „halina” należy teraz do innej osoby, więc jej nie zaznaczyliśmy.', $html);
        // Pozostałe, poprawne zaznaczenia nie znikają.
        $this->assertSame(20, preg_match_all('/name="follow\[\]"[^>]*checked/', $html));
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_kontrola_dodatnia_po_bledzie_walidacji_ta_sama_osoba_wraca_zaznaczona(): void
    {
        $viewer = $this->user('widz');
        $halina = $this->user('halina');
        $this->odrzuconyWybor($viewer, $halina);

        $html = $this->get(route('onboarding.people'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="follow\[\]"[^>]*value="halina"[^>]*checked/', $html);
        $this->assertStringContainsString('name="oczekiwani[halina]" value="'.$halina->getKey().'"', $html);
        $this->assertStringNotContainsString('należy teraz do innej osoby', $html);
        $this->assertSame(21, preg_match_all('/name="follow\[\]"[^>]*checked/', $html));
    }

    public function test_osoba_z_wynikow_nie_powtarza_sie_w_polecanych_a_miejsce_dostaje_nastepna(): void
    {
        $zofia = $this->autorZWpisem('zofia', now()->toDateTimeString(), 'Zofia');
        for ($i = 1; $i <= 8; $i++) {
            $this->autorZWpisem('kucharz_'.$i, now()->subDays($i)->toDateTimeString());
        }

        $html = $this->actingAs($this->user('widz'))
            ->get(route('onboarding.people', ['q' => 'zofia']))
            ->assertOk()->getContent();

        $wyniki = $this->nazwyWSekcji($html, 'Wyniki wyszukiwania', 'Osoby, które polecamy');
        $polecani = $this->nazwyWSekcji($html, 'Osoby, które polecamy', 'form-actions');

        $this->assertSame(['zofia'], $wyniki);
        $this->assertNotContains('zofia', $polecani);
        // Osiem miejsc wypełniają inne osoby — wykluczenie poszło przed LIMIT.
        $this->assertSame(array_map(fn ($i) => 'kucharz_'.$i, range(1, 8)), $polecani);
        $this->assertSame(1, substr_count($html, 'name="oczekiwani[zofia]" value="'.$zofia->getKey().'"'));
    }

    public function test_bez_frazy_lista_polecanych_zostaje_bez_zmian(): void
    {
        $this->autorZWpisem('zofia', now()->toDateTimeString(), 'Zofia');
        for ($i = 1; $i <= 8; $i++) {
            $this->autorZWpisem('kucharz_'.$i, now()->subDays($i)->toDateTimeString());
        }

        $html = $this->actingAs($this->user('widz'))->get(route('onboarding.people'))->assertOk()->getContent();

        preg_match_all('/name="follow\[\]" value="([^"]+)"/', $html, $m);
        $this->assertSame(['zofia', ...array_map(fn ($i) => 'kucharz_'.$i, range(1, 7))], $m[1]);
    }

    public function test_zaznaczenie_znalezionej_osoby_tworzy_jedno_obserwowanie(): void
    {
        $zofia = $this->autorZWpisem('zofia', now()->toDateTimeString(), 'Zofia');

        $this->actingAs($this->user('widz'))->post(route('onboarding.people'), [
            'follow' => ['zofia'],
            'oczekiwani' => ['zofia' => (string) $zofia->getKey()],
        ])->assertRedirect(route('onboarding.done'));

        $this->assertDatabaseCount('follows', 1);
        $this->assertSame(1, $zofia->notifications()->count());
    }
}
