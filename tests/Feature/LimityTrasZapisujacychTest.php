<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LIMITY ZAPYTAŃ NA TRASACH ZAPISUJĄCYCH (BRAMKA_BETY §7a).
 *
 * Dokument bety zapisał, że część tras zmieniających stan nie ma limitu na
 * poziomie trasy, i zostawił dobranie progów jako decyzję produktową. Progi
 * są już dobrane i mieszkają w `config/kuking.php`; ten plik pilnuje trzech
 * rzeczy, każdej na innej reprezentatywnej trasie:
 *
 *  1. GRUPA „POWIADAMIA KOGOŚ INNEGO" — obserwowanie osoby. Limit istnieje,
 *     ale NIE łapie człowieka, który pierwszego dnia klika przez „Odkryj".
 *  2. GRUPA „GENERUJE PRACĘ SERWERA" — paczka RODO. Limit istnieje i jest
 *     wyraźnie ciaśniejszy, bo jedno żądanie kolejkuje zadanie czytające
 *     całe konto.
 *  3. GRUPY NIE DZIELĄ LICZNIKA. Wieczór spędzony na zapisywaniu cudzych
 *     wpisów do zeszytu nie ma prawa odebrać prawa do opublikowania
 *     własnego wpisu — to jest test REGRESYJNY do usterki opisanej niżej.
 *
 * DLACZEGO TESTY CZYTAJĄ LIMIT Z KONFIGURACJI, A NIE MAJĄ LICZB W ŚRODKU
 * Bo sprawdzają REGUŁĘ („limit działa i mieści zwykłe użycie"), a nie jedną
 * konkretną liczbę. Właściciel ma prawo podnieść albo obniżyć próg
 * w `config/kuking.php` bez przepisywania testów — a test z wklejoną liczbą
 * albo by wtedy oblał bez powodu, albo (gorzej) przestał cokolwiek mierzyć.
 * Wyjątkiem są asercje o SENSOWNYM RZĘDZIE WIELKOŚCI: te są tu celowo, żeby
 * ktoś, kto wpisze `1,60`, dowiedział się o tym od testu, a nie od
 * użytkownika.
 */
class LimityTrasZapisujacychTest extends TestCase
{
    use RefreshDatabase;

    /** Pierwsza liczba z zapisu „próby,minuty" w config/kuking.php. */
    private function proby(string $klucz): int
    {
        return (int) explode(',', (string) config("kuking.limits.{$klucz}"))[0];
    }

    // -----------------------------------------------------------------
    //  1. Grupa „powiadamia kogoś innego" — obserwowanie
    // -----------------------------------------------------------------

    public function test_obserwowanie_naprawde_powiadamia_i_naprawde_ma_limit(): void
    {
        $basia = $this->user('basia');
        $this->user('adam');

        // Najpierw dowód, że ta trasa faktycznie należy do grupy, którą tu
        // testujemy: jedno kliknięcie budzi powiadomienie u drugiej osoby.
        // Bez tej asercji test mierzyłby limit dowolnego zapisu do bazy.
        $this->actingAs($basia)->post(route('social.follow', 'adam'))->assertRedirect();

        $this->assertSame(
            1,
            Notification::query()->where('type', Notification::TYPE_FOLLOW)->count(),
            'Obserwowanie przestało tworzyć powiadomienie u drugiej osoby. '
            .'Jeśli to zmiana zamierzona, ta trasa nie należy już do grupy '
            .'„powiadamia kogoś" i jej limit (`obserwowanie`) trzeba przemyśleć '
            .'od nowa, a nie tylko poprawić ten test.',
        );

        $limit = $this->proby('obserwowanie');

        $this->assertGreaterThanOrEqual(
            30,
            $limit,
            'Limit obserwowania spadł poniżej rzędu wielkości, w którym mieści się '
            .'pierwszy dzień w serwisie. Człowiek przechodzi wtedy przez „Odkryj" '
            .'i klika „Obserwuj" kilkadziesiąt razy w kilka minut — a od tego '
            .'zależy, czy jego strona główna nie będzie pusta (AGENTS.md §8). '
            .'Limit, który to złapie, jest gorszy niż jego brak.',
        );

        // Reszta budżetu, na przemian obserwuj/przestań obserwuj.
        //
        // NA PRZEMIAN JEST TU SEDNEM, NIE OSZCZĘDNOŚCIĄ. Oba kierunki mają
        // dzielić JEDEN licznik (prefiks `obserwowanie`), bo osobne dawałyby
        // cyklowi obserwuj→przestań→obserwuj podwójny budżet — czyli dokładnie
        // tyle, ile potrzebuje wzorzec, przed którym ten limit stoi.
        for ($i = 1; $i < $limit; $i++) {
            $odpowiedz = $i % 2 === 1
                ? $this->actingAs($basia)->delete(route('social.unfollow', 'adam'))
                : $this->actingAs($basia)->post(route('social.follow', 'adam'));

            $this->assertNotSame(
                429,
                $odpowiedz->getStatusCode(),
                "Limit odbił zmianę obserwowania numer {$i}, mieszcząc się jeszcze "
                ."w deklarowanym budżecie {$limit}. To znaczy, że licznik tej trasy "
                .'zbiera także żądania z innej grupy — patrz '
                .'`LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.',
            );
        }

        // Pierwsze żądanie PONAD budżet.
        $this->actingAs($basia)
            ->post(route('social.follow', 'adam'))
            ->assertStatus(429);
    }

    // -----------------------------------------------------------------
    //  2. Grupa „generuje pracę serwera" — paczka RODO
    // -----------------------------------------------------------------

    public function test_paczka_rodo_ma_wlasny_ciasny_limit(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $limit = $this->proby('eksport');

        $this->assertLessThan(
            $this->proby('ustawienia'),
            $limit,
            'Prośba o paczkę z danymi ma limit nie ciaśniejszy niż zwykłe zapisanie '
            .'ustawień, mimo że kolejkuje zadanie czytające CAŁE konto i pakujące '
            .'wszystkie zdjęcia. To jest najdroższe pojedyncze żądanie w serwisie '
            .'i nie może dzielić rzędu wielkości z przełącznikiem wyglądu.',
        );

        $this->assertGreaterThanOrEqual(
            3,
            $limit,
            'Limit paczki RODO jest tak niski, że złapie człowieka klikającego '
            .'„Przygotuj paczkę" drugi raz, bo nie widzi postępu — a zadanie trwa '
            .'kilkanaście minut, więc takie klikanie jest normą, nie nadużyciem.',
        );

        for ($i = 1; $i <= $limit; $i++) {
            $this->assertNotSame(
                429,
                $this->actingAs($basia)->post(route('settings.data.export'))->getStatusCode(),
                "Prośba o paczkę numer {$i} odbiła się o limit, mieszcząc się jeszcze "
                ."w deklarowanym budżecie {$limit}.",
            );
        }

        $this->actingAs($basia)
            ->post(route('settings.data.export'))
            ->assertStatus(429);

        // WŁASNY KOSZYK, NIE WSPÓLNY. Wyczerpanie budżetu paczki nie może
        // zamknąć człowiekowi ustawień czytelności — to inna grupa, inny
        // prefiks licznika i inna szkoda z nadużycia.
        $this->assertNotSame(
            429,
            $this->actingAs($basia)->put(route('settings.accessibility'), [
                'text_scale' => 125,
            ])->getStatusCode(),
            'Wyczerpanie limitu paczki RODO zablokowało też ustawienia czytelności. '
            .'Obie trasy dzielą licznik, mimo że mają różne progi — to jest ten sam '
            .'kształt usterki co zgłoszenie „429 przy pierwszym zdjęciu".',
        );
    }

    // -----------------------------------------------------------------
    //  3. Regresja: zeszyt nie zjada budżetu publikacji
    // -----------------------------------------------------------------

    public function test_zapisywanie_do_zeszytu_nie_zjada_budzetu_publikacji(): void
    {
        // ZMIERZONA USTERKA, NIE HIPOTEZA.
        //
        // `collections.save-post` i `collections.unsave-post` chodziły pod
        // prefiksem `post`, czyli pod budżetem PUBLIKOWANIA. Skutek: wieczór
        // spędzony na przeglądaniu „Odkryj" i zapisywaniu cudzych wpisów
        // odbierał prawo do opublikowania własnego — ten sam kształt usterki
        // co zgłoszenie „429 przy pierwszym zdjęciu", tylko na innej parze
        // tras. Naprawa: własny prefiks `zeszyt`.
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $budzetPublikacji = $this->proby('post');

        // Wyraźnie WIĘCEJ zapisów do zeszytu, niż wynosi cały budżet
        // publikacji — gdyby liczniki były wspólne, publikacja niżej nie
        // miałaby szans przejść.
        $ile = $budzetPublikacji + 5;

        for ($i = 0; $i < $ile; $i++) {
            $odpowiedz = $i % 2 === 0
                ? $this->actingAs($basia)->post(route('collections.save-post', $wpis))
                : $this->actingAs($basia)->delete(route('collections.unsave-post', $wpis));

            $this->assertNotSame(
                429,
                $odpowiedz->getStatusCode(),
                "Zapis do zeszytu numer {$i} odbił się o limit. Budżet zeszytu "
                .'(`zeszyt` w config/kuking.php) ma mieścić zwykły wieczór '
                .'z przeglądaniem „Odkryj", bo to najczęściej powtarzana uczciwa '
                .'akcja w całym produkcie.',
            );
        }

        $publikacja = $this->actingAs($basia)->post(route('posts.store'), [
            'body' => 'Rosół na niedzielę, z kaczki od sąsiada.',
            'visibility' => 'public',
            'media_ids' => [$zdjecie->getKey()],
        ]);

        $this->assertNotSame(
            429,
            $publikacja->getStatusCode(),
            'Publikacja odbiła się o limit po samym zapisywaniu cudzych wpisów do '
            .'zeszytu. Zapis do zeszytu znowu liczy się do budżetu publikowania — '
            .'czyli przeglądanie serwisu odbiera prawo głosu.',
        );

        $publikacja->assertRedirect();
    }

    // -----------------------------------------------------------------
    //  Reguła, nie jednorazowa poprawka
    // -----------------------------------------------------------------

    public function test_kazda_trasa_zapisujaca_ma_limit_albo_jawny_wyjatek(): void
    {
        // Trasy, którym limitu ŚWIADOMIE nie dajemy. Lista jest krótka
        // i każda pozycja ma tu mieć powód — dopisanie nowej wymaga
        // świadomej ręki, a nie przeoczenia w `routes/web.php`.
        $swiadomeWyjatki = [
            // Wylogowanie unieważnia sesję i nic nie tworzy. Za to 429 na tej
            // trasie zostawia OTWARTĄ sesję na wspólnym komputerze u kogoś,
            // kto właśnie próbuje ją zamknąć.
            'logout',

            // NIE NASZA TRASA. `PUT /storage/{path}` rejestruje sam framework
            // dla każdego dysku, który ma `serve => true` — u nas dysk `local`
            // (`config/filesystems.php`). W `routes/web.php` jej nie ma i nie
            // przechodzi przez żadną naszą grupę, więc nie ma gdzie doczepić
            // prefiksu `throttle:` bez przykrywania trasy frameworka własną.
            //
            // To ten sam powód, dla którego pętla niżej pomija `livewire`.
            // Różnica jest jednak taka, że tamto tylko renderuje komponenty,
            // a to ZAPISUJE PLIK — więc samo „nie nasza" nie wystarcza za
            // uzasadnienie. Tego, co chroni tę trasę zamiast limitu, pilnuje
            // test `test_trasa_zapisu_na_dysk_lokalny_odrzuca_zadanie_bez_podpisu`
            // zaraz pod tą regułą.
            'storage.local.upload',

            /*
             * POWIADOMIENIE O ODEBRANIU DOSTĘPU Z FACEBOOKA (issue #259).
             *
             * Woła to serwer Facebooka, nie człowiek — a limit liczy żądania
             * po adresie IP. Ustawiony na tyle nisko, żeby cokolwiek chronił,
             * zaczyna GUBIĆ PRAWDZIWE POWIADOMIENIA, gdy Facebook wyśle ich
             * kilka naraz (jedna osoba usuwa kilka aplikacji, serwery Meta
             * wychodzą z tej samej puli adresów). A Facebook nie ponawia
             * w nieskończoność: zgubione powiadomienie znaczy, że człowiek
             * odebrał nam dostęp, a my nadal pokazujemy mu „połączone".
             *
             * Co chroni tę trasę zamiast limitu: każde żądanie bez poprawnego
             * podpisu `signed_request` kończy się odrzuceniem po JEDNYM
             * `hash_hmac`, przed dotknięciem bazy. Bez sekretu aplikacji nie
             * da się takiego podpisu wytworzyć, więc zalewanie tej trasy jest
             * zalewaniem procesora, a nie drogą do zmiany czegokolwiek —
             * i przed tym broni warstwa przed aplikacją (Cloudflare), a nie
             * `throttle:`. Pilnują tego testy w `OdebranieDostepuFacebookaTest`,
             * w szczególności `test_zly_podpis_niczego_nie_zmienia`.
             */
            'facebook.deauthorize',
        ];

        $bezLimitu = [];
        $zbadanych = 0;

        foreach (Route::getRoutes() as $trasa) {
            $metody = array_diff($trasa->methods(), ['GET', 'HEAD', 'OPTIONS']);

            if ($metody === []) {
                continue;
            }

            // Trasy pakietów (Livewire) nie są nasze i nie my ustawiamy im
            // limity — tak samo jak w `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
            if (str_starts_with($trasa->uri(), 'livewire')) {
                continue;
            }

            if (in_array($trasa->getName(), $swiadomeWyjatki, true)) {
                continue;
            }

            $zbadanych++;
            $maLimit = false;

            foreach ($trasa->gatherMiddleware() as $warstwa) {
                if (is_string($warstwa) && str_starts_with($warstwa, 'throttle:')) {
                    $maLimit = true;
                    break;
                }
            }

            if (! $maLimit) {
                $bezLimitu[] = implode('|', $metody).' /'.$trasa->uri()
                    .'  ('.($trasa->getName() ?? 'bez nazwy').')';
            }
        }

        // ASERCJA KONTROLNA — BEZ NIEJ TEN TEST JEST WYDMUSZKĄ.
        //
        // Cała reguła wyżej to pętla, która dopisuje coś do listy tylko wtedy,
        // gdy znajdzie usterkę. Pusta lista znaczy „wszystko ma limit" ALBO
        // „pętla nie wykonała się ani razu" — a to drugie da się osiągnąć
        // przypadkiem: zmianą nazwy pakietu w warunku `livewire`, przeniesieniem
        // tras do innego pliku, wcześniejszym `continue`. Wtedy test świeciłby
        // na zielono, nie pilnując niczego. Liczba jest zaniżona świadomie:
        // 8 września trasy zapisujące było 67, więc próg 50 przetrwa
        // porządkowanie routingu, ale nie przetrwa sytuacji, w której pętla
        // przestaje je widzieć.
        $this->assertGreaterThan(
            50,
            $zbadanych,
            'Reguła obeszła mniej niż 50 tras zapisujących — to nie jest stan, '
            .'w którym pusta lista znalezisk cokolwiek znaczy. Sprawdź warunki '
            .'pomijające w pętli wyżej, zanim uwierzysz w zielony wynik.',
        );

        $this->assertSame(
            [],
            $bezLimitu,
            "Trasa zapisująca bez limitu zapytań:\n"
            .implode("\n", $bezLimitu)
            ."\n\nAGENTS.md §7: każdy endpoint przechodzi przez pięć pytań — auth, "
            .'authorization, validation, RATE LIMIT i audit. Dobierz grupę '
            .'w `config/kuking.php` (usuwanie / obserwowanie / zeszyt / ustawienia / '
            .'moderacja …) i podepnij ją prefiksem, np. '
            ."`throttle:{\$limits['usuwanie']},usuwanie`. Jeśli brak limitu jest "
            .'decyzją, dopisz nazwę trasy do `$swiadomeWyjatki` w tym teście '
            .'RAZEM Z POWODEM.',
        );
    }

    /**
     * DOWÓD DO WYJĄTKU `storage.local.upload` Z LISTY WYŻEJ.
     *
     * Wpisanie trasy zapisującej na listę wyjątków jest tanie, a przez to
     * niebezpieczne: raz dopisana pozycja zostaje tam na zawsze i nikt jej
     * więcej nie sprawdza. Dlatego wyjątek dla trasy, która ZAPISUJE PLIK,
     * ma tu obok dowód wykonywalny.
     *
     * Sprawdzana własność jest jedna i konkretna: żądanie BEZ ważnego podpisu
     * ma zostać odrzucone. Jeżeli framework kiedykolwiek przestanie tej trasy
     * pilnować, ten test spadnie na czerwono — i wtedy wyjątek z listy wyżej
     * trzeba będzie zdjąć, a nie limit dopisać.
     */
    public function test_trasa_zapisu_na_dysk_lokalny_odrzuca_zadanie_bez_podpisu(): void
    {
        $this->assertNotNull(
            Route::getRoutes()->getByName('storage.local.upload'),
            'Trasa zniknęła — a skoro jej nie ma, to nie ma też po co trzymać jej '
            .'na liście świadomych wyjątków w teście wyżej. Usuń stamtąd wpis '
            .'razem z tym testem.',
        );

        $odpowiedz = $this->put('/storage/podrzucone.txt', [], [
            'Content-Type' => 'text/plain',
        ]);

        $this->assertGreaterThanOrEqual(
            400,
            $odpowiedz->getStatusCode(),
            'Zapis na dysk lokalny przeszedł bez podpisu. To trasa bez limitu '
            .'zapytań (świadomy wyjątek w teście wyżej), więc cokolwiek ją do tej '
            .'pory zamykało — przestało. Wyjątek nie jest już uzasadniony.',
        );

        $this->assertFalse(
            Storage::disk('local')->exists('podrzucone.txt'),
            'Plik jednak powstał, mimo odmownego kodu odpowiedzi.',
        );
    }
}
