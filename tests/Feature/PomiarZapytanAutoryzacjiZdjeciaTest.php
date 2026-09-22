<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MEDIA-03 (#286), KROK 1 — POMIAR, który jest teraz także PROGIEM.
 *
 * UWAGA, ZMIANA STANU: liczby w tym pliku były najpierw pomiarem ZASTANEGO
 * kodu (15 zapytań na zdjęcie, 450 na stronę z trzydziestoma), a po kroku 2
 * i 3 z issue #286 są pomiarem kodu POPRAWIONEGO: **6 zapytań na zdjęcie,
 * 180 na stronę z trzydziestoma**. Nagłówek został, bo nadal opisuje, CO
 * i dlaczego mierzymy; progi niżej zostały zacieśnione z 18 i 20 do 8, żeby
 * pilnowały nowego stanu, a nie starego.
 *
 * Czego zmiana progów NIE znaczy: nie przepisano tu „licznika, żeby się
 * zgadzał". Progi jadą w dół razem ze zmierzoną liczbą i po zacieśnieniu
 * OBLEWAJĄ się przeciwko kodowi sprzed poprawki (15 > 8) — dokładnie tak
 * jak ma się zachowywać test regresyjny. Mechanizm, a nie tylko sumę, pilnuje
 * osobno `AutoryzacjaZdjeciaJednymPrzejsciemTest`.
 *
 * Audyt zewnętrzny (trzecia warstwa) postawił hipotezę: „autoryzacja jednego
 * zdjęcia to co najmniej pięć zapytań, a jedna strona feedu generuje ponad
 * sto żądań obrazków". `docs/research/audyt-2026-09-10/SPRAWDZENIE.md` mówi
 * wprost, że tej hipotezy NIKT nie sprawdził przy pliku — audyt zewnętrzny
 * jest zbiorem hipotez, nie prawdą o kodzie (jeden alarm z tej samej serii,
 * D-064, już okazał się fałszywy). Ten plik to sprawdza.
 *
 * DLACZEGO OSOBNO ZDJĘCIE I OSOBNO „FEED"
 * Sam audyt ostrzega przed pułapką: assercja na liczbie zapytań całej strony
 * łapie też zapytania z innych miejsc, więc test przejdzie po optymalizacji,
 * która nie dotknęła resolvera. Dlatego test 1 mierzy WYŁĄCZNIE samo
 * przekierowanie `media.show` dla jednego zdjęcia — bez feedu, bez sesji,
 * bez niczego innego. Test 2 liczy to samo przekierowanie powtórzone tyle
 * razy, ile zdjęć jest na stronie feedu (bo tyle właśnie żądań wysłałaby
 * przeglądarka — `MediaController` nie jest wołany przy renderowaniu HTML
 * feedu, tylko z każdego `<img src>` osobno, jako osobne żądanie HTTP).
 *
 * CO TEN PLIK UDOWADNIA, A CZEGO NIE
 * Udowadnia liczbę zapytań SQL na jedno przekierowanie i na sumę
 * przekierowań dla strony z N zdjęciami — to jest jedyna rzecz, którą
 * `DB::getQueryLog()` w ogóle umie policzyć. NIE mierzy czasu ani obciążenia
 * połączenia do bazy pod prawdziwym ruchem — to wymagałoby innego narzędzia
 * i nie jest tu potrzebne: sama LICZBA zapytań już rozstrzyga hipotezę
 * audytu, bo audyt mówił o zapytaniach, nie o milisekundach.
 */
class PomiarZapytanAutoryzacjiZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    private const WARIANT = 'feed';

    // -----------------------------------------------------------------
    // Test 1 — jedno zdjęcie, jedno przekierowanie
    // -----------------------------------------------------------------

    /**
     * Widz zalogowany, ale NIE właściciel i NIE moderator — to jest droga,
     * którą `DostepDoZdjecia::moze()` NIE skraca wcześnie. Właściciel i
     * moderator dostają `true` przed jakimkolwiek zapytaniem o rodziców
     * (patrz komentarz w `DostepDoZdjecia::moze()`), więc mierzenie ich
     * ścieżki zaniżyłoby liczbę i nie sprawdziłoby hipotezy audytu — audyt
     * mówił o zwykłym widzu przeglądającym feed, nie o autorze oglądającym
     * własne zdjęcie.
     */
    public function test_autoryzacja_jednego_zdjecia_publicznego_wpisu(): void
    {
        Storage::fake('public');

        $autor = $this->user('kucharka_media03');
        $widz = $this->user('sasiadka_media03');

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $adres = $zdjecie->url(self::WARIANT);

        DB::enableQueryLog();
        $odpowiedz = $this->actingAs($widz)->get($adres);
        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 302 na podpisany adres, nie 404 — inaczej mierzylibyśmy koszt
        // odmowy, a nie koszt prawdziwej autoryzacji.
        $odpowiedz->assertRedirect();

        $ile = count($zapytania);

        fwrite(STDERR, "\n[MEDIA-03 pomiar] jedno zdjęcie, widz nie-właściciel: {$ile} zapytań\n");

        // ZMIERZONA LICZBA W TYM REPOZYTORIUM, NIE OSZACOWANIE.
        //
        // STAN PO POPRAWCE #286 (dla widza zalogowanego, nie-właściciela,
        // oglądającego zdjęcie PUBLICZNEGO wpisu) — DOKŁADNIE 6 zapytań:
        //   [0] wiązanie trasy — `Media::find($id)` (spoza autoryzacji)
        //   [1] middleware — `users.ostatnio_widziany_at` (spoza autoryzacji)
        //   [2] `DostepDoZdjecia` — JEDNO zapytanie `UNION` rozpoznające,
        //       które tabele w ogóle wspominają to zdjęcie
        //   [3] wczytanie wpisu — jedynej tabeli, która je ma
        //   [4] `PostPolicy::view()` — dociągnięcie `$post->author`
        //   [5] `PostPolicy::view()` — `hasBlockRelationWith()` (blokada)
        //
        // Zapytania [4] i [5] to sama autoryzacja rodzica i mają tu zostać:
        // status autora i blokada są REGUŁĄ, nie kosztem do ścięcia.
        //
        // STAN PRZED POPRAWKĄ (pomiar 10.09.2026) — 15 zapytań, w tej
        // kolejności:
        //   [0]     wiązanie trasy — `Media::find($id)` (route model binding)
        //   [1]     `EnsureAccountIsActive` / middleware sesji — aktualizacja
        //           `users.ostatnio_widziany_at` widza
        //   [2..6]  `DostepDoZdjecia::rodzice()` PIERWSZY RAZ (jako widz) —
        //           po jednym `SELECT` na: wpisy, „Ugotowałem", profil
        //           (awatar), przepisy (hero/skan), kroki przepisu — DOKŁADNIE
        //           pięć, zgodnie z hipotezą audytu
        //   [7]     `PostPolicy::view()` — leniwe dociągnięcie `$post->author`
        //   [8]     `PostPolicy::view()` — `hasBlockRelationWith()` (blokada)
        //   [9..13] `DostepDoZdjecia::rodzice()` DRUGI RAZ, jako ANONIM —
        //           `MediaController::show()` woła `moze(null, $media)`
        //           osobno, żeby rozstrzygnąć nagłówek `Cache-Control`
        //           (patrz komentarz w kontrolerze); to jest NOWA instancja
        //           `Post`, więc `$post->author` dociąga się od nowa
        //   [14]    `PostPolicy::view()` jako anonim — dociągnięcie autora
        //           (blokada pomijana, bo widz jest `null`)
        //
        // Audyt postawił „co najmniej pięć" — i to było PRAWDĄ jako dolna
        // granica, ale nie pełnym obrazem: pięć było kosztem SAMEGO
        // `rodzice()` wywołanego RAZ, a widz nie-właściciel płacił go DWA
        // RAZY (raz jako on sam, raz jako anonim przy liczeniu
        // `Cache-Control`), plus zapytania samej `PostPolicy`, plus dwa
        // zapytania spoza autoryzacji. Rzeczywisty koszt był WIĘKSZY niż
        // liczba z audytu, nie mniejszy.
        //
        // DOLNA GRANICA ZOSTAJE JAKO KONTROLA DODATNIA, tylko niżej.
        // Cztery zapytania to absolutne minimum żądania, które NAPRAWDĘ
        // autoryzowało: wiązanie trasy, rozpoznanie tabel, wczytanie rodzica
        // i jedno zapytanie Policy. Spadek poniżej znaczy, że resolver
        // przestał pytać bazę o cokolwiek — czyli albo ktoś podstawił
        // `return true`, albo decyzja poszła do pamięci podręcznej (issue
        // #286 punkt 4 stawia to jako ostatni krok i wymaga unieważniania
        // przy czterech zdarzeniach). Jedno i drugie musi zapalić czerwone,
        // a nie przejść jako „jeszcze szybciej".
        $this->assertGreaterThanOrEqual(
            4,
            $ile,
            "Autoryzacja jednego zdjęcia wykonała tylko {$ile} zapytań — mniej niż ".
            'minimum żądania, które naprawdę zapytało bazę o rodzica i o Policy. '.
            'Sprawdź, czy decyzja nie trafiła do pamięci podręcznej (issue #286 '.
            'punkt 4: cache widoczności wymaga unieważniania przy czterech '.
            'zdarzeniach — bez nich pokazuje zdjęcie, którego ktoś nie ma prawa '.
            'zobaczyć). Jeśli to jednak świadoma, bezpieczna poprawa — zaktualizuj '.
            'tę liczbę i opisz ją w docs/research/2026-09-10-pomiar-zapytan-zdjecia.md.',
        );

        // PRÓG REGRESJI, ZACIEŚNIONY PO POPRAWCE #286 z 18 na 8.
        // Zmierzona liczba to DOKŁADNIE 6 (rozbicie w komentarzu wyżej).
        // Próg 8 zostawia zapas dwóch zapytań na wahania (przyszła kolumna,
        // inna kolejność), a jednocześnie OBLEWA się przeciwko każdemu
        // z dwóch mechanizmów, które ta zmiana usunęła: powrót drugiego
        // przejścia po grafie rodziców to +6, powrót pięciu stałych
        // `SELECT`-ów to +3. Zmierzone przeciwko kodowi sprzed poprawki:
        // 15 zapytań, czyli CZERWONE.
        $this->assertLessThan(
            8,
            $ile,
            "Autoryzacja jednego zdjęcia wykonała {$ile} zapytań — więcej niż próg 8. ".
            'To wygląda na regresję (drugie przejście po rodzicach dla nagłówka '.
            '`Cache-Control`, powrót pięciu stałych `SELECT`-ów albo nowe zapytanie '.
            'w Policy rodzica), nie na zmianę tego testu. Ta trasa jest najczęściej '.
            'wołaną w serwisie — jedna strona feedu to grubo ponad sto osobnych '.
            'żądań — więc każde dodatkowe zapytanie mnoży się przez sto.',
        );
    }

    // -----------------------------------------------------------------
    // Test 2 — „mało vs dużo": ta sama strona feedu z 3 i z 30 zdjęciami
    // -----------------------------------------------------------------

    /**
     * Symuluje to, co naprawdę robi przeglądarka na stronie feedu: po JEDNYM
     * żądaniu HTTP na każde `<img src>`. Strona feedu z N zdjęciami generuje
     * N takich żądań — jeśli koszt pojedynczego przekierowania jest stały
     * (a test 1 wyżej to mierzy), suma rośnie WPROST PROPORCJONALNIE do N.
     * To jest dokładnie to, co audyt nazwał „ponad sto żądań obrazków" —
     * nie jest to N+1 w JEDNYM zapytaniu do serwera, tylko pomnożenie stałego
     * kosztu przez liczbę zdjęć na stronie, bo każde zdjęcie płaci ten koszt
     * OSOBNO i BEZ NICZEGO WSPÓLNEGO między żądaniami (brak cache'a — patrz
     * uzasadnienie w issue #286, punkt 4: cache dopiero PO tym pomiarze).
     */
    public function test_suma_zapytan_rosnie_proporcjonalnie_do_liczby_zdjec_na_stronie(): void
    {
        Storage::fake('public');

        $malo = $this->zapytaniaDlaStronyZeZdjeciami(liczbaZdjec: 3);
        $duzo = $this->zapytaniaDlaStronyZeZdjeciami(liczbaZdjec: 30);

        // Kontrola dodatnia (PULAPKI_TESTOW.md #4): gdyby przekierowanie nie
        // działało wcale (np. każde zdjęcie dostawało 404 zamiast 302), obie
        // sumy byłyby bliskie zeru i test „udowodniłby" wzrost z niczego.
        $this->assertGreaterThan(
            0,
            $malo,
            'Strona z 3 zdjęciami wykonała zero zapytań — przekierowanie prawdopodobnie '.
            'nie działa (404 zamiast 302?), test nie mierzy tego, co miał.',
        );

        $naZdjecie = $duzo / 30;

        // PRÓG REGRESJI PO ZDJĘCIU, ZACIEŚNIONY PO POPRAWCE #286 z 20 na 8.
        // Test 1 zmierzył dokładnie 6 zapytań na jedno zdjęcie (przed
        // poprawką: 15) — tu liczymy ŚREDNIĄ z trzydziestu, żeby złapać
        // regresję, która ujawnia się dopiero przy większej liczbie treści
        // w bazie (np. `DostepDoZdjecia` zaczyna skanować PO WIERSZU zamiast
        // jednym zapytaniem — patrz kontrola ujemna w opisie PR-a).
        $this->assertLessThan(
            8,
            $naZdjecie,
            "Strona z 30 zdjęciami wykonała średnio {$naZdjecie} zapytań NA ZDJĘCIE — ".
            'więcej niż próg 8. Albo wrócił koszt sprzed #286 (drugie przejście po '.
            'rodzicach, pięć stałych `SELECT`-ów), albo jest gdzieś ukryty N+1 '.
            'zależny od skali, a nie tylko stały koszt razy N.',
        );

        // TO JEST SEDNO POMIARU: stosunek 30 zdjęć do 3 zdjęć powinien być
        // bliski 10 (30 / 3), jeśli koszt jednego zdjęcia jest stały. Bliski
        // 1 znaczyłoby, że coś już dziś dzieli koszt między żądaniami (byłby
        // to miły, ale mało prawdopodobny wynik, bo architektura — patrz
        // `MediaController` — nie ma żadnego cache'u między żądaniami).
        $stosunek = $duzo / $malo;

        $this->assertGreaterThan(
            7.0,
            $stosunek,
            "30 zdjęć kosztowało {$duzo} zapytań, 3 zdjęcia kosztowały {$malo} — stosunek ".
            "{$stosunek} jest dużo niższy niż liniowy (oczekiwane ok. 10). To ZASKAKUJĄCO ".
            'dobra wiadomość, ale sprzeczna z modelem kosztu opisanym w `MediaController` '.
            '— sprawdź, czy coś zaczęło współdzielić autoryzację między żądaniami.',
        );

        // Zapisujemy zmierzone liczby czytelnie w wyjściu testu (widoczne
        // w `--testdox` i w logu CI), żeby ten sam test dawał LICZBĘ, a nie
        // tylko „zielono"/„czerwono" — zgodnie z poleceniem z briefu.
        fwrite(STDERR, sprintf(
            "\n[MEDIA-03 pomiar] 3 zdjęcia: %d zapytań; 30 zdjęć: %d zapytań; ".
            "stosunek: %.2f; średnio na zdjęcie (przy 30): %.2f\n",
            $malo,
            $duzo,
            $stosunek,
            $naZdjecie,
        ));
    }

    /**
     * Buduje `$liczbaZdjec` opublikowanych wpisów TEGO SAMEGO autora, każdy
     * z własnym gotowym zdjęciem, i wykonuje po jednym GET na każde —
     * dokładnie tyle żądań, ile zdjęć byłoby widocznych na takiej stronie
     * feedu. Zwraca SUMĘ zapytań SQL ze wszystkich tych żądań razem.
     *
     * Jeden autor, jeden widz — celowo bez wariacji w widoczności, bo to
     * NIE JEST test macierzy widoczności (ten już istnieje w
     * `ZdjeciaChronioneNieWyciekajaTest`). Tu chodzi wyłącznie o to, jak
     * suma kosztu rośnie z liczbą zdjęć.
     */
    private function zapytaniaDlaStronyZeZdjeciami(int $liczbaZdjec): int
    {
        $autor = $this->user('autorka_media03_'.$liczbaZdjec);
        $widz = $this->user('widz_media03_'.$liczbaZdjec);

        $adresy = collect(range(1, $liczbaZdjec))->map(function (int $i) use ($autor): string {
            $wpis = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'visibility' => Post::VISIBILITY_PUBLIC,
                'published_at' => now()->subMinutes($i),
            ]);
            $zdjecie = $this->zdjecie($autor);
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

            return $zdjecie->url(self::WARIANT);
        });

        DB::enableQueryLog();

        $this->actingAs($widz);
        foreach ($adresy as $adres) {
            $this->get($adres)->assertRedirect();
        }

        $suma = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $suma;
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    /**
     * Gotowe zdjęcie z prawdziwymi bajtami wariantu `feed` na udawanym
     * dysku publicznym — ten sam wzorzec co `ZdjeciaChronioneNieWyciekajaTest`.
     * Plik musi naprawdę istnieć: na dysku bez podpisów (jak w testach)
     * `MediaController` sprawdza `exists()` przed oddaniem odpowiedzi.
     */
    private function zdjecie(User $wlasciciel): Media
    {
        $identyfikator = Str::uuid()->toString();
        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';
            $warianty[$nazwa] = ['key' => $klucz, 'width' => $krawedz, 'height' => $krawedz];
            Storage::disk('public')->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }
}
