<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Notifications\WidocznoscPowiadomien;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1687, etap 5: wybór imienia z partii zapisów (`zapisujacyDoPokazania()`)
 * przeniesiony z modelu `Notification` do `WidocznoscPowiadomien`, obok warunku
 * widoczności całej partii. Test kontraktowy: wiersz jest na liście
 * WTEDY I TYLKO WTEDY, gdy jest osoba, którą da się z imienia pokazać —
 * dwa zapytania nie mogą się rozjechać co do tego, kto jest „widoczny".
 */
class ZapisujacyDoPokazaniaZgodniZListaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiersz_na_liscie_i_imie_w_naglowku_zgadzaja_sie_w_calej_macierzy(): void
    {
        $autor = $this->user('zgodnosc_autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $aktywna = $this->user('zgodnosc_aktywna', ['display_name' => 'Aktywna']);
        $zablokowanaPrzezAutora = $this->user('zgodnosc_zablokowana_a');
        $blokujacaAutora = $this->user('zgodnosc_blokujaca');
        $zbanowana = $this->user('zgodnosc_zbanowana');
        $doUsuniecia = $this->user('zgodnosc_do_usuniecia');
        $zawieszona = $this->user('zgodnosc_zawieszona', ['display_name' => 'Zawieszona']);

        foreach ([$zablokowanaPrzezAutora, $blokujacaAutora, $zbanowana, $doUsuniecia, $zawieszona, $aktywna] as $kto) {
            app(SaveRecipeToCollection::class)->handle($kto, $przepis);
        }

        $partia = fn (): Notification => Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();
        $naLiscie = fn (): bool => $autor->notifications()
            ->visibleTo($autor)
            ->whereKey($partia()->getKey())
            ->exists();
        $imie = fn (): ?string => $partia()->fresh()->zapisujacyDoPokazania()?->getKey();

        // Kontrola danych: wszystkie sześć osób jest w partii.
        $this->assertCount(6, $partia()->data['savers']);

        // Krok 1: nikt nie ukryty poza tymi, których ukrywamy poniżej.
        app(BlockUser::class)->handle($autor, $zablokowanaPrzezAutora);
        app(BlockUser::class)->handle($blokujacaAutora, $autor);
        $zbanowana->forceFill(['status' => User::STATUS_BANNED])->save();
        $doUsuniecia->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        // Zawieszona (suspended) NIE ukrywa treści — jest pierwszą widoczną.
        $zawieszona->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertTrue($naLiscie());
        $this->assertSame($zawieszona->getKey(), $imie(), 'Pierwsza widoczna w kolejności zapisu.');

        // Krok 2: znika kolejna, imię przechodzi na następną, wiersz zostaje.
        $zawieszona->forceFill(['status' => User::STATUS_BANNED])->save();
        $this->assertTrue($naLiscie());
        $this->assertSame($aktywna->getKey(), $imie());

        // Krok 3: nikt widoczny — wiersz znika z listy i nie ma imienia.
        $aktywna->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $this->assertFalse($naLiscie());
        $this->assertNull($imie());

        // Krok 4: odblokowanie i powrót statusu przywracają oba naraz.
        app(BlockUser::class)->handle($autor, $aktywna); // kontrola: blokada nie psuje zgodności
        $aktywna->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->assertFalse($naLiscie());
        $this->assertNull($imie());

        DB::table('blocks')->where('blocked_id', $aktywna->getKey())->delete();
        $this->assertTrue($naLiscie());
        $this->assertSame($aktywna->getKey(), $imie());
    }

    public function test_pusta_lista_zapisujacych_nie_pyta_bazy(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $wynik = app(WidocznoscPowiadomien::class)->pierwszyWidocznyZapisujacy([], 'nieistotne');
        DB::disableQueryLog();

        $this->assertNull($wynik);
        $this->assertCount(0, DB::getQueryLog());
    }
}
