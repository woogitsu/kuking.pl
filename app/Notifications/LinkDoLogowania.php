<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
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
 * Kosztem jest to, że token W POSTACI JAWNEJ przechodzi przez payload
 * zadania w tabeli `jobs` (a przy nieudanej wysyłce zostaje w `failed_jobs`).
 * Jest to ta sama, świadomie przyjęta własność co przy resecie hasła, gdzie
 * Laravel serializuje token dokładnie tak samo. Wiersz `jobs` żyje sekundy;
 * wpis w `failed_jobs` przeżywa dłużej, ale niesie token, który i tak
 * przestaje działać po `login_link.waznosc_minut` (30 minut) — a listu,
 * którego wysyłka padła, nikt nie dostał. Bramką pozostaje to, że
 * `login_link_tokens` trzyma WYŁĄCZNIE skrót.
 */
final class LinkDoLogowania extends Notification implements ShouldQueue
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
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Twój link do zalogowania w Kuking')
            ->view('mail.link-do-logowania', [
                'linkUrl' => route('login.link.confirm', ['token' => $this->token]),
                'waznoscTekst' => self::waznosc(),
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
     */
    private static function waznosc(): string
    {
        $minut = max(1, (int) config('kuking.login_link.waznosc_minut'));

        return match (true) {
            $minut === 60 => 'przez godzinę',
            $minut === 30 => 'przez pół godziny',
            default => "przez {$minut} min.",
        };
    }
}
