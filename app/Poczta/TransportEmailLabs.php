<?php

declare(strict_types=1);

namespace App\Poczta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Wysyłka poczty przez API HTTPS EmailLabs — własny transport Symfony Mailera.
 *
 * PO CO TO ISTNIEJE (a nie „bo API jest modniejsze niż SMTP")
 * Railway blokuje SMTP na planach Free, Trial i Hobby; wychodzi dopiero od
 * planu Pro. Właściciel jest na Free i przechodzi na Hobby, więc SMTP nie
 * zadziała na żadnym z nich. Objaw zmierzony 9 września 2026 w dzienniku
 * Railwaya: zadanie `App\Notifications\UstawienieNowegoHasla` wchodziło
 * w `RUNNING` i NIGDY się nie kończyło — ani `DONE`, ani `FAIL`. Pakiety szły
 * w próżnię, a połączenie wisiało do timeoutu kontenera. Dla człowieka
 * wyglądało to jak trzecia z rzędu cicha awaria poczty tego samego dnia.
 *
 * DLACZEGO NADAL EMAILLABS, SKORO TRZEBA PISAĆ WŁASNY TRANSPORT
 * Decyzja właściciela, powód prawny, nie techniczny: EmailLabs to Vercom S.A.
 * z Poznania, serwery w EOG. Dzięki temu w polityce prywatności zostaje zdanie
 * „Twój adres e-mail przetwarzamy w Polsce". Każdy dostawca z gotowym
 * sterownikiem Laravela (Mailgun, SES, Postmark, Resend) to spółka
 * amerykańska — CLOUD Act, nowe DPA, ocena transferu i dodatkowy akapit
 * o wywozie danych poza EOG. Pełna analiza: `docs/decyzje/POCZTA.md` §2,
 * decyzja: `docs/DECISIONS.md` D-047.
 *
 * DLACZEGO ZERO NOWYCH PACZEK COMPOSERA
 * Klasa dziedziczy po `AbstractTransport` z `symfony/mailer` (jest w projekcie,
 * bo używa go każdy sterownik poczty Laravela) i woła API klientem HTTP
 * Laravela (`Illuminate\Support\Facades\Http`, też już jest — korzysta z niego
 * `App\Logging\WebhookBleduHandler`). Nic nie dochodzi do `composer.json`.
 *
 * KSZTAŁT API — Z DOKUMENTACJI, NIE ZE ZGADYWANIA
 * `POST https://api.emaillabs.io/v2.1/email`, ciało JSON, uwierzytelnienie
 * DWOMA nagłówkami: `Application-Key` (klucz aplikacji) i `Authorization`
 * (klucz autoryzacyjny, 128 znaków) — oba generowane w panelu EmailLabs
 * w „Konto → Ustawienia → API". To NIE są login i hasło SMTP; SMTP-owych
 * danych to API nie przyjmie.
 * Źródła (sprawdzone 9 września 2026):
 *   - specyfikacja OpenAPI: https://apidocs.emaillabs.io/openapi.json
 *     (`servers[0].url = https://api.emaillabs.io`, ścieżka `/v2.1/email`,
 *     `securitySchemes`: `Authorization` i `Application-Key` w nagłówku)
 *   - uwierzytelnienie: https://vercom.gitbook.io/emaillabs-api-docs/authentication
 *   - kształt odpowiedzi (`meta` / `data` / `errors`):
 *     https://vercom.gitbook.io/emaillabs-api-docs/introduction
 *   - generowanie kluczy:
 *     https://docs.emaillabs.io/konto/ustawienia/api/generowanie-kluczy-api
 *
 * Pola wymagane przez API: `subject`, `smtpAccount`, `content`, `from`, `to`.
 * Limity ze specyfikacji: `to` maksymalnie 200 adresatów, całe żądanie do
 * 15 MB, `subject` 2–128 znaków, nazwy osób 2–64 znaki.
 *
 * ODPOWIEDŹ ROZSTRZYGAMY, ZAMIAST JEJ UFAĆ
 * Sukces to HTTP 2xx **i** `meta.numberOfErrors == 0` **i** `meta.numberOfData
 * >= 1`. HTTP 207 (część adresatów odrzucona) jest tu PORAŻKĄ, choć wygląda
 * na częściowy sukces: nasze listy mają po jednym adresacie, więc „część nie
 * poszła" znaczy „nie poszedł żaden". Wszystko inne rzuca `OdmowaEmailLabs`,
 * czyli wywraca zadanie w kolejce — bo cicha porażka jest w poczcie
 * najgorszym możliwym skutkiem.
 *
 * ŚLEDZENIE ODNOŚNIKÓW JEST DOMYŚLNIE WYŁĄCZONE
 * EmailLabs domyślnie podmienia każdy odnośnik w treści na własny adres
 * przekierowujący. W liście z linkiem do zmiany hasła to jest zła zamiana:
 * osoba 60+ widzi wtedy adres, który nie ma nic wspólnego z kuking.pl — czyli
 * dokładnie ten kształt, przed którym ostrzegają banki. Wysyłamy więc nagłówek
 * `X-TRACKING-OFF`, dopóki `services.emaillabs.tracking` nie zostanie jawnie
 * włączone.
 */
final class TransportEmailLabs extends AbstractTransport
{
    /**
     * Nagłówki wiadomości, których NIE przekazujemy do API. EmailLabs składa
     * kopertę sam z pól `from`, `to`, `cc`, `bcc`, `subject` i `replyTo`;
     * powtórzenie ich w `headers` albo dołożyłoby drugi nagłówek tej samej
     * nazwy, albo — gorzej — przemyciłoby adres odbiorcy tam, gdzie go już nie
     * chcemy. `received` i `dkim-signature` należą do serwerów po drodze.
     *
     * @var list<string>
     */
    private const NAGLOWKI_POMIJANE = [
        'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date',
        'message-id', 'mime-version', 'content-type', 'content-transfer-encoding',
        'return-path', 'received', 'dkim-signature', 'x-transport',
    ];

    /** Specyfikacja API: nazwa nadawcy i adresata od 2 do 64 znaków. */
    private const MAKSYMALNA_DLUGOSC_NAZWY = 64;

    /** Ile znaków tytułu błędu przepuszczamy do komunikatu wyjątku. */
    private const MAKSYMALNA_DLUGOSC_TYTULU = 120;

    public function __construct(
        private readonly string $kluczAplikacji,
        private readonly string $kluczAutoryzacji,
        private readonly string $kontoSmtp,
        private readonly string $adresApi,
        private readonly int $limitCzasu,
        private readonly bool $sledzenieOdnosnikow,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    /**
     * Nazwa transportu w komunikatach Symfony. CELOWO bez klucza i bez konta
     * SMTP — ten łańcuch trafia do komunikatów wyjątków i do `__toString()`
     * mailera, czyli w miejsca, których nie kontrolujemy.
     */
    public function __toString(): string
    {
        return 'emaillabs+api://api.emaillabs.io';
    }

    protected function doSend(SentMessage $wiadomosc): void
    {
        $email = MessageConverter::toEmail($wiadomosc->getOriginalMessage());
        $tresc = $this->zadanie($email, $wiadomosc->getEnvelope());

        try {
            $odpowiedz = Http::withHeaders([
                'Application-Key' => $this->kluczAplikacji,
                'Authorization' => $this->kluczAutoryzacji,
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min($this->limitCzasu, 10))
                ->timeout($this->limitCzasu)
                ->post($this->adresApi, $tresc);
        } catch (ConnectionException $e) {
            // Nie udało się nawet dopytać dostawcy. NIE zakładamy, że list nie
            // poszedł — zakładamy, że nie wiemy, i wywracamy zadanie, żeby
            // ktoś to zobaczył w `queue:failed`.
            throw new OdmowaEmailLabs(
                'Nie udało się połączyć z API EmailLabs ('.$this->bezSekretow($e->getMessage()).'). '
                .'Nie wiadomo, czy list wyszedł. Sprawdź, czy kontener ma wyjście na HTTPS '
                .'i czy adres API jest poprawny.',
                previous: $e,
            );
        }

        $this->rozstrzygnij($odpowiedz, $wiadomosc);
    }

    /**
     * Ciało żądania — dokładnie te pola, które opisuje `EmailObject`
     * w specyfikacji OpenAPI EmailLabs.
     *
     * @return array<string, mixed>
     */
    private function zadanie(Email $email, Envelope $koperta): array
    {
        $zadanie = [
            'smtpAccount' => $this->kontoSmtp,
            'subject' => (string) $email->getSubject(),
            'from' => $this->osoba($email->getFrom()[0] ?? $koperta->getSender()),
            'to' => $this->osoby($this->adresaci($email, $koperta)),
            'content' => $this->zawartosc($email),
        ];

        if ($email->getCc() !== []) {
            $zadanie['cc'] = $this->osoby($email->getCc());
        }

        if ($email->getBcc() !== []) {
            $zadanie['bcc'] = $this->osoby($email->getBcc());
        }

        $odpowiedzDo = $email->getReplyTo()[0] ?? null;

        if ($odpowiedzDo instanceof Address) {
            $zadanie['replyTo'] = $this->osoba($odpowiedzDo);
        }

        $naglowki = $this->naglowki($email);

        if ($naglowki !== []) {
            $zadanie['headers'] = $naglowki;
        }

        $zalaczniki = $this->zalaczniki($email);

        if ($zalaczniki !== []) {
            $zadanie['attachments'] = $zalaczniki;
        }

        return $zadanie;
    }

    /**
     * Adresaci pola `to`: wszyscy z koperty MINUS ci, którzy są w `cc` i `bcc`.
     *
     * To jest ta sama arytmetyka, którą robi `AbstractApiTransport` w Symfony.
     * Koperta niesie KOMPLET odbiorców (do, kopia, kopia ukryta), a API
     * EmailLabs chce te trzy grupy osobno — bez odejmowania każdy odbiorca
     * kopii dostałby list dwa razy, a odbiorca kopii UKRYTEJ zobaczyłby siebie
     * w widocznym „Do", co jest wyciekiem adresu do pozostałych odbiorców.
     *
     * @return list<Address>
     */
    private function adresaci(Email $email, Envelope $koperta): array
    {
        $ukryci = array_merge($email->getCc(), $email->getBcc());

        return array_values(array_filter(
            $koperta->getRecipients(),
            static fn (Address $adres): bool => ! in_array($adres, $ukryci, true),
        ));
    }

    /** @return array<string, string> */
    private function osoba(?Address $adres): array
    {
        if (! $adres instanceof Address) {
            // Symfony nie wypuści wiadomości bez nadawcy, więc to jest obrona
            // przed zmianą we frameworku, nie przed zwykłym użyciem.
            return ['email' => ''];
        }

        $osoba = ['email' => $adres->getAddress()];
        $nazwa = $this->nazwa($adres->getName());

        if ($nazwa !== null) {
            $osoba['name'] = $nazwa;
        }

        return $osoba;
    }

    /**
     * @param  list<Address>  $adresy
     * @return list<array<string, string>>
     */
    private function osoby(array $adresy): array
    {
        return array_values(array_map(fn (Address $adres): array => $this->osoba($adres), $adresy));
    }

    /**
     * Nazwa widoczna obok adresu. API przyjmuje 2–64 znaki, więc dłuższą
     * PRZYCINAMY, a jednoznakową POMIJAMY.
     *
     * Powód jest praktyczny: nazwa bierze się z profilu użytkownika, czyli
     * z pola, którego długości nie kontrolujemy. Odrzucony przez API list
     * z powodu zbyt długiego imienia to dla osoby proszącej o nowe hasło
     * dokładnie ta sama strata konta, co brak poczty w ogóle — a przycięta
     * nazwa nadawcy nikomu niczego nie odbiera.
     */
    private function nazwa(string $nazwa): ?string
    {
        $nazwa = trim($nazwa);

        if (mb_strlen($nazwa) < 2) {
            return null;
        }

        return mb_substr($nazwa, 0, self::MAKSYMALNA_DLUGOSC_NAZWY);
    }

    /** @return array<string, string> */
    private function zawartosc(Email $email): array
    {
        $zawartosc = [];

        $html = $email->getHtmlBody();
        $tekst = $email->getTextBody();

        if (is_string($html) && $html !== '') {
            $zawartosc['html'] = $html;
        }

        if (is_string($tekst) && $tekst !== '') {
            $zawartosc['text'] = $tekst;
        }

        return $zawartosc;
    }

    /**
     * Nagłówki własne wiadomości (`List-Unsubscribe`, `X-…`) przekazane do API.
     *
     * Lista jest ODEJMOWANA, nie dobierana: przepuszczamy wszystko oprócz
     * `NAGLOWKI_POMIJANE`, bo nagłówki dokłada się w kodzie domenowym i lista
     * dozwolonych rozjechałaby się z rzeczywistością przy pierwszym nowym
     * powiadomieniu. Nazwa nagłówka może mieć u dostawcy najwyżej 100 znaków.
     *
     * @return array<string, string>
     */
    private function naglowki(Email $email): array
    {
        $naglowki = [];

        foreach ($email->getHeaders()->all() as $naglowek) {
            $nazwa = $naglowek->getName();

            if (in_array(mb_strtolower($nazwa), self::NAGLOWKI_POMIJANE, true)) {
                continue;
            }

            if (mb_strlen($nazwa) > 100) {
                continue;
            }

            $naglowki[$nazwa] = $naglowek->getBodyAsString();
        }

        if (! $this->sledzenieOdnosnikow) {
            // Udokumentowany przełącznik EmailLabs: „Link tracking is enabled
            // by default, you can disable it by adding a header
            // {"X-TRACKING-OFF":1}".
            $naglowki['X-TRACKING-OFF'] = '1';
        }

        return $naglowki;
    }

    /**
     * Załączniki w kształcie `attachments` z API: nazwa, typ MIME i zawartość
     * w base64.
     *
     * OGRANICZENIE, KTÓREGO NIE UDAJEMY: obrazek wstawiony w treść przez
     * `cid:` (Symfony ustawia mu `Content-ID`) przekazujemy z `inline: true`,
     * ale specyfikacja EmailLabs nie mówi, czy i jak zachowuje identyfikator
     * `cid`. Dopóki nikt tego nie sprawdzi na żywym koncie, listy Kuking nie
     * powinny polegać na obrazkach wstawianych w treść — dziś żaden tego nie
     * robi.
     *
     * @return list<array<string, string|bool>>
     */
    private function zalaczniki(Email $email): array
    {
        $zalaczniki = [];

        foreach ($email->getAttachments() as $czesc) {
            if (! $czesc instanceof DataPart) {
                continue;
            }

            $zalaczniki[] = [
                'fileName' => (string) $czesc->getFilename(),
                'fileMime' => $czesc->getMediaType().'/'.$czesc->getMediaSubtype(),
                'fileContent' => base64_encode($czesc->getBody()),
                'inline' => $czesc->getDisposition() === 'inline',
            ];
        }

        return $zalaczniki;
    }

    /**
     * Czy dostawca NAPRAWDĘ przyjął wiadomość.
     *
     * Trzy warunki naraz, bo każdy z osobna daje się spełnić bez wysyłki:
     *   1. HTTP 2xx — samo w sobie nic nie znaczy przy 207,
     *   2. zero błędów w `meta.numberOfErrors`,
     *   3. co najmniej jedna pozycja w `meta.numberOfData` — czyli dostawca
     *      potwierdza, że przyjął konkretną wiadomość, a nie samo żądanie.
     */
    private function rozstrzygnij(Response $odpowiedz, SentMessage $wiadomosc): void
    {
        $tresc = $this->odpowiedzJako($odpowiedz);
        $meta = is_array($tresc['meta'] ?? null) ? $tresc['meta'] : null;

        if ($odpowiedz->failed() || $odpowiedz->status() === 207) {
            throw new OdmowaEmailLabs($this->powod($odpowiedz, $tresc));
        }

        if ($meta === null) {
            throw new OdmowaEmailLabs(
                'API EmailLabs odpowiedziało HTTP '.$odpowiedz->status().', ale w odpowiedzi nie ma sekcji `meta`, '
                .'którą opisuje jego własna specyfikacja. Nie da się stwierdzić, czy list został przyjęty — '
                .'a zgadywanie „pewnie poszło" jest tu gorsze niż porażka. Sprawdź adres w EMAILLABS_ENDPOINT.',
            );
        }

        if ((int) ($meta['numberOfErrors'] ?? 0) > 0 || ($tresc['errors'] ?? []) !== []) {
            throw new OdmowaEmailLabs($this->powod($odpowiedz, $tresc));
        }

        if ((int) ($meta['numberOfData'] ?? 0) < 1) {
            throw new OdmowaEmailLabs(
                'API EmailLabs odpowiedziało HTTP '.$odpowiedz->status().' bez błędów, ale i bez ANI JEDNEJ '
                .'przyjętej wiadomości (`meta.numberOfData` = 0). Nikt nic nie dostanie. '
                .$this->identyfikator($meta),
            );
        }

        $identyfikator = $this->identyfikatorWiadomosci($tresc);

        if ($identyfikator !== null) {
            $wiadomosc->setMessageId($identyfikator);
        }
    }

    /** @return array<string, mixed> */
    private function odpowiedzJako(Response $odpowiedz): array
    {
        try {
            $tresc = $odpowiedz->json();
        } catch (Throwable) {
            // Ciało nie jest JSON-em (np. strona błędu proxy). Nie ma czego
            // czytać — i nie wolno tego wkleić do komunikatu, bo nie wiadomo,
            // co tam jest.
            return [];
        }

        return is_array($tresc) ? $tresc : [];
    }

    /**
     * Powód odmowy — zbudowany WYŁĄCZNIE z pól, o których wiemy, że opisują
     * błąd, a nie dane człowieka.
     *
     * Bierzemy: kod HTTP, `errors[].code`, `errors[].title`,
     * `errors[].meta.parameter` (NAZWA parametru) i `meta.uniqId`.
     * NIE bierzemy: `errors[].message` ani `errors[].meta.value` — dokumentacja
     * mówi wprost, że `meta.value` to „the value of this parameter passed",
     * czyli przy błędnym adresie odbiorcy byłby to jego adres e-mail. To jest
     * dokładnie ta pułapka, którą audyt A6-01 znalazł w `WebhookBleduHandler`:
     * komunikat błędu od cudzej biblioteki niesie dane, których autor kodu
     * tam nie włożył.
     *
     * @param  array<string, mixed>  $tresc
     */
    private function powod(Response $odpowiedz, array $tresc): string
    {
        $meta = is_array($tresc['meta'] ?? null) ? $tresc['meta'] : [];
        $bledy = is_array($tresc['errors'] ?? null) ? $tresc['errors'] : [];

        $opisy = [];

        foreach ($bledy as $blad) {
            if (! is_array($blad)) {
                continue;
            }

            $opis = $this->kodBledu($blad['code'] ?? null);
            $tytul = $this->tytulBledu($blad['title'] ?? null);
            $parametr = $this->parametrBledu($blad);

            $opisy[] = trim(implode(' ', array_filter([
                $opis,
                $tytul === null ? null : '('.$tytul.')',
                $parametr === null ? null : 'pole: '.$parametr,
            ], static fn (?string $czesc): bool => $czesc !== null && $czesc !== '')));
        }

        $opisy = array_values(array_filter($opisy, static fn (string $o): bool => $o !== ''));

        return $this->bezSekretow(implode(' ', array_filter([
            'API EmailLabs nie przyjęło wiadomości (HTTP '.$odpowiedz->status().').',
            $opisy === [] ? 'Dostawca nie podał rozpoznawalnego kodu błędu.' : 'Błędy: '.implode('; ', $opisy).'.',
            $this->identyfikator($meta),
            'Treść listu i adres odbiorcy celowo nie są tu wypisane — to dane osobowe, a ten komunikat '
            .'trafia do `failed_jobs` i do zgłoszenia błędu.',
        ], static fn (string $czesc): bool => $czesc !== '')));
    }

    /**
     * Kod błędu przepuszczamy tylko o bezpiecznym kształcie — ta sama zasada,
     * co w `WebhookBleduHandler::kod()`. Dokumentacja pokazuje kody typu
     * `E-0-004`; nic nie gwarantuje, że dostawca nie wstawi tam kiedyś
     * czegoś innego.
     */
    private function kodBledu(mixed $kod): ?string
    {
        if (! is_string($kod) && ! is_int($kod)) {
            return null;
        }

        $kod = (string) $kod;

        return preg_match('/^[A-Za-z0-9._:-]{1,32}$/', $kod) === 1 ? $kod : null;
    }

    /**
     * Tytuł błędu („Empty token", „Invalid parameter") — jedna linia, przycięta
     * i z wyciętym wszystkim, co wygląda na adres e-mail. Specyfikacja mówi, że
     * to „general error name", ale to zapewnienie dostawcy, nie gwarancja
     * formatu, a adres odbiorcy nie ma prawa stąd wyjść nawet przez pomyłkę.
     */
    private function tytulBledu(mixed $tytul): ?string
    {
        if (! is_string($tytul) || trim($tytul) === '') {
            return null;
        }

        $tytul = (string) preg_replace('/\s+/', ' ', trim($tytul));
        $tytul = (string) preg_replace('/[^\s<>()@,;]+@[^\s<>()@,;]+/', '[adres]', $tytul);

        return mb_substr($tytul, 0, self::MAKSYMALNA_DLUGOSC_TYTULU);
    }

    /**
     * Nazwa parametru, który dostawca odrzucił (`to`, `subject`, `smtpAccount`).
     * NAZWA, nigdy wartość — to jest najcenniejsza informacja diagnostyczna,
     * jaka po odcięciu treści zostaje.
     *
     * @param  array<mixed>  $blad
     */
    private function parametrBledu(array $blad): ?string
    {
        $meta = is_array($blad['meta'] ?? null) ? $blad['meta'] : [];
        $parametr = $meta['parameter'] ?? null;

        if (! is_string($parametr)) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.\[\]]{1,64}$/', $parametr) === 1 ? $parametr : null;
    }

    /**
     * `meta.uniqId` — identyfikator żądania po stronie dostawcy. Dokumentacja
     * prosi, żeby podawać go przy zgłoszeniu problemu, więc jest to jedyna
     * rzecz, którą warto przepisać z odpowiedzi wprost.
     *
     * @param  array<mixed>  $meta
     */
    private function identyfikator(array $meta): string
    {
        $id = $meta['uniqId'] ?? null;

        if (! is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $id) !== 1) {
            return '';
        }

        return 'Identyfikator żądania u dostawcy: '.$id.'.';
    }

    /**
     * Identyfikator przyjętej wiadomości — pozwala odnaleźć list w panelu
     * EmailLabs. Trafia do `SentMessage`, czyli do zdarzenia `MessageSent`.
     *
     * @param  array<string, mixed>  $tresc
     */
    private function identyfikatorWiadomosci(array $tresc): ?string
    {
        $dane = $tresc['data'] ?? null;

        if (! is_array($dane) || ! is_array($dane[0] ?? null)) {
            return null;
        }

        $adresaci = $dane[0]['to'] ?? null;

        if (! is_array($adresaci) || ! is_array($adresaci[0] ?? null)) {
            return null;
        }

        $id = $adresaci[0]['messageId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * OSTATNIA ZAPORA: gdyby cokolwiek — komunikat cURL-a, echo od dostawcy,
     * treść proxy — niosło nasz klucz, wycinamy go z tekstu, zanim wyjdzie
     * w wyjątku. Tego nie powinno się zdarzyć; ta metoda istnieje po to, żeby
     * „nie powinno" nie było jedynym zabezpieczeniem.
     */
    private function bezSekretow(string $tekst): string
    {
        $sekrety = array_values(array_filter(
            [$this->kluczAutoryzacji, $this->kluczAplikacji],
            static fn (string $sekret): bool => mb_strlen($sekret) >= 8,
        ));

        return $sekrety === []
            ? $tekst
            : str_replace($sekrety, '[klucz]', $tekst);
    }
}
