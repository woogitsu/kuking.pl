<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\RegistrationInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * „Zakładanie konta w Kuking" — wiadomość dla adresu BEZ konta (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TA WIADOMOŚĆ JEST INNA OD WSZYSTKICH POZOSTAŁYCH W SERWISIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wszystkie inne idą do KOGOŚ, kto ma u nas konto. Ta idzie na adres, na
 * którym konta NIE MA — więc po drugiej stronie może siedzieć osoba, która
 * nigdy o Kuking nie słyszała i o nic nie prosiła (ktoś pomylił się przy
 * przepisywaniu adresu albo wpisał go złośliwie).
 *
 * Stąd trzy twarde reguły treści, wypisane też w widoku:
 *
 *  1. ZERO ALARMU. Ani słowa o „próbie wejścia na Twoje konto" i ani słowa
 *     o bezpieczeństwie. Konta nie ma, więc nic nikomu nie grozi — a zdanie
 *     sugerujące zagrożenie wysłane do osoby 60+, która o nic nie prosiła,
 *     jest samo w sobie szkodą (i wygląda dokładnie jak phishing, o którym
 *     ostrzega ją bank).
 *  2. ZERO AKCJI DO WYKONANIA dla kogoś, kto o to nie prosił. Nie ma
 *     „potwierdź", „odrzuć", „zgłoś" ani odnośnika do wypisania się. Jedno
 *     zdanie: nic nie trzeba robić.
 *  3. ZERO PONAGLANIA. Nie mówimy „zostało 15 minut" — termin jest podany
 *     jako fakt, a nie jako presja.
 *
 * `ShouldQueue` z tego samego powodu co przy `LinkDoLogowania` (audyt W3-13
 * i D-056): awaria poczty nie ma prawa zamienić udanej czynności w błąd 500,
 * a tutaj dochodzi powód mocniejszy — formularz musi odpowiadać IDENTYCZNIE
 * dla adresu z kontem i bez konta. Wysyłka w żądaniu znaczyłaby, że przy
 * niedziałającej poczcie wywraca się tylko jedna z dwóch dróg, czyli sam kod
 * odpowiedzi mówi, czy konto istnieje.
 *
 * Kosztem jest to, że token W POSTACI JAWNEJ przechodzi przez payload zadania
 * w tabeli `jobs` (a przy nieudanej wysyłce zostaje w `failed_jobs`). Ta sama,
 * świadomie przyjęta własność co przy resecie hasła i przy linku do logowania.
 * Bramką pozostaje to, że `registration_invites` trzyma WYŁĄCZNIE skrót.
 *
 * Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
 */
final class ZaproszenieDoZalozeniaKonta extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly ?Carbon $wygasa = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Starsze zadania z datą null także muszą mieć aktualne zaproszenie w bazie. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $invite = RegistrationInvite::znajdzPoTokenie($this->token);

        return $invite !== null
            && $invite->email === $notifiable->routeNotificationFor('mail', $this)
            && $invite->jestWazne()
            && (($this->wygasa ?? null) === null || $this->wygasa->isFuture());
    }

    /**
     * Kolejka `high` (audyt B8-06): ten list wpuszcza człowieka na konto
     * i ma krótki termin ważności, więc nie staje w FIFO za podsumowaniem
     * tygodnia na `default`. Worker czyta `high` pierwszą (`docker/entrypoint.sh`,
     * pilnuje `UmowaKolejkiTest`).
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'high'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            /*
             * TEMAT BEZ SŁOWA „LINK" I BEZ TRYBU ROZKAZUJĄCEGO.
             *
             * „Dokończ zakładanie konta" brzmi jak polecenie wydane komuś, kto
             * o nic nie prosił. „Zakładanie konta w Kuking" mówi, o czym jest
             * ta wiadomość, i nie każe nikomu niczego.
             */
            ->subject('Zakładanie konta w Kuking')
            ->view('mail.zaproszenie-do-zalozenia-konta', [
                'linkUrl' => route('zaproszenie.pokaz', ['token' => $this->token]),
                'waznoscTekst' => $this->waznosc(),
            ]);
    }

    /**
     * Ważność zaproszenia po ludzku, BEZ podawania godziny zegarowej.
     *
     * Godziny świadomie nie drukujemy — ten sam powód co w `LinkDoLogowania`
     * i `UstawienieNowegoHasla`: `config/app.php` ma na sztywno
     * `'timezone' => 'UTC'`, więc „działa do 09:15" pokazywałoby czas
     * przesunięty o dwie godziny względem zegara w polskiej kuchni. Czas
     * TRWANIA jest na tę pomyłkę odporny.
     */
    private function waznosc(): string
    {
        $invite = RegistrationInvite::znajdzPoTokenie($this->token);
        if ($invite === null) {
            return 'tylko do terminu ustalonego przy zamówieniu';
        }

        $expiry = $invite->expires_at;
        if (($this->wygasa ?? null) !== null) {
            $expiry = $expiry->min($this->wygasa);
        }
        $minutes = max(0, (int) $invite->created_at->diffInMinutes($expiry));

        return (match (true) {
            $minutes === 60 => 'przez godzinę',
            $minutes === 1440 => 'przez dobę',
            $minutes > 0 && $minutes % 1440 === 0 => 'przez '.intdiv($minutes, 1440).' dni',
            $minutes > 0 && $minutes % 60 === 0 => 'przez '.intdiv($minutes, 60).' godz.',
            default => "przez {$minutes} min.",
        }).' od chwili zamówienia';
    }
}
