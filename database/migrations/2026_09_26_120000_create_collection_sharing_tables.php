<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rodzinny zeszyt — wspólne zapisywanie w gospodarstwie domowym (#1743, D-302).
 *
 * DWIE NOWE TABELE, ŻADNA ISTNIEJĄCA NIE ZMIENIA ZNACZENIA
 *
 * `collections.owner_id` zostaje jedynym właścicielem zeszytu — NOT NULL
 * i klucz obcy pilnują „dokładnie jednego właściciela" w bazie, jak dotąd.
 * Współpracownik NIE jest drugim właścicielem: jest wierszem
 * `collection_members`, który daje prawo oglądania, dopisywania i wyjmowania
 * pozycji, a nie zmiany nazwy, widoczności ani usunięcia zeszytu.
 *
 *  - `collection_members` — kto ma dostęp. Klucz główny (zeszyt, osoba)
 *    wymusza unikalne członkostwo. Wyzwalacz odmawia wpisania właściciela
 *    jako członka i dopisania kogokolwiek do domyślnego „Zapisane" — tego
 *    nie da się wyrazić CHECK-iem, bo dotyczy drugiej tabeli.
 *  - `collection_invitations` — zaproszenie po nazwie konta (`invitee_id`)
 *    albo link z jednorazowym tokenem (`token_hash`, SHA-256 — sam token nie
 *    trafia do bazy). Token znika z wiersza w chwili odpowiedzi, więc link
 *    działa dokładnie raz (CHECK `collection_invitations_token_only_pending`).
 *
 * Nowe tabele — reguły `lock_timeout`/`NOT VALID` z AGENTS.md §6 ich nie
 * dotyczą, nikt jeszcze na nie nie czeka.
 *
 * WYCOFANIE ODMAWIA, GDY JEST CO STRACIĆ (D-088)
 * Członkostwo i zaproszenie to decyzje człowieka o tym, KTO widzi jego
 * zeszyt. Zrzucenie tabel samo w sobie nie otwiera niczego nikomu (dostęp się
 * zawęża), ale kolejny `migrate` odtworzyłby je PUSTE — rodzina traci wspólny
 * zeszyt bez śladu błędu. Dlatego `down()` przerywa, gdy istnieje choć jedno
 * członkostwo albo oczekujące zaproszenie, i mówi, co zrobić. Na świeżej
 * bazie cofa się bez pytania. Wymuszenie: `KUKING_ROLLBACK_KASUJE_WSPOLDZIELENIE=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignUuid('inviter_id')->constrained('users')->cascadeOnDelete();
            // NULL przy zaproszeniu linkiem, dopóki nikt go nie przyjął.
            $table->foreignUuid('invitee_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();
            // Czy to był link — po przyjęciu token znika, a `invitee_id` jest
            // już ustawiony, więc bez tej kolumny nie dałoby się tego odróżnić.
            $table->boolean('via_link')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();

            $table->index('collection_id');
            $table->index('inviter_id');
        });

        Schema::create('collection_members', function (Blueprint $table): void {
            $table->foreignUuid('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['collection_id', 'user_id']);
            $table->index('user_id');
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE collection_invitations ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_status_check CHECK (status IN ('pending','accepted','declined','revoked'))");
        // Zaproszenie oczekujące musi mieć ADRESATA: konkretną osobę albo token.
        DB::statement("ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_target_check CHECK (status <> 'pending' OR num_nonnulls(invitee_id, token_hash) >= 1)");
        // Link jest jednorazowy: po odpowiedzi token znika z wiersza.
        DB::statement('ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_link_check CHECK (token_hash IS NULL OR via_link)');
        DB::statement("ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_token_only_pending CHECK (token_hash IS NULL OR status = 'pending')");
        // Przyjęte zawsze wie, KTO przyjął — także przy linku.
        DB::statement("ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_accepted_has_invitee CHECK (status <> 'accepted' OR invitee_id IS NOT NULL)");
        DB::statement("ALTER TABLE collection_invitations ADD CONSTRAINT collection_invitations_responded_check CHECK ((status = 'pending') = (responded_at IS NULL))");
        // Jedno oczekujące zaproszenie tej samej osoby do tego samego zeszytu.
        DB::statement("CREATE UNIQUE INDEX collection_invitations_one_pending_idx ON collection_invitations (collection_id, invitee_id) WHERE status = 'pending' AND invitee_id IS NOT NULL");
        DB::statement('CREATE INDEX collection_invitations_invitee_idx ON collection_invitations (invitee_id) WHERE invitee_id IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE FUNCTION collection_members_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                zeszyt record;
            BEGIN
                SELECT owner_id, is_default INTO zeszyt FROM collections WHERE id = NEW.collection_id;
                IF zeszyt.owner_id = NEW.user_id THEN
                    RAISE EXCEPTION 'Właściciel zeszytu nie może być jego członkiem (collection_members_guard)'
                        USING ERRCODE = 'check_violation';
                END IF;
                IF zeszyt.is_default THEN
                    RAISE EXCEPTION 'Domyślnego zeszytu nie udostępniamy (collection_members_guard)'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement('CREATE TRIGGER collection_members_guard BEFORE INSERT OR UPDATE ON collection_members FOR EACH ROW EXECUTE FUNCTION collection_members_guard()');
    }

    public function down(): void
    {
        $this->upewnijSieZeNicNieGinie();

        Schema::dropIfExists('collection_members');
        Schema::dropIfExists('collection_invitations');

        if ($this->isPostgres()) {
            DB::statement('DROP FUNCTION IF EXISTS collection_members_guard()');
        }
    }

    private function upewnijSieZeNicNieGinie(): void
    {
        if (! Schema::hasTable('collection_members') || ! Schema::hasTable('collection_invitations')) {
            return;
        }

        $czlonkostw = (int) DB::table('collection_members')->count();
        $zaproszen = (int) DB::table('collection_invitations')->where('status', 'pending')->count();

        if ($czlonkostw + $zaproszen === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null
        // (to samo uzasadnienie co w `collection_items_accept_posts`).
        if (getenv('KUKING_ROLLBACK_KASUJE_WSPOLDZIELENIE') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji zamknie wspólne zeszyty — bezpowrotnie.
            Liczba osób, które stracą dostęp do cudzego zeszytu: {$czlonkostw}.
            Liczba oczekujących zaproszeń, które przepadną: {$zaproszen}.
            Zeszyty i ich zawartość zostaną u właścicieli, ale ponowna migracja nie odtworzy, kto miał do nich dostęp.

            Zanim cofniesz:
              1. zrób kopię tabel:
                 CREATE TABLE collection_members_kopia AS TABLE collection_members;
                 CREATE TABLE collection_invitations_kopia AS TABLE collection_invitations;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — błąd w ekranie albo w akcji naprawia się bez ruszania bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_WSPOLDZIELENIE=1.

            Na świeżym środowisku, gdzie nikt niczego nie udostępnił, cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
