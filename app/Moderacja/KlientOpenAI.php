<?php

declare(strict_types=1);

namespace App\Moderacja;

use App\Support\DozwolonyHostApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cienki klient API moderacji OpenAI (`omni-moderation-latest`) — D-055.
 *
 * DLACZEGO BEZ NOWEJ PACZKI COMPOSERA
 * Bo całe API to jeden `POST` z dwoma polami, a Laravel ma klient HTTP
 * w standardzie (`AGENTS.md` §3: „kolejna biblioteka, gdy Laravel ma to
 * w standardzie" jest na liście zakazów). Ta sama droga, którą poszły
 * transport poczty (D-047) i weryfikacja Turnstile (D-050).
 *
 * KSZTAŁT API (sprawdzony 9 września 2026)
 * `POST https://api.openai.com/v1/moderations`, ciało JSON:
 * `{"model": "omni-moderation-latest", "input": [ … ]}`, gdzie każdy element
 * to `{"type":"text","text":"…"}` albo `{"type":"image_url","image_url":{"url":"…"}}`.
 * Odpowiedź: `results[]` z `flagged`, `categories` (bool) i `category_scores`
 * (0–1). Nagłówek `Authorization: Bearer <klucz>`.
 *
 * CO WYSYŁAMY, A CZEGO NIE
 * Wychodzi WYŁĄCZNIE oceniana treść: tekst wpisu albo zdjęcie (jako `data:`
 * z przekodowanego wariantu, więc bez EXIF-u i bez GPS-u kuchni). NIE
 * wychodzi ani adres e-mail, ani nazwa konta, ani identyfikator wpisu, ani
 * adres IP. To nie jest ostrożność na zapas — to jest warunek wpisu
 * w polityce prywatności (`resources/legal/polityka-prywatnosci.md`)
 * i jedyny powód, dla którego ta funkcja mieści się w minimalizacji danych
 * (`AGENTS.md` §7).
 *
 * BRAK KLUCZA = FUNKCJA WYŁĄCZONA I NIC NIE PADA. Tak jest lokalnie, w CI
 * i w testach: `oceniamy()` oddaje `false`, żadne żądanie nie wychodzi.
 * KLUCZ POD OBCYM ADRESEM (#991) też daje `false`, ale to już błąd, nie
 * spoczynek — `bladKonfiguracji()` go nazywa, `zglosBladKonfiguracji()`
 * zgłasza raz na godzinę.
 *
 * AWARIA PO TAMTEJ STRONIE NIE MOŻE NICZEGO WSTRZYMAĆ. Publikacja wpisu
 * dzieje się w zupełnie innym żądaniu (analiza chodzi w kolejce), więc
 * najgorsze, co może zrobić timeout, to brak jednej pozycji w kolejce
 * moderatora. Dlatego każdy błąd kończy się `null` i ostrzeżeniem w logu,
 * nigdy wyjątkiem lecącym dalej.
 */
final class KlientOpenAI
{
    /**
     * Jedyny host, któremu wolno dać klucz i cudzą treść do oceny (#991).
     *
     * @var list<string>
     */
    public const HOSTY = ['api.openai.com'];

    /**
     * Jedyna ścieżka: klient buduje żądanie w kształcie API moderacji, więc
     * pod żadnym innym adresem OpenAI i tak nie miałoby sensu (D-250).
     */
    public const SCIEZKA = '#^/v1/moderations$#';

    /** Kanoniczny adres — ten sam co wartość domyślna w `config/kuking.php`. */
    public const ADRES = 'https://api.openai.com/v1/moderations';

    /**
     * Klucz pod obcym adresem zgłaszamy najwyżej raz na to okno. Bez tego
     * KAŻDA oceniana treść dawała `Log::error` na kanale `blad_webhook`
     * i jeden błąd konfiguracji zalewał alarmy.
     */
    public const OKNO_ZGLOSZENIA_SEKUND = 3600;

    private const KLUCZ_ZGLOSZENIA = 'kuking:moderacja:obcy-adres-modelu';

    /**
     * Czy model w ogóle ocenia: jest klucz I adres prowadzi do OpenAI.
     * Klucz z obcym adresem to NIE jest „ocenianie" — nic nie wychodzi,
     * a nazwę błędu podaje `bladKonfiguracji()`.
     */
    public static function oceniamy(): bool
    {
        return self::maKlucz() && self::adresZgodny();
    }

    public static function maKlucz(): bool
    {
        return is_string(config('kuking.moderation.model.klucz'))
            && config('kuking.moderation.model.klucz') !== '';
    }

    /**
     * Czy `KUKING_MODEL_ENDPOINT` to dokładnie API moderacji OpenAI. Obcy
     * host, ścieżka, port, query albo fragment = klient odmawia każdego
     * zapytania, zanim cokolwiek wyjdzie.
     */
    public static function adresZgodny(): bool
    {
        return self::bladAdresu() === null;
    }

    /**
     * Zdanie dla operatora, gdy klucz jest, a adres nie prowadzi do OpenAI.
     * Tylko nazwa zmiennej, nazwa złej części adresu i poprawna wartość —
     * nigdy sam adres ani klucz. `null` = konfiguracja w porządku albo
     * brak klucza (to osobny, opisany stan).
     */
    public static function bladKonfiguracji(): ?string
    {
        if (! self::maKlucz()) {
            return null;
        }

        $powod = self::bladAdresu();

        if ($powod === null) {
            return null;
        }

        return "Zmienna KUKING_MODEL_ENDPOINT nie jest adresem API moderacji OpenAI ({$powod}). "
            .'Moderacja modelem NIE DZIAŁA — nic nie wysyłamy. Usuń zmienną (wartość domyślna '
            .'jest poprawna) albo wpisz '.self::ADRES.'.';
    }

    /**
     * Jeden `Log::error` na okno, nie jeden na treść. `Cache::add` jest
     * atomowe — przy kilku workerach zgłasza tylko pierwszy.
     */
    public static function zglosBladKonfiguracji(string $czego): void
    {
        $blad = self::bladKonfiguracji();

        if ($blad === null) {
            return;
        }

        if (! Cache::add(self::KLUCZ_ZGLOSZENIA, true, self::OKNO_ZGLOSZENIA_SEKUND)) {
            return;
        }

        // `error`, nie `warning`: to jest błąd konfiguracji, który ma dojść do
        // kanału alarmowego. Bez adresu w kontekście — zmienna bywa wklejana
        // razem z tokenem, a nazwa zmiennej wystarcza, żeby wiedzieć, co zmienić.
        Log::error($blad, [
            'czego' => $czego,
            'zmienna' => 'KUKING_MODEL_ENDPOINT',
            'dozwolone' => self::HOSTY,
            'stage' => 'openai_obcy_host',
        ]);
    }

    private static function bladAdresu(): ?string
    {
        return DozwolonyHostApi::powod(
            (string) config('kuking.moderation.model.endpoint'),
            self::HOSTY,
            self::SCIEZKA,
        );
    }

    /**
     * Ocena tekstu.
     *
     * @return ?WynikOceny `null` znaczy „nie wiemy" — funkcja wyłączona,
     *                     awaria albo odpowiedź w nieznanym kształcie.
     *                     Nigdy „treść jest w porządku": pusty `WynikOceny`
     *                     mówiłby coś, czego nie sprawdziliśmy.
     */
    public function ocenTekst(string $tekst): ?WynikOceny
    {
        $tekst = trim($tekst);

        if ($tekst === '') {
            return null;
        }

        return $this->zapytaj(
            [['type' => 'text', 'text' => mb_substr($tekst, 0, 8000)]],
            'tekst',
        );
    }

    /**
     * Ocena obrazu podanego jako `data:` URI.
     *
     * Wysyłamy PRZEKODOWANY wariant (thumb), nie oryginał: oryginał niesie
     * pełny EXIF, czyli współrzędne GPS kuchni, w której zrobiono zdjęcie
     * (`AGENTS.md` §7, pipeline zdjęć). Wariant powstaje przez przekodowanie,
     * więc metadanych już nie ma — i to jest jedyna postać, w jakiej wolno
     * wypuścić czyjeś zdjęcie poza nasz serwer.
     */
    public function ocenObraz(string $dataUri): ?WynikOceny
    {
        if (! str_starts_with($dataUri, 'data:image/')) {
            return null;
        }

        return $this->zapytaj(
            [['type' => 'image_url', 'image_url' => ['url' => $dataUri]]],
            'zdjęcie',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $wejscie
     */
    private function zapytaj(array $wejscie, string $czego): ?WynikOceny
    {
        if (! self::maKlucz()) {
            return null;
        }

        if (! self::adresZgodny()) {
            self::zglosBladKonfiguracji($czego);

            return null;
        }

        try {
            $odpowiedz = Http::withToken((string) config('kuking.moderation.model.klucz'))
                // Limit z konfiguracji przycięty do 1–8 s: `0` w Guzzle znaczy
                // „bez limitu", a zawieszony dostawca trzymałby worker kolejki.
                ->connectTimeout(3)
                ->timeout(max(1, min(8, (int) config('kuking.moderation.model.limit_czasu'))))
                ->acceptJson()
                ->post((string) config('kuking.moderation.model.endpoint'), [
                    'model' => (string) config('kuking.moderation.model.nazwa'),
                    'input' => $wejscie,
                ]);
        } catch (Throwable $blad) {
            // Bez treści w logu — to jest cudzy tekst albo cudze zdjęcie,
            // a dziennik błędów nie jest miejscem na treści użytkowników.
            Log::warning('Ocena treści modelem nie doszła do skutku.', [
                'czego' => $czego,
                ...ExceptionContext::forStage($blad, 'openai_transport'),
            ]);

            return null;
        }

        if ($odpowiedz->failed()) {
            Log::warning('Model moderacji odpowiedział błędem.', [
                'czego' => $czego,
                'status' => $odpowiedz->status(),
            ]);

            return null;
        }

        return $this->zwynik((array) $odpowiedz->json(), $czego);
    }

    /**
     * Odpowiedź API → `WynikOceny`, z zastosowaniem NASZYCH progów.
     *
     * ŚWIADOMIE NIE UŻYWAMY POLA `flagged`. Model decyduje po swojemu,
     * a jego „tak" przy polszczyźnie i kuchni bywa hojne: „zabiłam kurę na
     * rosół", „krwisty stek", „ubić pianę". Każde takie trafienie kosztuje
     * uwagę jedynego moderatora, więc próg trzymamy u siebie, w konfiguracji,
     * gdzie da się go zmienić po pomiarze (`kuking:raport-sygnalow`).
     *
     * @param  array<string, mixed>  $dane
     */
    private function zwynik(array $dane, string $czego): ?WynikOceny
    {
        $wyniki = $dane['results'][0]['category_scores'] ?? null;

        // Pusta albo uszkodzona lista wyników NIE jest oceną „nic nie ma".
        // Przedtem `[]` i wyniki-napisy przechodziły tędy jako czysta treść,
        // bez śladu w dzienniku (D-240).
        if (! $this->poprawneWyniki($wyniki)) {
            Log::warning('Model moderacji oddał odpowiedź w nieznanym kształcie.', ['czego' => $czego]);

            return null;
        }

        $prog = (float) config('kuking.moderation.model.prog');
        $progPilny = (float) config('kuking.moderation.model.prog_pilny');

        $ponadProg = [];
        $pilne = false;

        foreach ($wyniki as $kategoria => $wynik) {
            if (! is_string($kategoria) || ! is_numeric($wynik)) {
                continue;
            }

            $wynik = (float) $wynik;
            $czyPilna = KategorieModeracji::jestPilna($kategoria);

            // Niższy próg dla spraw, które nie mogą czekać: tu wolimy fałszywy
            // alarm od przeoczenia.
            if ($wynik < ($czyPilna ? $progPilny : $prog)) {
                continue;
            }

            $ponadProg[$kategoria] = $wynik;
            $pilne = $pilne || $czyPilna;
        }

        return new WynikOceny($ponadProg, $pilne, $czego);
    }

    /**
     * Czy wyniki da się uczciwie nazwać oceną.
     *
     * Jedno uszkodzone pole unieważnia całą odpowiedź — nie wiemy, co jeszcze
     * jest w niej nie tak. Nowa, nieznana nam kategoria obok znanych oceny nie
     * unieważnia (dostawca dokłada kategorie), ale odpowiedź bez ANI JEDNEJ
     * znanej kategorii nie mówi nic o tym, czego szukamy.
     */
    private function poprawneWyniki(mixed $wyniki): bool
    {
        if (! is_array($wyniki) || $wyniki === []) {
            return false;
        }

        $znana = false;

        foreach ($wyniki as $kategoria => $wynik) {
            if (! is_string($kategoria) || trim($kategoria) === ''
                || (! is_int($wynik) && ! is_float($wynik))
                || ! is_finite((float) $wynik) || $wynik < 0 || $wynik > 1) {
                return false;
            }

            $znana = $znana || KategorieModeracji::jestZnana($kategoria);
        }

        return $znana;
    }
}
