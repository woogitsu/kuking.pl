<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cennik składników do orientacyjnego kosztu dania (V2, D-286 część 2).
 *
 * Słownik, nie treść użytkowników: wiersze pochodzą WYŁĄCZNIE z pliku
 * `database/data/ceny_skladnikow.csv` wczytywanego komendą
 * `kuking:ceny-skladnikow` (średnie ceny detaliczne GUS + jawnie opisane
 * wyjątki, np. woda z kranu). Przeliczanie przepisu na złotówki robi PHP
 * (`App\Domain\Recipes\Koszt\SzacunekKosztuZCen`) — deterministycznie,
 * bez modelu językowego.
 *
 * Kolumny `g_*` to miary domowe TEGO składnika w gramach — „szklanka mąki"
 * to nie „szklanka cukru" — a `g_na_jednostke` mówi, ile gramów waży
 * jednostka, za którą GUS podaje cenę (1 kg = 1000 g, 1 l mleka ≈ 1000 g,
 * 1 jajko ≈ 60 g).
 *
 * ROLLBACK: `DROP TABLE` bez strażnika D-088 — nikt tu nic nie wpisał,
 * całość odtwarza z repozytorium ta sama komenda. Strona przepisu bez tej
 * tabeli po prostu nie pokazuje przedziału (kod sprawdza jej obecność
 * pośrednio: pusty cennik = brak szacunku).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ceny_skladnikow', function (Blueprint $table): void {
            // Stały, czytelny klucz z pliku CSV (`maka_pszenna`), nie UUID —
            // to wiersz słownika, do którego nikt nie trafia z adresu.
            $table->string('klucz', 60)->primary();
            $table->string('nazwa', 160);
            // Formy słowa w tekście składnika (ASCII, małe litery), `|`.
            $table->text('wzorce');
            // Początki słów, które wykluczają dopasowanie („ziemniaczan").
            $table->text('wyklucz')->nullable();
            $table->decimal('cena_zl', 8, 2);
            $table->decimal('za_ilosc', 8, 3);
            $table->string('jednostka', 3);
            $table->decimal('g_na_jednostke', 8, 2);
            $table->decimal('g_szklanka', 8, 2)->nullable();
            $table->decimal('g_lyzka', 8, 2)->nullable();
            $table->decimal('g_lyzeczka', 8, 2)->nullable();
            $table->decimal('g_sztuka', 8, 2)->nullable();
            // Kolejność dopasowania: pierwszy pasujący wiersz wygrywa, więc
            // „kiełbasa sucha" musi stać przed „kiełbasą".
            $table->unsignedSmallInteger('kolejnosc');
            $table->string('okres', 40);
            $table->string('zrodlo', 240);
            $table->string('zmienna_bdl', 20)->nullable();
            $table->timestampTz('zaimportowano_at');
        });

        DB::statement("ALTER TABLE ceny_skladnikow ADD CONSTRAINT ceny_skladnikow_jednostka_check CHECK (jednostka IN ('kg', 'l', 'szt'))");
        DB::statement('ALTER TABLE ceny_skladnikow ADD CONSTRAINT ceny_skladnikow_liczby_check CHECK (
            cena_zl >= 0 AND za_ilosc > 0 AND g_na_jednostke > 0
            AND (g_szklanka IS NULL OR g_szklanka > 0)
            AND (g_lyzka IS NULL OR g_lyzka > 0)
            AND (g_lyzeczka IS NULL OR g_lyzeczka > 0)
            AND (g_sztuka IS NULL OR g_sztuka > 0)
        )');
        DB::statement("ALTER TABLE ceny_skladnikow ADD CONSTRAINT ceny_skladnikow_wzorce_check CHECK (btrim(wzorce) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ceny_skladnikow');
    }
};
