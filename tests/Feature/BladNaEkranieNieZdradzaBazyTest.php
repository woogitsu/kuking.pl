<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Awaria techniczna nie wypisuje się człowiekowi na ekran.
 *
 * CO BYŁO ZEPSUTE
 * Warstwa domenowa rzucała zwykłym `RuntimeException` z komunikatem
 * napisanym dla człowieka („Nie można obserwować samego siebie."),
 * a kontrolery robiły `catch (RuntimeException $e)` i wkładały
 * `$e->getMessage()` do worka błędów formularza. Wzorzec sam w sobie dobry —
 * tyle że `PDOException` DZIEDZICZY po `RuntimeException`, więc ten sam
 * `catch` łapał także `QueryException` z każdego zapytania wykonanego
 * w środku bloku `try`.
 *
 * Zmierzone, nie wydedukowane. Po ukryciu tabeli `follows` kliknięcie
 * „Obserwuj" pokazywało w formularzu:
 *
 *   SQLSTATE[42P01] … (Connection: pgsql, Host: 127.0.0.1, Port: 5432,
 *   Database: kuking_test, SQL: select exists(select * from "users" inner
 *   join "follows" … "follows"."follower_id" = 01a079be-… ) as "exists")
 *
 * Adres bazy, port, jej nazwa, schemat zapytania i identyfikatory obu kont.
 * Dotyczyło to kilkunastu ekranów: wpisy, przepisy, komentarze, wykonania,
 * zgłoszenia, odwołania, moderacja, awatar, usuwanie konta.
 *
 * CO PILNUJE TEN PLIK
 * 1. Że awaria bazy w środku akcji NIE trafia na ekran.
 * 2. Że prawdziwy komunikat domenowy DALEJ na ten ekran trafia — bo
 *    „naprawa" polegająca na wyciszeniu wszystkiego przeszłaby punkt 1.
 * 3. Że nigdzie w `app/` ani w widokach nie wraca `catch (RuntimeException)` —
 *    to jest reguła, a nie jednorazowa poprawka, i wraca się do niej
 *    odruchowo, bo wygląda niewinnie.
 */
class BladNaEkranieNieZdradzaBazyTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaria_bazy_przy_obserwowaniu_nie_pokazuje_sql_ani_adresu_bazy(): void
    {
        $basia = $this->user('basia');
        $this->user('adam');

        // DDL w PostgreSQL jest transakcyjny, więc `RefreshDatabase` to wycofa.
        // Ukrycie tabeli to najkrótsza droga do prawdziwego `QueryException`
        // w środku akcji — bez podmieniania klas i bez atrap.
        DB::statement('alter table follows rename to follows_schowane');

        $odpowiedz = $this->actingAs($basia)->post(route('social.follow', 'adam'));

        // Awaria techniczna kończy się stroną błędu, a nie „ładnym"
        // komunikatem w formularzu. Tak ma być: to nie jest nic, co człowiek
        // mógłby poprawić, i nie ma prawa wyglądać jak jego pomyłka.
        $odpowiedz->assertStatus(500);

        // Nie sprawdzamy TREŚCI strony 500: `phpunit.xml` nie wymusza
        // `APP_DEBUG`, więc bierze się ono z `.env` i u każdego może być inne;
        // przy włączonym strona debugowania pokazuje wszystko i słusznie,
        // bo na produkcji jej nie ma. Sprawdzamy worek błędów formularza — to,
        // co przed poprawką wracało przekierowaniem 302 wprost pod pole
        // „Obserwuj" i renderowało się na normalnej stronie.
        $bledy = session('errors');
        $wSesji = $bledy === null ? '' : json_encode($bledy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        foreach (['SQLSTATE', 'Host:', 'Port:', 'Database:', 'select ', $basia->getKey()] as $tajemnica) {
            $this->assertStringNotContainsString(
                (string) $tajemnica,
                $wSesji,
                "Do worka błędów formularza trafiło „{$tajemnica}\". Ten worek ".
                'renderuje się wprost na stronie, obok pola, którego dotyczy.',
            );
        }
    }

    public function test_prawdziwy_komunikat_domenowy_dalej_dochodzi_do_czlowieka(): void
    {
        // Druga połowa reguły. Bez tego testu poprzedni przechodziłby także
        // wtedy, gdyby ktoś „naprawił" sprawę, usuwając `catch` w ogóle —
        // i człowiek klikający „Obserwuj" samego siebie zobaczyłby stronę 500
        // zamiast jednego zdania wyjaśnienia.
        // Blokowanie samego siebie, a nie obserwowanie: przy „obserwuj"
        // pierwsza odmawia `FollowPolicy` (403) i reguła domenowa nigdy nie
        // dochodzi do głosu. `SocialController::block` idzie prosto do akcji.
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('social.block', 'basia'));

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHasErrors('block');

        $this->assertSame(
            'Nie można zablokować samego siebie.',
            session('errors')->first('block'),
        );
    }

    public function test_nigdzie_nie_lapiemy_juz_golego_runtimeexception(): void
    {
        $trafienia = [];

        foreach ($this->plikiZrodlowe() as $plik) {
            $tresc = (string) file_get_contents($plik);

            // Komentarze precz PRZED szukaniem: w `ReportController` stoi akapit
            // opisujący, dlaczego tego `catch` już tam nie ma, i asercja
            // trafiłaby we własne uzasadnienie zamiast w kod.
            $tresc = (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', $tresc);

            if (preg_match('/catch\s*\(\s*\\\\?RuntimeException\b/', $tresc) === 1) {
                $trafienia[] = str_replace(base_path().'/', '', $plik);
            }
        }

        $this->assertSame(
            [],
            $trafienia,
            'Wrócił `catch (RuntimeException)`. `PDOException` dziedziczy po '.
            '`RuntimeException`, więc taki `catch` łapie też awarię bazy i — jeśli '
            .'gdzieś obok stoi `$e->getMessage()` — pokazuje człowiekowi adres bazy, '
            .'jej nazwę i całe zapytanie. Rzucaj i łap `App\Exceptions\BladDlaCzlowieka`: '
            .'na ekran idzie wyłącznie to, co ktoś świadomie dla niego napisał. '
            .'Pliki: '.implode(', ', $trafienia),
        );
    }

    /** @return list<string> */
    private function plikiZrodlowe(): array
    {
        $pliki = [];

        foreach ([app_path(), resource_path('views')] as $katalog) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog));

            foreach ($iterator as $plik) {
                if ($plik instanceof \SplFileInfo && $plik->isFile() && $plik->getExtension() === 'php') {
                    $pliki[] = $plik->getPathname();
                }
            }
        }

        sort($pliki);

        return $pliki;
    }
}
