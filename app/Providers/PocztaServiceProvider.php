<?php

declare(strict_types=1);

namespace App\Providers;

use App\Poczta\BrakKonfiguracjiEmailLabs;
use App\Poczta\TransportEmailLabs;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Rejestruje sterownik poczty `emaillabs`, żeby `MAIL_MAILER=emaillabs`
 * po prostu działało.
 *
 * DLACZEGO OSOBNY PROVIDER, A NIE LINIJKA W `AppServiceProvider`
 * `AppServiceProvider::boot()` trzyma dziś dwie rzeczy z zupełnie innych
 * światów (renderowanie `PostTooLargeException` i odmianę rzeczowników
 * w walidacji) i każda ma nad sobą akapit wyjaśnienia. Trzecia, o poczcie,
 * zrobiłaby z tego pliku skrzynkę na wszystko. Poczta ma własny katalog
 * (`app/Poczta`), własną komendę i własny runbook — niech ma też własne
 * miejsce rejestracji.
 *
 * CO TO ZMIENIA DLA RESZTY KODU: NIC.
 * `Mail::`, wszystkie `Notification`, kolejka i `kuking:sprawdz-poczte`
 * chodzą przez `MailManager` i nie wiedzą, czym idzie list. Zmiana dostawcy
 * jest zmianą zmiennej `MAIL_MAILER`, nie zmianą kodu domenowego — tak samo
 * jak przy `smtp`, `ses` czy `resend`.
 */
class PocztaServiceProvider extends ServiceProvider
{
    /** Adres wysyłkowy API EmailLabs (OpenAPI: `servers[0].url` + `/v2.1/email`). */
    public const ADRES_API = 'https://api.emaillabs.io/v2.1/email';

    /**
     * Sekundy. Wysyłka idzie z workera kolejki, więc czekanie nie blokuje
     * nikomu strony — ale zawieszone połączenie nie może trzymać workera
     * w nieskończoność. To jest dokładnie ta awaria, którą zmierzyliśmy przy
     * SMTP: zadanie w `RUNNING` bez końca.
     */
    public const LIMIT_CZASU = 15;

    public function boot(): void
    {
        Mail::extend('emaillabs', function (array $konfiguracja): TransportInterface {
            return $this->transport($konfiguracja);
        });
    }

    /**
     * Budowa transportu — i JEDYNE miejsce, w którym sprawdzamy komplet
     * konfiguracji.
     *
     * Rzucenie tutaj jest celowe: `App\Support\Poczta::dziala()` buduje
     * transport, żeby odpowiedzieć na pytanie „czy poczta wychodzi", więc pusty
     * klucz jest widoczny natychmiast — na ekranie „Nie pamiętam hasła"
     * i w `kuking:sprawdz-poczte` — zamiast czekać na pierwsze zadanie
     * w kolejce.
     *
     * @param  array<string, mixed>  $konfiguracja  wpis z `config/mail.php`
     */
    private function transport(array $konfiguracja): TransportEmailLabs
    {
        /** @var array<string, mixed> $uslugi */
        $uslugi = (array) config('services.emaillabs', []);

        // Wpis w `config/mail.php` wygrywa z `config/services.php`. Dzięki temu
        // da się kiedyś postawić DRUGI mailer na tym samym kluczu, ale innym
        // koncie SMTP — osobny strumień dla digestu, o który prosi
        // `docs/decyzje/POCZTA.md` §5 pkt 3 — bez dotykania kodu.
        $wartosc = static fn (string $klucz): mixed => $konfiguracja[$klucz] ?? $uslugi[$klucz] ?? null;

        $kluczAplikacji = trim((string) ($wartosc('key') ?? ''));
        $kluczAutoryzacji = trim((string) ($wartosc('secret') ?? ''));
        $kontoSmtp = trim((string) ($wartosc('smtp_account') ?? ''));
        $adresApi = trim((string) ($wartosc('endpoint') ?? self::ADRES_API));

        if ($kluczAplikacji === '') {
            throw BrakKonfiguracjiEmailLabs::brakujeZmiennej(
                'EMAILLABS_APP_KEY',
                'klucz aplikacji z panelu EmailLabs, nagłówek Application-Key',
            );
        }

        if ($kluczAutoryzacji === '') {
            throw BrakKonfiguracjiEmailLabs::brakujeZmiennej(
                'EMAILLABS_SECRET_KEY',
                'klucz autoryzacyjny z panelu EmailLabs, nagłówek Authorization',
            );
        }

        if ($kontoSmtp === '') {
            throw BrakKonfiguracjiEmailLabs::brakujeZmiennej(
                'EMAILLABS_SMTP_ACCOUNT',
                'nazwa konta SMTP w panelu, w kształcie 1.nazwa.smtp',
            );
        }

        if (! str_starts_with($adresApi, 'https://')) {
            throw BrakKonfiguracjiEmailLabs::zlyAdresApi('EMAILLABS_ENDPOINT');
        }

        return new TransportEmailLabs(
            kluczAplikacji: $kluczAplikacji,
            kluczAutoryzacji: $kluczAutoryzacji,
            kontoSmtp: $kontoSmtp,
            adresApi: $adresApi,
            limitCzasu: max(1, (int) ($wartosc('timeout') ?? self::LIMIT_CZASU)),
            sledzenieOdnosnikow: (bool) ($wartosc('tracking') ?? false),
            // Dyspozytor zdarzeń zostaje pusty CELOWO: `AbstractTransport`
            // chce dyspozytora PSR-14, a Laravel ma własny (`Illuminate`),
            // i to Laravel — nie Symfony — rozgłasza `MessageSending`
            // i `MessageSent`. Tak samo robią wbudowane sterowniki Laravela.
            // Dziennik podajemy, bo `LogManager` jest zgodny z PSR-3.
            logger: $this->app->make('log'),
        );
    }
}
