<?php

declare(strict_types=1);

namespace App\Providers;

use App\Poczta\BrakKonfiguracjiEmailLabs;
use App\Poczta\TransportEmailLabs;
use App\Poczta\ZapiszNieudanyList;
use App\Support\DozwolonyHostApi;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
     * Jedyne hosty, którym wolno dać klucze EmailLabs i treść listu (#991).
     * Starsze API `api.emaillabs.net.pl` (v1) ma inny kształt żądania, więc
     * tego transportu i tak by nie przyjęło — na liście go nie ma.
     *
     * @var list<string>
     */
    public const HOSTY_API = ['api.emaillabs.io'];

    /** Jedyna ścieżka, pod którą transport wysyła list (D-250). */
    public const SCIEZKA_API = '#^/v2\\.1/email$#';

    /**
     * Sekundy. Wysyłka idzie z workera kolejki, więc czekanie nie blokuje
     * nikomu strony — ale zawieszone połączenie nie może trzymać workera
     * w nieskończoność. To jest dokładnie ta awaria, którą zmierzyliśmy przy
     * SMTP: zadanie w `RUNNING` bez końca.
     */
    public const LIMIT_CZASU = 15;

    public function boot(): void
    {
        // Odmowa przed rozpoczęciem obsługi żądań lub zadań workera.
        // Sprawdzamy transport, nie nazwę mailera: SES może mieć alias albo
        // być składnikiem failover/roundrobin. Uśpiony SES niczego nie blokuje.
        $this->validateSelectedSesMailer((string) config('mail.default'));

        foreach (['ses', 'ses-v2'] as $driver) {
            Mail::extend($driver, function (array $config): TransportInterface {
                $this->validateSesConfiguration($config);

                // Osobny manager bez rozszerzeń woła fabrykę Laravela,
                // nie tę samą funkcję ponownie. Nie kopiujemy klienta SDK.
                return (new MailManager($this->app))->createSymfonyTransport($config);
            });
        }

        Mail::extend('emaillabs', function (array $konfiguracja): TransportInterface {
            return $this->transport($konfiguracja);
        });

        // ŚLAD PO LIŚCIE, KTÓRY PRZEPADŁ (issue #234, D-062).
        //
        // Rejestracja jest TUTAJ, a nie w `AppServiceProvider`, z tego samego
        // powodu co cała ta klasa: poczta ma własne miejsce. `Queue::failing`
        // to słuchacz `JobFailed`, czyli chwili, w której worker odkłada
        // zadanie do `failed_jobs` — po trzeciej próbie, a nie po pierwszej.
        //
        // Słuchacz NIE MA PRAWA RZUCIĆ: leci przed tym, który zapisuje wiersz
        // w `failed_jobs` (`WorkCommand::logFailedJob`, rejestrowany dopiero
        // przy starcie `queue:work`), więc jego wyjątek zabrałby diagnostyce
        // ostatnią rzecz, jaka po awarii zostaje. Całość `ZapiszNieudanyList`
        // jest z tego powodu w `try`.
        Queue::failing(static function (JobFailed $zdarzenie): void {
            app(ZapiszNieudanyList::class)($zdarzenie);
        });
    }

    /** @param list<string> $visited */
    private function validateSelectedSesMailer(string $name, array $visited = []): void
    {
        if (in_array($name, $visited, true)) {
            return;
        }

        $config = (array) config("mail.mailers.{$name}", []);
        $driver = $config['transport'] ?? null;

        if (in_array($driver, ['ses', 'ses-v2'], true)) {
            $this->validateSesConfiguration($config);
        } elseif (in_array($driver, ['failover', 'roundrobin'], true)) {
            foreach ($config['mailers'] ?? [] as $child) {
                $this->validateSelectedSesMailer((string) $child, [...$visited, $name]);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function validateSesConfiguration(array $config): void
    {
        // Taką samą kolejność scalania stosuje MailManager. Puste jawne
        // nadpisanie NIE wraca do wartości wspólnej ani do łańcucha SDK.
        $config = array_merge((array) config('services.ses', []), $config);

        foreach (['key' => 'MAIL_SES_KEY', 'secret' => 'MAIL_SES_SECRET'] as $key => $variable) {
            if (! is_string($config[$key] ?? null) || empty($config[$key]) || trim($config[$key]) === '') {
                throw new RuntimeException("Ustaw {$variable} dla poczty SES. Zmienne AWS_* należą do Cloudflare R2 i nie zastępują poświadczeń poczty.");
            }
        }
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

        // Sam HTTPS nie wystarcza: klucze i treść listu szłyby na DOWOLNY
        // host z tej zmiennej. Odmowa przy budowie, nie przy pierwszym liście.
        $powod = DozwolonyHostApi::powod($adresApi, self::HOSTY_API, self::SCIEZKA_API);

        if ($powod !== null) {
            throw BrakKonfiguracjiEmailLabs::obcyHostApi('EMAILLABS_ENDPOINT', $powod);
        }

        $dziennik = $this->app->make('log');

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
            // Dziennik podajemy, bo `LogManager` jest zgodny z PSR-3 — ale
            // sprawdzamy to, zamiast zakładać: kontener oddaje tu `mixed`.
            logger: $dziennik instanceof LoggerInterface ? $dziennik : null,
        );
    }
}
