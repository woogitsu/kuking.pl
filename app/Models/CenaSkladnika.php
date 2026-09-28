<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wiersz cennika składników (D-286, część 2).
 *
 * Słownik wczytywany WYŁĄCZNIE komendą `kuking:ceny-skladnikow` z pliku
 * `database/data/ceny_skladnikow.csv`. Żaden formularz tu nie pisze, więc
 * `$fillable` nie przyjmuje niczego od użytkownika — komenda przepisuje
 * kolumny z pliku w repozytorium.
 */
class CenaSkladnika extends Model
{
    protected $table = 'ceny_skladnikow';

    protected $primaryKey = 'klucz';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'klucz',
        'nazwa',
        'wzorce',
        'wyklucz',
        'cena_zl',
        'za_ilosc',
        'jednostka',
        'g_na_jednostke',
        'g_szklanka',
        'g_lyzka',
        'g_lyzeczka',
        'g_sztuka',
        'kolejnosc',
        'okres',
        'zrodlo',
        'zmienna_bdl',
        'zaimportowano_at',
    ];

    protected function casts(): array
    {
        return [
            'cena_zl' => 'float',
            'za_ilosc' => 'float',
            'g_na_jednostke' => 'float',
            'g_szklanka' => 'float',
            'g_lyzka' => 'float',
            'g_lyzeczka' => 'float',
            'g_sztuka' => 'float',
            'kolejnosc' => 'integer',
            'zaimportowano_at' => 'datetime',
        ];
    }

    /** Cena jednego grama — to, co mnoży się przez masę składnika. */
    public function cenaZaGram(): float
    {
        return $this->cena_zl / ($this->za_ilosc * $this->g_na_jednostke);
    }

    /** Czy to składnik, który nic nie kosztuje (woda) — nie liczy się do pokrycia. */
    public function bezplatny(): bool
    {
        return $this->cena_zl <= 0.0;
    }
}
