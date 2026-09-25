<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\LoginLinkToken;
use App\Models\User;
use App\Support\AdresKanoniczny;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * „Zaloguj się w Kuking" — list z jednorazowym linkiem (issue #25, D-056).
 *
 * Ten list ma trudniejsze zadanie niż „Ustaw nowe hasło": ma przekonać osobę,
 * której bank i telewizja od lat powtarzają „nie klikaj w linki z maili",
 * że akurat ten link wpuści ją na jej własne konto. Stąd — jak
 * w `UstawienieNowegoHasla` — własny widok zamiast domyślnego szablonu
 * Laravela: jedna kolumna, duży tekst, jeden przycisk, pełny adres do
 * przepisania i adres, pod którym siedzi człowiek.
 *
 * Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KOLEJKA — I DLACZEGO TOKEN PRZECHODZI PRZEZ NIĄ MIMO WSZYSTKO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `ShouldQueue` z tego samego powodu co przy `UstawienieNowegoHasla` (audyt
 * W3-13): awaria poczty nie ma prawa zamienić UDANEJ czynności w błąd 500.
 * Tutaj dochodzi drugi, mocniejszy powód — formularz musi odpowiadać
 * IDENTYCZNIE dla adresu z kontem i bez konta. Wysyłka w żądaniu znaczyłaby,
 * że przy niedziałającej poczcie tylko jedna z tych dwóch dróg się wywraca,
 * a to jest gotowa wyrocznia „kto ma konto w Kuking".
 *
 * Token przechodzi przez payload zadania w tabeli `jobs` (a przy nieudanej
 * wysyłce zostaje w `failed_jobs`) — od audytu A5-10 ZASZYFROWANY kluczem
 * aplikacji (`ShouldBeEncrypted`), a nie w postaci jawnej. Zrzut bazy albo
 * odczyt `failed_jobs` nie daje już gotowego wejścia na konto. Komendy, które
 * z tego ładunku czytają odbiorców, odszyfrowują go przez
 * `App\Domain\Kolejka\PolecenieZadania`. Bramką pozostaje też to, że
 * `login_link_tokens` trzyma WYŁĄCZNIE skrót, a token wygasa po
 * `login_link.waznosc_minut`.
 */
final class LinkDoLogowania extends Notification implements ShouldBeEncrypted, ShouldQueue
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

    /**
     * STRAŻNIK TERMINU PRZY WYKONANIU ZADANIA (#889).
     *
     * Kolejka potrafi się spóźnić. Bez tego sprawdzenia worker wysłałby
     * zaproszenie do kliknięcia w link, który już nie działa — a osoba 60+
     * uzna wtedy, że „znowu coś zepsuła". Wygasły, zużyty albo zastąpiony
     * kolejną prośbą link zatrzymuje list PRZED transportem. Nowego tokenu
     * nie wystawiamy i terminu nie przesuwamy: nowy link to nowa prośba
     * człowieka, z własnym limitem i budżetem.
     *
     * Budżetu nie oddajemy — miejsce zarezerwowała prośba i budżet liczy
     * PRÓBY (ta sama zasada co przy nieudanej wysyłce w `WyslijLinkDoLogowania`).
     *
     * Starsze zadania mogą mieć `wygasa === null`: wtedy termin bierzemy
     * z wiersza w bazie, nigdy z chwili wykonania zadania.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $wiersz = LoginLinkToken::znajdzPoTokenie($this->token);

        return $notifiable instanceof User
            && $wiersz !== null
            && (string) $wiersz->user_id === (string) $notifiable->getKey()
            && $this->termin($wiersz)?->isFuture() === true;
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

    /**
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Twój link do zalogowania w Kuking')
            ->view('mail.link-do-logowania', [
                // KANONICZNY KORZEŃ, NIE HOST Z ŻĄDANIA (S2, D-071). Ten link
                // DAJE SESJĘ, więc jest w całym serwisie tym jednym, dla
                // którego „host był na liście dozwolonych" nie wystarcza —
                // ma nie zależeć od nagłówków żądania w ogóle.
                'linkUrl' => AdresKanoniczny::zbuduj(
                    fn (): string => route('login.link.confirm', ['token' => $this->token]),
                ),
                'waznoscTekst' => $this->waznosc(),
                'displayName' => $notifiable->profile?->display_name,
            ]);
    }

    /**
     * Ważność linku po ludzku, BEZ podawania godziny zegarowej.
     *
     * Godziny świadomie nie drukujemy — ten sam powód co
     * w `UstawienieNowegoHasla`: `config/app.php` ma na sztywno
     * `'timezone' => 'UTC'`, więc „link działa do 09:15" pokazywałoby czas
     * przesunięty o dwie godziny względem zegara w polskiej kuchni. Czas
     * TRWANIA jest na tę pomyłkę odporny.
     *
     * Czas liczymy z TEGO linku, nie z konfiguracji (#889): list wysłany
     * z opóźnieniem mówi, ile naprawdę zostało, zamiast obiecywać pełne
     * pół godziny od chwili wysyłki.
     */
    private function waznosc(): string
    {
        $wiersz = LoginLinkToken::znajdzPoTokenie($this->token);
        $termin = $wiersz === null ? null : $this->termin($wiersz);
        if ($wiersz === null || $termin === null || $wiersz->created_at === null) {
            return 'tylko do terminu ustalonego przy zamówieniu';
        }

        $pelne = max(1, (int) round($wiersz->created_at->diffInSeconds($termin) / 60));
        $sekund = max(0, now()->diffInSeconds($termin, false));
        $zostalo = (int) round($sekund / 60);

        if ($zostalo >= $pelne - 1) {
            return (match ($pelne) {
                60 => 'przez godzinę',
                30 => 'przez pół godziny',
                default => "przez {$pelne} min.",
            }).' od chwili zamówienia';
        }

        return ($sekund < 60 ? 'jeszcze przez niecałą minutę' : "jeszcze przez około {$zostalo} min.")
            .' (list wyszedł z opóźnieniem, a czas liczy się od chwili zamówienia)';
    }

    /** Termin tego linku; przekazana data może go skrócić, nigdy wydłużyć. */
    private function termin(LoginLinkToken $wiersz): ?Carbon
    {
        $zBazy = $wiersz->expires_at;
        if ($zBazy === null) {
            return null;
        }

        return ($this->wygasa ?? null) === null ? $zBazy : $zBazy->min($this->wygasa);
    }
}
