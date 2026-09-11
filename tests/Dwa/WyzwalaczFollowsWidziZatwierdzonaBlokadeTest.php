<?php

declare(strict_types=1);

namespace Tests\Dwa;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-080 NA DWÓCH POŁĄCZENIACH: bariera `BEFORE INSERT ON follows` widzi
 * blokadę ZATWIERDZONĄ NA INNYM POŁĄCZENIU — i nie widzi niezatwierdzonej.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO DOKŁADNIE TEN TEST MIERZY
 * ══════════════════════════════════════════════════════════════════════
 *
 * Migracja `2026_09_10_400000_obserwowanie_nie_wspolistnieje_z_blokada`
 * stawia wyzwalacz, który ma odmówić wstawienia wiersza `follows`, gdy
 * między tymi osobami istnieje blokada. Jego wartość polega na tym, że
 * łapie KAŻDĄ drogę zapisu — akcję dopisaną za pół roku, komendę konsolową,
 * seeder, ręczny `INSERT` w psql podczas awarii — a nie tylko `FollowUser`.
 * Dlatego ten test wstawia wiersz `follows` SUROWYM ZAPYTANIEM, z pominięciem
 * całego kodu domenowego: gdyby szedł przez `FollowUser`, mierzyłby
 * `exists()` w PHP, a nie barierę w bazie.
 *
 * Docblock tamtej migracji mówi też, czego bariera NIE daje: przy
 * `READ COMMITTED` nie widzi blokady NIEZATWIERDZONEJ. To zdanie było do
 * dziś rozumowaniem — jednego połączenia nie da się zapytać o to, co widzi
 * drugie. Tutaj jest zmierzone, w obie strony:
 *
 *   blokada ZATWIERDZONA na połączeniu A   →  `INSERT` na B ODMÓWIONY
 *   blokada NIEZATWIERDZONA na połączeniu A →  `INSERT` na B PRZECHODZI
 *
 * Druga linijka nie jest usterką i nie ma jej „naprawiać": za równoległość
 * odpowiada `ZamekPary` (D-080, D-090), a bariera odpowiada za DROGI ZAPISU.
 * Trzeba obu i to jest zapisane w migracji. Test pilnuje, żeby ten podział
 * ról pozostał prawdą, a nie stał się zdaniem z dokumentacji.
 *
 * ── KONTROLA UJEMNA (wykonana, nie zaplanowana) ──
 *
 * Zdjęcie wyzwalacza z bazy wyścigów, czyli dokładnie to, co dałaby
 * migracja bez swojego `up()`:
 *
 *     DROP TRIGGER follows_blokada_ma_pierwszenstwo_trg ON follows;
 *
 * oblewa ten test:
 *
 *     Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady
 *     (blokujący obserwuje blokowanego).
 *     Failed asserting that true is false.
 *
 * Osobno zmierzona jest każda gałąź warunku `OR` w wyzwalaczu: podmiana
 * funkcji na taką, która sprawdza tylko kierunek „blokujący → blokowany",
 * oblewa drugą asercję:
 *
 *     Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady
 *     (blokowany obserwuje blokującego).
 *     Failed asserting that true is false.
 *
 * Zakleszczenia w tym teście nie ma i być nie może — mierzy widoczność
 * zatwierdzonego stanu między dwoma backendami, a nie kolejność blokad.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że para z blokadą I obserwowaniem naraz nie może już powstać. Wyzwalacz
 * pilnuje `INSERT`-ów do `follows`; wiersz-sierotę sprzed migracji zostawia
 * nietkniętym (świadomie, patrz migracja), a `blocks` nie ma wyzwalacza
 * wcale — bo blokada musi się udać zawsze.
 */
#[Group('dwa-polaczenia')]
final class WyzwalaczFollowsWidziZatwierdzonaBlokadeTest extends TestDwochPolaczen
{
    public function test_bariera_widzi_blokade_zatwierdzona_na_drugim_polaczeniu(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();

        $blokujacy = $this->nowePolaczenie();
        $obserwujacy = $this->nowePolaczenie();

        // Blokada powstaje na POŁĄCZENIU A i zostaje ZATWIERDZONA.
        $blokujacy->beginTransaction();
        $this->wstawBlokade($blokujacy, (string) $wyzsza->getKey(), (string) $nizsza->getKey());
        $blokujacy->commit();

        // ── KONTROLA DODATNIA: ta sama droga zapisu, ta sama tabela, para
        // BEZ blokady — musi przejść. Bez niej asercje niżej („nie da się
        // wstawić") przechodzą także wtedy, gdy nie da się wstawić NICZEGO:
        // literówka w nazwie kolumny, zła baza, brak uprawnień
        // (`docs/PULAPKI_TESTOW.md` §4).
        $obca = $this->konto();

        $this->assertTrue(
            $this->probaObserwowania($obserwujacy, (string) $nizsza->getKey(), (string) $obca->getKey()),
            'Nie da się wstawić wiersza `follows` NAWET dla pary bez blokady — ten test nie '
            .'mierzy bariery, tylko własną usterkę.',
        );

        // ── WŁAŚCIWY POMIAR, OBIE GAŁĘZIE WARUNKU W WYZWALACZU ──
        //
        // Wyzwalacz sprawdza parę w obie strony jednym `OR`, a to są DWIE
        // gałęzie warunku, więc każda ma tu własną asercję
        // (`docs/PULAPKI_TESTOW.md` §3b: dwa testy trafiające w tę samą
        // gałąź to jeden test i jedna atrapa).
        $this->assertFalse(
            $this->probaObserwowania($obserwujacy, (string) $wyzsza->getKey(), (string) $nizsza->getKey()),
            'Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady '
            .'(blokujący obserwuje blokowanego).',
        );

        $this->assertFalse(
            $this->probaObserwowania($obserwujacy, (string) $nizsza->getKey(), (string) $wyzsza->getKey()),
            'Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady '
            .'(blokowany obserwuje blokującego).',
        );
    }

    public function test_bariera_nie_widzi_blokady_niezatwierdzonej_i_tak_ma_byc(): void
    {
        [$nizsza, $wyzsza] = $this->paraPosortowana();

        $blokujacy = $this->nowePolaczenie();
        $obserwujacy = $this->nowePolaczenie();

        // Blokada NIE JEST zatwierdzona — transakcja zostaje otwarta.
        $blokujacy->beginTransaction();
        $this->wstawBlokade($blokujacy, (string) $wyzsza->getKey(), (string) $nizsza->getKey());

        $przeszlo = $this->probaObserwowania($obserwujacy, (string) $nizsza->getKey(), (string) $wyzsza->getKey());

        $blokujacy->rollBack();

        $this->assertTrue(
            $przeszlo,
            'Bariera zobaczyła blokadę NIEZATWIERDZONĄ z drugiego połączenia. To nie jest '
            .'„lepiej": znaczyłoby, że pomiar idzie po jednym połączeniu albo że poziom '
            .'izolacji nie jest już `READ COMMITTED` — a na tym założeniu stoi cały podział '
            .'ról między barierą a `ZamekPary` (D-080).',
        );
    }

    private function wstawBlokade(PDO $polaczenie, string $blokujacy, string $blokowany): void
    {
        $polaczenie
            ->prepare('INSERT INTO blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, now())')
            ->execute([$blokujacy, $blokowany]);
    }

    /**
     * Próbuje wstawić wiersz `follows` SUROWYM zapytaniem i mówi, czy weszło.
     *
     * Odmowę poznajemy po komunikacie wyzwalacza, a nie po samym fakcie
     * wyjątku: klucz główny, klucz obcy albo `CHECK` na samoobserwowanie też
     * rzucają wyjątek, a to byłaby zupełnie inna odmowa niż ta, której ten
     * test pilnuje. Wyjątek z innego powodu leci dalej i oblewa test —
     * zamiast po cichu policzyć się jako „bariera zadziałała".
     */
    private function probaObserwowania(PDO $polaczenie, string $kto, string $kogo): bool
    {
        try {
            $polaczenie
                ->prepare('INSERT INTO follows (follower_id, followed_id, created_at) VALUES (?, ?, now())')
                ->execute([$kto, $kogo]);

            return true;
        } catch (PDOException $e) {
            if (! str_contains($e->getMessage(), 'Blokada ma pierwszenstwo przed obserwowaniem')) {
                throw $e;
            }

            return false;
        }
    }
}
