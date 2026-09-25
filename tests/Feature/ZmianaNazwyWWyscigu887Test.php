<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresja #887: nazwa zajęta MIĘDZY walidacją a zapisem.
 *
 * Wyścig odgrywamy na jednym połączeniu: zaraz po zapytaniu reguły
 * `UsernameNotTaken` (które zwróciło „wolna") inna osoba zajmuje tę nazwę.
 * Kontroler dochodzi do UPDATE-u z nazwą, której baza już nie przyjmie —
 * dokładnie tak jak przegrany z dwóch równoczesnych żądań. Prawdziwy przeplot
 * na dwóch połączeniach mierzy `tests/Dwa/ZmianaNazwyProfiluNaDwochPolaczeniachTest`;
 * tutaj sprawdzamy to, czego tamten nie widzi: sesję i wyrenderowany formularz.
 */
class ZmianaNazwyWWyscigu887Test extends TestCase
{
    use RefreshDatabase;

    public function test_nazwa_zajeta_po_walidacji_daje_blad_pola_i_zachowuje_wpisane_dane(): void
    {
        $zwyciezca = $this->user('zwyciezca_887');
        $przegrany = $this->user('przegrany_887', ['display_name' => 'Stare imię']);

        $this->ktosZajmieNazwePoSprawdzeniu('wolna_nazwa', $zwyciezca);

        $odpowiedz = $this->actingAs($przegrany)
            ->from('/ustawienia/profil')
            ->put('/ustawienia/profil', $this->formularz('wolna_nazwa'));

        $odpowiedz->assertRedirect('/ustawienia/profil');
        $odpowiedz->assertSessionHasErrors(['username']);
        $this->assertStringStartsWith('Ta nazwa jest już zajęta — wybierz inną.', (string) session('errors')->first('username'));
        $odpowiedz->assertSessionHasInput('display_name', 'Barbara');
        $odpowiedz->assertSessionHasInput('bio', 'Gotuję od czterdziestu lat.');
        $odpowiedz->assertSessionHasInput('region', 'Podkarpacie');
        $odpowiedz->assertSessionHasInput('speciality', 'zupy i kiszonki');

        // Kontrola dodatnia na to, że wyścig NAPRAWDĘ się odegrał: nazwę ma
        // zwycięzca, a przegrany niczego nie zapisał — ani nazwy, ani reszty.
        $this->assertSame('wolna_nazwa', $zwyciezca->profile()->first()->username);
        $zapisany = $przegrany->profile()->first();
        $this->assertSame('przegrany_887', $zapisany->username);
        $this->assertSame('Stare imię', $zapisany->display_name);
        $this->assertNull($zapisany->bio);
        $this->assertSame(1, Profile::query()->whereRaw('lower(username) = ?', ['wolna_nazwa'])->count());

        // Wyrenderowany formularz: komunikat przy polu i dane wpisane przed
        // chwilą. Drugi przegrany wyścig, tym razem aż do strony po powrocie.
        $this->ktosZajmieNazwePoSprawdzeniu('druga_wolna', $zwyciezca);

        $this->actingAs($przegrany)
            ->followingRedirects()
            ->from('/ustawienia/profil')
            ->put('/ustawienia/profil', $this->formularz('druga_wolna'))
            ->assertOk()
            ->assertSee('Ta nazwa jest już zajęta — wybierz inną.', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Gotuję od czterdziestu lat.', false)
            ->assertSee('value="Barbara"', false)
            ->assertSee('value="Podkarpacie"', false)
            ->assertSee('value="zupy i kiszonki"', false);
    }

    public function test_kontrola_dodatnia_zwykla_zmiana_nazwy_dalej_sie_zapisuje(): void
    {
        $basia = $this->user('basia_887');

        $this->actingAs($basia)
            ->from('/ustawienia/profil')
            ->put('/ustawienia/profil', $this->formularz('nowa_basia'))
            ->assertRedirect('/ustawienia/profil')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Zapisane.');

        $this->assertSame('nowa_basia', $basia->profile()->first()->username);
        $this->assertSame('Gotuję od czterdziestu lat.', $basia->profile()->first()->bio);
    }

    public function test_inna_kolizja_unikalnosci_nie_udaje_zajetej_nazwy(): void
    {
        // Tymczasowy indeks tylko w transakcji testu (RefreshDatabase go
        // cofnie): kolizja NIE na nazwie ma dalej iść do obsługi błędów.
        DB::statement('CREATE UNIQUE INDEX test_887_bio_unique ON profiles (bio)');

        $this->user('pierwsza_887')->profile()->update(['bio' => 'Gotuję od czterdziestu lat.']);
        $druga = $this->user('druga_887');

        $this->withoutExceptionHandling();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('test_887_bio_unique');

        $this->actingAs($druga)
            ->from('/ustawienia/profil')
            ->put('/ustawienia/profil', $this->formularz('druga_887'));
    }

    /**
     * Po zapytaniu reguły o zajętość nazwy wstawia tę nazwę innej osobie —
     * raz, dokładnie w oknie między walidacją a UPDATE-em.
     */
    private function ktosZajmieNazwePoSprawdzeniu(string $nazwa, User $ktos): void
    {
        $juz = false;

        DB::listen(function (QueryExecuted $query) use (&$juz, $nazwa, $ktos): void {
            if ($juz || ! str_contains($query->sql, 'lower(username) = ?') || ! str_contains($query->sql, 'exists')) {
                return;
            }

            $juz = true;
            DB::table('profiles')->where('user_id', $ktos->getKey())->update(['username' => $nazwa]);
        });
    }

    /** @return array<string, string> */
    private function formularz(string $nazwa): array
    {
        return [
            'display_name' => 'Barbara',
            'username' => $nazwa,
            'bio' => 'Gotuję od czterdziestu lat.',
            'region' => 'Podkarpacie',
            'speciality' => 'zupy i kiszonki',
        ];
    }
}
