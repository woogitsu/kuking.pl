<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import z adresu strony chodzi w kolejce (#28) — zlecenie z `zrodlo = 'url'`
 * musi umieć zapamiętać, DLACZEGO strona nie dała przepisu, bo człowiek czyta
 * to na ekranie postępu, a nie w odpowiedzi na wysłanie formularza.
 *
 * Do zamkniętej listy `importy_przepisow_kod_bledu_check` dochodzi siedem
 * powodów odmowy odczytu strony — te same kody co `ImportOdrzucony`, z tym
 * samym zdaniem „co zrobić”: `adres_nieprawidlowy`, `adres_niepubliczny`,
 * `strona_niedostepna`, `za_duzo_przekierowan`, `za_duza_strona`, `za_dlugo`,
 * `nie_strona`. Robots.txt i „brak przepisu na stronie” to nie błąd zlecenia
 * (kończą się szkicem z samym źródłem), więc kodów nie dostają.
 *
 * ROLLBACK NIE ODMAWIA (AGENTS.md §6, D-088), ale nie jest bezstratny i to
 * jest świadome: wiersz zlecenia nie niesie treści przepisu ani decyzji
 * człowieka, tylko ślad nieudanej próby. Przed przywróceniem wąskiej listy
 * te siedem kodów zamieniamy na `blad_wewnetrzny` — status `nieudany` zostaje,
 * ginie tylko dokładny powód, którego stara lista nie potrafi zapisać.
 * Limity osoby liczą się z `proby_importu`, nie z tej kolumny.
 */
return new class extends Migration
{
    private const STARE = [
        'limit_osoby', 'budzet_dzienny', 'budzet_miesieczny', 'brak_zgody', 'wylaczony', 'model_niedostepny',
        'nieczytelne', 'odpowiedz_bledna', 'zdjecie_niedostepne', 'szkic_zmieniony', 'blad_wewnetrzny',
    ];

    private const NOWE = [
        'adres_nieprawidlowy', 'adres_niepubliczny', 'strona_niedostepna', 'za_duzo_przekierowan',
        'za_duza_strona', 'za_dlugo', 'nie_strona',
    ];

    public function up(): void
    {
        $this->ustawListe([...self::STARE, ...self::NOWE]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('importy_przepisow')) {
            return;
        }

        DB::table('importy_przepisow')->whereIn('kod_bledu', self::NOWE)->update(['kod_bledu' => 'blad_wewnetrzny']);

        $this->ustawListe(self::STARE);
    }

    /** @param list<string> $kody */
    private function ustawListe(array $kody): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $lista = implode(', ', array_map(static fn (string $k): string => "'".$k."'", $kody));

        DB::statement('ALTER TABLE importy_przepisow DROP CONSTRAINT IF EXISTS importy_przepisow_kod_bledu_check');
        DB::statement(
            'ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_kod_bledu_check '
            .'CHECK (kod_bledu IS NULL OR kod_bledu IN ('.$lista.'))',
        );
    }
};
