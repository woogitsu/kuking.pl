<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Moderacja\ExceptionContext;
use App\Moderacja\ModelChwilowoNiedostepny;
use App\Support\DozwolonyHostApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cienki klient OpenAI Responses API dla importu przepisu (V2, D-298).
 *
 * WZORZEC: `App\Moderacja\KlientOpenAI` (D-055, D-240, D-250) — bez paczki
 * Composera, klucz i nazwa modelu w konfiguracji, host i ścieżka W KODZIE.
 * Klient moderacji celowo nie przyjmuje innej ścieżki niż `/v1/moderations`,
 * więc import ma własny klient z własną, równie wąską ścieżką.
 *
 * CO WYCHODZI, A CO NIE (D-296)
 * Wychodzi WYŁĄCZNIE: stała instrukcja, schemat odpowiedzi i treść do
 * odczytania (obraz kartki jako `data:` z wariantu przekodowanego u nas —
 * bez EXIF/XMP/GPS). NIE wychodzi: e-mail, nazwa konta, IP, identyfikator
 * przepisu, zlecenia ani konta, pole `user`/`safety_identifier`, adres
 * strony. `store: false` — odpowiedź nie jest przechowywana po stronie
 * OpenAI do późniejszego pobrania (to NIE jest obietnica zerowej retencji;
 * ta zależy od umowy z dostawcą).
 *
 * TRZY WYNIKI, JAK W MODERACJI (#1662)
 *  - `OdpowiedzModelu` — żądanie doszło; `dane` mogą być `null`, gdy treść
 *    się nie nadaje (usage i tak liczymy do budżetu);
 *  - `null` — żądanie NIE wyszło albo odpadło na stałe (brak konfiguracji,
 *    4xx): nic nie kosztowało i ponowienie nic nie da;
 *  - `ModelChwilowoNiedostepny` — timeout, zerwane połączenie, 429, 5xx.
 *    Ponawia ZADANIE, nie klient.
 */
final class KlientLuna
{
    /** @var list<string> */
    public const HOSTY = ['api.openai.com'];

    public const SCIEZKA = '#^/v1/responses$#';

    public const ADRES = 'https://api.openai.com/v1/responses';

    /** Zadania, które znają konfiguracja i klient. */
    public const ZADANIE_OCR = 'ocr';

    public const ZADANIE_TEKST = 'tekst';

    /** @var list<string> */
    public const ZADANIA = [self::ZADANIE_OCR, self::ZADANIE_TEKST];

    /**
     * Dozwolone wartości `reasoning.effort` (decyzja właściciela 26.09.2026).
     * Lista w KODZIE: wartość spoza niej wyłącza zadanie, zamiast wysłać
     * żądanie, którego API nie przyjmie (4xx po każdym zleceniu).
     *
     * @var list<string>
     */
    public const WYSILKI = ['minimal', 'low', 'medium', 'high'];

    private const STATUSY_PRZEJSCIOWE = [429, 500, 502, 503, 504];

    /**
     * Czy to zadanie w ogóle może wysłać żądanie: klucz, model, adres,
     * intensywność i cennik. `false` = przycisku nie ma (D-053).
     */
    public static function skonfigurowany(string $zadanie): bool
    {
        return self::braki($zadanie) === [];
    }

    /**
     * Czego brakuje, po polsku, dla operatora — nazwy zmiennych, nigdy wartości.
     *
     * @return list<string>
     */
    public static function braki(string $zadanie): array
    {
        $braki = [];

        if (! self::maKlucz()) {
            $braki[] = 'Brak klucza OPENAI_IMPORT_KEY — odczyt przepisów jest wyłączony.';
        }

        if (trim((string) config('kuking.import.model.nazwa')) === '') {
            $braki[] = 'Pusta nazwa modelu KUKING_IMPORT_MODEL.';
        }

        $adres = DozwolonyHostApi::powod((string) config('kuking.import.model.endpoint'), self::HOSTY, self::SCIEZKA);

        if ($adres !== null) {
            $braki[] = "Zmienna KUKING_IMPORT_ENDPOINT nie jest adresem Responses API OpenAI ({$adres}). Usuń ją albo wpisz ".self::ADRES.'.';
        }

        if (! in_array($zadanie, self::ZADANIA, true)) {
            $braki[] = "Nieznane zadanie modelu: {$zadanie}.";
        } elseif (self::wysilek($zadanie) === null) {
            $zmienna = $zadanie === self::ZADANIE_OCR ? 'KUKING_IMPORT_EFFORT_OCR' : 'KUKING_IMPORT_EFFORT_TEKST';
            $braki[] = "Zmienna {$zmienna} ma wartość spoza listy (".implode(', ', self::WYSILKI).').';
        }

        if (Cennik::zKonfiguracji() === null) {
            $braki[] = 'Brak cennika KUKING_IMPORT_CENA_WEJSCIE / KUKING_IMPORT_CENA_WYJSCIE (USD za milion tokenów) — bez niego nie da się zarezerwować budżetu.';
        }

        return $braki;
    }

    public static function maKlucz(): bool
    {
        $klucz = config('kuking.import.model.klucz');

        return is_string($klucz) && trim($klucz) !== '';
    }

    /** Intensywność myślenia dla zadania — albo `null`, gdy konfiguracja jest spoza listy. */
    public static function wysilek(string $zadanie): ?string
    {
        $wartosc = config("kuking.import.model.wysilek.{$zadanie}");

        if (! is_string($wartosc)) {
            return null;
        }

        $wartosc = strtolower(trim($wartosc));

        return in_array($wartosc, self::WYSILKI, true) ? $wartosc : null;
    }

    public static function maxWyjscie(): int
    {
        return max(256, min(64000, (int) config('kuking.import.model.max_wyjscie_tokenow')));
    }

    /**
     * @param  list<array<string, mixed>>  $tresc  części wiadomości (`input_text`, `input_image`)
     * @param  array<string, mixed>  $schemat  JSON Schema odpowiedzi (tryb `strict`)
     *
     * @throws ModelChwilowoNiedostepny
     */
    public function odczytaj(string $zadanie, string $instrukcja, array $tresc, string $nazwaSchematu, array $schemat): ?OdpowiedzModelu
    {
        if (! self::skonfigurowany($zadanie)) {
            return null;
        }

        try {
            $odpowiedz = Http::withToken(trim((string) config('kuking.import.model.klucz')))
                ->connectTimeout(5)
                ->timeout(max(10, min(110, (int) config('kuking.import.model.limit_czasu'))))
                ->acceptJson()
                ->post((string) config('kuking.import.model.endpoint'), [
                    'model' => trim((string) config('kuking.import.model.nazwa')),
                    'store' => false,
                    'instructions' => $instrukcja,
                    'input' => [['role' => 'user', 'content' => $tresc]],
                    'max_output_tokens' => self::maxWyjscie(),
                    'reasoning' => ['effort' => self::wysilek($zadanie)],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => $nazwaSchematu,
                        'strict' => true,
                        'schema' => $schemat,
                    ]],
                ]);
        } catch (Throwable $blad) {
            // Bez treści w logu — to jest czyjeś zdjęcie albo tekst.
            Log::warning('Odczyt przepisu modelem nie doszedł do skutku.', [
                'zadanie' => $zadanie,
                ...ExceptionContext::forStage($blad, 'import_transport'),
            ]);

            if ($blad instanceof ConnectionException) {
                throw new ModelChwilowoNiedostepny;
            }

            return null;
        }

        if ($odpowiedz->failed()) {
            Log::warning('Model importu odpowiedział błędem.', [
                'zadanie' => $zadanie,
                'status' => $odpowiedz->status(),
                'stage' => 'import_http',
            ]);

            if (in_array($odpowiedz->status(), self::STATUSY_PRZEJSCIOWE, true)) {
                throw new ModelChwilowoNiedostepny($this->ponowZa($odpowiedz));
            }

            return null;
        }

        return $this->zOdpowiedzi((array) $odpowiedz->json());
    }

    /** @param  array<string, mixed>  $json */
    private function zOdpowiedzi(array $json): OdpowiedzModelu
    {
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $wejscie = is_int($usage['input_tokens'] ?? null) && $usage['input_tokens'] >= 0 ? $usage['input_tokens'] : null;
        $wyjscie = is_int($usage['output_tokens'] ?? null) && $usage['output_tokens'] >= 0 ? $usage['output_tokens'] : null;

        // Do diagnozy trzymamy odpowiedź bez rozumowania (bywa długie i nie
        // mówi nic o błędzie odczytu) — 30 dni, potem `NULL`.
        $surowa = [
            'status' => $json['status'] ?? null,
            'incomplete_details' => $json['incomplete_details'] ?? null,
            'usage' => $usage,
            'output_text' => null,
        ];

        if (($json['status'] ?? null) !== 'completed') {
            return new OdpowiedzModelu(null, $wejscie, $wyjscie, $surowa, 'status_'.(is_string($json['status'] ?? null) ? $json['status'] : 'brak'));
        }

        $tekst = null;

        foreach ((array) ($json['output'] ?? []) as $element) {
            if (! is_array($element) || ($element['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($element['content'] ?? []) as $czesc) {
                if (is_array($czesc) && ($czesc['type'] ?? null) === 'refusal') {
                    return new OdpowiedzModelu(null, $wejscie, $wyjscie, $surowa, 'odmowa');
                }

                if (is_array($czesc) && ($czesc['type'] ?? null) === 'output_text' && is_string($czesc['text'] ?? null)) {
                    $tekst = ($tekst ?? '').$czesc['text'];
                }
            }
        }

        $surowa['output_text'] = $tekst === null ? null : mb_substr($tekst, 0, 20000);

        $dane = is_string($tekst) ? json_decode($tekst, true) : null;

        if (! is_array($dane)) {
            return new OdpowiedzModelu(null, $wejscie, $wyjscie, $surowa, 'nie_json');
        }

        return new OdpowiedzModelu($dane, $wejscie, $wyjscie, $surowa);
    }

    private function ponowZa(Response $odpowiedz): ?int
    {
        $naglowek = trim($odpowiedz->header('Retry-After'));

        return $naglowek !== '' && ctype_digit($naglowek) ? (int) $naglowek : null;
    }
}
