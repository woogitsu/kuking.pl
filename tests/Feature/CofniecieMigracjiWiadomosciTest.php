<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan wycofania migracji `create_contact_messages_table` jest WYKONANY,
 * nie tylko opisany.
 *
 * AGENTS.md §6 wymaga opisu rollbacku przy każdej zmianie schematu. Opis bez
 * przebiegu jest jednak wart tyle, co komentarz: `down()`, którego nikt nigdy
 * nie uruchomił, zwykle nie działa — a uruchamia się go w najgorszym możliwym
 * momencie, przy wycofywaniu wdrożenia.
 *
 * Ten plik sprawdza obie strony (`down()` i ponowne `up()`) ORAZ te
 * ograniczenia bazy, które są jedyną prawdziwą barierą przy zapisie
 * z pominięciem modelu.
 */
class CofniecieMigracjiWiadomosciTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_09_100000_create_contact_messages_table.php',
        );
    }

    /**
     * Migracja odpowiedzi operatora (D-058). `contact_message_replies`
     * WSKAZUJE na `contact_messages`, więc tamtej tabeli nie da się skasować,
     * dopóki ta stoi — PostgreSQL odmawia `DROP TABLE` z zależnym kluczem
     * obcym (`SQLSTATE 2BP01`), i to jest zachowanie pożądane: kaskada
     * kasująca WIERSZE nie ma prawa zamienić się w kaskadę kasującą TABELE
     * przy pomyłce w rollbacku.
     */
    private function migracjaOdpowiedzi(): object
    {
        return require database_path(
            'migrations/2026_09_10_200000_create_contact_message_replies_table.php',
        );
    }

    private function tabelaIstnieje(string $nazwa = 'contact_messages'): bool
    {
        return DB::select(
            'SELECT table_name FROM information_schema.tables WHERE table_name = ?',
            [$nazwa],
        ) !== [];
    }

    public function test_cofniecie_i_ponowne_zalozenie_dziala(): void
    {
        ContactMessage::factory()->create();

        $this->assertTrue($this->tabelaIstnieje());

        // KOLEJNOŚĆ JAK W PRAWDZIWYM `migrate:rollback`: OD NAJNOWSZEJ.
        // Odpowiedzi operatora (D-058) wskazują kluczem obcym na tę tabelę,
        // więc próba cofnięcia samej starszej migracji kończy się odmową
        // bazy. Ten test przechodził, dopóki tamtej tabeli nie było — i to
        // on złapał zależność, gdy powstała.
        $this->migracjaOdpowiedzi()->down();
        $this->migracja()->down();
        $this->assertFalse($this->tabelaIstnieje(), 'down() nie skasował tabeli.');
        $this->assertFalse($this->tabelaIstnieje('contact_message_replies'));

        $this->migracja()->up();
        $this->migracjaOdpowiedzi()->up();
        $this->assertTrue($this->tabelaIstnieje(), 'Ponowne up() nie odtworzyło tabeli.');
        $this->assertTrue($this->tabelaIstnieje('contact_message_replies'));

        // Odtworzona tabela musi być TA SAMA, nie „podobna": jeśli któryś
        // CHECK albo indeks powstaje tylko przy pierwszym przebiegu,
        // wycofanie i ponowne wdrożenie zostawiłoby bazę bez ochrony,
        // o której nikt by nie wiedział.
        $this->assertNotNull(ContactMessage::factory()->create()->getKey());
        $this->assertTrue($this->ograniczenieIstnieje('contact_messages_status_check'));
        $this->assertTrue($this->ograniczenieIstnieje('contact_messages_kind_check'));
        $this->assertTrue($this->ograniczenieIstnieje('contact_messages_handled_complete'));
        $this->assertTrue($this->indeksIstnieje('contact_messages_one_per_klucz_wyslania'));
        $this->assertTrue($this->ograniczenieIstnieje('contact_message_replies_status_check'));
        $this->assertTrue($this->ograniczenieIstnieje('contact_message_replies_sent_complete'));
        $this->assertTrue($this->ograniczenieIstnieje('contact_message_replies_body_not_blank'));
        $this->assertTrue($this->indeksIstnieje('contact_message_replies_message_idx'));
    }

    /**
     * Cofnięcie SAMEJ migracji odpowiedzi (D-058) zostawia wiadomości
     * nietknięte.
     *
     * To jest realna droga wycofania tej zmiany: znika formularz odpowiedzi
     * w panelu, wraca stan sprzed niej (`mailto:` na karcie wiadomości), a to,
     * co ludzie napisali, zostaje.
     */
    public function test_cofniecie_samych_odpowiedzi_nie_rusza_wiadomosci(): void
    {
        $wiadomosc = ContactMessage::factory()->create();

        $this->migracjaOdpowiedzi()->down();

        $this->assertFalse($this->tabelaIstnieje('contact_message_replies'));
        $this->assertDatabaseHas('contact_messages', ['id' => $wiadomosc->getKey()]);

        $this->migracjaOdpowiedzi()->up();
    }

    /**
     * Baza nie przyjmie odpowiedzi „wysłanej" bez godziny wysłania — ani
     * odwrotnie.
     *
     * CHECK `contact_message_replies_sent_complete` jest tu ostatnią barierą:
     * „wysłana" bez `sent_at` znaczyłoby „poszło, ale nie wiadomo kiedy",
     * czyli dokładnie tę ciszę, przed którą ta funkcja ma bronić.
     */
    public function test_baza_nie_przyjmie_wyslanej_odpowiedzi_bez_godziny(): void
    {
        $wiadomosc = ContactMessage::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('contact_message_replies')->insert([
            'id' => (string) Str::uuid7(),
            'contact_message_id' => $wiadomosc->getKey(),
            'body' => 'Odpowiedź bez godziny wysłania.',
            'status' => ContactMessageReply::STATUS_WYSLANA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_baza_nie_przyjmie_pustej_odpowiedzi(): void
    {
        $wiadomosc = ContactMessage::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('contact_message_replies')->insert([
            'id' => (string) Str::uuid7(),
            'contact_message_id' => $wiadomosc->getKey(),
            'body' => '   ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `down()` nie rusza NICZEGO poza własną tabelą. To jest ta połowa planu
     * wycofania, która decyduje, czy wolno go w ogóle uruchomić na produkcji:
     * najgorszy skutek ma być „znika formularz kontaktowy", a nie „znika
     * kolejka moderacyjna".
     */
    public function test_cofniecie_nie_rusza_zgloszen_ani_uzytkownikow(): void
    {
        $basia = $this->user('basia');

        $this->migracjaOdpowiedzi()->down();
        $this->migracja()->down();

        $this->assertDatabaseHas('users', ['id' => $basia->getKey()]);
        $this->assertTrue(
            DB::select("SELECT table_name FROM information_schema.tables WHERE table_name = 'reports'") !== [],
            'Cofnięcie migracji ruszyło tabelę `reports`. `down()` ma dotykać wyłącznie tego, '
            .'co ta migracja sama utworzyła.',
        );

        $this->migracja()->up();
        $this->migracjaOdpowiedzi()->up();
    }

    public function test_baza_nie_przyjmie_nieznanego_statusu(): void
    {
        $this->expectException(QueryException::class);

        DB::table('contact_messages')->insert([
            'id' => (string) Str::uuid7(),
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Wiadomość z wymyślonym statusem.',
            'status' => 'zamkniete-na-cztery-spusty',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_baza_nie_przyjmie_zalatwionej_bez_sladu_kto_i_kiedy(): void
    {
        $this->expectException(QueryException::class);

        // `status = 'done'` bez `handled_by` i `handled_at` znaczyłoby, że
        // retencja nie ma od czego liczyć — wiersz zostałby w bazie na zawsze.
        DB::table('contact_messages')->insert([
            'id' => (string) Str::uuid7(),
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Załatwiona bez śladu, kto ją załatwił.',
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_baza_nie_przyjmie_pustej_wiadomosci(): void
    {
        $this->expectException(QueryException::class);

        DB::table('contact_messages')->insert([
            'id' => (string) Str::uuid7(),
            'kind' => ContactMessage::KIND_INNE,
            'message' => '   ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ograniczenieIstnieje(string $nazwa): bool
    {
        return DB::select(
            'SELECT conname FROM pg_constraint WHERE conname = ?',
            [$nazwa],
        ) !== [];
    }

    private function indeksIstnieje(string $nazwa): bool
    {
        return DB::select(
            'SELECT indexname FROM pg_indexes WHERE indexname = ?',
            [$nazwa],
        ) !== [];
    }
}
