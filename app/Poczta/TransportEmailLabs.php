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
        // `x-to`, `x-cc`, `x-bcc` NIE SĄ tu na wszelki wypadek. Laravel dokłada
        // je sam: `Mailer::setGlobalToAndRemoveCcAndBcc()` (czynne, gdy
        // `config('mail.to')` jest ustawione — czyli po wpisaniu
        // `MAIL_TO_ADDRESS`, co jest standardową praktyką na stagingu) woła
        // `Message::forgetTo()/forgetCc()/forgetBcc()`, a te przepisują
        // ORYGINALNE adresy odbiorców do nagłówków `X-To`, `X-Cc`, `X-Bcc`.
        //
        // Bez tych trzech wpisów adresy użytkowników poszłyby do dostawcy
        // w polu `headers` i ZOSTAŁY w dostarczonym liście — czyli dokładnie
        // ten przemyt adresu, przed którym broni komentarz wyżej. Dziś nie
        // strzela, bo `config/mail.php` nie ma klucza `to`; kosztuje trzy
        // wiersze, a zamyka klasę awarii, nie jej dzisiejszy objaw.
        'x-to', 'x-cc', 'x-bcc',
    ];

    /**
     * Słowa, po których rozpoznajemy wyczerpany limit w odpowiedzi dostawcy
     * (issue #234). Małymi literami — porównanie idzie po `mb_strtolower`.
     *
     * @var list<string>
     */
    private const SLOWA_O_LIMICIE = ['limit', 'quota', 'too many', 'throttl', 'rate exceed', 'przekroczon'];

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
            // PRZEJŚCIOWA, choć nie wiemy, czy list wyszedł: zerwane
            // połączenie prawie zawsze naprawia się samo, a powtórzenie
            // najwyżej zdubluje list — czyli kosztuje mniej niż potwierdzenie
            // rejestracji, które nie doszło (issue #234, D-062).
            throw OdmowaEmailLabs::powodu(
                PowodOdmowy::PRZEJSCIOWA,
                'Nie udało się połączyć z API EmailLabs ('.$this->bezSekretow($e->getMessage()).'). '
                .'Nie wiadomo, czy list wyszedł. Sprawdź, czy kontener ma wyjście na HTTPS '
                .'i czy adres API jest poprawny.',
                poprzedni: $e,
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
            throw OdmowaEmailLabs::powodu(
                $this->powodOdmowy($odpowiedz, $tresc),
                $this->powod($odpowiedz, $tresc),
                $odpowiedz->status(),
                confirmedRejection: $odpowiedz->status() >= 400 && $odpowiedz->status() < 500 && $odpowiedz->status() !== 408,
            );
        }

        if ($meta === null) {
            throw OdmowaEmailLabs::powodu(
                PowodOdmowy::NIEZNANA,
                'API EmailLabs odpowiedziało HTTP '.$odpowiedz->status().', ale w odpowiedzi nie ma sekcji `meta`, '
                .'którą opisuje jego własna specyfikacja. Nie da się stwierdzić, czy list został przyjęty — '
                .'a zgadywanie „pewnie poszło" jest tu gorsze niż porażka. Sprawdź adres w EMAILLABS_ENDPOINT.',
                $odpowiedz->status(),
            );
        }

        if ((int) ($meta['numberOfErrors'] ?? 0) > 0 || ($tresc['errors'] ?? []) !== []) {
            // HTTP 2xx z błędami w treści: żądanie było poprawne, wiadomości
            // dostawca nie przyjął. Kategoria wychodzi z kodów błędów, nie
            // z kodu HTTP — bo ten mówi tu „ok".
            throw OdmowaEmailLabs::powodu(
                $this->powodOdmowy($odpowiedz, $tresc),
                $this->powod($odpowiedz, $tresc),
                $odpowiedz->status(),
                confirmedRejection: ($meta['numberOfData'] ?? null) === 0,
            );
        }

        if ((int) ($meta['numberOfData'] ?? 0) < 1) {
            throw OdmowaEmailLabs::powodu(
                PowodOdmowy::NIEZNANA,
                'API EmailLabs odpowiedziało HTTP '.$odpowiedz->status().' bez błędów, ale i bez ANI JEDNEJ '
                .'przyjętej wiadomości (`meta.numberOfData` = 0). Nikt nic nie dostanie. '
                .$this->identyfikator($meta),
                $odpowiedz->status(),
                confirmedRejection: ($meta['numberOfData'] ?? null) === 0,
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
     * KATEGORIA odmowy: „nie wyszedł teraz" czy „nie wyjdzie nigdy"
     * (issue #234, D-062, `PowodOdmowy`).
     *
     * Kolejność warunków jest tu regułą, nie przypadkiem:
     *
     *  1. NAJPIERW SŁOWA O LIMICIE, potem kody HTTP. Wyczerpany limit dobowy
     *     przychodzi u dostawców i jako 429, i jako 4xx z komunikatem
     *     o limicie, i — jak pokazuje specyfikacja EmailLabs — jako HTTP 2xx
     *     z błędem w `errors[]`. Gdyby kod HTTP rozstrzygał pierwszy,
     *     wyczerpana pula meldowałaby się jako „trwała odmowa", czyli
     *     kazałaby właścicielowi szukać usterki w konfiguracji przez cały
     *     dzień, w którym wystarczyło poczekać do północy.
     *  2. 429 i 5xx to awaria po TAMTEJ stronie — przejściowa.
     *  3. Pozostałe 4xx (i 207, czyli „część adresatów odrzucona") to
     *     odmowa trwała: zły adres, zły klucz, odrzucony nadawca. Powtarzanie
     *     nie da nic, dopóki człowiek czegoś nie zmieni.
     *  4. Cokolwiek innego — NIEZNANA. Nie zgadujemy.
     *
     * Słowa szukamy w `code`, `title` i `message` dostawcy, czyli w polach
     * `ErrorObject` ze specyfikacji. Tekst ten służy TYLKO do
     * zaklasyfikowania i nie wychodzi stąd nigdzie — do komunikatu wyjątku
     * idzie osobno, przez `powod()`, po redakcji adresów.
     *
     * @param  array<string, mixed>  $tresc
     */
    private function powodOdmowy(Response $odpowiedz, array $tresc): PowodOdmowy
    {
        $status = $odpowiedz->status();

        if ($this->mowiOLimicie($tresc) || $status === 429) {
            return PowodOdmowy::LIMIT_DOBOWY;
        }

        if ($status >= 500 || $status === 408) {
            return PowodOdmowy::PRZEJSCIOWA;
        }

        if ($status >= 400 || $status === 207) {
            return PowodOdmowy::TRWALA;
        }

        // HTTP 2xx z błędami w treści, ale bez słowa o limicie: dostawca
        // odrzucił konkretną wiadomość, więc powtórzenie odbije się tak samo.
        return ($tresc['errors'] ?? []) !== [] ? PowodOdmowy::TRWALA : PowodOdmowy::NIEZNANA;
    }

    /**
     * Czy w błędach dostawcy stoi cokolwiek o wyczerpanym limicie.
     *
     * Lista słów jest krótka i po angielsku, bo API odpowiada po angielsku
     * (`ErrorObject.title`: „general error name"). `przekroczon` jest tu na
     * wypadek polskich komunikatów z panelu — kosztuje jedno słowo, a zamyka
     * przypadek, którego inaczej nikt by nie zauważył.
     *
     * @param  array<string, mixed>  $tresc
     */
    private function mowiOLimicie(array $tresc): bool
    {
        $bledy = is_array($tresc['errors'] ?? null) ? $tresc['errors'] : [];
        $tekst = '';

        foreach ($bledy as $blad) {
            if (! is_array($blad)) {
                continue;
            }

            foreach (['code', 'title', 'message'] as $pole) {
                $wartosc = $blad[$pole] ?? null;

                if (is_string($wartosc) || is_int($wartosc)) {
                    $tekst .= ' '.mb_strtolower((string) $wartosc);
                }
            }
        }

        if ($tekst === '') {
            return false;
        }

        foreach (self::SLOWA_O_LIMICIE as $slowo) {
            if (str_contains($tekst, $slowo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Powód odmowy — zbudowany WYŁĄCZNIE z pól, o których wiemy, że opisują
     * błąd, a nie dane człowieka.
     *
     * Bierzemy: kod HTTP, `errors[].code`, `errors[].title`, `errors[].message`
     * (przepuszczone przez tę samą redakcję co tytuł) i `meta.uniqId`.
     * NIE bierzemy: `errors[].meta.value`.
     *
     * SPROSTOWANIE, 9 września 2026 — poprzednia wersja tego komentarza była
     * nieprawdziwa i kosztowała diagnostykę. Twierdziła, że odcinamy
     * `errors[].message` dlatego, że obok stoi `meta.value` z wartością
     * parametru. `components/schemas/ErrorObject` w pobranej specyfikacji
     * OpenAPI dostawcy ma jednak DOKŁADNIE trzy pola, wszystkie wymagane:
     *
     *     "required": ["title", "message", "code"]
     *
     * Nie ma tam `meta`. Czyli `meta.value` nie istnieje (ostrożność była
     * skierowana w puste miejsce), `meta.parameter` też nie — więc
     * `parametrBledu()` zwracało `null` przy każdej odmowie i fragment
     * „pole: …" nie pojawiał się nigdy. Odcięte zostało za to jedyne pole
     * z detalami: `message`, opisane jako „Error details".
     *
     * Skutek był taki, że przy HTTP 400 do `failed_jobs` wpadał sam kod
     * i tytuł, a operator nie wiedział, CO dostawca odrzucił.
     *
     * Lekcja z audytu A6-01 zostaje w mocy — po prostu stosujemy ją tam, gdzie
     * jest jej miejsce: `message` przechodzi przez `trescBledu()`, czyli tę
     * samą redakcję adresów i to samo obcięcie co `title`. `meta.parameter`
     * czytamy dalej jako dodatek, gdyby dostawca kiedyś je dosłał, ale nic
     * już na nim nie stoi.
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
            $tresc = $this->trescBledu($blad['message'] ?? null);
            $parametr = $this->parametrBledu($blad);

            // `message` pomijamy, gdy tylko powtarza tytuł — inaczej komunikat
            // mówiłby to samo dwa razy.
            if ($tresc !== null && $tresc === $tytul) {
                $tresc = null;
            }

            $opisy[] = trim(implode(' ', array_filter([
                $opis,
                $tytul === null ? null : '('.$tytul.')',
                $tresc === null ? null : '— '.$tresc,
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

        // Redakcja stoi w `BezpiecznyKomunikat`, bo tę samą robi teraz
        // `ZapiszNieudanyList` nad komunikatem DOWOLNEGO transportu (D-062).
        return BezpiecznyKomunikat::z($tytul, self::MAKSYMALNA_DLUGOSC_TYTULU);
    }

    /**
     * `errors[].message` — „Error details" ze specyfikacji, przykład z niej:
     * „Field ... is invalid". To JEDYNE pole, które mówi, co dostawcy nie
     * pasowało, więc bez niego odmowa jest nierozpoznawalna.
     *
     * Redakcja jest tu ostrzejsza niż przy tytule, i to celowo: specyfikacja
     * nie obiecuje, że dostawca nie wstawi w „details" wartości parametru —
     * a przy odrzuconym adresie odbiorcy tą wartością byłby jego adres. Więc:
     * jedna linia, wszystko o kształcie adresu zamienione na `[adres]`,
     * obcięcie do 120 znaków. Ta sama zasada, co w `WebhookBleduHandler`
     * (audyt A6-01): komunikat od cudzej strony niesie dane, których autor
     * kodu tam nie włożył.
     */
    private function trescBledu(mixed $tresc): ?string
    {
        if (! is_string($tresc) || trim($tresc) === '') {
            return null;
        }

        return BezpiecznyKomunikat::z($tresc, self::MAKSYMALNA_DLUGOSC_TYTULU);
    }

    /**
     * Nazwa parametru, który dostawca odrzucił (`to`, `subject`, `smtpAccount`).
     * NAZWA, nigdy wartość.
     *
     * UWAGA: `ErrorObject` w specyfikacji NIE MA pola `meta`, więc dziś ta
     * metoda zwraca `null` przy każdej odmowie. Zostaje jako dodatek na wypadek,
     * gdyby dostawca zaczął je dosyłać — ale diagnostyka stoi na `code`,
     * `title` i `message`, nie na tym. Nie pisz testu, który zakłada, że to
     * pole przychodzi: taki test dowodziłby, że umiemy odczytać coś, czego API
     * nie zwraca, i dokładnie tak było do 9 września.
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

        $adresat = $dane[0]['to'] ?? null;

        if (! is_array($adresat)) {
            return null;
        }

        // `to` W ODPOWIEDZI JEST OBIEKTEM, NIE LISTĄ — i to nie jest domysł.
        // `components/schemas/EmailStatusObject` w specyfikacji OpenAPI
        // dostawcy (pobrana i sprawdzona 9 września 2026) ma:
        //
        //     "to": { "properties": { "email", "name", "messageId" },
        //             "type": "object" }
        //
        // a `POST /v2.1/email` → 200 zwraca `data` jako tablicę WŁAŚNIE tych
        // obiektów. Pierwsza wersja czytała `$adresat[0]['messageId']`, czyli
        // traktowała `to` jak listę — i zwracała `null` przy KAŻDEJ udanej
        // wysyłce. Awaria była całkowicie cicha: `setMessageId()` nie było
        // wołane nigdy, `SentMessage` zostawał z lokalnym identyfikatorem,
        // którego dostawca nigdy nie widział (`message-id` jest na liście
        // `NAGLOWKI_POMIJANE`), więc wysyłki nie dało się połączyć z wpisem
        // w panelu EmailLabs. Test tego nie łapał, bo atrapa odpowiedzi
        // powtarzała ten sam błędny kształt.
        //
        // Listę przyjmujemy nadal, gdyby dostawca kiedyś zmienił kształt na
        // wieloadresatowy: taniej tolerować oba niż wrócić tu po awarii.
        $id = is_array($adresat[0] ?? null)
            ? ($adresat[0]['messageId'] ?? null)
            : ($adresat['messageId'] ?? null);

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
