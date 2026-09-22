<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Odpowiedź operatora na wiadomość z „Napisz do nas" (D-058).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  BEZ `ShouldQueue` I BEZ `Queueable` — TO NIE JEST PRZEOCZENIE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Każdy inny list w tym serwisie idzie kolejką, i słusznie: nikt nie czeka
 * przed ekranem na to, żeby powiadomienie wyszło. Ten jeden czeka.
 *
 * Issue #234 opisuje, co się dzieje z listem, którego EmailLabs nie przyjmie:
 * `TransportException`, `--tries=3`, po ~6 minutach wiersz w `failed_jobs`
 * i cisza. Przy powiadomieniu to jest zła, ale znośna cena. Tutaj cena jest
 * inna: moderator kliknąłby „Wyślij", zobaczył „wysłano", oznaczył sprawę
 * jako załatwioną i przeszedł do następnej — a człowiek po drugiej stronie
 * nigdy nie dostałby odpowiedzi i nikt by o tym nie wiedział. Kolejka
 * zamieniłaby więc jedną cichą awarię (brak funkcji „odpisz") w drugą,
 * gorszą, bo z fałszywym potwierdzeniem.
 *
 * Dlatego ten list leci SYNCHRONICZNIE, w żądaniu HTTP, a wynik idzie
 * na ekran taki, jaki jest. `App\Domain\Contact\WyslijOdpowiedzNaWiadomosc`
 * łapie `TransportException` i zapisuje go przy odpowiedzi. Koszt: żądanie
 * trwa tyle, ile odpowiedź API EmailLabs (limit czasu z
 * `services.emaillabs.limit_czasu`). Kilka listów dziennie, jeden człowiek —
 * ten koszt jest zauważalny wyłącznie dla niego i to on go wybrał.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  NADAWCĄ JEST SERWIS, `Reply-To` PROWADZI NA SKRZYNKĘ, KTÓRĄ KTOŚ CZYTA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Prywatny adres moderatora nie ma prawa wyjść na zewnątrz — ani w `From`,
 * ani w `Reply-To`. Osoba pisała do serwisu i odpowiedź ma przyjść od
 * serwisu; poza tym moderator ma prawo do własnej skrzynki bez cudzej
 * korespondencji, a lista moderatorów nie jest informacją publiczną.
 *
 * `Reply-To` ustawiamy JAWNIE na `kuking.community.contact_email`
 * (`kontakt@kuking.pl`), mimo że dziś jest to ten sam adres co `From`.
 * Powód: te dwie wartości są w konfiguracji NIEZALEŻNE
 * (`MAIL_FROM_ADDRESS` i `KUKING_CONTACT_EMAIL`) i pierwszego dnia, w którym
 * ktoś ustawi nadawcę na adres techniczny, `Reply-To` jest tym, co decyduje,
 * czy odpowiedź człowieka dotrze do skrzynki, którą ktokolwiek otwiera.
 * Zasada „na list z Kuking musi się dać odpisać" ma już własny test
 * (`NadawcaPocztyNieJestNoreplyTest`); tutaj ma dodatkowo dokąd trafić.
 *
 * ODPOWIEDŹ CZŁOWIEKA WRACA NA SKRZYNKĘ, NIE DO PANELU — I TO JEST GRANICA
 * TEJ FUNKCJI, ŚWIADOMIE POSTAWIONA. Serwis nie odbiera poczty (nie ma
 * webhooka przychodzącego ani IMAP-a), więc każda dalsza wymiana zdań dzieje
 * się w skrzynce `kontakt@kuking.pl`. Panel pokazuje to, co wyszło Z NIEGO —
 * i nie udaje, że jest pełnym wątkiem. Uzasadnienie: D-058.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CZEGO W LIŚCIE NIE MA: CYTATU CAŁEJ ORYGINALNEJ WIADOMOŚCI
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Kuszące i standardowe w każdym systemie zgłoszeń, a tu ryzykowne. Adres
 * gościa nie jest przez nikogo weryfikowany — człowiek wpisuje go ręcznie
 * i potrafi się pomylić o jedną literę. Cytat znaczyłby wtedy, że pod obcy
 * adres idzie zdanie w rodzaju „nie mogę się zalogować, mój adres to …,
 * mieszkam z siostrą, która ma to samo nazwisko".
 *
 * Wysyłamy więc DATĘ i RODZAJ wiadomości — tyle, żeby człowiek rozpoznał
 * własną sprawę po dwóch tygodniach, i nie więcej. Kto potrzebuje przypomnieć
 * treść, przepisze z niej jedno zdanie własnymi słowami w odpowiedzi.
 */
class OdpowiedzNaWiadomosc extends Mailable
{
    use SerializesModels;

    public function __construct(
        private readonly ContactMessage $wiadomosc,
        private readonly string $tresc,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Nadawca WPROST z konfiguracji serwisu, nie z globalnego
            // domyślnego ustawienia mailera. Różnica jest widoczna dopiero
            // wtedy, gdy ktoś kiedyś wyśle ten list innym mailerem albo
            // z kontekstu, w którym Laravel domyślnego nadawcy nie dołoży —
            // i wtedy prywatny adres moderatora byłby jedynym kandydatem
            // na `From`, bo to on jest zalogowany.
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            replyTo: [new Address(
                (string) config('kuking.community.contact_email'),
                'Kuking.pl',
            )],
            // Temat mówi, czego dotyczy, i nie udaje numeru sprawy — ta
            // kolejka świadomie nie ma numerów spraw (D-045: nie ma tu
            // decyzji, od której da się odwołać).
            subject: 'Odpowiedź na Twoją wiadomość do Kuking',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.odpowiedz-na-wiadomosc',
            with: [
                'tresc' => $this->tresc,
                'napisanaKiedy' => $this->wiadomosc->created_at,
                'rodzaj' => $this->wiadomosc->rodzajLabel(),
                'adresKontaktowy' => (string) config('kuking.community.contact_email'),
            ],
        );
    }
}
