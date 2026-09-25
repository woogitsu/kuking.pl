<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Social\Actions\BlockUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-070: zbiorcze powiadomienie o zapisie (`NotifyRecipeSaved`) NIE idzie
 * przez `NotifyUser`, więc granice, które `NotifyUser` trzyma dla każdego
 * innego powiadomienia, musi trzymać samo. Ten plik mierzy każdą z nich —
 * i dla PIERWSZEGO zapisu (nowy wiersz), i dla DOŁĄCZENIA do otwartej partii
 * (aktualizacja istniejącego wiersza), bo to są dwie różne ścieżki kodu.
 *
 * Granice (AGENTS.md, „trzy przypadki, w których powiadomienia nie ma"
 * + docblock `NotifyUser`): własna akcja (osobny test
 * w `ZbiorczePowiadomienieOZapisieTest`), konto autora zamknięte, blokada
 * w którąkolwiek stronę. Zawieszony AUTOR powiadomienie dostaje.
 * Dochodzi czwarta, właściwa zapisowi: zapis z konta, które nie jest
 * aktywne, nikogo nie powiadamia (decyzja właściciela #926).
 */
class ZbiorczyZapisTrzymaGranicePowiadomienTest extends TestCase
{
    use RefreshDatabase;

    public function test_blokada_ustawiona_przez_autora_nie_powiadamia_ani_nie_dopisuje_do_partii(): void
    {
        $autor = $this->user('granica_autor_blokuje');
        $pierwsza = $this->user('granica_pierwsza_1');
        $zablokowana = $this->user('granica_zablokowana');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $drugiPrzepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(BlockUser::class)->handle($autor, $zablokowana);

        // Partia otwarta przez pierwszą osobę — zablokowana nie dołącza.
        // (Każda osoba zapisuje RAZ: drugi zeszyt tej samej osoby wraca
        // wcześniej, jeszcze przed granicami, i nic by tu nie zmierzył.)
        $this->zapisz($pierwsza, $przepis);
        $this->zapiszOdmowa($zablokowana, $przepis);

        $partia = $this->jedynaPartia($autor);
        $this->assertSame([$pierwsza->getKey()], $partia->data['savers']);
        $this->assertSame(0, $partia->data['others_count']);

        // Sama, bez otwartej partii — nowy wiersz też nie powstaje.
        $this->zapiszOdmowa($zablokowana, $drugiPrzepis);
        $this->assertSame(1, $this->ileZapisow($autor));
    }

    public function test_blokada_ustawiona_przez_zapisujaca_osobe_tez_odcina(): void
    {
        $autor = $this->user('granica_autor_zablokowany');
        $pierwsza = $this->user('granica_pierwsza_2');
        $blokujaca = $this->user('granica_blokujaca');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $drugiPrzepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(BlockUser::class)->handle($blokujaca, $autor);

        $this->zapisz($pierwsza, $przepis);
        $this->zapiszOdmowa($blokujaca, $przepis);
        $this->assertSame([$pierwsza->getKey()], $this->jedynaPartia($autor)->data['savers']);

        $this->zapiszOdmowa($blokujaca, $drugiPrzepis);
        $this->assertSame(1, $this->ileZapisow($autor));
    }

    public function test_wymazane_konto_autora_nie_dostaje_powiadomienia(): void
    {
        $autor = $this->user('granica_autor_wymazany');
        $osoba = $this->user('granica_przy_wymazanym');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $autor->markDataErased();
        $this->assertFalse($autor->fresh()->mozeCzytac(), 'Kontrola danych: wymazane konto nie czyta.');

        $this->zapisz($osoba, $przepis->fresh());

        $this->assertSame(0, $this->ileZapisow($autor));
    }

    public function test_zawieszony_autor_powiadomienie_dostaje(): void
    {
        $autor = $this->user('granica_autor_zawieszony');
        $osoba = $this->user('granica_przy_zawieszonym');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $autor->suspend(now()->addDay());

        $this->zapisz($osoba, $przepis->fresh());

        $this->assertSame(1, $this->ileZapisow($autor));
    }

    public function test_zapis_z_zawieszonego_konta_nie_powiadamia_ani_nie_dopisuje_do_partii(): void
    {
        $autor = $this->user('granica_autor_4');
        $pierwsza = $this->user('granica_pierwsza_4');
        $zawieszona = $this->user('granica_zawieszona');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $drugiPrzepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $zawieszona->suspend(now()->addDay());
        $zawieszona = $zawieszona->fresh();
        $this->assertFalse($zawieszona->isActive(), 'Kontrola danych: konto naprawdę zawieszone.');

        $this->zapisz($pierwsza, $przepis);
        $this->zapisz($zawieszona, $przepis);
        $this->assertSame([$pierwsza->getKey()], $this->jedynaPartia($autor)->data['savers']);

        $this->zapisz($zawieszona, $drugiPrzepis);
        $this->assertSame(1, $this->ileZapisow($autor));
    }

    private function zapisz(User $kto, Recipe $przepis): void
    {
        app(SaveRecipeToCollection::class)->handle($kto, $przepis);
    }

    /**
     * Blokada w którąkolwiek stronę odcina już SAM zapis (`ZamekZapisuDoZeszytu`
     * pyta Policy pod zamkami) — więc tym bardziej powiadomienie i dopisanie
     * do partii. Odmowa ma być zdaniem dla człowieka, a zeszyt ma zostać pusty.
     */
    private function zapiszOdmowa(User $kto, Recipe $przepis): void
    {
        try {
            $this->zapisz($kto, $przepis);
            $this->fail('Zapis przez blokadę miał zostać odrzucony.');
        } catch (BladDlaCzlowieka) {
            // oczekiwane
        }

        $this->assertSame(0, DB::table('collection_items')
            ->where('recipe_id', $przepis->getKey())
            ->whereIn('collection_id', $kto->collections()->select('id'))
            ->count());
    }

    private function ileZapisow(User $autor): int
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->count();
    }

    private function jedynaPartia(User $autor): Notification
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->sole();
    }
}
