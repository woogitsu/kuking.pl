<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Moderacja\KlientOpenAI;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * „Czy moderacja modelem naprawdę działa?" — jedna komenda z odpowiedzią.
 *
 * PO CO TO ISTNIEJE
 * `KlientOpenAI` celowo zwraca `null` przy KAŻDEJ porażce: brak klucza, zła
 * sieć, HTTP 401, odpowiedź w nieznanym kształcie. Dla aplikacji to jest
 * poprawne — „nie wiemy" nigdy nie może znaczyć „treść jest w porządku",
 * a awaria cudzej usługi nie może zatrzymać czyjegoś wpisu.
 *
 * Ale to samo `null` sprawia, że z zewnątrz NIE DA SIĘ ODRÓŻNIĆ działającej
 * moderacji od wyłączonej. `/health` też o niej nie mówi. Właściciel wgrywa
 * klucz i nie ma jak sprawdzić, czy trafił — a wygląda tak samo w obu
 * przypadkach. To jest dokładnie ta klasa usterki, którą w tym repozytorium
 * tępimy: narzędzie melduje sukces, nie robiąc nic.
 *
 * Ta komenda robi JEDNO prawdziwe zapytanie i mówi po polsku, co z niego
 * wyszło — z osobną radą dla każdego kodu błędu, bo „HTTP 401" nie jest
 * odpowiedzią na pytanie „co mam zrobić".
 *
 * BEZPIECZEŃSTWO: klucz nigdy nie jest wypisywany, nawet fragmentami.
 * Odpowiedź serwera pokazujemy skróconą, bo w treści błędu OpenAI potrafi
 * zwrócić identyfikator organizacji.
 */
class SprawdzModel extends Command
{
    protected $signature = 'kuking:sprawdz-model
                            {--zdjecie : Sprawdź też ocenę obrazu, nie tylko tekstu}
                            {--surowe : Pokaż wyniki liczbowe wszystkich kategorii}';

    protected $description = 'Zadaje modelowi jedno pytanie i mówi po polsku, czy moderacja treści naprawdę działa';

    /** Nieszkodliwe zdanie po polsku — model ma je uznać za czyste. */
    private const ZDANIE_TESTOWE = 'Dziś ugotowałam rosół z domowym makaronem i marchewką.';

    /** Najmniejszy poprawny PNG (1×1, przezroczysty) jako data URI. */
    private const OBRAZ_TESTOWY = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function handle(): int
    {
        $this->line('<options=bold>Sprawdzenie moderacji modelem</>');
        $this->newLine();

        if (! KlientOpenAI::maKlucz()) {
            return $this->brakKlucza();
        }

        $this->stanKonfiguracji();

        if (! KlientOpenAI::adresZgodny()) {
            return $this->obcyHost();
        }

        $wynik = $this->zapytaj(
            [['type' => 'text', 'text' => self::ZDANIE_TESTOWE]],
            'tekst',
        );

        if ($wynik !== self::SUCCESS) {
            return $wynik;
        }

        if ($this->option('zdjecie')) {
            $this->newLine();

            return $this->zapytaj(
                [['type' => 'image_url', 'image_url' => ['url' => self::OBRAZ_TESTOWY]]],
                'obraz',
            );
        }

        $this->newLine();
        $this->line('Ocenę zdjęć sprawdzisz osobno: <options=bold>php artisan kuking:sprawdz-model --zdjecie</>');

        return self::SUCCESS;
    }

    private function obcyHost(): int
    {
        $this->error('Zmienna KUKING_MODEL_ENDPOINT nie prowadzi do API moderacji OpenAI. Żadne zapytanie nie wyszło.');
        $this->newLine();
        $this->line((string) KlientOpenAI::bladKonfiguracji());
        $this->line('Klucz i treść do oceny wolno wysłać tylko pod: '.KlientOpenAI::ADRES.'.');

        return self::FAILURE;
    }

    private function brakKlucza(): int
    {
        $this->error('Moderacja modelem jest WYŁĄCZONA — nie ma klucza.');
        $this->newLine();
        $this->line('Nic się przez to nie psuje: wpisy publikują się normalnie, a kolejka moderatora');
        $this->line('dostaje tylko pozycje z sygnałów lokalnych (powtórzona treść, obcy odnośnik, spam).');
        $this->line('Brakuje drugiej pary oczu do zdjęć i do treści, których wzorce nie łapią.');
        $this->newLine();
        $this->line('Żeby włączyć, ustaw w Railway zmienną <options=bold>OPENAI_MODERATION_KEY</> (jako „Sealed")');
        $this->line('i poczekaj na wdrożenie. Klucz wyrabia się na platform.openai.com → API keys;');
        $this->line('wystarczy uprawnienie do `/v1/moderations`. Endpoint jest bezpłatny.');
        $this->newLine();
        $this->line('WARUNEK, KTÓRY NIE JEST FORMALNOŚCIĄ: zanim włączysz, upewnij się, że w polityce');
        $this->line('prywatności stoi OpenAI na liście podmiotów przetwarzających, a w `zasady.md`');
        $this->line('punkt o automatycznym sprawdzaniu treści. Bez tego wysyłamy cudze dane za ocean');
        $this->line('bez podstawy i bez poinformowania człowieka.');

        return self::FAILURE;
    }

    private function stanKonfiguracji(): void
    {
        $klucz = (string) config('kuking.moderation.model.klucz');

        $this->line('Klucz:      jest (długość '.mb_strlen($klucz).' znaków, wartości nie pokazuję)');
        // Sam host, nie pełny adres: zmienna bywa wklejana razem z tokenem,
        // a wynik tej komendy ląduje w czatach i zgłoszeniach (#991).
        $this->line('Endpoint:   host '.$this->hostEndpointu());
        $this->line('Model:      '.(string) config('kuking.moderation.model.nazwa'));
        $this->line('Zdjęcia:    '.(config('kuking.moderation.model.ocenia_zdjecia') ? 'oceniamy' : 'NIE oceniamy'));
        $this->newLine();
    }

    private function hostEndpointu(): string
    {
        $host = parse_url((string) config('kuking.moderation.model.endpoint'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '(nie da się odczytać)';
    }

    /**
     * @param  list<array<string, mixed>>  $wejscie
     */
    private function zapytaj(array $wejscie, string $czego): int
    {
        $this->line('Pytam model o '.$czego.'…');

        try {
            $odpowiedz = Http::withToken((string) config('kuking.moderation.model.klucz'))
                ->timeout((int) config('kuking.moderation.model.limit_czasu'))
                ->acceptJson()
                ->post((string) config('kuking.moderation.model.endpoint'), [
                    'model' => (string) config('kuking.moderation.model.nazwa'),
                    'input' => $wejscie,
                ]);
        } catch (Throwable $blad) {
            $this->error('Nie udało się nawiązać połączenia.');
            $this->newLine();
            $this->line('To nie jest odmowa serwera — zapytanie w ogóle tam nie dotarło albo nie wróciło');
            $this->line('w wyznaczonym czasie ('.(int) config('kuking.moderation.model.limit_czasu').' s).');
            $this->line('Sprawdź, czy kontener ma wyjście na świat i czy endpoint jest poprawny.');
            $this->newLine();
            $this->line('<options=bold>Szczegół techniczny</>: '.$blad::class.': '.$this->skrot($blad->getMessage()));

            return self::FAILURE;
        }

        if ($odpowiedz->failed()) {
            return $this->radaDoBledu($odpowiedz);
        }

        return $this->pokazWynik($odpowiedz, $czego);
    }

    private function radaDoBledu(Response $odpowiedz): int
    {
        $status = $odpowiedz->status();

        $this->error('Model odpowiedział błędem HTTP '.$status.'.');
        $this->newLine();

        match (true) {
            $status === 401 => $this->rada([
                'Klucz jest nieprawidłowy albo unieważniony.',
                'Najczęstsze przyczyny: skopiowana spacja na końcu, klucz z innego konta,',
                'albo klucz skasowany w panelu OpenAI po wgraniu tutaj.',
                'Wyrób nowy na platform.openai.com → API keys i wgraj go ponownie.',
            ]),
            $status === 403 => $this->rada([
                'Konto istnieje, ale nie ma prawa użyć tego endpointu.',
                'Sprawdź, czy klucz nie jest ograniczony („Restricted") bez prawa do',
                '`/v1/moderations` — w panelu OpenAI siedzi to pod „Model capabilities".',
                'Sprawdź też, czy organizacja nie jest zablokowana regionalnie.',
            ]),
            $status === 404 => $this->rada([
                'Endpoint albo nazwa modelu nie istnieje.',
                'Model: '.(string) config('kuking.moderation.model.nazwa'),
                'Jeśli OpenAI wycofało tę nazwę, ustaw nową w KUKING_MODEL_NAZWA —',
                'nie trzeba do tego wdrożenia.',
            ]),
            $status === 429 => $this->rada([
                'Za dużo zapytań albo wyczerpany limit konta.',
                'Endpoint moderacji jest bezpłatny, więc to raczej limit tempa niż pieniądze.',
                'Odczekaj minutę i powtórz.',
            ]),
            $status >= 500 => $this->rada([
                'Awaria po stronie OpenAI.',
                'Nic nie rób — nasz kod przepuszcza wtedy wpisy dalej i zapisuje ostrzeżenie',
                'w dzienniku. Powtórz za kwadrans.',
            ]),
            default => $this->rada(['Nieoczekiwany kod odpowiedzi.']),
        };

        $this->newLine();
        $this->line('<options=bold>Odpowiedź serwera</> (skrócona): '.$this->skrot($odpowiedz->body()));

        return self::FAILURE;
    }

    private function pokazWynik(Response $odpowiedz, string $czego): int
    {
        $wyniki = $odpowiedz->json('results');

        if (! is_array($wyniki) || $wyniki === []) {
            $this->error('Model odpowiedział, ale w odpowiedzi nie ma pola `results`.');
            $this->line('To znaczy, że rozmawiamy z czymś innym niż API moderacji OpenAI.');
            $this->newLine();
            $this->line('<options=bold>Odpowiedź</> (skrócona): '.$this->skrot($odpowiedz->body()));

            return self::FAILURE;
        }

        $pierwszy = (array) $wyniki[0];
        $oznaczone = (bool) ($pierwszy['flagged'] ?? false);

        $this->info('Działa — model ocenił '.$czego.' i odpowiedział.');
        $this->newLine();
        $this->line($oznaczone
            ? 'Uwaga: nieszkodliwą próbkę model UZNAŁ za coś do przejrzenia. Połączenie działa,'
            : 'Nieszkodliwa próbka nie została oznaczona — czyli dokładnie tak, jak powinno być.');

        if ($oznaczone) {
            $this->line('ale progi warto obejrzeć: przy takim wyniku kolejka moderatora będzie zalana.');
        }

        if ($this->option('surowe')) {
            $this->newLine();
            $this->line('<options=bold>Wyniki liczbowe kategorii</>:');

            /** @var array<string, float> $punkty */
            $punkty = (array) ($pierwszy['category_scores'] ?? []);
            arsort($punkty);

            foreach (array_slice($punkty, 0, 8, true) as $kategoria => $wartosc) {
                $this->line(sprintf('  %-28s %.6f', (string) $kategoria, (float) $wartosc));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $wiersze
     */
    private function rada(array $wiersze): void
    {
        foreach ($wiersze as $wiersz) {
            $this->line($wiersz);
        }
    }

    /**
     * Odpowiedź serwera bywa długa i potrafi nieść identyfikator organizacji.
     */
    private function skrot(string $tekst): string
    {
        $tekst = (string) preg_replace('/\s+/u', ' ', trim($tekst));

        return mb_strimwidth($tekst, 0, 240, '…');
    }
}
