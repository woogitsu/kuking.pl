<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprzątanie wygasłych wierszy tabeli `cache` (#2292, audyt
 * docs/audyt/2026-09-30-wydajnosc-baza.md, F6).
 *
 * DLACZEGO TO POTRZEBNE. Sterownik `database` Laravela usuwa wygasły wiersz
 * wyłącznie przy odczycie TEGO SAMEGO klucza
 * (`DatabaseStore::forgetManyIfExpired()`). Klucz, o który nikt już nie
 * zapyta, leży w tabeli na zawsze. Tak jest z limiterami per adres
 * (`landing…`, `discover…` — klucz i `:timer`): każde wejście gościa z nowego
 * adresu zostawia dwa wiersze. Boty z wielu adresów puchną tabelę i indeks
 * `cache_pkey`, a z tej samej tabeli czytają limitery i budżet listów (D-076).
 *
 * CO JEST KASOWANE. Tylko wiersze z `expiration <= teraz` — dokładnie te,
 * które `DatabaseStore` i tak uznaje za nieistniejące (ten sam warunek co
 * w `forgetManyIfExpired()`), więc dla aplikacji nic się nie zmienia.
 * Wpisy „na zawsze” (`forever()`) mają termin za dziesięć lat i zostają.
 * Tabela blokad (`cache_locks`, `onOneServer()`, `withoutOverlapping()`)
 * nie jest dotykana — to osobna tabela z własnym sprzątaniem Laravela.
 *
 * PARTIAMI (`UsuwanieWPartiach`, #1657): wiersz, który między wyborem partii
 * a `DELETE` dostał nowy termin (limiter znów trafiony), nie spełnia już
 * warunku i zostaje. Budżet na przebieg ogranicza czas pierwszego sprzątania
 * zaległości; reszta idzie następnej nocy.
 */
final class WygasleWpisyCache
{
    /** @return int ile wierszy skasowano (albo skasowałoby, przy `$naSucho`) */
    public function posprzataj(bool $naSucho = false): int
    {
        $teraz = Carbon::now()->getTimestamp();
        $kandydaci = fn (): Builder => $this->tabela()->where('expiration', '<=', $teraz);

        return $naSucho
            ? $kandydaci()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun($kandydaci, 'key', $this->nazwaTabeli());
    }

    private function tabela(): Builder
    {
        $polaczenie = config('cache.stores.database.connection');

        return DB::connection(is_string($polaczenie) && $polaczenie !== '' ? $polaczenie : null)
            ->table($this->nazwaTabeli());
    }

    private function nazwaTabeli(): string
    {
        $tabela = config('cache.stores.database.table');

        return is_string($tabela) && $tabela !== '' ? $tabela : 'cache';
    }
}
