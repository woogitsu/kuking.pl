<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Moderation\AdresZgloszenia;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Potwierdzenie odbioru zgłoszenia nielegalnej treści (DSA art. 16 ust. 4).
 *
 * Przepis wymaga potwierdzenia BEZ ZBĘDNEJ ZWŁOKI, gdy zgłaszający podał dane
 * kontaktowe. To nie jest uprzejmość — to jest jedyny sygnał, że zgłoszenie
 * w ogóle do nas doszło. Bez niego człowiek, który właśnie zgłosił zdjęcie
 * swojego dziecka, nie wie, czy kliknięcie cokolwiek zrobiło.
 *
 * Kolejkowane, jak reszta listów transakcyjnych: awaria serwera poczty nie
 * może przewrócić żądania po zapisaniu zgłoszenia (audyt W3-13).
 *
 * ZNACZNIK `receipt_sent_at` MÓWI „POTWIERDZILIŚMY", NIE „ZLECILIŚMY" (#2218).
 * `ZglosNielegalnaTresc::potwierdzOdbior()` ustawia go razem ze zleceniem
 * listu, więc przy kolejce bazodanowej stoi on już przy WPISIE do kolejki.
 * Gdy dostawca poczty padnie później, w workerze, samo zlecenie jest za nami,
 * a list nie wyszedł — i baza kłamałaby „wysłano". Dlatego wyczerpanie prób
 * (`failed()`) zdejmuje znacznik: sprawa wraca do zaległych, a dosyłka
 * (`kuking:dosylaj-potwierdzenia-zgloszen`) obejmuje także zgłoszenia bez
 * konta, jeśli podano adres. Sam wpis w `failed_jobs` zostaje jako ślad.
 *
 * PONAWIANIE MA SUFIT. Dosyłka chodzi co godzinę, a adres podał ktoś spoza
 * serwisu — adres, który dostawca odrzuca zawsze, dawałby co godzinę kolejne
 * próby i wpisy w `failed_jobs` przez całe życie sprawy. Dlatego `failed()`
 * liczy porażki listu tej sprawy (cache, `PAMIEC_PORAZEK_DNI`), a dosyłka po
 * `LIMIT_PORAZEK_LISTU` porażkach przestaje sama ponawiać list, mówi to
 * w wyniku komendy i alarmuje operatora (`AlarmSufituPotwierdzen`). Sprawa
 * zostaje zaległością i dalej jest liczona.
 */
final class PotwierdzenieZgloszeniaNielegalnejTresci extends Notification implements ShouldQueue
{
    use Queueable;

    /** Po tylu ostatecznych porażkach listu dosyłka przestaje go sama ponawiać. */
    public const LIMIT_PORAZEK_LISTU = 3;

    private const PAMIEC_PORAZEK_DNI = 30;

    public function __construct(private readonly Report $zgloszenie) {}

    public static function kluczPorazek(string $idZgloszenia): string
    {
        return 'kuking:potwierdzenie-dsa:porazki-listu:'.$idZgloszenia;
    }

    public static function ponawianieWstrzymane(string $idZgloszenia): bool
    {
        return (int) Cache::get(self::kluczPorazek($idZgloszenia), 0) >= self::LIMIT_PORAZEK_LISTU;
    }

    /**
     * Ostateczna porażka listu (próby wyczerpane) — patrz nagłówek klasy.
     * Zdejmuje znacznik tylko z tej sprawy i nigdy nie rzuca: wyjątek tutaj
     * przykryłby prawdziwą przyczynę porażki w `failed_jobs`.
     */
    public function failed(Throwable $awaria): void
    {
        try {
            Report::query()
                ->whereKey($this->zgloszenie->getKey())
                ->whereNotNull('receipt_sent_at')
                ->update(['receipt_sent_at' => null]);
        } catch (Throwable $blad) {
            report($blad);
        }

        try {
            $klucz = self::kluczPorazek((string) $this->zgloszenie->getKey());
            Cache::put($klucz, (int) Cache::get($klucz, 0) + 1, now()->addDays(self::PAMIEC_PORAZEK_DNI));
        } catch (Throwable $blad) {
            report($blad);
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Numer zgłoszenia w temacie: człowiek ma się móc na niego powołać,
        // a my — odnaleźć sprawę, gdy napisze ponownie.
        $numer = $this->zgloszenie->numer_sprawy;

        return (new MailMessage)
            ->subject("Przyjęliśmy Twoje zgłoszenie (nr {$numer})")
            ->greeting('Dzień dobry.')
            ->line('Dostaliśmy Twoje zgłoszenie treści, którą uważasz za niezgodną z prawem.')
            ->line("Numer sprawy: **{$numer}**. Warto go zachować.")
            // Wartość z publicznego formularza — jako tekst, nie Markdown (#1636).
            ->line(AdresZgloszenia::liniaListu($this->zgloszenie->target_url))
            ->line('Sprawdzimy to i odpiszemy Ci z decyzją. Napiszemy także wtedy, gdy '
                .'uznamy, że treść zostaje — razem z powodem i z informacją, co możesz '
                .'zrobić dalej, jeśli się z nami nie zgadzasz.')
            ->line('Ten list jest potwierdzeniem odbioru. Nie musisz na niego odpisywać.')
            ->salutation('Zespół Kuking.pl');
    }
}
