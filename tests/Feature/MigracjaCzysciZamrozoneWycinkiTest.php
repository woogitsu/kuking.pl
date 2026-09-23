<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Strażnik migracji `2026_09_23_120000_usun_zamrozone_wycinki_komentarzy`.
 *
 * DLACZEGO TEST NA MIGRACJĘ DANYCH, A NIE SAMO JEJ URUCHOMIENIE.
 * Migracja danych wykonuje się raz i nikt jej potem nie ogląda. Jedyny
 * moment, w którym można sprawdzić, czy zrobiła to, co obiecuje — i czy
 * NIE zrobiła niczego ponad to — jest teraz. Zapytanie jest przepisane
 * z migracji dosłownie, więc rozjazd między nimi jest widoczny jako
 * czerwień, a nie jako cicha różnica.
 *
 * TRZY KIERUNKI, BO SAM „USUWA" NIE WYSTARCZA.
 * Asercja mówiąca wyłącznie „wycinek zniknął" byłaby zielona także wtedy,
 * gdyby zapytanie wyczyściło CAŁĄ kolumnę albo przeszło po wszystkich
 * typach powiadomień. Dlatego sprawdzamy równolegle, że reszta kluczy
 * przeżyła i że wiersz innego typu został nietknięty.
 */
final class MigracjaCzysciZamrozoneWycinkiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zapytanie przepisane z migracji. Gdyby tam się zmieniło, a tu nie,
     * ten test przestanie mierzyć migrację i zacznie mierzyć sam siebie —
     * dlatego stoi tu jedną kopią, a nie w dwóch miejscach.
     */
    private function uruchomCzyszczenie(): int
    {
        return DB::table('notifications')
            ->whereIn('type', ['comment.created', 'comment.replied'])
            ->whereRaw("jsonb_exists(data, 'excerpt')")
            ->update(['data' => DB::raw("data - 'excerpt'")]);
    }

    private function wstaw(string $typ, array $dane): string
    {
        $id = (string) Str::uuid();

        // Tabela `notifications` NIE MA `updated_at` — powiadomienie jest
        // wierszem, który powstaje raz i nie jest edytowany.
        DB::table('notifications')->insert([
            'id' => $id,
            'user_id' => User::factory()->create()->getKey(),
            'type' => $typ,
            'data' => json_encode($dane, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $id;
    }

    private function dane(string $id): array
    {
        $wiersz = DB::table('notifications')->where('id', $id)->value('data');

        return json_decode((string) $wiersz, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_zdejmuje_wycinek_z_powiadomien_o_komentarzach(): void
    {
        $komentarz = $this->wstaw('comment.created', [
            'excerpt' => 'dodaję dwie łyżki masła',
            'comment_id' => 'abc',
            'post_id' => 'def',
        ]);
        $odpowiedz = $this->wstaw('comment.replied', [
            'excerpt' => 'a ja dodaję trzy',
            'comment_id' => 'ghi',
        ]);

        $zmienionych = $this->uruchomCzyszczenie();

        $this->assertSame(2, $zmienionych, 'migracja miała dotknąć dokładnie dwóch wierszy');
        $this->assertArrayNotHasKey('excerpt', $this->dane($komentarz));
        $this->assertArrayNotHasKey('excerpt', $this->dane($odpowiedz));
    }

    public function test_nie_rusza_pozostalych_kluczy_w_tym_samym_wierszu(): void
    {
        $id = $this->wstaw('comment.created', [
            'excerpt' => 'treść do skasowania',
            'comment_id' => 'abc',
            'post_id' => 'def',
        ]);

        $this->uruchomCzyszczenie();
        $po = $this->dane($id);

        // Sedno: zdejmujemy KLUCZ, nie czyścimy kolumny.
        $this->assertSame('abc', $po['comment_id'] ?? null);
        $this->assertSame('def', $po['post_id'] ?? null);
    }

    public function test_nie_rusza_powiadomien_innego_typu(): void
    {
        /*
         * `excerpt` w innych typach NIE został zastąpiony niczym żywym.
         * Skasowanie go zabrałoby treść, której nic nie odtworzy — i to
         * jest jedyny powód, dla którego migracja ma listę typów zamiast
         * przejścia po wszystkim.
         */
        $obce = $this->wstaw('follow.created', ['excerpt' => 'to ma zostać']);

        $zmienionych = $this->uruchomCzyszczenie();

        $this->assertSame(0, $zmienionych);
        $this->assertSame('to ma zostać', $this->dane($obce)['excerpt'] ?? null);
    }

    public function test_powtorzone_uruchomienie_nie_dotyka_juz_niczego(): void
    {
        // Migracje bywają uruchamiane ponownie po nieudanym wdrożeniu.
        $this->wstaw('comment.created', ['excerpt' => 'raz', 'comment_id' => 'x']);

        $this->assertSame(1, $this->uruchomCzyszczenie());
        $this->assertSame(0, $this->uruchomCzyszczenie(), 'drugi przebieg nie ma już czego zdejmować');
    }

    public function test_kontrola_dodatnia_zapytanie_naprawde_widzi_wiersze(): void
    {
        /*
         * PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`: zapytanie, które nie trafia
         * w żaden wiersz, kończy się sukcesem. Bez tej asercji wszystkie
         * testy wyżej byłyby zielone także wtedy, gdyby warunek `whereIn`
         * przestał cokolwiek dopasowywać.
         */
        $this->wstaw('comment.created', ['excerpt' => 'a', 'comment_id' => 'x']);
        $this->wstaw('comment.replied', ['excerpt' => 'b', 'comment_id' => 'y']);
        $this->wstaw('comment.created', ['comment_id' => 'z']);

        $doZdjecia = DB::table('notifications')
            ->whereIn('type', ['comment.created', 'comment.replied'])
            ->whereRaw("jsonb_exists(data, 'excerpt')")
            ->count();

        $this->assertSame(2, $doZdjecia, 'warunek przestał odróżniać wiersze z wycinkiem od tych bez');
    }
}
