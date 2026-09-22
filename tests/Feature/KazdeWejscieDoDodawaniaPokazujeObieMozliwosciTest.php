<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Każde wejście do dodawania kończy się ekranem z OBIEMA możliwościami
 * (issue #366).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Zgłoszenie właściciela, 11 września: „użytkownicy nie widzą, że w »Dodaj«
 * można wybrać »Zdjęcie i kilka słów« i »Cały przepis«, przez co wszyscy
 * dodają zdjęcie i kilka słów". Policzone w #366, nie wyczute: z jedenastu
 * drzwi do dodawania OSIEM prowadziło prosto w formularz zdjęcia, trzy prosto
 * w kreator przepisu, a na ekran wyboru `/dodaj` — tylko trzy pozycje
 * nawigacji. Ekran `/dodaj` z dwoma kaflami istniał i był dobrze napisany;
 * prawie nikt na niego nie trafiał.
 *
 * DLACZEGO TEN TEST WYMIENIA WSZYSTKIE WEJŚCIA Z NAZWY
 * Bo bez tej listy pilnowałby jednego przypadku i UDAWAŁ, że pilnuje reguły.
 * Reguła z #366 brzmi „nieważne, gdzie człowiek kliknie, żeby coś opublikować
 * — widzi dwie możliwości", a regułę o wszystkich drzwiach da się sprawdzić
 * tylko chodząc po wszystkich drzwiach. Test na samym `/dodaj/zdjecie`
 * przeszedłby także wtedy, gdyby ktoś dołożył dziewiąte wejście prowadzące
 * gdzie indziej — a to jest dokładnie ten rodzaj strażnika, który tę usterkę
 * już raz przepuścił.
 *
 * Dlatego każde wejście jest osobnym przypadkiem z opisem po polsku: gdy
 * któreś przestanie działać, komunikat mówi KTÓRE, a nie „coś na stronie”.
 *
 * CO DOKŁADNIE SPRAWDZAMY NA KAŻDYM WEJŚCIU
 *   1. że odnośnik z tabeli #366 nadal istnieje i nadal prowadzi tam, gdzie
 *      tabela mówi (inaczej test zieleniałby na wejściu, którego już nie ma),
 *   2. że ekran, na którym ten odnośnik LĄDUJE, pokazuje obie możliwości:
 *      odnośnik „Zdjęcie i kilka słów” do `/dodaj/zdjecie` i odnośnik
 *      „Cały przepis” do `/dodaj/przepis`.
 *
 * Punkt 2 jest sprawdzany tak samo dla `/dodaj` (dwa kafle) i dla obu
 * formularzy (zakładki `x-zakladki-dodawania`) — bo dla człowieka to jest
 * ta sama rzecz, a nie dwa różne mechanizmy.
 */
class KazdeWejscieDoDodawaniaPokazujeObieMozliwosciTest extends TestCase
{
    use RefreshDatabase;

    /** Ścieżka formularza „Zdjęcie i kilka słów”. */
    private const CEL_ZDJECIE = '/dodaj/zdjecie';

    /** Ścieżka kreatora „Cały przepis”. */
    private const CEL_PRZEPIS = '/dodaj/przepis';

    /** Ścieżka ekranu wyboru. */
    private const CEL_WYBOR = '/dodaj';

    /**
     * OSIEM WEJŚĆ PROWADZĄCYCH PROSTO DO „ZDJĘCIE I KILKA SŁÓW”.
     *
     * Lista jest przepisana z tabeli w issue #366 co do pozycji.
     *
     * @return array<string, array{0: string}>
     */
    public static function wejsciaDoZdjecia(): array
    {
        return [
            'kafel „composer” na /home' => ['home-composer'],
            'pusty stan na /home' => ['home-pusty-stan'],
            '„Dodaj zdjęcie” na własnym profilu' => ['profil-przycisk'],
            'pusty stan własnego profilu' => ['profil-pusty-stan'],
            'pusty stan strony tagu /tag/{tag}' => ['tag-pusty-stan'],
            'pusty stan /odkryj' => ['odkryj-pusty-stan'],
            'koniec onboardingu /witaj/gotowe' => ['onboarding-koniec'],
            '„Dodaj kolejne zdjęcie” pod własnym wpisem' => ['wpis-kolejne-zdjecie'],
        ];
    }

    /**
     * TRZY WEJŚCIA PROWADZĄCE PROSTO DO KREATORA PRZEPISU.
     *
     * @return array<string, array{0: string}>
     */
    public static function wejsciaDoPrzepisu(): array
    {
        return [
            '„Dodaj taki przepis” na pustej wyszukiwarce' => ['szukaj-pusty-stan'],
            'pusty stan zakładki „Przepisy” na własnym profilu' => ['profil-przepisy-pusty-stan'],
            '„Napisz przepis” w szynie własnego profilu' => ['szyna-profilu-przepis'],
        ];
    }

    /**
     * TRZY WEJŚCIA PROWADZĄCE NA EKRAN WYBORU `/dodaj`.
     *
     * To są trzy pozycje nawigacji z `components/layout.blade.php`. Nie da
     * się ich rozróżnić po napisie — każda mówi „Dodaj” — więc rozróżnia je
     * klasa, tak jak w arkuszu.
     *
     * @return array<string, array{0: string}>
     */
    public static function wejsciaNaEkranWyboru(): array
    {
        return [
            'przycisk „Dodaj” w górnej belce' => ['belka-dodaj'],
            'pozycja „Dodaj” w nawigacji bocznej' => ['nawigacja-boczna-dodaj'],
            'pozycja „Dodaj” w dolnym pasku' => ['dolny-pasek-dodaj'],
        ];
    }

    #[DataProvider('wejsciaDoZdjecia')]
    public function test_wejscie_do_zdjecia_konczy_sie_ekranem_z_obiema_mozliwosciami(string $wejscie): void
    {
        $this->sprawdzWejscie($wejscie, self::CEL_ZDJECIE);
    }

    #[DataProvider('wejsciaDoPrzepisu')]
    public function test_wejscie_do_przepisu_konczy_sie_ekranem_z_obiema_mozliwosciami(string $wejscie): void
    {
        $this->sprawdzWejscie($wejscie, self::CEL_PRZEPIS);
    }

    #[DataProvider('wejsciaNaEkranWyboru')]
    public function test_wejscie_na_ekran_wyboru_konczy_sie_ekranem_z_obiema_mozliwosciami(string $wejscie): void
    {
        $this->sprawdzWejscie($wejscie, self::CEL_WYBOR);
    }

    /**
     * KONTROLA DODATNIA DLA SAMEGO MECHANIZMU SPRAWDZANIA.
     *
     * Gdyby `assertPokazujeObieMozliwosci()` przepuszczało cokolwiek (na
     * przykład przez literówkę w ścieżce albo zbyt luźne dopasowanie napisu),
     * wszystkie przypadki wyżej byłyby zielone, nic nie sprawdzając. Ten test
     * karmi tę samą asercję stroną, na której zakładek NIE MA, i oczekuje,
     * że asercja OBLEJE.
     */
    public function test_asercja_oblewa_na_ekranie_bez_obu_mozliwosci(): void
    {
        $this->actingAs($this->user());

        $this->expectException(AssertionFailedError::class);

        // Ustawienia dostępności to zwykły ekran serwisu bez zakładek
        // dodawania — i ma taki zostać.
        $this->assertPokazujeObieMozliwosci('/ustawienia/dostepnosc', 'kontrola dodatnia');
    }

    // ---------------------------------------------------------------------
    // Maszyneria
    // ---------------------------------------------------------------------

    /**
     * Jedno wejście: postaw świat, znajdź odnośnik, sprawdź, dokąd prowadzi,
     * i sprawdź, co widać na ekranie, na którym ląduje.
     */
    private function sprawdzWejscie(string $wejscie, string $oczekiwanyCel): void
    {
        [$adresZrodla, $napis, $klasa, $zawiera] = $this->postawSwiat($wejscie);

        $zrodlo = $this->get($adresZrodla);
        $zrodlo->assertOk();

        $cel = $this->celOdnosnika($zrodlo->getContent(), $napis, $klasa, $zawiera, $wejscie);

        $this->assertSame(
            $oczekiwanyCel,
            $cel,
            "Wejście „{$wejscie}” ({$adresZrodla}) prowadzi teraz do „{$cel}”, ".
            "a tabela w issue #366 mówi „{$oczekiwanyCel}”. Jeśli to zmiana celowa, ".
            'popraw tabelę w issue i listę w tym teście — nie usuwaj przypadku.',
        );

        $this->assertPokazujeObieMozliwosci($cel, $wejscie);
    }

    /**
     * Ekran docelowy MUSI pokazywać obie możliwości: odnośnik „Zdjęcie
     * i kilka słów" do `/dodaj/zdjecie` i „Cały przepis” do `/dodaj/przepis`.
     *
     * Sprawdzamy odnośniki, a nie sam napis: napis bez adresu jest informacją,
     * że coś istnieje, a nie drogą do tego. O to właśnie chodziło w #366.
     */
    private function assertPokazujeObieMozliwosci(string $sciezka, string $wejscie): void
    {
        $ekran = $this->get($sciezka);
        $ekran->assertOk();

        $html = $ekran->getContent();

        // Tryb „zawiera”, nie równość: na `/dodaj` odnośnikiem jest CAŁY kafel,
        // razem ze zdaniem wyjaśnienia pod nagłówkiem, a na formularzach —
        // sama zakładka. Jedno sprawdzenie ma obsłużyć oba kształty, bo dla
        // człowieka to jest ta sama droga. Ostrość bierze się z sprawdzenia
        // JEDNOZNACZNOŚCI w `celOdnosnika()`, nie z długości napisu.
        $this->assertSame(
            self::CEL_ZDJECIE,
            $this->celOdnosnika($html, 'Zdjęcie i kilka słów', null, true, $wejscie),
            "Wejście „{$wejscie}” kończy się na „{$sciezka}”, a tam nie ma drogi do ".
            '„Zdjęcie i kilka słów”. Zakładki „x-zakladki-dodawania” albo kafel na „/dodaj” zniknęły.',
        );

        $this->assertSame(
            self::CEL_PRZEPIS,
            $this->celOdnosnika($html, 'Cały przepis', null, true, $wejscie),
            "Wejście „{$wejscie}” kończy się na „{$sciezka}”, a tam nie ma drogi do ".
            '„Cały przepis”. To jest dokładnie usterka z issue #366: człowiek wchodzi '.
            'i nie dowiaduje się, że druga możliwość istnieje.',
        );
    }

    /**
     * Ścieżka spod jedynego odnośnika o podanym napisie (i opcjonalnie
     * o podanej klasie).
     *
     * Napis dopasowujemy DOKŁADNIE po znormalizowanych białych znakach, bo
     * „Dodaj zdjęcie” jest początkiem także „Dodaj zdjęcie profilowe”
     * i „Dodaj zdjęcie i kilka słów” — dopasowanie po fragmencie trafiałoby
     * w cudzy odnośnik i test zieleniałby z nie tego powodu. Wyjątki, gdzie
     * odnośnik obejmuje nagłówek razem z podpisem, deklarują to jawnie
     * (`$zawiera`).
     */
    private function celOdnosnika(string $html, string $napis, ?string $klasa, bool $zawiera, string $wejscie): string
    {
        $trafienia = [];

        foreach ($this->odnosniki($html) as $odnosnik) {
            $pasujeNapis = $zawiera
                ? str_contains($odnosnik['tekst'], $napis)
                : $odnosnik['tekst'] === $napis;

            if (! $pasujeNapis) {
                continue;
            }

            if ($klasa !== null && ! in_array($klasa, $odnosnik['klasy'], true)) {
                continue;
            }

            $trafienia[] = $odnosnik['sciezka'];
        }

        $this->assertNotEmpty(
            $trafienia,
            "Wejście „{$wejscie}”: nie ma na stronie odnośnika o napisie „{$napis}”".
            ($klasa !== null ? " i klasie „{$klasa}”" : '').'.',
        );

        $unikalne = array_values(array_unique($trafienia));

        $this->assertCount(
            1,
            $unikalne,
            "Wejście „{$wejscie}”: odnośniki „{$napis}” prowadzą w różne miejsca (".
            implode(', ', $unikalne).'). Test nie ma jak rozstrzygnąć, które z nich jest tym z tabeli #366.',
        );

        return $unikalne[0];
    }

    /**
     * Wszystkie odnośniki ze strony: ścieżka (bez hosta i bez zapytania),
     * znormalizowany tekst i lista klas.
     *
     * DOMDocument, nie wyrażenie regularne: odnośnik „composer” na `/home`
     * i pozycja szyny obejmują kilka elementów razem z podpisem, a tekst
     * takiego odnośnika trzeba złożyć z drzewa, nie wyciąć ze znacznika.
     *
     * @return list<array{sciezka: string, tekst: string, klasy: list<string>}>
     */
    private function odnosniki(string $html): array
    {
        $dokument = new \DOMDocument;

        $poprzednie = libxml_use_internal_errors(true);
        // Bez `mb_convert_encoding`: `<meta charset>` w layoucie wystarcza,
        // a jawny prefiks XML gwarantuje UTF-8 także dla fragmentów.
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $wynik = [];

        foreach ($dokument->getElementsByTagName('a') as $element) {
            $href = $element->getAttribute('href');

            if ($href === '') {
                continue;
            }

            $sciezka = parse_url($href, PHP_URL_PATH);

            if (! is_string($sciezka)) {
                continue;
            }

            $wynik[] = [
                'sciezka' => rtrim($sciezka, '/') === '' ? '/' : rtrim($sciezka, '/'),
                'tekst' => trim((string) preg_replace('/\s+/u', ' ', $element->textContent)),
                'klasy' => preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [],
            ];
        }

        return $wynik;
    }

    /**
     * Dane i logowanie dla jednego wejścia.
     *
     * Zwraca: adres ekranu źródłowego, napis odnośnika, klasę odnośnika
     * (gdy napis nie wystarcza) i tryb dopasowania napisu.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: bool}
     */
    private function postawSwiat(string $wejscie): array
    {
        return match ($wejscie) {
            // --- osiem drzwi do „Zdjęcie i kilka słów” --------------------
            'home-composer' => [
                $this->zalogujIWezAdres(fn () => route('home')),
                'Co dziś gotujesz?',
                'composer',
                // Pytanie razem z podpisem należy do jednego odnośnika.
                // Klasa composer odróżnia go od pozostałych wejść do publikacji.
                true,
            ],
            'home-pusty-stan' => [
                $this->zalogujIWezAdres(fn () => route('home')),
                'Dodaj pierwsze zdjęcie',
                null,
                false,
            ],
            'profil-przycisk' => [
                $this->zalogujIWezAdres(fn (User $ja) => route('profile.show', $ja->profile->username)),
                'Dodaj zdjęcie',
                null,
                false,
            ],
            'profil-pusty-stan' => [
                $this->zalogujIWezAdres(fn (User $ja) => route('profile.show', $ja->profile->username)),
                'Dodaj pierwsze zdjęcie',
                null,
                false,
            ],
            'tag-pusty-stan' => [
                $this->zalogujIWezAdres(function () {
                    // Tag bez ani jednego wpisu — pusty stan strony tagu.
                    $tag = Tag::factory()->create();

                    return route('tags.show', $tag->slug);
                }),
                'Dodaj zdjęcie',
                null,
                false,
            ],
            'odkryj-pusty-stan' => [
                $this->zalogujIWezAdres(fn () => route('discover')),
                'Dodaj pierwsze zdjęcie',
                null,
                false,
            ],
            'onboarding-koniec' => [
                $this->zalogujIWezAdres(fn () => route('onboarding.done')),
                'Dodaj pierwsze zdjęcie',
                null,
                false,
            ],
            'wpis-kolejne-zdjecie' => [
                $this->zalogujIWezAdres(function (User $ja) {
                    $wpis = Post::factory()->for($ja, 'author')->create();

                    return route('posts.show', $wpis);
                }),
                'Dodaj kolejne zdjęcie',
                null,
                false,
            ],

            // --- trzy drzwi do kreatora przepisu --------------------------
            'szukaj-pusty-stan' => [
                $this->zalogujIWezAdres(fn () => route('search', ['q' => 'niczegotakiegoniema'])),
                'Dodaj taki przepis',
                null,
                false,
            ],
            'profil-przepisy-pusty-stan' => [
                $this->zalogujIWezAdres(fn (User $ja) => route('profile.show', [
                    'username' => $ja->profile->username,
                    'zakladka' => 'przepisy',
                ])),
                'Dodaj przepis',
                null,
                false,
            ],
            'szyna-profilu-przepis' => [
                $this->zalogujIWezAdres(fn (User $ja) => route('profile.show', $ja->profile->username)),
                'Napisz przepis',
                null,
                // Pozycja szyny ma pod nazwą jedno zdanie wyjaśnienia.
                true,
            ],

            // --- trzy drzwi na ekran wyboru -------------------------------
            'belka-dodaj' => [
                $this->zalogujIWezAdres(fn () => route('home')),
                'Dodaj',
                'topbar-desktop-only',
                false,
            ],
            'nawigacja-boczna-dodaj' => [
                $this->zalogujIWezAdres(fn () => route('home')),
                'Dodaj',
                'side-nav-item',
                false,
            ],
            'dolny-pasek-dodaj' => [
                $this->zalogujIWezAdres(fn () => route('home')),
                'Dodaj',
                'bottom-nav-item-glowna',
                false,
            ],

            default => throw new \InvalidArgumentException("Nieznane wejście: {$wejscie}"),
        };
    }

    /**
     * Konto z profilem, zalogowanie i adres ekranu źródłowego.
     *
     * Świat jest stawiany OSOBNO dla każdego wejścia, bo połowa z nich to
     * PUSTE STANY — jeden wspólny zestaw danych wykluczyłby je nawzajem
     * (wpis potrzebny do „Dodaj kolejne zdjęcie” wypełniłby feed, a wtedy
     * pusty stan `/home` w ogóle by się nie narysował).
     *
     * @param  \Closure(User): string  $adres
     */
    private function zalogujIWezAdres(\Closure $adres): string
    {
        $ja = $this->user();
        $this->actingAs($ja);

        return $adres($ja);
    }
}
