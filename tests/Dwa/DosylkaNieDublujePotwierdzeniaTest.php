<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * DOSYŁKA ZALEGŁEGO POTWIERDZENIA I POWRÓT CZŁOWIEKA DO SPRAWY — JEDNO
 * POTWIERDZENIE, NIE DWA (issue #797, D-252 — decyzja właściciela z 23.09.2026).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PO CO TEN TEST, SKORO JEST `DosylkaZaleglychPotwierdzenTest`
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo tamten NICZEGO NIE MÓWI O DWÓCH POŁĄCZENIACH (`docs/PULAPKI_TESTOW.md`
 * §6). `RefreshDatabase` trzyma cały test w jednej, niezatwierdzonej
 * transakcji, więc „równoległy" przebieg komendy widzi znacznik ustawiony
 * przez poprzednie wywołanie i wychodzi bez walki. Taki test przechodzi
 * także wtedy, gdyby warunku `WHERE receipt_sent_at IS NULL` nie było wcale
 * — bo drugie wywołanie i tak czytałoby świeżą wartość.
 *
 * A sedno zadania jest właśnie tu: KOMENDA Z HARMONOGRAMU I CZŁOWIEK
 * WRACAJĄCY DO SPRAWY MOGĄ ZBIEC SIĘ W CZASIE. To są dwa różne procesy,
 * dwa połączenia, dwie transakcje — i dokładnie jeden człowiek, który ma
 * dostać dokładnie jedno potwierdzenie przyjęcia (DSA art. 16 ust. 4 mówi
 * o potwierdzeniu jednej sprawy, nie o liście na każde wejście).
 *
 * ── PRZEPLOT, KTÓRY TO ROZSTRZYGA ──
 *
 * Bariera trzyma wiersz `reports` pod `FOR UPDATE`. Obie drogi — komenda
 * i `ReportContent` — kończą tym samym warunkowym `UPDATE reports SET
 * receipt_sent_at = ... WHERE id = ? AND receipt_sent_at IS NULL`, więc obie
 * stają w kolejce za barierą, w znanej kolejności.
 *
 *   dziś (warunek w UPDATE)                bez warunku / z `SELECT` + `if`
 *   ─────────────────────────────────      ─────────────────────────────────
 *   A: czeka na wiersz `reports`           A: sprawdził — znacznika NIE MA,
 *   B: czeka na ten sam wiersz                czeka na wiersz
 *   zwolnienie bariery:                    B: sprawdził — znacznika NIE MA,
 *   A zajmuje znacznik (1 wiersz),            bo A jeszcze nie zatwierdził
 *      tworzy ping, zatwierdza             zwolnienie bariery:
 *   B powtarza warunek na ŚWIEŻYM          A ustawia znacznik i tworzy ping,
 *      wierszu, dostaje 0 wierszy,         B ustawia znacznik i tworzy ping
 *      nie tworzy niczego                  → DWA potwierdzenia jednej sprawy
 *   → JEDNO potwierdzenie
 *
 * ── SKĄD BIERZE SIĘ TU STAN „SPRAWA BEZ POTWIERDZENIA" ──
 *
 * Z wiersza wstawionego wprost, a nie ze wstrzykniętej awarii. Awarię
 * wstrzykuje i mierzy `DosylkaZaleglychPotwierdzenTest`; tutaj potrzebny
 * jest sam STAN, a `DB::listen` w procesie potomnym byłby atrapą tamtego
 * pomiaru, nie jego powtórzeniem. Rzeczą mierzoną naprawdę jest tu ZBIEG —
 * i ten jest prawdziwy: dwa procesy, dwa połączenia, bariera w bazie.
 *
 * ── CZEGO TEN TEST NIE DOWODZI ──
 *
 * Że potwierdzenia są wolne od wyścigów w ogóle. Mierzy JEDEN przeplot — ten
 * z decyzji właściciela: nocna dosyłka trafiająca w moment, w którym człowiek
 * właśnie klika „Zgłoś" drugi raz.
 */
#[Group('dwa-polaczenia')]
final class DosylkaNieDublujePotwierdzeniaTest extends TestDwochPolaczen
{
    /** @var list<string> Sprawy założone w teście — do posprzątania. */
    private array $sprawy = [];

    protected function tearDown(): void
    {
        // `reports.reporter_id` ma `ON DELETE SET NULL`, więc skasowanie kont
        // przez klasę bazową ZOSTAWIŁOBY tu sieroty — a ta baza nie ma
        // transakcji, która by je cofnęła (zasada 1 i 3).
        if ($this->sprawy !== []) {
            $identyfikatory = '{'.implode(',', $this->sprawy).'}';
            $this->sprawy = [];

            try {
                $sprzataczka = $this->nowePolaczenie();
                $sprzataczka->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$identyfikatory]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie spraw nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    public function test_dosylka_i_powrot_czlowieka_daja_jedno_potwierdzenie(): void
    {
        $zglaszajacy = $this->konto();
        $autor = $this->konto();
        $wpis = $this->wpis($autor);
        $sprawa = $this->zalegleZgloszenie($zglaszajacy, $wpis);

        // Bariera na wierszu sprawy: warunkowy `UPDATE` obu dróg staje tutaj
        // w kolejce. Bez niej procesy mogłyby się rozminąć w czasie i test
        // mierzyłby dwa przebiegi po kolei, czyli to samo, co test
        // jednopołączeniowy.
        $bariera = $this->bariera('SELECT 1 FROM reports WHERE id = ? FOR UPDATE', [$sprawa]);

        $dosylka = $this->wTle('dosylka-potwierdzen', []);
        $this->czekajNaZablokowane(1);

        $czlowiek = $this->wTle('powrot-do-sprawy', [
            'kto' => (string) $zglaszajacy->getKey(),
            'wpis' => (string) $wpis->getKey(),
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikDosylki = $dosylka->wynik();
        $wynikCzlowieka = $czlowiek->wynik();

        $this->assertBezZakleszczenia($wynikDosylki, 'dosyłka zaległych potwierdzeń');
        $this->assertBezZakleszczenia($wynikCzlowieka, 'powrót człowieka do sprawy');

        // KONTROLA DODATNIA: oba procesy MUSIAŁY dojść do końca. Bez tego
        // test przechodzi także wtedy, gdy któryś wywrócił się przed
        // pierwszym zapytaniem — a wtedy nie było żadnego wyścigu.
        $this->assertTrue($wynikDosylki['ok'], 'Dosyłka padła: '.$wynikDosylki['komunikat']);
        $this->assertTrue($wynikCzlowieka['ok'], 'Powrót do sprawy padł: '.$wynikCzlowieka['komunikat']);

        // Komenda ma wyjść z kodem 0 także wtedy, gdy zamek dostał człowiek —
        // przegrana w wyścigu nie jest awarią i nie wolno jej meldować
        // harmonogramowi jako porażki.
        $this->assertSame(0, $wynikDosylki['wartosc'], 'Dosyłka zameldowała porażkę mimo poprawnego przebiegu.');

        // I człowiek dostał TĘ SAMĄ sprawę, a nie drugą.
        $this->assertSame($sprawa, $wynikCzlowieka['wartosc'], 'Powrót do sprawy założył drugie zgłoszenie.');

        $this->assertSame(
            1,
            DB::table('reports')->where('reporter_id', $zglaszajacy->getKey())->count(),
            'Powstała druga sprawa dla tej samej pary.',
        );

        $this->assertSame(
            1,
            DB::table('notifications')
                ->where('user_id', $zglaszajacy->getKey())
                ->where('type', Notification::TYPE_REPORT_RECEIVED)
                ->count(),
            'Dosyłka i powrót człowieka wysłały DWA potwierdzenia jednej sprawy.',
        );

        $this->assertNotNull(
            DB::table('reports')->where('id', $sprawa)->value('receipt_sent_at'),
            'Sprawa została bez znacznika mimo utworzonego potwierdzenia.',
        );
    }

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    /**
     * Sprawa w stanie po awarii potwierdzenia: zapisana, z adresatem, bez
     * pingu i bez znacznika.
     */
    private function zalegleZgloszenie(User $zglaszajacy, Post $wpis): string
    {
        $sprawa = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'details' => 'To jest reklama.',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->sprawy[] = (string) $sprawa->getKey();

        $this->assertNull($sprawa->receipt_sent_at, 'Sprawa nie jest zaległością — test mierzyłby co innego.');

        return (string) $sprawa->getKey();
    }
}
