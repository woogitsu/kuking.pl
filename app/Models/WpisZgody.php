<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Jedno zdarzenie w dzienniku zgód: udzielenie albo wycofanie (D-072).
 *
 * Zapisuj WYŁĄCZNIE przez `App\Domain\Zgody\PrzestawZgodeNaDigest` — tam
 * mieszka reguła „zdarzenie powstaje tylko przy realnej zmianie" i tam jest
 * rozstrzygnięta asymetria między udzieleniem a wycofaniem. Wołanie
 * `WpisZgody::create()` wprost obchodzi jedno i drugie.
 *
 * MODEL NIE UMIE ANI ZMIENIĆ, ANI SKASOWAĆ WIERSZA — i to nie jest ozdoba.
 * Dziennik zgód jest append-only, a wymusza to wyzwalacz w bazie (migracja
 * `2026_09_10_400000_create_dziennik_zgod_table`). Blokada tutaj łapie ten
 * sam błąd WCZEŚNIEJ i czytelniej: programista, który napisze
 * `$wpis->update([...])`, dostaje `LogicException` z nazwą zasady zamiast
 * `QueryException` z komunikatem z Postgresa. Baza zostaje jako druga linia,
 * bo tej tutaj nie widzi `DB::table('dziennik_zgod')->delete()`.
 *
 * `$timestamps = false`, bo tabela nie ma ani `created_at`, ani `updated_at`:
 * `wystapilo_at` jest MOMENTEM ZDARZENIA, a nie znacznikiem zapisu wiersza,
 * i drugiej daty tu nie ma po co trzymać — wiersz nigdy się nie zmienia.
 */
class WpisZgody extends Model
{
    protected $table = 'dziennik_zgod';

    public $timestamps = false;

    /**
     * Zgoda na tygodniowy digest (issue #11, D-057) — dziś jedyny cel
     * w serwisie. Zbiór jest zamknięty CHECK-iem `dziennik_zgod_cel_check`:
     * druga zgoda wymaga migracji i recenzji, nie nowego napisu w kodzie.
     */
    public const CEL_TYGODNIOWY_DIGEST = 'tygodniowy_digest';

    /** Mail z życzeniami urodzinowymi (issue #1755, etap c). */
    public const CEL_ZYCZENIA_URODZINOWE = 'zyczenia_urodzinowe';

    public const UDZIELONA = 'udzielona';

    public const WYCOFANA = 'wycofana';

    /** Haczyk na `/ustawienia/prywatnosc`. */
    public const ZRODLO_USTAWIENIA = 'ustawienia';

    /**
     * Podpisany odnośnik ze stopki listu i nagłówka `List-Unsubscribe`
     * (RFC 8058) — `App\Domain\Digest\OdnosnikWypisania::dla()`.
     */
    public const ZRODLO_LINK_WYPISANIA = 'link_wypisania';

    /**
     * Przycisk „jednak chcę" na ekranie PO wypisaniu
     * (`OdnosnikWypisania::powrotDla()`). Osobne źródło, nie
     * `link_wypisania`, bo to jest dowód czynności ODWROTNEJ i dokładnie tu
     * widać naprawę po skanerze odnośników w firmowej poczcie, który wypisał
     * kogoś bez jego wiedzy.
     */
    public const ZRODLO_LINK_POWROTNY = 'link_powrotny';

    /**
     * Anonimizacja konta (`EraseAccountData`, D-022) gasi zgodę razem
     * z kontem. Bez tego wpisu dziennik kończyłby się na „udzielona" i nie
     * dałoby się wykazać, DLACZEGO wysyłka ustała.
     */
    public const ZRODLO_USUNIECIE_KONTA = 'usuniecie_konta';

    protected $fillable = [
        'user_id',
        'cel',
        'czynnosc',
        'zrodlo',
        'wersja_polityki',
        'wystapilo_at',
    ];

    protected function casts(): array
    {
        return [
            'wystapilo_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException(
                'Dziennik zgód jest tylko do dopisywania (D-072). Wycofanie zgody zapisuje '
                .'NOWY wiersz `czynnosc = wycofana`, a nie zmianę istniejącego.',
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'Wiersza w dzienniku zgód nie wolno kasować (D-072) — jest dowodem podstawy '
                .'prawnej wysyłki (RODO art. 7 ust. 1). Usunięcie konta go NIE usuwa: '
                .'`EraseAccountData` anonimizuje samo konto.',
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
