<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

/**
 * JEDYNE miejsce, w którym powstaje treść wychodząca na kanał alarmowy.
 *
 * DLACZEGO TO W OGÓLE ZOSTAŁO WYJĘTE Z `WebhookBleduHandler`
 * Bo od issue #599 kanały alarmowe są DWA: webhook (`blad_webhook`) i poczta
 * (`blad_email`). Drugie formatowanie obok pierwszego rozjechałoby się
 * z nim przy pierwszej zmianie — a rozjazd wygląda tu tak, że jedna droga
 * nadal niczego nie zdradza, a druga zaczyna wysyłać komunikat wyjątku
 * z e-mailem i hashem hasła w środku (audyt A6-01, niżej). Dlatego treść
 * jest JEDNA, budowana JEDNĄ metodą, a oba handlery tylko ją przenoszą.
 * Kod niżej to dosłownie to, co do 21 września 2026 stało prywatnie
 * w `WebhookBleduHandler` — przeniesienie bez zmiany ani jednego znaku
 * wychodzącego tekstu.
 *
 * DLACZEGO TREŚĆ JEST BUDOWANA RĘCZNIE, A NIE Z `$record->context`
 * `$record->context['exception']` to PRAWDZIWY obiekt wyjątku, jaki Laravel
 * przekazuje do `Log::error()` przy raportowaniu (`Illuminate\Foundation\
 * Exceptions\Handler::report()`). Jego `getTrace()` potrafi zawierać dokładne
 * ARGUMENTY wywołań ze stosu — adres e-mail podany do funkcji, treść
 * formularza, hasło przekazane wprost. Domyślne formattery Monologa potrafią
 * to POKAZAĆ w wysyłanej wiadomości. AGENTS.md §7 zakazuje PII w logach,
 * a to jest jedyny log w całym serwisie, który wychodzi POZA serwer — więc
 * to jest najgorsze możliwe miejsce, żeby zaufać cudzemu formatowaniu „na oko".
 *
 * Dlatego ta klasa NIGDY nie serializuje `$record->context` ani
 * `$record->extra` w całości. Bierze z wyjątku wyłącznie: nazwę klasy,
 * kod o bezpiecznym kształcie, plik:linię rzucenia, wzorzec trasy HTTP (nie
 * rzeczywisty adres — ta sama zasada, co przy logowaniu 429 w
 * `bootstrap/app.php`), odcisk i ślad stosu OGRANICZONY do plik:linia
 * + nazwa funkcji, bez ŻADNEGO argumentu.
 *
 * KOMUNIKAT WYJĄTKU NIE WYCHODZI STĄD W OGÓLE — i to jest poprawka błędu.
 * Do 9 września ten kod wysyłał `$e->getMessage()`, opierając się na
 * założeniu: „komunikat wyjątku to tekst napisany przez kogoś z nas
 * w kodzie". DLA `QueryException` TO ZAŁOŻENIE JEST FAŁSZYWE. Komunikat
 * buduje sterownik i wkłada w niego SQL razem z wartościami:
 *
 *     SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value
 *     violates unique constraint "users_email_unique"
 *     DETAIL: Key (email)=(ktos@example.com) already exists.
 *     (Connection: pgsql, SQL: insert into "users" ("email","password", …)
 *      values (ktos@example.com, $2y$12$…, …))
 *
 * Czyli adres e-mail i hash hasła człowieka. Znalazł to audyt zewnętrzny
 * (A6-01), odtwarzając prawdziwy błąd unikalności przez trasę HTTP.
 *
 * Odfiltrowywanie danych z takiego tekstu wyrażeniem regularnym byłoby
 * zgadywaniem: sterownik może zmienić format, a każdy inny pakiet może
 * zbudować komunikat po swojemu. Dlatego treść jest budowana wyłącznie
 * z LISTY DOZWOLONYCH PÓL — nazwa klasy, kod błędu o bezpiecznym kształcie,
 * plik:linia, wzorzec trasy, odcisk i ślad bez argumentów.
 *
 * CO Z TEGO TRACIMY I CZYM TO NADRABIAMY
 * Kanał przestaje być raportem, a staje się DZWONKIEM: mówi „coś się
 * zepsuło, tutaj, tego rodzaju". Pełny komunikat zostaje w logu serwera,
 * który nigdzie nie wychodzi. Żeby dało się jedno z drugim zestawić,
 * wiadomość niesie ODCISK — osiem znaków z klasy, pliku i linii. Ten sam
 * błąd ma zawsze ten sam odcisk, więc przy okazji widać, czy to nowa awaria,
 * czy dziesiąte powtórzenie tej samej. Od #599 ten sam odcisk jest też
 * kluczem wyciszania duplikatów w `EmailBleduHandler`.
 */
final class TrescAlarmu
{
    /**
     * Więcej ramek i tak nie zmieści się w jednej wiadomości Discorda/Slacka
     * (limit ~4000 znaków) — a każda dodatkowa ramka to kolejna okazja, żeby
     * coś, czego nie przewidzieliśmy, znalazło się w wysyłanej treści.
     */
    private const MAKSYMALNIE_RAMEK = 8;

    /**
     * Limit Slacka na pole `text` to 4000 znaków; zostawiamy zapas na resztę
     * wiadomości.
     *
     * POCZTA MA TEN SAM LIMIT, CHOĆ GO NIE POTRZEBUJE. To jest świadome:
     * wymóg z #599 brzmi „ten sam tekst, co na webhooku", a tekst przycięty
     * inaczej to już inny tekst. Gdyby kiedyś miało to być luźniejsze dla
     * poczty, zmiana ma iść razem z testem porównującym oba wyjścia —
     * `KanalAlarmowyMailemTest::oba_kanaly_wysylaja_dokladnie_te_sama_tresc`.
     */
    private const MAKSYMALNIE_ZNAKOW = 3500;

    /**
     * Treść wiadomości alarmowej. Ta sama dla webhooka i dla listu.
     */
    public static function tresc(LogRecord $record): string
    {
        $naglowek = self::naglowek();
        $wyjatek = self::wyjatek($record);

        if (! $wyjatek instanceof Throwable) {
            // Wpis zalogowany na kanał alarmowy bez obiektu wyjątku (np.
            // `kuking:sprawdz-alarm`, czujka kopii, `/health`). Reszta
            // kontekstu rekordu NIE JEST tu dołączana świadomie — mógłby
            // nieść cokolwiek, co ktoś kiedyś doda do wywołania `Log::error()`.
            return self::przytnij($naglowek.' '.self::jednalinia($record->message));
        }

        $linie = array_filter([
            $naglowek.' '.$wyjatek::class,
            self::kod($wyjatek),
            sprintf('%s:%d', self::wzgledna($wyjatek->getFile()), $wyjatek->getLine()),
            self::trasa(),
            'odcisk: '.self::odcisk($wyjatek),
        ], static fn (?string $linia): bool => $linia !== null && $linia !== '');

        return self::przytnij(implode("\n", [
            ...$linie,
            '',
            'Treść komunikatu zostaje w logu serwera — na webhook nie wychodzi.',
            '```',
            ...self::slad($wyjatek),
            '```',
        ]));
    }

    /**
     * Temat listu — potrzebny WYŁĄCZNIE kanałowi pocztowemu (webhook tematu
     * nie ma).
     *
     * SKŁADANY Z TEJ SAMEJ LISTY DOZWOLONYCH, CO TREŚĆ, i to jest cały sens
     * trzymania go tutaj, a nie w handlerze poczty. Temat listu jest tą
     * częścią wiadomości, którą widać na powiadomieniu w telefonie, zanim
     * ktokolwiek otworzy list — więc gdyby PII miało kiedyś wyciec, to jest
     * najgorsze możliwe miejsce.
     *
     * `$record->message` NIE WCHODZI TU NIGDY, nawet gdy rekord nie niesie
     * wyjątku. W treści wchodzi (bo tam jest to jedyna informacja, jaką taki
     * rekord ma), ale temat ma zostać stały i przewidywalny — inaczej reguła
     * w skrzynce właściciela („wątek: alarmy Kuking") przestaje działać przy
     * pierwszym nietypowym wpisie.
     */
    public static function temat(LogRecord $record): string
    {
        $wyjatek = self::wyjatek($record);

        if (! $wyjatek instanceof Throwable) {
            return self::naglowek().' alarm';
        }

        return sprintf('%s alarm: %s (odcisk %s)', self::naglowek(), $wyjatek::class, self::odcisk($wyjatek));
    }

    /**
     * Odcisk rekordu — albo `null`, gdy rekord nie niesie obiektu wyjątku.
     *
     * `null` jest tu ZNACZĄCE i `EmailBleduHandler` na nim stoi: rekord bez
     * wyjątku (próba kanału, `/health`, czujki) NIE PODLEGA wyciszaniu
     * duplikatów i nie zapisuje pamięci wyciszania. Uzasadnienie w komentarzu
     * `EmailBleduHandler::write()`.
     */
    public static function odciskRekordu(LogRecord $record): ?string
    {
        $wyjatek = self::wyjatek($record);

        return $wyjatek instanceof Throwable ? self::odcisk($wyjatek) : null;
    }

    private static function wyjatek(LogRecord $record): ?Throwable
    {
        $wyjatek = $record->context['exception'] ?? null;

        return $wyjatek instanceof Throwable ? $wyjatek : null;
    }

    private static function naglowek(): string
    {
        return sprintf('[%s/%s]', config('app.name'), config('app.env'));
    }

    /**
     * Kod błędu, ale TYLKO jeśli ma bezpieczny kształt. Dla `QueryException`
     * jest to SQLSTATE (`23505` = naruszenie unikalności) i to jest
     * najcenniejsza pojedyncza informacja, jaka po usunięciu komunikatu
     * zostaje. `getCode()` nie jest jednak niczym ograniczony — biblioteka
     * może tam wstawić dowolny łańcuch — więc przepuszczamy wyłącznie krótki
     * kod z liter, cyfr i podkreślenia. Cokolwiek innego pomijamy zamiast
     * przycinać: przycięty tekst nadal mógłby nieść fragment danych.
     */
    private static function kod(Throwable $wyjatek): ?string
    {
        $kod = $wyjatek->getCode();

        if (is_int($kod)) {
            return $kod === 0 ? null : 'kod: '.$kod;
        }

        return preg_match('/^[A-Za-z0-9_]{1,20}$/', (string) $kod) === 1
            ? 'kod: '.$kod
            : null;
    }

    /**
     * Osiem znaków, które identyfikują RODZAJ awarii, nie jej wystąpienie.
     * Liczone z klasy, pliku i linii — czyli z rzeczy, które i tak są
     * w wiadomości otwartym tekstem. To nie jest skrót danych osobowych
     * i nie da się z niego niczego odzyskać; ma jedno zadanie: pozwolić
     * odróżnić „nowy błąd" od „ten sam, dziesiąty raz", i odnaleźć wpis
     * w logu serwera.
     */
    private static function odcisk(Throwable $wyjatek): string
    {
        return substr(sha1($wyjatek::class.'|'.$wyjatek->getFile().'|'.$wyjatek->getLine()), 0, 8);
    }

    /**
     * Wzorzec trasy (`POST /wpisy/{post}/komentarz`), NIGDY rzeczywisty adres.
     * Ta sama zasada, co przy logowaniu 429 w `bootstrap/app.php`: adres
     * z podstawionym UUID-em albo slugiem potrafi identyfikować osobę,
     * wzorzec z `{param}` — nigdy.
     */
    private static function trasa(): string
    {
        if (! app()->bound('request')) {
            return 'CLI / kolejka (brak żądania HTTP)';
        }

        $request = request();

        return sprintf('%s /%s', $request->method(), $request->route()?->uri() ?? '?');
    }

    /**
     * Ślad stosu OGRANICZONY do plik:linia i nazwa funkcji — bez klucza
     * `args`. To jest dokładnie to miejsce, w którym PHP potrafi umieścić
     * w śladzie prawdziwe wartości wywołania (hasło podane wprost jako
     * argument, adres e-mail, treść formularza) — `getTraceAsString()`
     * i część formatterów Monologa te wartości POKAZUJĄ. Budujemy ślad
     * ręcznie właśnie po to, żeby argumentów tam nigdy nie było.
     *
     * @return list<string>
     */
    private static function slad(Throwable $wyjatek): array
    {
        $ramki = array_slice($wyjatek->getTrace(), 0, self::MAKSYMALNIE_RAMEK);

        return array_values(array_map(static function (array $ramka): string {
            $miejsce = isset($ramka['file'], $ramka['line'])
                ? sprintf('%s:%d', self::wzgledna((string) $ramka['file']), $ramka['line'])
                : '[php internal]';

            $funkcja = isset($ramka['class'])
                ? sprintf('%s%s%s()', $ramka['class'], $ramka['type'] ?? '::', $ramka['function'])
                : sprintf('%s()', $ramka['function']);

            return $miejsce.' '.$funkcja;
        }, $ramki));
    }

    /** Ścieżka względem katalogu aplikacji — bez tego każda linia niesie pełną, niepotrzebną ścieżkę kontenera. */
    private static function wzgledna(string $sciezka): string
    {
        return str_starts_with($sciezka, base_path())
            ? ltrim(substr($sciezka, strlen(base_path())), '/')
            : $sciezka;
    }

    /** Komunikat bywa wielolinijkowy (np. z SQL-a) — tu ma być jedną linią wiadomości. */
    private static function jednalinia(string $tekst): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $tekst));
    }

    private static function przytnij(string $tekst): string
    {
        return mb_strlen($tekst) > self::MAKSYMALNIE_ZNAKOW
            ? mb_substr($tekst, 0, self::MAKSYMALNIE_ZNAKOW - 1).'…'
            : $tekst;
    }
}
