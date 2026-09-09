<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `collection_items` nie ma klucza głównego — i ma go nie mieć (audyt G13).
 *
 * SKĄD TEN TEST
 * `docs/DATABASE.md` mówił w jednym miejscu prawdę („klucz musiał zniknąć,
 * zastępują go dwa indeksy częściowe"), a osiemdziesiąt linijek niżej —
 * nieprawdę: „`collection_items` ma `PRIMARY KEY (collection_id, recipe_id)`
 * (…) zgłoszenie było nieaktualne, klucz jest na miejscu". Ten drugi akapit
 * został z czasu sprzed migracji `2026_09_06_150000_collection_items_accept_posts`
 * i nikt go nie poprawił, bo NIC GO NIE SPRAWDZAŁO.
 *
 * DLACZEGO TO NIE JEST TYLKO LITERÓWKA W DOKUMENCIE
 * Dokument z takim zdaniem jest instrukcją naprawy dla następnej osoby.
 * Ktoś porówna `\d collection_items` z `DATABASE.md`, zobaczy brakujący klucz
 * i „przywróci" go migracją. Klucz główny wymaga `recipe_id NOT NULL`, więc
 * ta migracja musiałaby najpierw SKASOWAĆ wszystkie zapisane wpisy z zeszytów
 * — bo w nich `recipe_id` jest NULL, a wypełniony jest `post_id`. Utrata
 * danych z powodu zdania w dokumentacji.
 *
 * Dlatego test pilnuje OBU STRON naraz: rzeczywistego schematu i tego, czego
 * dokument o nim nie ma prawa twierdzić.
 */
class ZeszytBezKluczaGlownegoTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabela_nie_ma_klucza_glownego_i_ma_dwa_indeksy_czesciowe(): void
    {
        $klucze = DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'collection_items'::regclass AND contype = 'p'",
        );

        $this->assertSame(
            [],
            $klucze,
            'Ktoś przywrócił klucz główny na `collection_items`. Klucz główny wymaga '
            .'`recipe_id NOT NULL`, a zapisane WPISY mają tam NULL — więc ta migracja '
            .'kasuje wpisy z zeszytów. Poprawny stan opisuje docs/DATABASE.md, sekcja '
            .'„collection_items — przepisy ORAZ wpisy".',
        );

        $indeksy = array_column(
            DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'collection_items'"),
            'indexname',
        );

        // Bez tych dwóch nie ma czym zastąpić klucza — a wtedy „brak klucza
        // głównego" wyżej znaczyłoby „ta sama pozycja może stanąć w zeszycie
        // dwa razy", czyli dokładnie issue #43 z powrotem.
        $this->assertContains('collection_items_recipe_unique', $indeksy);
        $this->assertContains('collection_items_post_unique', $indeksy);
    }

    /**
     * Kontrola metody pomiaru: zapytanie o klucz główny UMIE go znaleźć.
     *
     * Bez tego asercja „pusta tablica" wyżej przechodziłaby także wtedy, gdyby
     * zapytanie było źle napisane albo `contype = 'p'` nic nie znaczyło.
     */
    public function test_kontrola_to_samo_zapytanie_znajduje_klucz_glowny_w_post_media(): void
    {
        $klucze = array_column(
            DB::select(
                "SELECT conname FROM pg_constraint WHERE conrelid = 'post_media'::regclass AND contype = 'p'",
            ),
            'conname',
        );

        $this->assertSame(['post_media_pkey'], $klucze);
    }

    public function test_dokumentacja_nie_przypisuje_zeszytowi_klucza_glownego(): void
    {
        $dokument = (string) file_get_contents(base_path('docs/DATABASE.md'));

        // Szukamy TWIERDZENIA, nie samego słowa: „collection_items ma
        // PRIMARY KEY". Zdanie „klucz główny musiał zniknąć" i ostrzeżenie
        // „nie przywracaj tu klucza głównego" mają zostać.
        $this->assertDoesNotMatchRegularExpression(
            '/`?collection_items`?\s+ma\s+`?PRIMARY KEY/iu',
            $dokument,
            'docs/DATABASE.md znowu twierdzi, że `collection_items` ma klucz główny. '
            .'Nie ma i mieć nie może — patrz test wyżej.',
        );
    }

    /**
     * Okres retencji powiadomień w dokumencie ma zgadzać się z konfiguracją.
     *
     * To jest ta sama usterka co wyżej, tylko na liczbie: `DATABASE.md`
     * podawał 24 miesiące, `config/kuking.php` i polityka prywatności — 3.
     * W samym dokumencie stoi zresztą ślad, że to się już raz zdarzyło przy
     * `audit_log` („Stało tu «24 miesiące»"). Dwa razy to nie przypadek,
     * tylko brak testu.
     *
     * Czytamy TYLKO sekcję `### notifications`, żeby nie łapać liczb
     * z sąsiednich tabel (36 miesięcy przy sprawach moderacyjnych, 12 przy
     * audycie) — one są prawdziwe i mają zostać.
     */
    public function test_dokumentacja_podaje_ten_sam_okres_retencji_powiadomien_co_konfiguracja(): void
    {
        $dokument = (string) file_get_contents(base_path('docs/DATABASE.md'));

        $this->assertSame(
            1,
            preg_match('/^### notifications$.*?(?=^### )/msu', $dokument, $dopasowanie),
            'Nie znaleziono sekcji „### notifications" w docs/DATABASE.md. '
            .'Jeśli sekcję przemianowano, popraw ten test razem z nią — '
            .'inaczej przestanie cokolwiek sprawdzać.',
        );

        $sekcja = $dopasowanie[0];
        $miesiecy = (int) config('kuking.notifications.retention_months');

        $this->assertStringContainsString(
            "**{$miesiecy} miesiące**",
            $sekcja,
            "Sekcja `notifications` w docs/DATABASE.md nie podaje {$miesiecy} miesięcy, "
            .'czyli tego, co naprawdę robi `kuking:sprzataj-powiadomienia`. '
            .'Tę samą liczbę widzi człowiek w polityce prywatności.',
        );

        // Kontrola drugiej strony: żadnej INNEJ liczby miesięcy w tej sekcji
        // być nie może. Bez tego dopisanie „(dawniej 24 miesiące)" przeszłoby
        // bez echa i dokument znowu podawałby dwie różne liczby naraz.
        preg_match_all('/(\d+)\s+miesi/u', $sekcja, $wszystkie);

        $this->assertSame(
            [(string) $miesiecy],
            array_values(array_unique($wszystkie[1])),
            'Sekcja `notifications` podaje więcej niż jedną liczbę miesięcy. '
            .'Dwie liczby w jednym akapicie znaczą, że czytający wybierze złą. '
            .'Wyjątek dla powiadomień o decyzji moderacyjnej opisujemy przez '
            .'`ModerationAction::appealDeadline()`, nie przez drugą liczbę.',
        );
    }
}
