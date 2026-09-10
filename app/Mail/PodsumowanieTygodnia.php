<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Digest\TrescDigestu;
use App\Models\CookedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Tygodniowe podsumowanie — jedyny list Kuking, którego nikt nie zamówił
 * kliknięciem tuż przed wysyłką (issue #11, `docs/DECISIONS.md` D-057).
 *
 * `ShouldQueue`, ZAWSZE. Harmonogram chodzi W TYM SAMYM PROCESIE PHP co
 * serwer WWW (`routes/console.php`, `Schedule::call()` — `proc_open` jest
 * wyłączone w `docker/php.ini`). Sto dwadzieścia wywołań API pocztowego
 * wykonanych wprost z zadania zablokowałoby pętlę harmonogramu na kilkanaście
 * minut, a przy jednej replice — także obsługę zwykłych żądań.
 *
 * TEMAT JEST KONKRETNY I ZMIENNY, bo od niego zależy, czy ktokolwiek ten
 * list otworzy (`docs/product/RETENTION_LOOPS.md` §4.1: „nigdy »Newsletter
 * Kuking #14«"). Kolejność wygrywania jest tu regułą redakcyjną, nie
 * upodobaniem: najpierw to, co dotyczy adresata WPROST, potem reszta.
 */
class PodsumowanieTygodnia extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public TrescDigestu $tresc) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->temat(),
            // ADRES, NA KTÓRY DA SIĘ ODPISAĆ, I ODPOWIEDZI CZYTA CZŁOWIEK
            // (`docs/product/RETENTION_LOOPS.md` §4: „to najtańszy kanał
            // badań użytkowników, jaki mamy"). Nadawcą jest gospodarz
            // z imienia — nazwa nadawcy stoi w `config/mail.php` i bierze
            // imię z `config('kuking.community.host_name')`, więc nie ma jej
            // tutaj drugi raz.
            replyTo: [config('kuking.community.contact_email')],
        );
    }

    /**
     * `List-Unsubscribe` — przycisk „wypisz się" WEWNĄTRZ Gmaila i Outlooka.
     *
     * To nie jest ozdoba. Ten nagłówek jest najkrótszą drogą wyjścia, jaka
     * istnieje: człowiek klika przy nadawcy, nie szuka odnośnika na dole
     * listu. Filtry antyspamowe traktują jego obecność jako sygnał, że
     * nadawca jest w porządku — a jego brak zwiększa szansę, że ktoś zamiast
     * wypisania kliknie „to jest spam" i popsuje dostarczalność wszystkim
     * (`docs/decyzje/POCZTA.md` §3).
     *
     * `List-Unsubscribe-Post` (RFC 8058) mówi klientowi pocztowemu, że wolno
     * wysłać puste `POST` bez pytania człowieka o potwierdzenie. Dlatego
     * trasa przyjmuje obie metody i jest wyjęta spod CSRF — patrz
     * `routes/web.php` i `bootstrap/app.php`.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.OdnosnikWypisania::dla($this->tresc->odbiorca).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.podsumowanie-tygodnia',
            // WERSJA TEKSTOWA ZAWSZE (issue #11, zakres). Nie dla ozdoby:
            // część klientów pocztowych u osób 50+ (i część filtrów) czyta
            // tylko ją, a list bez wersji tekstowej jest sam w sobie sygnałem
            // spamowym.
            text: 'mail.podsumowanie-tygodnia-tekst',
            with: [
                'tresc' => $this->tresc,
                'imie' => $this->tresc->odbiorca->displayName(),
                'gospodarz' => config('kuking.community.host_name'),
                'wypisz' => OdnosnikWypisania::dla($this->tresc->odbiorca),
            ],
        );
    }

    /**
     * Temat listu.
     *
     * BEZ FORM ZAKŁADAJĄCYCH RODZAJ — i to jest jedyny powód, dla którego nie
     * używamy gotowca „{imię} ugotowała Twój rosół" z `docs/brand/
     * COPY_STYLE.md` §6 wprost. Tamten napis powstał jako przykład
     * powiadomienia o KONKRETNEJ osobie, o której wiadomo, że jest kobietą.
     * Tutaj kucharzem bywa ktokolwiek, a serwis nie pyta o płeć i nigdy nie
     * będzie (`resources/legal/polityka-prywatnosci.md` §2: „Nie pytamy
     * o: […] płeć"). „Ugotował" do Haliny i „ugotowała" do Andrzeja to
     * dokładnie ta wpadka, przed którą ostrzega `AGENTS.md` §11.
     *
     * Rozwiązaniem jest konstrukcja bez czasownika w czasie przeszłym:
     * „Twój rosół u Haliny w kuchni". Zostaje imię, zostaje nazwa potrawy
     * (`docs/product/RETENTION_LOOPS.md` §3.3 pkt 1 i 2), znika rodzaj.
     */
    private function temat(): string
    {
        $wykonanie = $this->tresc->wykonania[0] ?? null;

        if ($wykonanie instanceof CookedEvent) {
            $kto = $wykonanie->user?->displayName();
            $co = Str::limit((string) $wykonanie->recipe?->title, 40);

            if ($kto !== null && $co !== '') {
                return "Twój przepis na {$co} — u {$kto} w kuchni";
            }
        }

        $obserwujacy = $this->tresc->nowiObserwujacy[0] ?? null;

        if ($obserwujacy !== null) {
            // „Obserwuje" jest w trzeciej osobie i nie odmienia się przez
            // rodzaj — w odróżnieniu od „zaczęła/zaczął obserwować".
            return $obserwujacy->displayName().' obserwuje teraz Twoje gotowanie';
        }

        // Gotowy napis z `docs/brand/COPY_STYLE.md` §6, wiersz „temat
        // digestu, ogólny". Nie parafrazujemy go.
        return 'Co się działo w Kuking w tym tygodniu';
    }
}
