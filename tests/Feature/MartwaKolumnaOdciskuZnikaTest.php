<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `media.perceptual_hash` znika, a strażnik przy `up()` naprawdę odmawia.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Przegląd schematu z D-166 wymienił trzy kolumny podejrzane o martwotę.
 * Weryfikacja w kodzie potwierdziła martwotę TYLKO tej jednej: jedynym jej
 * wystąpieniem w kodzie produkcyjnym był `$fillable` modelu `Media`.
 * `collection_items.note` i `daily_picks.note` okazały się ŻYWE i zostają —
 * pilnują ich `DataExportTest` i `DailyBoardTest`, które oblewają się, gdy
 * któraś z tych dwóch kolumn zniknie.
 *
 * DLACZEGO STRAŻNIK STOI PRZY `up()`, A NIE PRZY `down()`
 * W tej migracji kierunkiem niszczącym jest `up()` — to ono kasuje kolumnę.
 * `down()` ją odtwarza i nie ma czego zgubić, bo kolumna była pusta (D-088:
 * rozstrzygnięcie i jego uzasadnienie stoją w nagłówku migracji, nie
 * w milczeniu). Wzorzec odmowy z `create_dziennik_zgod_table` zostaje więc
 * zastosowany po właściwej stronie.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że kolumna jest pusta NA PRODUKCJI. Tego z bazy testowej wyprowadzić się
 * nie da i dlatego właśnie strażnik w `up()` istnieje: gdyby ustalenie z D-166
 * okazało się nieaktualne w chwili wdrożenia, migracja zatrzyma wdrożenie
 * zamiast skasować dane bez śladu.
 */
class MartwaKolumnaOdciskuZnikaTest extends TestCase
{
    use RefreshDatabase;

    private const FURTKA = 'KUKING_USUN_NIEPUSTY_PERCEPTUAL_HASH';

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_12_100000_usun_martwa_kolumne_perceptual_hash.php',
        );
    }

    /** Czy kolumna jest w ŻYWEJ bazie — czytane z `information_schema`, nie z plików. */
    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['media', 'perceptual_hash'],
        ) !== [];
    }

    public function test_po_migracjach_kolumny_nie_ma_w_bazie(): void
    {
        $this->assertFalse(
            $this->kolumnaIstnieje(),
            'Kolumna media.perceptual_hash nadal jest w bazie po wykonaniu migracji.',
        );

        // KONTROLA DODATNIA: sam brak trafienia niczego nie dowodzi — zapytanie
        // z literówką w nazwie tabeli też nic nie zwróci (pułapka 4
        // z docs/PULAPKI_TESTOW.md). Sąsiednia kolumna tej samej tabeli
        // dowodzi, że patrzymy we właściwe miejsce.
        $this->assertNotSame(
            [],
            DB::select(
                'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                ['media', 'checksum_sha256'],
            ),
            'Nie widać nawet checksum_sha256 — to zapytanie pyta o złą tabelę.',
        );
    }

    public function test_model_media_nie_obiecuje_juz_tego_pola(): void
    {
        // `$fillable` z kolumną, której nie ma, to zaproszenie do
        // `SQLSTATE[42703]` przy pierwszym `create()` z tym kluczem.
        $this->assertNotContains('perceptual_hash', (new Media)->getFillable());

        // KONTROLA DODATNIA: lista w ogóle jest czytana i nie jest pusta.
        $this->assertContains('checksum_sha256', (new Media)->getFillable());
    }

    public function test_cofniecie_przywraca_kolumne_w_tej_samej_postaci(): void
    {
        $this->migracja()->down();

        $opis = DB::select(
            'SELECT data_type, character_maximum_length, is_nullable
             FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            ['media', 'perceptual_hash'],
        );

        $this->assertCount(1, $opis, 'Cofnięcie migracji nie przywróciło kolumny.');
        $this->assertSame('character varying', $opis[0]->data_type);
        $this->assertSame(128, (int) $opis[0]->character_maximum_length);
        $this->assertSame('YES', $opis[0]->is_nullable);

        // Baza wraca do stanu po migracjach, żeby nie zostawić jej w połowie
        // drogi dla kolejnych testów w tym samym procesie.
        $this->migracja()->up();
        $this->assertFalse($this->kolumnaIstnieje());
    }

    public function test_migracja_odmawia_gdy_kolumna_jednak_ma_dane(): void
    {
        $this->migracja()->down();

        $zdjecie = Media::factory()->create();
        DB::table('media')->where('id', $zdjecie->getKey())
            ->update(['perceptual_hash' => 'ff00ff00ff00ff00']);

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, która dziedziczy po
        // `RuntimeException` — postawiona wewnątrz `try` wpadłaby do własnego
        // `catch` i test „przeszedłby" z powodu własnej porażki.
        $odmowa = null;

        try {
            $this->migracja()->up();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Migracja skasowała kolumnę razem z danymi.');

        // Komunikat ma mówić ILE się straci i CO ZROBIĆ. Liczba w formie
        // odpornej na odmianę przez liczbę (D-132) — jeden wiersz jest stanem
        // bardziej prawdopodobnym niż pięć.
        $this->assertStringContainsString('Liczba zapisów, które znikną: 1.', (string) $odmowa->getMessage());
        $this->assertStringContainsString('\\copy', (string) $odmowa->getMessage());
        $this->assertStringContainsString(self::FURTKA, (string) $odmowa->getMessage());

        // NAJWAŻNIEJSZE: dane NADAL SĄ. Odmowa, która zdążyła skasować,
        // to tylko ładniejszy komunikat o stracie.
        $this->assertTrue($this->kolumnaIstnieje());
        $this->assertSame(
            1,
            DB::table('media')->whereNotNull('perceptual_hash')->count(),
            'Odmowa poleciała już po skasowaniu kolumny.',
        );

        $this->posprzataj();
    }

    public function test_na_pustej_kolumnie_migracja_przechodzi_bez_pytania(): void
    {
        // KONTROLA DODATNIA do testu wyżej. Bez niej ten sam komplet
        // przechodziłby także dla migracji, która odmawia ZAWSZE — czyli
        // blokuje wdrożenie na każdym środowisku. To błąd tej samej wagi
        // w drugą stronę (AGENTS.md §6: „odmowa musi być WĄSKA").
        $this->migracja()->down();

        // Wiersz JEST, tylko bez odcisku. Inaczej test przeszedłby także
        // dlatego, że tabela jest pusta — a to nie to samo co „kolumna pusta".
        Media::factory()->create();
        $this->assertSame(1, DB::table('media')->count());

        $this->migracja()->up();

        $this->assertFalse($this->kolumnaIstnieje(), 'Migracja nie usunęła pustej kolumny.');
    }

    public function test_furtka_ze_zmiennej_srodowiskowej_naprawde_dziala(): void
    {
        // Migracja z furtką dostaje czwarty obowiązek (D-132): droga opisana
        // w komunikacie odmowy ma naprawdę otwierać. Furtka, która nie działa,
        // zostawia właściciela z instrukcją prowadzącą donikąd.
        $this->migracja()->down();

        $zdjecie = Media::factory()->create();
        DB::table('media')->where('id', $zdjecie->getKey())
            ->update(['perceptual_hash' => 'ff00ff00ff00ff00']);

        putenv(self::FURTKA.'=true');

        try {
            $this->migracja()->up();
        } finally {
            putenv(self::FURTKA);
        }

        $this->assertFalse(
            $this->kolumnaIstnieje(),
            'Furtka opisana w komunikacie odmowy nie otwiera.',
        );
    }

    /** Przywraca stan po migracjach, cokolwiek test zdążył zmienić. */
    private function posprzataj(): void
    {
        DB::table('media')->update(['perceptual_hash' => null]);
        $this->migracja()->up();

        if (Schema::hasColumn('media', 'perceptual_hash')) {
            $this->fail('Nie udało się przywrócić stanu bazy po teście.');
        }
    }
}
