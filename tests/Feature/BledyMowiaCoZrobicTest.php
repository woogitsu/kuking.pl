<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Błędy po polsku, mówiące CO ZROBIĆ” (AGENTS.md §5) — mierzone na tym,
 * co naprawdę stanęło na ekranie, a nie na tym, co leży w `lang/`.
 *
 * DLACZEGO TEN PLIK STOI OBOK `WalidacjaPoPolskuTest`
 * Tamten pilnuje, żeby komunikat nie wyszedł PO ANGIELSKU ani jako surowy
 * klucz tłumaczenia (issue #86). To jest warunek konieczny i niewystarczający:
 * „Pole «widoczność» jest wymagane. Uzupełnij je, żeby wysłać formularz."
 * jest po polsku i nazywa pole — a i tak nie mówi człowiekowi, co ma zrobić,
 * bo na żadnym ekranie nie ma niczego o nazwie „widoczność”. Ten plik
 * dokłada trzy brakujące kryteria i pilnuje ich razem.
 *
 * KOMUNIKAT WYZWALANY, NIE CZYTANY Z PLIKU
 * Każdy sprawdzany tu tekst pochodzi z PRAWDZIWEGO żądania do prawdziwej
 * trasy. Komunikat, który leży w `lang/pl/validation.php`, ale nigdy nie
 * wypada, nie jest komunikatem produktu i nie ma czego pilnować. Dlatego
 * scenariusze niżej wysyłają puste i błędne dane do formularzy, z których
 * korzysta człowiek, a asercje czytają WOREK BŁĘDÓW z sesji.
 *
 * ZMIERZONE PRZED NAPRAWĄ (wrzesień 2026), na 85 komunikatach wyzwolonych
 * z 46 żądań:
 *
 * | Kryterium | Oblewało |
 * |---|---|
 * | po polsku, bez surowego klucza | 0 |
 * | nazywa pole słowem z ekranu | 12 |
 * | mówi, co zrobić | 12 |
 * | odnośnik z podsumowania prowadzi do istniejącej kotwicy | 7 z 22 |
 * | błąd powiązany z polem przez ARIA | 7 z 25 |
 *
 * PUŁAPKA 1 i 1b Z `docs/PULAPKI_TESTOW.md`
 * Żadna asercja w tym pliku nie patrzy na cały dokument. Treści komunikatów
 * bierzemy wprost z worka błędów (a nie z HTML-a, w którym to samo zdanie
 * stoi dwa razy: przy polu i w podsumowaniu), a kotwice i powiązania ARIA
 * sprawdzamy przez `DOMXPath` po KONKRETNYM identyfikatorze pola. Dzięki
 * temu test nie może przejść dlatego, że szukany napis stoi w `<title>`,
 * w belce albo w stopce.
 *
 * PUŁAPKA 2
 * Test przechodzący po zbiorze przechodzi też wtedy, gdy zbiór jest pusty.
 * Dlatego każdy test ma asercję na MINIMALNĄ liczbę zebranych komunikatów.
 */
final class BledyMowiaCoZrobicTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ile komunikatów musi się WYZWOLIĆ, żeby ten plik cokolwiek mierzył.
     *
     * Liczba jest niższa od zmierzonych 85, bo część scenariuszy zależy od
     * kolejności reguł i przy zmianie formularza może dać jeden komunikat
     * mniej. Ma pilnować rzędu wielkości, nie dokładnej liczby — chodzi
     * o to, żeby przeniesienie trasy albo zmiana nazwy pola nie wyłączyły
     * testu bez ani jednego czerwonego przebiegu (pułapka 2).
     */
    private const MINIMUM_KOMUNIKATOW = 60;

    /**
     * Nazwy kolumn i pól technicznych, które NIE MAJĄ PRAWA stanąć na ekranie.
     *
     * Lista jest wyprowadzona z sekcji `attributes` w `lang/pl/validation.php`
     * — to są dokładnie te nazwy, których ten plik ma nie pokazywać człowiekowi.
     */
    private const NAZWY_TECHNICZNE = [
        'display_name', 'username', 'email', 'password', 'password_confirmation',
        'age_confirmed', 'terms_accepted', 'visibility', 'source_type', 'source_url',
        'source_person', 'source_note', 'source_scan', 'hero_photo', 'family_since_year',
        'prep_minutes', 'cook_minutes', 'perceived_difficulty', 'actual_minutes',
        'would_make_again', 'changes_note', 'reason_code', 'user_message', 'suspend_days',
        'text_scale', 'wants_weekly_digest', 'collection_id', 'parent_id', 'media_ids',
        'topic_id', 'decision_note', 'illegality_explanation', 'good_faith', 'target_url',
        'reportable_id', 'author_id', 'created_at', 'updated_at',
    ];

    /**
     * Polecenia, po których poznajemy, że zdanie MÓWI, CO ZROBIĆ.
     *
     * To jest lista JAWNA i skończona — nie heurystyka. Komunikat bez ani
     * jednego z tych słów mówi najwyżej, co jest źle („To pole nie może być
     * puste”, „Wartość jest nieprawidłowa”), a taki tekst zostawia człowieka
     * dokładnie tam, gdzie był. Nowe polecenie wolno dopisać do tej listy,
     * ale świadomie, razem z komunikatem, który go używa.
     */
    private const POLECENIA = [
        'wpisz', 'zaznacz', 'wybierz', 'podaj', 'napisz', 'uzupełnij', 'skróć',
        'dopisz', 'zmieść', 'sprawdź', 'otwórz', 'wklej', 'potwierdź', 'dodaj',
        'opisz', 'popraw', 'spróbuj', 'kliknij', 'odśwież', 'zmień', 'powtórz',
        'wróć', 'poproś', 'załóż', 'zaloguj', 'poczekaj', 'usuń', 'wyślij',
        'zapisz', 'wymyśl',
    ];

    // -----------------------------------------------------------------
    //  Trzy kryteria treści
    // -----------------------------------------------------------------

    public function test_kazdy_wyzwolony_komunikat_jest_po_polsku(): void
    {
        $zebrane = $this->wyzwolKomunikaty();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_KOMUNIKATOW,
            count($zebrane),
            'Scenariusze nie wyzwoliły komunikatów — zmieniły się trasy albo nazwy pól? '
            .'Test bez wyzwolonych komunikatów niczego nie mierzy (pułapka 2).',
        );

        foreach ($zebrane as $gdzie => $komunikat) {
            $this->assertStringNotContainsString(
                'validation.',
                $komunikat,
                "{$gdzie}: komunikat jest surowym kluczem tłumaczenia — „{$komunikat}”.",
            );

            foreach ([' field ', 'is required', 'must be', 'may not be', 'The '] as $fraza) {
                $this->assertStringNotContainsString(
                    $fraza,
                    $komunikat,
                    "{$gdzie}: komunikat zawiera angielską frazę „{$fraza}” — „{$komunikat}”.",
                );
            }
        }
    }

    public function test_zaden_wyzwolony_komunikat_nie_nazywa_pola_nazwa_kolumny(): void
    {
        $zebrane = $this->wyzwolKomunikaty();

        $this->assertGreaterThanOrEqual(self::MINIMUM_KOMUNIKATOW, count($zebrane));

        foreach ($zebrane as $gdzie => $komunikat) {
            foreach (self::NAZWY_TECHNICZNE as $nazwa) {
                $this->assertStringNotContainsString(
                    $nazwa,
                    mb_strtolower($komunikat),
                    "{$gdzie}: komunikat nazywa pole jego nazwą techniczną „{$nazwa}”. "
                    ."Człowiek widzi na ekranie etykietę, nie kolumnę — „{$komunikat}”.",
                );
            }
        }
    }

    public function test_kazdy_wyzwolony_komunikat_mowi_co_zrobic(): void
    {
        $zebrane = $this->wyzwolKomunikaty();

        $this->assertGreaterThanOrEqual(self::MINIMUM_KOMUNIKATOW, count($zebrane));

        foreach ($zebrane as $gdzie => $komunikat) {
            $male = mb_strtolower($komunikat);

            $maPolecenie = false;
            foreach (self::POLECENIA as $polecenie) {
                if (str_contains($male, $polecenie)) {
                    $maPolecenie = true;
                    break;
                }
            }

            $this->assertTrue(
                $maPolecenie,
                "{$gdzie}: komunikat mówi, co jest źle, ale nie mówi, CO ZROBIĆ — „{$komunikat}”. "
                .'Dopisz polecenie („Wpisz…", „Zaznacz…", „Skróć…"), a jeśli używasz nowego '
                .'czasownika — dopisz go do listy POLECENIA w tym pliku.',
            );
        }
    }

    /**
     * Cudzysłów drukarski w NAZWIE POLA daje cudzysłów w cudzysłowie.
     *
     * Szablony w `lang/pl/validation.php` same otaczają `:attribute`
     * cudzysłowem, więc nazwa z własnym cudzysłowem dawała na ekranie
     * „Pole «odpowiedź «zrobię jeszcze raz»» przyjmuje…”. Zmierzone na
     * `would_make_again` przy formularzu „Ugotowałem”.
     */
    public function test_nazwy_pol_nie_zawieraja_wlasnego_cudzyslowu(): void
    {
        $nazwy = require base_path('lang/pl/validation.php');
        $nazwy = $nazwy['attributes'];

        $this->assertGreaterThan(50, count($nazwy), 'Sekcja `attributes` jest pusta — zła ścieżka?');

        foreach ($nazwy as $pole => $nazwa) {
            $this->assertDoesNotMatchRegularExpression(
                '/[„”"]/u',
                (string) $nazwa,
                "Nazwa pola „{$pole}” zawiera cudzysłów. Szablony walidacji dokładają własny, "
                .'więc na ekran wyjdzie cudzysłów w cudzysłowie.',
            );
        }
    }

    // -----------------------------------------------------------------
    //  Błąd wskazuje POLE, którego dotyczy
    // -----------------------------------------------------------------

    /**
     * Odnośnik z podsumowania na górze formularza prowadzi do istniejącego pola.
     *
     * `x-error-summary` robi z każdego błędu odnośnik pod `#f-<nazwa pola>`.
     * Przed naprawą siedem z dwudziestu dwóch takich odnośników nie miało
     * celu w dokumencie: `x-field` nadaje polu to `id`, ale grupy wyboru
     * (`visibility`, `reason`, `kind`, `perceived_difficulty`) nie nadawały
     * go wcale. Kliknięcie błędu nie ruszało strony z miejsca.
     */
    public function test_kazdy_odnosnik_z_podsumowania_prowadzi_do_pola(): void
    {
        $sprawdzone = 0;

        foreach ($this->ekranyZBledami() as $etykieta => $scenariusz) {
            [$wyslij, $adres] = $scenariusz;
            $wyslij();

            $xpath = $this->xpath($this->get($adres)->getContent());
            $odnosniki = $xpath->query("//*[contains(@class,'error-summary')]//a");

            $this->assertNotNull($odnosniki);
            $this->assertGreaterThan(
                0,
                $odnosniki->length,
                "{$etykieta}: na ekranie nie ma podsumowania błędów, choć żądanie zostało odrzucone.",
            );

            foreach ($odnosniki as $odnosnik) {
                $kotwica = ltrim(($odnosnik instanceof DOMElement ? $odnosnik->getAttribute('href') : ''), '#');

                $this->assertSame(
                    1,
                    $xpath->query("//*[@id='".$kotwica."']")->length,
                    "{$etykieta}: odnośnik „#{$kotwica}” z podsumowania błędów nie prowadzi "
                    .'do żadnego pola. Kliknięcie błędu nie rusza strony z miejsca.',
                );

                $sprawdzone++;
            }
        }

        $this->assertGreaterThanOrEqual(
            15,
            $sprawdzone,
            'Nie sprawdzono żadnych odnośników — podsumowanie się nie wyrenderowało (pułapka 2).',
        );
    }

    /**
     * Błąd jest CZĘŚCIĄ OPISU pola, a nie osobnym zdaniem gdzieś pod spodem.
     *
     * Dla pól z `x-field` robi to sam komponent. Dla grup wyboru i pól
     * plikowych trzeba było dołożyć `id`, `aria-invalid` i `aria-describedby`
     * ręcznie — bez nich czytnik ekranu wchodził w grupę i nie dowiadywał
     * się, że jest z nią coś nie tak.
     */
    public function test_blad_jest_powiazany_z_polem_dla_czytnika_ekranu(): void
    {
        $sprawdzone = 0;

        foreach ($this->ekranyZBledami() as $etykieta => $scenariusz) {
            [$wyslij, $adres] = $scenariusz;
            $wyslij();

            $pola = array_keys($this->workBledow());
            // Dwa formularze bezpieczeństwa mają wspólne nazwy pól, lecz
            // osobne konteksty błędu. Sufiks pochodzi z odrzuconej operacji.
            $wiersz = old('_wiersz');
            $xpath = $this->xpath($this->get($adres)->getContent());

            foreach ($pola as $pole) {
                $id = 'f-'.str_replace(['[', ']', '.'], '-', $pole);
                if ($wiersz !== null) {
                    $id .= '-'.str_replace(['[', ']', '.'], '-', (string) $wiersz);
                }

                $this->assertSame(
                    1,
                    $xpath->query("//*[@id='".$id."-error']")->length,
                    "{$etykieta}: błąd pola „{$pole}” nie stoi PRZY POLU — jest tylko "
                    .'w podsumowaniu na górze, a UX_50_PLUS.md wymaga obu miejsc naraz.',
                );

                $this->assertGreaterThan(
                    0,
                    $xpath->query("//*[@name='".$pole."'][@aria-invalid='true']")->length
                    + $xpath->query("//*[@id='".$id."'][@aria-invalid='true']")->length,
                    "{$etykieta}: pole „{$pole}” nie ma `aria-invalid` — czytnik ekranu nie powie, "
                    .'że jest z nim coś nie tak.',
                );

                $this->assertGreaterThan(
                    0,
                    $xpath->query("//*[@name='".$pole."'][contains(@aria-describedby,'".$id."-error')]")->length
                    + $xpath->query("//*[@id='".$id."'][contains(@aria-describedby,'".$id."-error')]")->length,
                    "{$etykieta}: treść błędu pola „{$pole}” nie jest wpięta w `aria-describedby`, "
                    .'więc czytnik ekranu przeczyta etykietę bez błędu.',
                );

                $sprawdzone++;
            }
        }

        $this->assertGreaterThanOrEqual(
            15,
            $sprawdzone,
            'Nie sprawdzono żadnego pola — żaden scenariusz nie dał błędu (pułapka 2).',
        );
    }

    // -----------------------------------------------------------------
    //  Poprawne dane nie znikają
    // -----------------------------------------------------------------

    /**
     * Wybór w grupie przycisków przeżywa odrzucenie formularza.
     *
     * `checked` wpisane na sztywno wygląda niewinnie, a kasuje ŚWIADOMĄ
     * decyzję o widoczności: kto przy zakładaniu zeszytu wybrał „Wszyscy”
     * i pomylił się w nazwie, dostawał formularz z powrotem z zaznaczonym
     * „Tylko ja”. Cicha zmiana widoczności jest groźniejsza od utraty tekstu.
     *
     * KONTROLA DODATNIA (pułapka 4) jest w drugiej połowie testu: sprawdzamy
     * także, że przy PUSTYM formularzu domyślnie stoi „Tylko ja”. Bez tego
     * asercja przeszłaby również wtedy, gdyby nie było zaznaczone nic.
     */
    public function test_wybrana_widocznosc_zeszytu_nie_znika_po_odrzuceniu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post('/zeszyt', [
            'name' => str_repeat('a', 200),   // za długa — formularz wróci
            'visibility' => 'public',          // ŚWIADOMY wybór „Wszyscy"
        ])->assertSessionHasErrors('name');

        $this->assertSame(
            'public',
            $this->zaznaczonaWidocznoscZeszytu(),
            'Wybór „Wszyscy" zniknął po odrzuceniu formularza — zeszyt założyłby się jako prywatny.',
        );

        // Kontrola dodatnia: na czystym formularzu stoi wartość domyślna,
        // więc asercja wyżej mierzy przeniesienie wyboru, a nie to, że
        // cokolwiek jest zaznaczone.
        $this->assertSame(
            'private',
            $this->zaznaczonaWidocznoscZeszytu(),
            'Na czystym formularzu nie jest zaznaczona żadna opcja domyślna.',
        );
    }

    /**
     * Tekst, który człowiek wpisał, wraca do pola razem z błędem.
     *
     * Druga twarda reguła UX 50+ obok samego komunikatu. Sprawdzamy na
     * dodawaniu wpisu, bo tam obok siebie stoją pole tekstowe i grupa wyboru.
     */
    public function test_wpisany_tekst_nie_znika_po_odrzuceniu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post('/dodaj/zdjecie', [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada.',
            'visibility' => 'nie-taka-opcja',
        ])->assertSessionHasErrors('visibility');

        $xpath = $this->xpath($this->get('/dodaj/zdjecie')->getContent());
        $pole = $xpath->query("//textarea[@name='body']")?->item(0);

        $this->assertInstanceOf(DOMElement::class, $pole, 'Na ekranie nie ma pola „Napisz kilka słów".');
        $this->assertStringContainsString(
            'Rosół na niedzielę, z kaczki od sąsiada.',
            $pole->textContent,
            'Wpisany tekst zniknął po odrzuceniu formularza.',
        );
    }

    // -----------------------------------------------------------------
    //  Pomoc
    // -----------------------------------------------------------------

    /**
     * Scenariusze, które wyzwalają błędy na formularzach używanych przez ludzi.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    private function ekranyZBledami(): array
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        $przepis = Recipe::factory()->create(['author_id' => $halina->getKey()]);
        $wpis = Post::factory()->create(['author_id' => $halina->getKey()]);

        return [
            'rejestracja' => [fn () => $this->post('/register', []), '/register'],
            'logowanie' => [fn () => $this->post('/login', []), '/login'],
            'dodanie wpisu' => [fn () => $this->actingAs($basia)->post('/dodaj/zdjecie', []), '/dodaj/zdjecie'],
            'dodanie przepisu' => [fn () => $this->actingAs($basia)->post('/dodaj/przepis', []), '/dodaj/przepis'],
            'zeszyt' => [fn () => $this->actingAs($basia)->post('/zeszyt', []), '/zeszyt'],
            'ustawienia profilu' => [fn () => $this->actingAs($basia)->put('/ustawienia/profil', []), '/ustawienia/profil'],
            'zmiana hasła' => [
                fn () => $this->actingAs($basia)->put('/ustawienia/bezpieczenstwo/haslo', []),
                '/ustawienia/bezpieczenstwo',
            ],
            'zgłoszenie treści' => [
                fn () => $this->actingAs($basia)->post('/zglos/post/'.$wpis->getKey(), []),
                '/zglos/post/'.$wpis->getKey(),
            ],
            'ugotowałem' => [
                fn () => $this->actingAs($basia)->post('/przepisy/'.$przepis->slug.'/ugotowalem', [
                    'perceived_difficulty' => 'nie-taka',
                    'would_make_again' => 'moze',
                ]),
                '/przepisy/'.$przepis->slug.'/ugotowalem',
            ],
            'napisz do nas' => [fn () => $this->post('/napisz-do-nas', []), '/napisz-do-nas'],
            'zdjęcie profilowe' => [
                fn () => $this->actingAs($basia)->post('/ustawienia/zdjecie', []),
                '/ustawienia/zdjecie',
            ],
            'usunięcie konta' => [
                fn () => $this->actingAs($basia)->post('/ustawienia/twoje-dane/usun-konto', []),
                '/ustawienia/twoje-dane',
            ],
            'zgłoszenie nielegalnej treści' => [
                fn () => $this->post('/zglos-nielegalna-tresc', []),
                '/zglos-nielegalna-tresc',
            ],
        ];
    }

    /**
     * Wysyła wszystkie scenariusze i zwraca to, co REALNIE stanęło w worku
     * błędów, pod kluczem „formularz / pole”.
     *
     * @return array<string, string>
     */
    private function wyzwolKomunikaty(): array
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        $przepis = Recipe::factory()->create(['author_id' => $halina->getKey()]);
        $wpis = Post::factory()->create(['author_id' => $halina->getKey()]);

        $scenariusze = [
            'rejestracja / puste' => fn () => $this->post('/register', []),
            'rejestracja / złe' => fn () => $this->post('/register', [
                'display_name' => 'a',
                'username' => '!!!',
                'email' => 'basia',
                'password' => 'haslo',
                'age_confirmed' => '0',
                'terms_accepted' => '0',
            ]),
            'logowanie / puste' => fn () => $this->post('/login', []),
            'logowanie / złe hasło' => fn () => $this->post('/login', [
                'login' => 'basia', 'password' => 'nie-to-haslo',
            ]),
            'nie pamiętam hasła' => fn () => $this->post('/nie-pamietam-hasla', ['email' => 'nie-adres']),
            'nowe hasło / puste' => fn () => $this->post('/nowe-haslo', []),
            'nowe hasło / złe' => fn () => $this->post('/nowe-haslo', [
                'token' => 'zly', 'email' => 'basia@example.com', 'password' => 'abc',
            ]),
            'wpis / puste' => fn () => $this->actingAs($basia)->post('/dodaj/zdjecie', []),
            'wpis / złe' => fn () => $this->actingAs($basia)->post('/dodaj/zdjecie', [
                'body' => str_repeat('a', 4001), 'visibility' => 'nie-taka',
            ]),
            'przepis / puste' => fn () => $this->actingAs($basia)->post('/dodaj/przepis', []),
            'przepis / złe' => fn () => $this->actingAs($basia)->post('/dodaj/przepis', [
                'action' => 'publish',
                'title' => 'a',
                'visibility' => 'nie-taka',
                'source_type' => 'nie-takie',
                'servings' => 0,
                'prep_minutes' => -5,
                'cook_minutes' => 100000,
                'difficulty' => 'nie-taka',
                'family_since_year' => 1000,
                'source_url' => 'to-nie-adres',
            ]),
            'komentarz pod wpisem' => fn () => $this->actingAs($basia)
                ->post('/wpisy/'.$wpis->getKey().'/komentarz', []),
            'komentarz pod przepisem' => fn () => $this->actingAs($basia)
                ->post('/przepisy/'.$przepis->slug.'/komentarz', []),
            'ugotowałem / złe' => fn () => $this->actingAs($basia)
                ->post('/przepisy/'.$przepis->slug.'/ugotowalem', [
                    'perceived_difficulty' => 'nie-taka',
                    'actual_minutes' => -3,
                    'would_make_again' => 'moze',
                    'note' => str_repeat('a', 3000),
                ]),
            'zeszyt / puste' => fn () => $this->actingAs($basia)->post('/zeszyt', []),
            'zeszyt / złe' => fn () => $this->actingAs($basia)->post('/zeszyt', [
                'name' => str_repeat('a', 200), 'visibility' => 'nie-taka',
            ]),
            'profil / puste' => fn () => $this->actingAs($basia)->put('/ustawienia/profil', []),
            'profil / złe' => fn () => $this->actingAs($basia)->put('/ustawienia/profil', [
                'display_name' => 'a',
                'username' => '!!',
                'bio' => str_repeat('a', 1000),
                'region' => str_repeat('a', 500),
                'speciality' => str_repeat('a', 500),
            ]),
            'profil / nazwa zajęta' => fn () => $this->actingAs($basia)->put('/ustawienia/profil', [
                'display_name' => 'Basia', 'username' => 'halina',
            ]),
            'zmiana hasła / puste' => fn () => $this->actingAs($basia)
                ->put('/ustawienia/bezpieczenstwo/haslo', []),
            'zmiana hasła / złe' => fn () => $this->actingAs($basia)
                ->put('/ustawienia/bezpieczenstwo/haslo', [
                    'current_password' => 'zle-haslo', 'password' => 'abc', 'password_confirmation' => 'xyz',
                ]),
            'zmiana e-maila' => fn () => $this->actingAs($basia)->post('/ustawienia/e-mail', [
                'email' => 'nie-adres', 'password' => 'zle',
            ]),
            'czytelność' => fn () => $this->actingAs($basia)->put('/ustawienia/czytelnosc', ['text_scale' => 999]),
            'zgłoszenie / puste' => fn () => $this->actingAs($basia)->post('/zglos/post/'.$wpis->getKey(), []),
            'zgłoszenie / złe' => fn () => $this->actingAs($basia)->post('/zglos/post/'.$wpis->getKey(), [
                'reason_code' => 'nie-taki', 'details' => str_repeat('a', 3000),
            ]),
            'nielegalna treść' => fn () => $this->post('/zglos-nielegalna-tresc', []),
            'napisz do nas' => fn () => $this->post('/napisz-do-nas', []),
            'odwołanie' => fn () => $this->post('/odwolanie', []),
            'usunięcie konta' => fn () => $this->actingAs($basia)
                ->post('/ustawienia/twoje-dane/usun-konto', []),
            'wygląd' => fn () => $this->post('/motyw', ['theme' => 'nie-taki']),
            'włączenie 2FA' => fn () => $this->actingAs($basia)->post('/ustawienia/2fa/wlacz', []),
            'zdjęcie profilowe' => fn () => $this->actingAs($basia)->post('/ustawienia/zdjecie', []),
        ];

        $zebrane = [];

        foreach ($scenariusze as $etykieta => $zadanie) {
            $zadanie();

            foreach ($this->workBledow() as $pole => $komunikaty) {
                foreach ($komunikaty as $numer => $komunikat) {
                    $zebrane[$etykieta.' / '.$pole.($numer > 0 ? ' #'.$numer : '')] = $komunikat;
                }
            }
        }

        return $zebrane;
    }

    /**
     * Worek błędów z sesji, jako zwykła tablica „pole => komunikaty”.
     *
     * Czytamy z sesji, a NIE z HTML-a, bo to samo zdanie stoi w dokumencie
     * dwa razy (przy polu i w podsumowaniu), a do tego strona niesie belkę,
     * szynę i stopkę — pułapka 1 i 1b z `docs/PULAPKI_TESTOW.md`.
     *
     * @return array<string, list<string>>
     */
    private function workBledow(): array
    {
        $bledy = app('session.store')->get('errors');

        if (is_object($bledy)) {
            return $bledy->getBag('default')->getMessages();
        }

        return is_array($bledy) ? ($bledy['default']['messages'] ?? []) : [];
    }

    /** Która opcja widoczności jest zaznaczona na formularzu nowego zeszytu. */
    private function zaznaczonaWidocznoscZeszytu(): ?string
    {
        $xpath = $this->xpath($this->get('/zeszyt')->getContent());

        // Zawężamy do GRUPY, a nie do całego dokumentu: na tym ekranie stoją
        // też karty istniejących zeszytów (pułapka 1).
        $zaznaczone = $xpath->query("//*[@id='f-visibility']//input[@checked]");

        $this->assertNotNull($zaznaczone);

        $pierwszy = $zaznaczone->item(0);

        return $pierwszy instanceof DOMElement ? $pierwszy->getAttribute('value') : null;
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dokument);
    }
}
