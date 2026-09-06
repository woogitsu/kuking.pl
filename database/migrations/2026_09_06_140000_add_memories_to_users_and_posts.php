<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wspomnienia: „Rok temu gotowałaś…" (issue #34).
 *
 * DWIE KOLUMNY, BO SĄ DWA RÓŻNE „NIE CHCĘ TEGO WIDZIEĆ"
 *
 * `users.memories_enabled` — wyłączenie mechaniki W CAŁOŚCI, jednym
 * przełącznikiem. To nie jest ustawienie wygody. Wpis z przepisem po mamie,
 * która zmarła w tym roku, wyświetlony bez ostrzeżenia na stronie głównej,
 * jest okrutny — i człowiek w żałobie musi mieć jak to wyłączyć od razu,
 * nie „odklikując" wspomnienie po wspomnieniu.
 *
 * `posts.hide_as_memory` — ukrycie JEDNEGO wpisu, przy zachowaniu całej
 * mechaniki. Bo zwykle boli jedna rzecz, a nie wszystkie.
 *
 * DLACZEGO KOLUMNA NA `posts`, A NIE OSOBNA TABELA
 * Wspomnienie to ZAWSZE własny wpis oglądającego — pokazujemy komuś jego
 * własne archiwum, nie cudze. Właściciel wpisu i osoba ukrywająca to ta sama
 * osoba, więc tabela `(user_id, post_id)` niosłaby tę samą informację
 * w dwóch kolumnach, z których jedna zawsze wynika z drugiej.
 *
 * Wpis pozostaje w archiwum profilu — ukrycie dotyczy WYŁĄCZNIE wypływania
 * na stronie głównej. „Nie przypominaj mi o tym" to nie to samo co „usuń to".
 *
 * ROLLBACK
 * `down()` zdejmuje obie kolumny. Traci przy tym listę ukrytych wspomnień
 * (ustawienie wraca do domyślnego „pokazuj"), więc po cofnięciu tej migracji
 * człowiek zobaczy z powrotem to, co świadomie schował. Przy cofaniu na
 * produkcji najpierw kopia obu kolumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // DOMYŚLNIE WŁĄCZONE. Funkcja, która wymaga włączenia, nie istnieje
            // dla nikogo poza tym, kto o niej wie — a to jest mechanika dla
            // osoby gotującej od czterdziestu lat, nie dla osoby, która czyta
            // ustawienia.
            $table->boolean('memories_enabled')->default(true);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('hide_as_memory')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('hide_as_memory');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('memories_enabled');
        });
    }
};
