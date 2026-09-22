<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `dostep_odebrany_at` — kiedy człowiek odebrał nam dostęp u dostawcy
 * (issue #259).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA KOLUMNA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Facebook wysyła na `Deauthorize callback URL` powiadomienie, gdy człowiek
 * odbierze naszej aplikacji dostęp w swoich ustawieniach Facebooka. Bez tej
 * kolumny nie mieliśmy gdzie tego zapisać — wiersz powiązania zostawał
 * w bazie jakby nic się nie stało, a człowiek dowiadywał się dopiero
 * z nieudanego logowania, którego nikt mu nie wytłumaczy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO ZNACZNIK, A NIE SKASOWANIE WIERSZA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Skasowanie byłoby najgorszą z możliwych reakcji. Kto wszedł do Kuking
 * wyłącznie kontem Facebooka i nigdy nie ustawił hasła (w `password` leży
 * skrót wartości losowej, której nie zna nikt, także my), straciłby JEDYNĄ
 * drogę wejścia, jaką zna — przez kliknięcie w ustawieniach Facebooka,
 * którego skutków nikt mu nie zapowiedział. To jest dokładnie ta sama
 * pułapka, przed którą stoi strażnik przy cofnięciu migracji Facebooka.
 *
 * Poprawne dane u nas nie znikają (AGENTS.md). Znacznik mówi „to powiązanie
 * jest uśpione", a nie „tego powiązania nigdy nie było" — i daje się cofnąć
 * w chwili, w której człowiek znów kliknie „Wejdź kontem Facebooka".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO KOLUMNA JEST OGÓLNA, A NIE „FACEBOOKOWA"
 * ────────────────────────────────────────────────────────────────────────
 *
 * Odebranie dostępu nie jest osobliwością Facebooka — Google ma to samo
 * w swoim panelu konta. Dziś powiadamia nas o tym tylko Facebook, bo tylko
 * on wysyła `signed_request` na nasz adres; gdyby doszedł drugi dostawca,
 * kolumna jest gotowa i nie trzeba będzie drugiej migracji.
 */
return new class extends Migration
{
    private const TABELA = 'tozsamosci_zewnetrzne';

    private const KOLUMNA = 'dostep_odebrany_at';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELA) || Schema::hasColumn(self::TABELA, self::KOLUMNA)) {
            return;
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $table->timestampTz(self::KOLUMNA)->nullable();
        });
    }

    /**
     * COFNIĘCIE ODMAWIA, GDY JEST CO STRACIĆ (D-088).
     *
     * Usunięcie kolumny kasuje informację „ta osoba odebrała nam dostęp".
     * Po `down()` prawie zawsze idzie kolejny `migrate`, kolumna wraca PUSTA
     * i NIE MA BŁĘDU DO ZAUWAŻENIA — a serwis od tej chwili twierdzi, że
     * powiązanie jest żywe, choć u dostawcy zostało cofnięte. Ekran
     * bezpieczeństwa przestaje mówić prawdę i nikt się o tym nie dowie.
     *
     * Gdy kolumna jest pusta, nie ma czego stracić i cofnięcie przechodzi
     * bez pytania.
     *
     * UWAGA NA `migrate:rollback --step 1`: cofa migrację NAJPÓŹNIEJSZĄ,
     * niekoniecznie tę. Żeby cofnąć właśnie ją, wołaj `down()` wprost.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABELA) || ! Schema::hasColumn(self::TABELA, self::KOLUMNA)) {
            return;
        }

        $zapisane = (int) DB::table(self::TABELA)->whereNotNull(self::KOLUMNA)->count();

        if ($zapisane > 0 && ! $this->wolnoSkasowacZnaczniki()) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „że 1 osób
            // odebrało" to nie polszczyzna, a jedna osoba jest stanem
            // prawdopodobniejszym niż pięć. Mianownik przed dwukropkiem nie
            // odmienia się wcale, więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Cofnięcie tej migracji skasowałoby informację o tym, kto odebrał naszej '
                .'aplikacji dostęp u dostawcy. Liczba osób, których to dotyczy: '.$zapisane.".\n\n"
                ."CZYM TO GROZI\n"
                .'Po ponownym `migrate` kolumna wróci pusta, więc serwis uzna te powiązania za '
                .'żywe. Ekran „Ustawienia → Bezpieczeństwo" będzie tym osobom pokazywał '
                ."połączone konto, którego u dostawcy już nie ma.\n\n"
                ."JEŚLI NAPRAWDĘ CHCESZ TO SKASOWAĆ\n"
                .'Powiedz to wprost: KUKING_ROLLBACK_KASUJ_ZNACZNIKI_ODEBRANIA=true '
                .'php artisan migrate:rollback',
            );
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $table->dropColumn(self::KOLUMNA);
        });
    }

    /**
     * `getenv()`, a nie `env()` ani `config()` — tak jak w pozostałych
     * migracjach z tym samym zabezpieczeniem. `env()` oddaje `null` przy
     * zbuforowanej konfiguracji.
     */
    private function wolnoSkasowacZnaczniki(): bool
    {
        return filter_var(
            (string) getenv('KUKING_ROLLBACK_KASUJ_ZNACZNIKI_ODEBRANIA'),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
};
