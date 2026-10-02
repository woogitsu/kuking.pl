<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Strażnik kontraktu czasu (#2407): każda kolumna `timestamp`/`timestamptz`/
 * `date` tabeli modelu z app/Models ma cast (`datetime`, `immutable_datetime`,
 * `date`...) albo stoi na jawnej liście wyjątków z powodem. Bez castu pole jest
 * napisem zależnym od strefy sesji PostgreSQL, a porównanie robi się na tekście.
 *
 * `created_at`/`updated_at` (i `deleted_at` przy SoftDeletes) obsługuje Eloquent
 * sam — ten wyjątek jest zaszyty w teście, nie na liście.
 */
class KolumnyCzasuMajaCastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wyjątki: 'tabela.kolumna' => powód, dla którego cast jest świadomie pominięty.
     *
     * @var array<string, string>
     */
    private const WYJATKI = [
        'users.terms_notice_dismissed_version' => 'Etykieta WERSJI regulaminu (data zapisana i porównywana jako napis ISO `Y-m-d` z configiem, '
            .'D-306), nie chwila w czasie; cast `date` zrobiłby z niej Carbon i zepsuł porównanie napisów oraz eksport RODO.',
        'users.policy_notice_dismissed_version' => 'Jak wyżej, dla wersji polityki prywatności (ZmianaPolityki): napis ISO porównywany '
            .'z `kuking.zgody.*`, eksportowany w paczce RODO bez zmian.',
    ];

    /**
     * @return list<class-string<Model>>
     */
    private function modele(): array
    {
        $wynik = [];
        foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [] as $plik) {
            $klasa = 'App\\Models\\'.basename($plik, '.php');
            if (! class_exists($klasa)) {
                continue;
            }
            $ref = new ReflectionClass($klasa);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }
            $wynik[] = $klasa;
        }
        sort($wynik);

        return $wynik;
    }

    /**
     * @return array<string, list<string>> tabela => kolumny czasu
     */
    private function kolumnyCzasu(): array
    {
        $wiersze = DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND data_type IN ('timestamp with time zone', 'timestamp without time zone', 'date')
             ORDER BY table_name, ordinal_position",
        );
        $wynik = [];
        foreach ($wiersze as $w) {
            $wynik[$w->table_name][] = $w->column_name;
        }

        return $wynik;
    }

    /**
     * @return list<string> braki w postaci 'Model: tabela.kolumna'
     */
    private function braki(): array
    {
        $kolumny = $this->kolumnyCzasu();
        $braki = [];
        foreach ($this->modele() as $klasa) {
            $model = new $klasa;
            $tabela = $model->getTable();
            $casty = $model->getCasts();
            foreach ($kolumny[$tabela] ?? [] as $kolumna) {
                if ($model->usesTimestamps() && in_array($kolumna, [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()], true)) {
                    continue;
                }
                if ($kolumna === 'deleted_at' && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                    continue;
                }
                if (isset($casty[$kolumna]) || isset(self::WYJATKI[$tabela.'.'.$kolumna])) {
                    continue;
                }
                $braki[] = class_basename($klasa).": {$tabela}.{$kolumna}";
            }
        }

        return $braki;
    }

    public function test_kazda_kolumna_czasu_modelu_ma_cast_albo_jawny_wyjatek(): void
    {
        $this->assertSame(
            [],
            $this->braki(),
            'Dodaj cast `datetime` w casts() modelu albo wpisz kolumnę do WYJATKI z powodem.',
        );
    }

    public function test_wyjatki_dotycza_istniejacych_kolumn_bez_castu(): void
    {
        $kolumny = $this->kolumnyCzasu();
        $castowane = [];
        foreach ($this->modele() as $klasa) {
            $model = new $klasa;
            foreach (array_keys($model->getCasts()) as $kolumna) {
                $castowane[$model->getTable().'.'.$kolumna] = true;
            }
        }
        $zbedne = [];
        foreach (array_keys(self::WYJATKI) as $klucz) {
            [$tabela, $kolumna] = explode('.', $klucz);
            if (! in_array($kolumna, $kolumny[$tabela] ?? [], true) || isset($castowane[$klucz])) {
                $zbedne[] = $klucz;
            }
        }
        $this->assertSame([], $zbedne, 'Wyjątek wskazuje kolumnę, której nie ma albo która ma już cast — usuń go.');
    }
}
