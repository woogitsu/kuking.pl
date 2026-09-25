<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\AdresKanoniczny;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * „Ustaw nowe hasło" — jedyna droga powrotu dla kogoś, kto wypadł z konta.
 *
 * Dlaczego własna klasa, a nie samo tłumaczenie (issue #79):
 *
 * Domyślne powiadomienie Laravela składa treść z `MailMessage` i renderuje
 * ją szablonem `notifications::email`. Da się to przetłumaczyć przez
 * `lang/pl.json` (i jest przetłumaczone — na wypadek, gdyby ktoś kiedyś
 * wysłał zwykły `MailMessage`), ale zostaje wygląd: nagłówek z nazwą
 * aplikacji, mały tekst, mały przycisk, stopka o prawach autorskich.
 *
 * Dla osoby po sześćdziesiątce list z prośbą o kliknięcie w link do zmiany
 * hasła jest dokładnie tym, przed czym ostrzega ją bank. Musi wyglądać jak
 * reszta Kuking i tłumaczyć, skąd się wziął — a nie jak formularz z obcego
 * systemu. Stąd własny widok, wzorowany na `mail/data-export-ready`.
 *
 * Bez gry słowem „kuKING" — D-009 zabrania jej w komunikatach technicznych.
 */
final class UstawienieNowegoHasla extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    /*
     * KOLEJKA, NIE WYSYŁKA W ŻĄDANIU (audyt W3-13).
     *
     * Bez tego awaria serwera poczty zamieniała UDANĄ czynność w błąd 500.
     * Przy rejestracji wyglądało to tak: konto i profil zapisują się
     * w transakcji, transakcja się zatwierdza, potem leci `event(new
     * Registered($user))`, a listener Laravela wysyła list SYNCHRONICZNIE.
     * Wyjątek z SMTP przewracał więc żądanie PO utworzeniu konta — człowiek
     * widział błąd, próbował jeszcze raz i słyszał „na ten adres jest już
     * założone konto". Wyglądało to jak zgubiona rejestracja, choć konto
     * istniało.
     *
     * Z kolejką list jest zadaniem: nieudana wysyłka ponawia się i zostawia
     * ślad w `failed_jobs`, a czynność człowieka kończy się tak, jak
     * powinna. Kolejka to `database`, ta sama co reszta — nic nowego
     * nie dokładamy.
     */
    use Queueable;

    public function __construct(string $token, private readonly ?Carbon $wygasa = null)
    {
        parent::__construct($token);
    }

    /** Broker sprawdza także zużycie i zastąpienie tokenu; sama data nie wystarcza. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! Password::tokenExists($notifiable, $this->token)) {
            return false;
        }

        $created = $this->createdAt($notifiable);

        return $created !== null && $this->expiresAt($created)->isFuture();
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ustaw nowe hasło do Kuking')
            ->view('mail.nowe-haslo', [
                // `resetUrl()` z klasy nadrzędnej, żeby link powstawał
                // dokładnie tak samo jak w Laravelu — łącznie z hakiem
                // `ResetPassword::createUrlUsing()`, gdyby kiedyś był
                // potrzebny (np. adres na innej domenie).
                //
                // W `AdresKanoniczny`, bo host tego linku nie ma prawa
                // zależeć od nagłówków żądania (S2, D-071). Na produkcji nic
                // to nie zmienia — to powiadomienie i tak idzie kolejką, więc
                // adres powstaje w workerze, gdzie żądania HTTP nie ma
                // i Laravel bierze korzeń z `APP_URL`. Zmienia to natomiast
                // gwarancję: przestaje ona zależeć od tego, że kolejka jest
                // asynchroniczna.
                'linkUrl' => AdresKanoniczny::zbuduj(fn (): string => $this->resetUrl($notifiable)),
                'waznoscTekst' => $this->waznosc($notifiable),
                'displayName' => $notifiable->profile?->display_name,
            ]);
    }

    /**
     * Ważność linku po ludzku, bez podawania godziny zegarowej.
     *
     * Godziny świadomie NIE drukujemy: `config/app.php` ma na sztywno
     * `'timezone' => 'UTC'` (zmienna APP_TIMEZONE nie jest tam czytana),
     * więc „link działa do 09:15" pokazywałoby czas przesunięty o dwie
     * godziny względem zegara w polskiej kuchni. Czas trwania jest odporny
     * na tę pomyłkę.
     */
    private function waznosc(User $notifiable): string
    {
        $created = $this->createdAt($notifiable);
        if ($created === null) {
            return 'tylko do terminu ustalonego przy zamówieniu';
        }

        $minutes = max(0, (int) $created->diffInMinutes($this->expiresAt($created)));

        return ($minutes === 60 ? 'przez godzinę' : "przez {$minutes} min.").' od chwili zamówienia';
    }

    private function createdAt(User $notifiable): ?Carbon
    {
        $broker = config('auth.passwords.'.config('auth.defaults.passwords'));
        $created = DB::connection($broker['connection'] ?? null)
            ->table($broker['table'])
            ->where('email', $notifiable->getEmailForPasswordReset())
            ->value('created_at');

        return $created === null ? null : Carbon::parse($created);
    }

    private function expiresAt(Carbon $created): Carbon
    {
        $expiry = $created->copy()->addMinutes((int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire', 60,
        ));

        // Starsze zadanie nie ma daty: odtwarzamy ją z wystawienia w bazie,
        // nigdy z czasu wykonania kolejki. Nowe zachowuje także własny termin.
        return ($this->wygasa ?? null) === null ? $expiry : $expiry->min($this->wygasa);
    }
}
