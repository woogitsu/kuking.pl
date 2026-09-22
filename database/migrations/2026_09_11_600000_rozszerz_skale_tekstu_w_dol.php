<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skala tekstu schodzi poniżej 90% — trzy mniejsze rozmiary do wyboru.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO NIE JEST ZŁAMANIE ZASADY „TEKST ≥ 18 PX"
 * ────────────────────────────────────────────────────────────────────────
 *
 * `AGENTS.md` mówi, że tekst ma mieć co najmniej 18 px. Ta zasada opisuje,
 * CO CZŁOWIEK WIDZI, ZANIM CZEGOKOLWIEK DOTKNIE — czyli domyślny wygląd
 * serwisu. Domyślna skala zostaje 100%, czyli `--text-body` = 18 px, i ta
 * migracja jej nie rusza.
 *
 * Zmienia się co innego: osoba, dla której 18 px jest ZA DUŻE, mogła dotąd
 * zejść najwyżej do 90%. Zgłosił to właściciel serwisu — trzydziestokilkulatek
 * czytający własny produkt. Kuking jest robiony dla grupy 50+, ale „dla 50+"
 * nie znaczy „nieczytelny dla reszty": ustawienie czytelności, które działa
 * tylko w jedną stronę, jest ustawieniem połowicznym.
 *
 * CHECK rośnie więc w dół, z `BETWEEN 90 AND 140` na `BETWEEN 70 AND 140`.
 *
 * Co to znaczy w pikselach (token `--text-body` = 1.125rem × skala):
 *
 *   100%  →  18,0 px   (domyślnie, bez zmian)
 *    90%  →  16,2 px
 *    80%  →  14,4 px
 *    70%  →  12,6 px   — najmniejszy możliwy, wyłącznie na własne życzenie
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO `down()` POTRAFI ODMÓWIĆ (D-088)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Zwężenie CHECK-a z powrotem do 90 odbije się o bazę, gdy choć jedno konto
 * ma zapisane 70 lub 80. Najprostszym sposobem „naprawienia" takiego
 * cofnięcia jest podniesienie tym kontom skali do 90 po cichu — i wtedy
 * człowiek, który świadomie ustawił sobie mniejszy tekst, dostaje większy,
 * bez słowa wyjaśnienia i bez śladu. Po `down()` prawie zawsze idzie kolejny
 * `migrate`, CHECK wraca i NIE MA BŁĘDU DO ZAUWAŻENIA.
 *
 * Dlatego `down()` odmawia, mówi ILU osób to dotyczy i jak powiedzieć wprost
 * „wiem, co robię". To ten sam wzorzec co przy cofnięciu migracji Facebooka
 * (#259) i przy MIG-01 (#287).
 *
 * UWAGA NA `migrate:rollback --step 1`: cofa migrację NAJPÓŹNIEJSZĄ,
 * niekoniecznie tę. Żeby cofnąć właśnie ją, wołaj `down()` wprost.
 */
return new class extends Migration
{
    private const OGRANICZENIE = 'users_text_scale_check';

    /** Dolna granica przed tą migracją i po jej cofnięciu. */
    private const DOL_PRZED = 90;

    /** Dolna granica po tej migracji. */
    private const DOL_PO = 70;

    private const GORA = 140;

    public function up(): void
    {
        if (! $this->pgZTabela()) {
            return;
        }

        $this->ustawZakres(self::DOL_PO, self::GORA);
    }

    public function down(): void
    {
        if (! $this->pgZTabela()) {
            return;
        }

        $dotkniete = $this->ileKontPonizejProgu();

        if ($dotkniete > 0 && ! $this->wolnoPodniescSkale()) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „na 1
            // kontach" to nie polszczyzna, a jedno konto jest stanem
            // prawdopodobniejszym niż pięć. Mianownik przed dwukropkiem nie
            // odmienia się wcale, więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Cofnięcie tej migracji musiałoby podnieść rozmiar tekstu na kontach, '
                .'które świadomie ustawiły sobie mniejszy niż 90%. '
                .'Liczba kont, których to dotyczy: '.$dotkniete.'. '
                ."Zobaczyliby nagle większe litery i nie dowiedzieliby się dlaczego.\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Usuń trzy mniejsze rozmiary z `config/kuking.php` (klucz `kuking.text.scales`) '
                .'i z `resources/css/tokens.css`. Nowe konta ich nie zobaczą, a te, które już '
                ."wybrały, zachowają swój wybór — baza go przyjmuje.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ PODNIEŚĆ IM SKALĘ DO 90%\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_PODNIES_SKALE_TEKSTU=true '
                .'php artisan migrate:rollback',
            );
        }

        /*
         * ZGODA WYPOWIEDZIANA WPROST podnosi skalę do dolnej granicy, bo
         * inaczej zwężenie CHECK-a odbiłoby się o bazę i cofnięcie stanęłoby
         * w połowie. Ruszamy WYŁĄCZNIE konta poniżej progu — reszcie nikt
         * niczego nie przestawia przy okazji.
         */
        if ($dotkniete > 0) {
            DB::table('users')
                ->where('text_scale', '<', self::DOL_PRZED)
                ->update(['text_scale' => self::DOL_PRZED]);
        }

        $this->ustawZakres(self::DOL_PRZED, self::GORA);
    }

    private function ustawZakres(int $dol, int $gora): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT '.self::OGRANICZENIE
            ." CHECK (text_scale BETWEEN {$dol} AND {$gora})",
        );
    }

    private function ileKontPonizejProgu(): int
    {
        return (int) DB::table('users')->where('text_scale', '<', self::DOL_PRZED)->count();
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji, a zgoda na zmianę cudzego ustawienia nie ma
     * prawa zależeć od tego, czy ktoś uruchomił wcześniej `config:cache`.
     */
    private function wolnoPodniescSkale(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_PODNIES_SKALE_TEKSTU'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function pgZTabela(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql'
            && Schema::hasTable('users');
    }
};
