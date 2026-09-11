<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `hero_picks` — zdjęcia wybrane przez gospodarza do kolażu w hero strony
 * powitalnej.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA TABELA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Zgłoszenie właściciela: „na stronie głównej na samej górze po prawej
 * stronie można zrobić kolaż w którym będą najładniejsze (albo wybrane przez
 * admina) zdjęcia użytkowników". Tabela trzyma tę drugą połowę zdania —
 * wybór człowieka. Pierwsza połowa („najładniejsze") nie ma w bazie nic,
 * bo dobór automatyczny liczy się z bieżących wpisów i niczego nie zapisuje.
 *
 * Kształt jest świadomie bliźniaczy do `daily_picks`: pozycja, kurator, data
 * utworzenia i ani jednej kolumny z punktami, liczbą polubień czy wynikiem.
 * To nie jest tabela rankingowa i nigdy nią nie będzie (AGENTS.md §12).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO DWA KLUCZE OBCE, A NIE SAM `media_id`
 * ────────────────────────────────────────────────────────────────────────
 *
 * O tym, czy zdjęcie wolno pokazać nieznajomemu, nie decyduje wiersz
 * w `media` — decyduje WPIS, przy którym to zdjęcie wisi (`posts.visibility`,
 * `posts.status`, stan konta autora). To samo zdjęcie może być przypięte do
 * kilku wpisów (`post_media` jest relacją wiele-do-wielu), więc bez zapisania
 * KTÓREGO wpisu dotyczy wybór, nie da się później sprawdzić, czy wciąż jest
 * publiczny. Stąd para.
 *
 * `cascadeOnDelete` na obu: gdy wpis albo zdjęcie znika, pozycja kolażu nie
 * ma już czego pokazywać. To jedyne miejsce w tej tabeli, gdzie kasowanie
 * jest właściwą odpowiedzią — wiersz nie niesie żadnej informacji o
 * człowieku, tylko wskazanie na cudzą treść.
 *
 * UWAGA: kaskada NIE JEST zabezpieczeniem prywatności i nie wolno jej tak
 * traktować. Wpis przełączony na „prywatny", schowany przez moderatora albo
 * autor zawieszony — to wszystko zostawia oba wiersze na miejscu. Filtr
 * widoczności stoi w `App\Domain\Feed\HeroKolaz` i jest sprawdzany przy
 * KAŻDYM wyświetleniu, nie przy zapisie (test:
 * `KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest`).
 */
return new class extends Migration
{
    private const TABELA = 'hero_picks';

    public function up(): void
    {
        if (Schema::hasTable(self::TABELA)) {
            return;
        }

        Schema::create(self::TABELA, function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Zdjęcie i wpis, przy którym ono wisi — patrz docblock wyżej.
            $table->foreignUuid('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();

            $table->smallInteger('position')->default(0);

            // Kto wybrał. Tak samo jak w `daily_picks`: po to, żeby dało się
            // zapytać „dlaczego to?", a nie po to, żeby liczyć czyjeś zasługi.
            $table->foreignUuid('curator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();

            // Jedno zdjęcie w kolażu najwyżej raz. To jest zarazem hamulec na
            // wyścig przy podwójnym kliknięciu „Zapisz" — ten sam wzorzec co
            // `daily_picks.unique(['shown_on','subject_type','subject_id'])`.
            $table->unique('media_id');
            $table->index('position');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE hero_picks ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_position_check CHECK (position >= 0)');
        }
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY JEST CO STRACIĆ (D-088).
     *
     * `DROP TABLE` kasuje WYBÓR CZŁOWIEKA — konkretne zdjęcia, które
     * gospodarz obejrzał i wskazał do pokazania gościom. Po `down()` prawie
     * zawsze idzie kolejny `migrate` (`migrate:refresh` w CI, awaryjny
     * rollback wdrożenia), tabela wraca PUSTA i NIE MA BŁĘDU DO ZAUWAŻENIA:
     * kolaż po cichu przechodzi w tryb automatyczny i pokazuje na stronie
     * powitalnej cztery zdjęcia, których nikt nie wybierał. To jest dokładnie
     * ta klasa błędu, którą opisuje D-088 — stan wygląda poprawnie, więc
     * nikt go nie prostuje.
     *
     * Przy dodatkowej wadze tej akurat tabeli: dopóki `resources/legal/`
     * nie rozstrzyga użycia promocyjnego (patrz `HeroKolazController`),
     * różnica między „gospodarz to obejrzał i wskazał" a „maszyna dobrała
     * sama" nie jest kosmetyczna.
     *
     * ODMOWA JEST WĄSKA: pusta tabela nie ma czego stracić i cofnięcie
     * przechodzi bez pytania. Kontrola dodatnia jest w teście.
     *
     * UWAGA NA `migrate:rollback --step 1`: cofa migrację NAJPÓŹNIEJSZĄ,
     * niekoniecznie tę. Żeby cofnąć właśnie ją, wołaj `down()` wprost.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABELA)) {
            return;
        }

        $wybranych = (int) DB::table(self::TABELA)->count();

        if ($wybranych > 0 && ! $this->wolnoSkasowacWybor()) {
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby wybór gospodarza: '.$wybranych.' zdjęć '
                ."wskazanych ręcznie do kolażu na stronie powitalnej.\n\n"
                ."CZYM TO GROZI\n"
                .'Po ponownym `migrate` tabela wróci pusta, a kolaż przejdzie w tryb '
                .'automatyczny bez jednego komunikatu. Strona powitalna pokaże wtedy cztery '
                ."zdjęcia, których nikt nie obejrzał ani nie zatwierdził.\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                ."Zapisz wybór przed cofnięciem:\n"
                ."  \\copy (SELECT media_id, post_id, position FROM hero_picks ORDER BY position) \n"
                ."  TO 'hero_picks.csv' CSV HEADER\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY=true '
                .'php artisan migrate:rollback',
            );
        }

        Schema::drop(self::TABELA);
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji, czyli dokładnie na produkcji.
     */
    private function wolnoSkasowacWybor(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
