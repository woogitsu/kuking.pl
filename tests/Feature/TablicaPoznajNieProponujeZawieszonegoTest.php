<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #780 zdjęło martwy przycisk „Obserwuj” przy koncie zawieszonym — ale
 * WYŁĄCZNIE na liście relacji i na profilu, bo tylko te dwa ekrany
 * wymieniało. Tablica „Poznaj” (`components/kuking-board/people.blade.php`)
 * została wtedy nieprzejrzana: rysuje formularz `social.follow` bez
 * warunku `isActive()`, a karmi ją zupełnie inne zapytanie niż tamte listy.
 *
 * WYNIK PRZEGLĄDU: tego samego wzorca tam NIE MA i przycisk nie jest martwy.
 * Powód jest w zapytaniu, nie w widoku — obie drogi, którymi osoba trafia na
 * tę tablicę, odsiewają konta nieaktywne już w SQL:
 *
 *  * `DailyBoard::peopleToFollow()` (dobór automatu) —
 *    `->where('users.status', User::STATUS_ACTIVE)`,
 *  * `DailyBoard::fromCuratedPicks()` (wybór gospodarza z panelu) —
 *    `->where('status', User::STATUS_ACTIVE)`.
 *
 * Widok dostaje więc wyłącznie konta aktywne i nie ma czego chować. TO JEST
 * CAŁA RÓŻNICA WOBEC LIST RELACJI: tamte świadomie używają
 * `widocznyJakoOsoba()`, które konto zawieszone PRZEPUSZCZA (zawieszenie
 * jest tymczasowe, karta osoby ma zostać na liście obserwujących) — i stąd
 * brał się tam martwy przycisk.
 *
 * Ten plik istnieje po to, żeby „sprawdzone i czysto” miało dowód, a nie
 * zdanie w meldunku: gdyby ktoś kiedyś poluzował filtr statusu w `DailyBoard`
 * (np. żeby tablica nie rzedła), martwy przycisk wróciłby tu po cichu
 * i żaden test #780 by tego nie złapał.
 */
class TablicaPoznajNieProponujeZawieszonegoTest extends TestCase
{
    use RefreshDatabase;

    private function autorZWpisem(string $nazwa): User
    {
        $user = $this->user($nazwa);

        Post::factory()->create([
            'author_id' => $user->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        return $user;
    }

    public function test_dobor_automatu_nie_stawia_na_tablicy_konta_zawieszonego(): void
    {
        $widz = $this->user('widz_tablicy');
        $aktywny = $this->autorZWpisem('kucharz_aktywny');
        $zawieszony = $this->autorZWpisem('kucharz_zawieszony');

        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();

        $html = $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        // KONTROLA DODATNIA. Bez niej ten test przechodziłby także wtedy,
        // gdyby tablica była pusta z zupełnie innego powodu — a skan, który
        // nie znajduje niczego, zawsze jest zielony.
        $this->assertStringContainsString('action="'.route('social.follow', 'kucharz_aktywny').'"', $html);
        $this->assertStringContainsString($aktywny->displayName(), $html);

        // Konto zawieszone nie dociera do widoku w ogóle — nie ma więc ani
        // karty, ani przycisku, który zawsze kończyłby się 403 (D-053).
        $this->assertStringNotContainsString('kucharz_zawieszony', $html);
        $this->assertStringNotContainsString('action="'.route('social.follow', 'kucharz_zawieszony').'"', $html);
    }

    public function test_wybor_gospodarza_tez_nie_stawia_na_tablicy_konta_zawieszonego(): void
    {
        // Druga droga na tę samą tablicę — i jedyna, którą steruje człowiek.
        // Gospodarz mógł wybrać kogoś rano, a moderacja zawiesić to konto po
        // południu; tablica dnia żyje wtedy dalej z nieaktualnym wyborem.
        $widz = $this->user('widz_wyboru');
        $gospodarz = $this->user('gospodarz_tablicy');
        $wybrany = $this->autorZWpisem('wybrany_aktywny');
        $wybranyZawieszony = $this->autorZWpisem('wybrany_zawieszony');

        foreach ([$wybrany, $wybranyZawieszony] as $pozycja => $osoba) {
            DailyPick::query()->create([
                'shown_on' => now()->toDateString(),
                'subject_type' => DailyPick::TYPE_USER,
                'subject_id' => $osoba->getKey(),
                'position' => $pozycja,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }

        $wybranyZawieszony->status = User::STATUS_SUSPENDED;
        $wybranyZawieszony->save();

        $html = $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('social.follow', 'wybrany_aktywny').'"', $html);
        $this->assertStringNotContainsString('wybrany_zawieszony', $html);
    }

    public function test_policy_dalej_odmawia_gdyby_ktos_trafil_zadaniem_wprost(): void
    {
        // Kontrola dodatnia dla samej granicy: tablica niczego nie obiecuje,
        // ale `UserPolicy::follow()` i tak jest tym, co broni akcji.
        $widz = $this->user('widz_wprost');
        $zawieszony = $this->autorZWpisem('zawieszony_wprost');

        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();

        $this->actingAs($widz)
            ->post(route('social.follow', 'zawieszony_wprost'))
            ->assertForbidden();
    }
}
