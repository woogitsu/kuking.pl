<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kolumny, po których pytamy przy każdym żądaniu albo pod blokadą, mają
 * indeks z tą kolumną jako WIODĄCĄ (audyt B3 W1/W2/W5, B4 W4/W5/N13).
 *
 * Najważniejszy jest pierwszy test: przechodzi po `DostepDoZdjecia::ODWOLANIA`,
 * a nie po liście przepisanej z palca. Nowa kolumna wskazująca na `media`
 * dopisana do `ODWOLANIA` bez indeksu wyjdzie tutaj — tak samo, jak kolumna
 * nieobecna na tej liście wychodzi z `AutoryzacjaZdjeciaJednymPrzejsciemTest`.
 *
 * „Wiodąca" jest istotne: `post_media.media_id` od zawsze była w indeksie —
 * jako druga kolumna klucza głównego `(post_id, media_id)`. Taki indeks nie
 * zawęża wyszukiwania po `media_id`, więc test go nie liczy.
 *
 * @bez-kontroli-dodatniej database_path() służy tylko do require migracji, a assertStringContainsString sprawdza definicję indeksu z pg_indexes, nie treść źródła; kontrola dodatnia wykrywacza stoi w test_wykrywacz_indeksu_wiodacego_odpowiada_takze_przeczaco.
 */
class IndeksyKluczyObcychNaGoracychSciezkachTest extends TestCase
{
    use RefreshDatabase;

    /** Kolumny spoza `ODWOLANIA` objęte tą samą migracją. */
    private const POZOSTALE = [
        ['posts', 'recipe_id'],
        ['notifications', 'actor_id'],
        ['product_signals', 'user_id'],
    ];

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_25_100000_indeksy_kluczy_obcych_na_goracych_sciezkach.php');
    }

    public function test_kazde_odwolanie_do_zdjecia_ma_indeks_wiodacy(): void
    {
        $this->assertNotEmpty(DostepDoZdjecia::ODWOLANIA);

        $bez = array_filter(
            DostepDoZdjecia::ODWOLANIA,
            fn (array $para): bool => ! $this->maIndeksWiodacy($para[0], $para[1]),
        );

        $this->assertSame([], array_values($bez), 'Kolumna wskazująca na media bez indeksu z nią jako wiodącą: '
            .'każde wydanie zdjęcia (DostepDoZdjecia::rozstrzygnij) skanowałoby tę tabelę w całości. '
            .'Dołóż indeks (CREATE INDEX CONCURRENTLY w migracji bez transakcji).');
    }

    public function test_klucze_obce_na_goracych_sciezkach_maja_indeks_wiodacy(): void
    {
        foreach (self::POZOSTALE as [$tabela, $kolumna]) {
            $this->assertTrue($this->maIndeksWiodacy($tabela, $kolumna), "{$tabela}.{$kolumna} bez indeksu wiodącego.");
        }
    }

    public function test_indeks_sygnalow_obejmuje_nazwe_sygnalu(): void
    {
        $definicja = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'product_signals_user_signal_idx'");

        $this->assertNotNull($definicja);
        $this->assertStringContainsString('(user_id, signal_name)', $definicja->indexdef);
    }

    public function test_wykrywacz_indeksu_wiodacego_odpowiada_takze_przeczaco(): void
    {
        // Druga kolumna klucza głównego NIE jest wiodąca — to dokładnie stan
        // sprzed migracji dla post_media.media_id.
        $this->assertFalse($this->maIndeksWiodacy('post_media', 'position'));

        DB::statement('DROP INDEX post_media_media_idx');
        $this->assertFalse($this->maIndeksWiodacy('post_media', 'media_id'));

        DB::statement('DROP INDEX posts_recipe_idx');
        $this->assertFalse($this->maIndeksWiodacy('posts', 'recipe_id'));
    }

    public function test_cofniecie_zdejmuje_indeksy_a_ponowienie_je_przywraca(): void
    {
        $migracja = $this->migracja();

        $migracja->down();

        foreach ([...DostepDoZdjecia::ODWOLANIA, ...self::POZOSTALE] as [$tabela, $kolumna]) {
            if ($tabela === 'hero_picks') {
                continue; // unikalny indeks sprzed tej migracji, nie jej
            }
            $this->assertFalse($this->maIndeksWiodacy($tabela, $kolumna), "{$tabela}.{$kolumna} po down().");
        }

        $migracja->up();
        $migracja->up(); // drugi raz bez błędu: IF NOT EXISTS

        foreach ([...DostepDoZdjecia::ODWOLANIA, ...self::POZOSTALE] as [$tabela, $kolumna]) {
            $this->assertTrue($this->maIndeksWiodacy($tabela, $kolumna), "{$tabela}.{$kolumna} po up().");
        }
    }

    public function test_niedokonczony_indeks_jest_budowany_od_nowa(): void
    {
        if (! DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->rolsuper) {
            $this->markTestSkipped('Oznaczenie indeksu jako INVALID wymaga roli superużytkownika (CI ją ma).');
        }

        DB::statement('DROP INDEX posts_recipe_idx');
        // Tak zostaje po przerwanym CREATE INDEX CONCURRENTLY: nazwa zajęta,
        // indeks INVALID. IF NOT EXISTS sam by go przepuścił.
        DB::statement('CREATE INDEX posts_recipe_idx ON posts (published_at)');
        DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'posts_recipe_idx'::regclass");

        $this->migracja()->up();

        $this->assertTrue($this->maIndeksWiodacy('posts', 'recipe_id'));
    }

    private function maIndeksWiodacy(string $tabela, string $kolumna): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i '
            .'JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0] '
            .'WHERE i.indrelid = ?::regclass AND a.attname = ? AND i.indisvalid',
            [$tabela, $kolumna],
        ) !== null;
    }
}
