<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Poczta\SladySledzeniaOtwarc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Issue #204 — EmailLabs wstrzykuje do listów transakcyjnych obcy obrazek
 * liczący OTWARCIA. Zmierzone 9 września 2026 na prawdziwym liście
 * doręczonym na o2.pl: `<img>` 1×1 z `click.kuking.pl/track/o/…` oraz
 * zapasowy `<div>` z tym samym adresem w `background:url()`.
 *
 * CO TEN PLIK MIERZY, A CZEGO NIE — przeczytaj, zanim dopiszesz tu asercję
 *
 * Piksel dokłada DOSTAWCA, już po naszym żądaniu. Nasze szablony listów nie
 * mają obrazków w ogóle (D-057), a `X-TRACKING-OFF` gasi wyłącznie śledzenie
 * ODNOŚNIKÓW — tak mówi specyfikacja API dosłownie („Link tracking"). Z tego
 * wynika rzecz, którą trzeba powiedzieć wprost, zamiast udawać, że jej nie ma:
 *
 *   • ASERCJA NA TREŚCI, KTÓRĄ SKŁADAMY SAMI, NIE MIERZY PIKSELA. Będzie
 *     zielona i dziś, i po włączeniu śledzenia otwarć w panelu, bo po naszej
 *     stronie nic się wtedy nie zmienia. Taki test pilnuje NASZYCH szablonów
 *     (i to jest jego jedyna, ale prawdziwa wartość: dzięki niemu wiadomo,
 *     że obcy obrazek w doręczonym liście nie jest nasz) — patrz dwa
 *     ostatnie testy w tym pliku, z tym ograniczeniem w docblocku.
 *
 *   • PIKSEL DA SIĘ ZMIERZYĆ TYLKO W DORĘCZONYM LIŚCIE. Dlatego pomiarem
 *     jest komenda `kuking:sprawdz-piksel`, której człowiek podaje surowe
 *     źródło wiadomości ze skrzynki, a testy niżej sprawdzają, że ta komenda
 *     NAPRAWDĘ OBLEWA na liście, w którym piksel stoi — na prawdziwym
 *     kształcie tego listu, z kodowaniem `quoted-printable` i wszystkim.
 *
 *   • USTAWIENIA KONTA U DOSTAWCY NIE DA SIĘ SPRAWDZIĆ W OGÓLE. API, którym
 *     wysyłamy, nie ma pola, którym dałoby się odczytać przełącznik śledzenia
 *     otwarć. Żaden test w tym repozytorium tego nie powie i nie ma sensu
 *     pisać takiego, który by udawał, że mówi.
 */
final class PikselSledzacyOtwarciaTest extends TestCase
{
    use RefreshDatabase;

    /** Ten host WYGLĄDA jak nasz, a jest domeną śledzącą dostawcy (CNAME na `tracking.mf-settings.com`). */
    private const HOST_SLEDZACY = 'click.kuking.pl';

    private function fixture(string $nazwa): string
    {
        $sciezka = base_path('tests/Fixtures/poczta/'.$nazwa);

        $this->assertFileExists($sciezka);

        return (string) file_get_contents($sciezka);
    }

    /**
     * KONTROLA DODATNIA CAŁEGO POMIARU. Ten sam list bez wstawek dostawcy
     * przechodzi — i przechodzi, BO ZOSTAŁ PRZECZYTANY, a nie bo z pliku nic
     * się nie odkodowało. Dowodem jest liczba adresów http(s) w treści:
     * w liście z Kuking jest ich kilka (przycisk i adres pod nim).
     *
     * Bez tej kontroli wszystkie asercje „nie ma piksela" niżej byłyby
     * zielone także wtedy, gdyby dekodowanie zwracało pustkę
     * (`docs/PULAPKI_TESTOW.md` §2 i §4).
     */
    #[Test]
    public function test_kontrola_doreczony_list_bez_piksela_przechodzi_i_naprawde_zostal_przeczytany(): void
    {
        $zrodlo = $this->fixture('list-doreczony-bez-piksela.eml');
        $slady = SladySledzeniaOtwarc::wSurowymZrodle($zrodlo);

        $this->assertFalse($slady->nicNieZmierzono(), 'Z pliku nie odkodował się ani jeden adres — pomiar nic nie mierzy.');
        $this->assertGreaterThanOrEqual(2, $slady->adresow);
        $this->assertSame([], $slady->slady);
        $this->assertTrue($slady->czysto());

        [$kod, $wyjscie] = $this->uruchom($this->plikTymczasowy($zrodlo));

        $this->assertSame(0, $kod, 'Komenda oblała się na liście bez piksela. Wyjście: '.$wyjscie);
        $this->assertStringContainsString('nie ma obcego obrazka', $wyjscie);
        // Komenda mówi wprost, o czym NIE świadczy jej zielony wynik.
        $this->assertStringContainsString('TYM JEDNYM liście', $wyjscie);
    }

    /**
     * WŁAŚCIWY POMIAR. Prawdziwy kształt listu z 9 września — komenda musi
     * OBLAĆ i powiedzieć, który ślad znalazła.
     */
    #[Test]
    public function test_piksel_w_doreczonym_liscie_oblewa_sprawdzenie(): void
    {
        [$kod, $wyjscie] = $this->uruchom($this->plikTymczasowy($this->fixture('list-doreczony-z-pikselem-otwarcia.eml')));

        $this->assertSame(1, $kod, 'Komenda PRZESZŁA na liście, w którym stoi piksel. Wyjście: '.$wyjscie);
        $this->assertStringContainsString('stoi śledzenie treści', $wyjscie);
        $this->assertStringContainsString(self::HOST_SLEDZACY, $wyjscie);
        $this->assertStringContainsString('licznik OTWARĆ', $wyjscie);
        // Komenda mówi, co z tym zrobić — bo naprawa nie jest w kodzie.
        $this->assertStringContainsString('panel EmailLabs', $wyjscie);
    }

    /**
     * OBA ZNACZNIKI OSOBNO, bo dostawca wstawia je jako parę i wyłączenie
     * jednego nic nie daje: `<div>` z tłem jest zapasem dla klientów, które
     * wycinają `<img>`.
     *
     * To jest ta sama zasada co w `docs/PULAPKI_TESTOW.md` §3b — każda droga
     * potrzebuje własnego sprawdzenia, bo dwa sprawdzenia trafiające w tę
     * samą drogę to jedno sprawdzenie.
     */
    #[Test]
    public function test_kazda_z_dwoch_drog_piksela_jest_wykrywana_osobno(): void
    {
        $adres = 'https://'.self::HOST_SLEDZACY.'/track/o/1/3/9f4c2a71e8b5d360/ab91c7e2';

        $tylkoObrazek = SladySledzeniaOtwarc::wHtml(
            '<p>Treść listu</p><img src="'.$adres.'" width="1" height="1" alt="">',
        );

        $tylkoTlo = SladySledzeniaOtwarc::wHtml(
            '<p>Treść listu</p><div aria-hidden="true" style="width:1px;height:1px;background:url(\''.$adres.'\')"></div>',
        );

        $this->assertFalse($tylkoObrazek->czysto(), 'Piksel w <img> nie został wykryty.');
        $this->assertStringContainsString('obcy obrazek w treści', implode("\n", $tylkoObrazek->slady));

        $this->assertFalse($tylkoTlo->czysto(), 'Piksel w tle CSS nie został wykryty.');
        $this->assertStringContainsString('obcy obrazek w tle', implode("\n", $tylkoTlo->slady));
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU I NAJMNIEJ OCZYWISTY.
     *
     * W doręczonym liście adres piksela jest PRZEŁAMANY miękkim zawinięciem
     * `quoted-printable` (`=\r\n` w środku ścieżki) i ma `=3D` zamiast `=`.
     * Zwykłe `str_contains($zrodlo, 'click.kuking.pl/track/o/')` na surowym
     * źródle tego NIE ZNAJDUJE — czyli sprawdzenie bez odkodowania byłoby
     * zielone na liście, w którym piksel siedzi. Dokładnie ta atrapa pomiaru,
     * o którą chodzi w `docs/PULAPKI_TESTOW.md`.
     *
     * Test mierzy więc dwie rzeczy naraz: że naiwne szukanie zawodzi
     * i że nasze — nie.
     */
    #[Test]
    public function test_adres_piksela_przelamany_kodowaniem_tez_jest_znajdowany(): void
    {
        $zrodlo = $this->fixture('list-doreczony-z-pikselem-otwarcia.eml');

        $this->assertStringNotContainsString(
            self::HOST_SLEDZACY.'/track/o/1/3/9f4c2a71e8b5d360',
            $zrodlo,
            'Kontrola tego testu: w tym pliku adres piksela MA być przełamany zawinięciem quoted-printable. '
            .'Jeśli nie jest, test niżej przechodzi z niewłaściwego powodu i niczego nie dowodzi.',
        );

        $slady = SladySledzeniaOtwarc::wSurowymZrodle($zrodlo);

        $this->assertFalse($slady->czysto());
        $this->assertContains(self::HOST_SLEDZACY, $slady->obceHosty);
    }

    /**
     * TWARDSZA WERSJA TESTU WYŻEJ — i powstała z KONTROLI UJEMNEJ, która
     * pokazała, że tamten jest za miękki.
     *
     * Przy wyłączonym odkodowaniu tamten test nadal PRZECHODZIŁ: w adresie
     * z `background:url()` zawinięcie wypadło za nazwą hosta, więc sam host
     * dawał się jeszcze znaleźć w surowym źródle i ślad się pojawiał —
     * z niewłaściwego powodu. Czyli kontrola ujemna oblewała się gdzie
     * indziej, niż myślałem (`docs/PULAPKI_TESTOW.md` §8).
     *
     * Tu zawinięcie rozcina NAZWĘ HOSTA, więc w surowym źródle nie ma jej
     * w ogóle. Detekcja bez odkodowania jest wtedy niemożliwa, nie tylko
     * trudniejsza — i dopiero to mierzy odkodowanie.
     */
    #[Test]
    public function test_piksel_z_hostem_rozcietym_zawinieciem_tez_jest_znajdowany(): void
    {
        $html = '<p>Potwierdź adres</p>'
            .'<a href="https://kuking.pl/potwierdz-email/17/abc">Potwierdź</a>'
            .'<img src="https://'.self::HOST_SLEDZACY.'/track/o/1/3/abc" width="1" height="1">';

        // Miękkie zawinięcie quoted-printable dokładnie w środku nazwy hosta
        // — tak samo, jak zrobiłby to serwer poczty, gdyby znacznik wypadł
        // o kilka znaków dalej w wierszu.
        $rozciete = str_replace('click.ku', "click.ku=\r\n", quoted_printable_encode($html));

        $zrodlo = "From: kontakt@kuking.pl\r\nContent-Type: text/html; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\n".$rozciete;

        $this->assertStringNotContainsString(
            self::HOST_SLEDZACY,
            $zrodlo,
            'Kontrola tego testu: hosta MA nie być w surowym źródle. Inaczej test przechodzi bez odkodowania.',
        );

        $slady = SladySledzeniaOtwarc::wSurowymZrodle($zrodlo);

        $this->assertFalse($slady->czysto());
        $this->assertContains(self::HOST_SLEDZACY, $slady->obceHosty);
    }

    /** Ta sama treść w części zakodowanej `base64` — w źródle nie ma jej wtedy jako tekstu w ogóle. */
    #[Test]
    public function test_piksel_w_czesci_base64_tez_jest_znajdowany(): void
    {
        $html = '<html><body><p>Potwierdź adres</p>'
            .'<a href="https://kuking.pl/potwierdz-email/17/abc">Potwierdź</a>'
            .'<img src="https://'.self::HOST_SLEDZACY.'/track/o/1/3/9f4c2a71e8b5d360" width="1" height="1">'
            .'</body></html>';

        $zrodlo = "From: kontakt@kuking.pl\r\nTo: basia@example.com\r\n"
            ."Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($html), 76, "\r\n");

        $this->assertStringNotContainsString(self::HOST_SLEDZACY, $zrodlo, 'Kontrola: w base64 hosta nie ma jako tekstu.');

        $slady = SladySledzeniaOtwarc::wSurowymZrodle($zrodlo);

        $this->assertFalse($slady->czysto());
        $this->assertContains(self::HOST_SLEDZACY, $slady->obceHosty);
    }

    /**
     * PUŁAPKA, KTÓRA PRZEPUŚCIŁABY PIKSEL BEZ SŁOWA.
     *
     * Domena śledząca dostawcy jest PODDOMENĄ naszej domeny
     * (`click.kuking.pl CNAME tracking.mf-settings.com`). Sprawdzanie
     * końcówki adresu („czy host kończy się na kuking.pl") uznałoby ją za
     * nasz własny obrazek — a byłby to najgorszy możliwy wynik: zielony,
     * wyglądający na dowód.
     */
    #[Test]
    public function test_poddomena_dostawcy_nie_jest_uznawana_za_nasz_host(): void
    {
        $this->assertNotContains(self::HOST_SLEDZACY, SladySledzeniaOtwarc::naszeHosty());
        $this->assertContains('kuking.pl', SladySledzeniaOtwarc::naszeHosty());

        $nasz = SladySledzeniaOtwarc::wHtml(
            '<a href="https://kuking.pl/potwierdz-email/17/abc">Potwierdź</a>',
        );
        $obcy = SladySledzeniaOtwarc::wHtml(
            '<img src="https://'.self::HOST_SLEDZACY.'/track/o/1/3/abc" width="1" height="1">',
        );

        $this->assertTrue($nasz->czysto(), 'Nasz własny odnośnik został uznany za obcy.');
        $this->assertFalse($obcy->czysto(), 'Poddomena śledząca dostawcy została uznana za naszą.');
    }

    /** Przepisany odnośnik (śledzenie kliknięć) też jest śladem — w doręczonym liście widać go po drugiej stronie nagłówka `X-TRACKING-OFF`. */
    #[Test]
    public function test_odnosnik_przepisany_na_obca_domene_jest_sladem(): void
    {
        $slady = SladySledzeniaOtwarc::wHtml(
            '<a href="https://'.self::HOST_SLEDZACY.'/track/c/1/3/abc?u=https%3A%2F%2Fkuking.pl">Potwierdź adres</a>',
        );

        $this->assertFalse($slady->czysto());
        $this->assertStringContainsString('odnośnik przepisany', implode("\n", $slady->slady));
    }

    /**
     * BRAK WYNIKU TO NIE „W PORZĄDKU", TO „NIE WIEMY"
     * (`docs/PULAPKI_TESTOW.md` §5).
     *
     * Plik, w którym nie ma ani jednego adresu http(s), nie jest źródłem
     * naszego listu — każdy z nich niesie przycisk z odnośnikiem. Gdyby
     * komenda kończyła się wtedy sukcesem, wystarczyłoby podać jej pusty
     * plik, żeby „udowodnić", że piksela nie ma.
     */
    #[Test]
    public function test_plik_bez_ani_jednego_adresu_nie_jest_sukcesem(): void
    {
        [$kod, $wyjscie] = $this->uruchom($this->plikTymczasowy("Subject: cokolwiek\r\n\r\nTreść bez odnośników.\r\n"));

        $this->assertSame(1, $kod, 'Plik bez ani jednego adresu zakończył się sukcesem. Wyjście: '.$wyjscie);
        $this->assertStringContainsString('nie ma czego sprawdzać', $wyjscie);
        $this->assertStringContainsString('nic nie zmierzyliśmy', $wyjscie);
    }

    #[Test]
    public function test_brak_pliku_konczy_sie_porazka_i_mowi_co_zrobic(): void
    {
        [$kod, $wyjscie] = $this->uruchom(sys_get_temp_dir().'/nie-ma-takiego-pliku-kuking-204.eml');

        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Nie da się przeczytać pliku', $wyjscie);
        $this->assertStringContainsString('Pokaż oryginał', $wyjscie);
    }

    /**
     * W adresie piksela stoi identyfikator wiadomości, a ten wskazuje na
     * konkretnego odbiorcę. Wynik komendy ma nazwać HOST i ścieżkę, a nie
     * przepisać cały adres — inaczej pomiar prywatności sam zostawiałby
     * daną osobową w wyjściu terminala i w logu CI (AGENTS.md §7).
     */
    #[Test]
    public function test_wynik_nie_przepisuje_identyfikatora_z_adresu_piksela(): void
    {
        $slady = SladySledzeniaOtwarc::wSurowymZrodle($this->fixture('list-doreczony-z-pikselem-otwarcia.eml'));
        $wypisane = implode("\n", $slady->slady);

        $this->assertStringContainsString(self::HOST_SLEDZACY, $wypisane);
        $this->assertStringNotContainsString('9f4c2a71e8b5d360', $wypisane);
        $this->assertStringNotContainsString('ab91c7e2', $wypisane);
    }

    /**
     * NASZA STRONA UKŁADU — i tylko ona.
     *
     * Ten test NIE MIERZY PIKSELA i nie udaje, że mierzy: dostawca dokłada go
     * po nas, więc ta asercja jest zielona niezależnie od ustawienia w panelu.
     * Mierzy rzecz węższą, ale prawdziwą i jedyną, którą da się tu
     * egzekwować: że treść, którą składamy sami, nie ma ani jednego obcego
     * obrazka. Dzięki temu każdy obcy obrazek znaleziony w DORĘCZONYM liście
     * jest dowodem na wstawkę dostawcy, a nie pytaniem „może to nasze?".
     *
     * Zero obrazków w listach jest decyzją, nie zbiegiem okoliczności
     * (D-057, komentarz w `resources/views/mail/podsumowanie-tygodnia.blade.php`).
     */
    #[Test]
    public function test_nasze_szablony_listow_nie_maja_zadnego_obcego_obrazka(): void
    {
        $szablony = glob(resource_path('views/mail/*.blade.php')) ?: [];

        // Bez tej asercji test przechodziłby, gdyby katalog się przeniósł
        // albo glob przestał cokolwiek łapać (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertGreaterThanOrEqual(8, count($szablony), 'Skan nie czyta szablonów listów — zła ścieżka?');

        foreach ($szablony as $szablon) {
            $slady = SladySledzeniaOtwarc::wHtml((string) file_get_contents($szablon));

            $this->assertSame(
                [],
                $slady->slady,
                basename($szablon).' ma obcy obrazek albo obcy odnośnik: '.implode('; ', $slady->slady)
                .'. Listy z Kuking są bez obrazków (D-057), a obcy obrazek w liście to licznik otwarć '
                .'po stronie odbiorcy — nawet jeśli wstawiony w dobrej wierze.',
            );
        }
    }

    /**
     * TO SAMO NA TREŚCI NAPRAWDĘ WYRENDEROWANEJ, nie na szablonie.
     *
     * Część napisów i znaczników dokłada Laravel po drodze, więc skan samego
     * pliku Blade nie jest tym samym co list, który wychodzi. To jest ostatni
     * punkt układu, który należy do nas — dalej jest już żądanie do API
     * dostawcy i jego wstawki.
     *
     * CZEGO NIE DOWODZI: niczego o pikselu z issue #204. Patrz docblock klasy.
     */
    #[Test]
    public function test_wyrenderowany_list_potwierdzenia_adresu_nie_ma_obcego_obrazka(): void
    {
        $user = $this->user(null, ['email' => 'basia@example.com', 'email_verified_at' => null]);

        $user->sendEmailVerificationNotification();

        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $wiadomosci = $transport->messages();

        $this->assertNotEmpty($wiadomosci, 'Aplikacja nie wysłała żadnej wiadomości.');

        /** @var Email $email */
        $email = $wiadomosci->last()->getOriginalMessage();
        $html = (string) $email->getHtmlBody();

        $slady = SladySledzeniaOtwarc::wHtml($html);

        // KONTROLA DODATNIA: list naprawdę się wyrenderował i ma w sobie
        // nasz własny odnośnik. Bez tego asercja niżej byłaby zielona także
        // na pustej treści.
        $this->assertGreaterThan(200, mb_strlen($html));
        $this->assertGreaterThanOrEqual(1, $slady->adresow);
        $this->assertStringContainsString('/potwierdz-email/', $html);

        $this->assertSame([], $slady->slady, 'Wyrenderowany list ma obce odwołanie: '.implode('; ', $slady->slady));
    }

    /**
     * Uruchomienie komendy z wynikiem i CAŁYM wyjściem.
     *
     * Świadomie `Artisan::call()`, a nie `$this->artisan()`: wyjście wraca tu
     * jako jeden łańcuch, więc komunikat oblanego testu może je pokazać.
     * Przy `expectsOutputToContain()` wiadomo tylko, że czegoś nie ma —
     * i przy pierwszej rozbieżności nie widać, co komenda naprawdę wypisała.
     *
     * @return array{0: int, 1: string}
     */
    private function uruchom(string $plik): array
    {
        $kod = Artisan::call('kuking:sprawdz-piksel', ['plik' => $plik]);

        return [$kod, Artisan::output()];
    }

    private function plikTymczasowy(string $tresc): string
    {
        $plik = tempnam(sys_get_temp_dir(), 'kuking-204-');

        $this->assertIsString($plik);

        file_put_contents($plik, $tresc);

        return $plik;
    }
}
