<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\DozwolonyHostApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Usunięcie skasowanych zdjęć z cache Cloudflare (audyt G-03).
 *
 * PO CO, SKORO PLIK JUŻ SKASOWANY
 * Cloudflare wprost ostrzega w dokumentacji spójności R2: przy włączonym cache
 * na własnej domenie skasowany obiekt BYWA DALEJ SERWOWANY z cache aż do
 * wygaśnięcia albo wypchnięcia. Skasowanie pliku w buckecie nie jest więc
 * skasowaniem go z internetu.
 *
 * Dla miniatury w feedzie to niedogodność. Dla:
 *   * wymazania konta po karencji,
 *   * żądania usunięcia danych (RODO art. 17),
 *   * decyzji moderacyjnej o zdjęciu treści,
 *   * zdjęcia wgranego przez pomyłkę i natychmiast usuniętego,
 * to jest awaria prywatności — serwis mówi „skasowane", a plik nadal się
 * otwiera pod tym samym adresem.
 *
 * DLACZEGO OSOBNE ZADANIE, A NIE WYWOŁANIE W `KasujZdjecie`
 * Cudze API bywa niedostępne, a kasowanie zdjęcia nie może się przez to nie
 * udać — inaczej awaria Cloudflare zatrzymałaby wymazywanie kont. Kolejka daje
 * ponowienia z rosnącym odstępem, a nieudane czyszczenie ląduje w `failed_jobs`
 * zamiast zniknąć: kasowanie, o którym nikt nie wie, że się nie udało, jest
 * gorsze od kasowania, które jawnie padło.
 *
 * IDEMPOTENTNE: czyszczenie adresu, którego w cache nie ma, jest poprawną
 * operacją bez skutku. Można więc powtarzać bez zastanawiania się, czy już.
 */
class PurgePublicMediaCache implements ShouldQueue
{
    use Queueable;

    /**
     * Jedyny host, któremu wolno dać token czyszczenia (#991).
     * `CLOUDFLARE_PURGE_ENDPOINT` zmienia ścieżkę, nie dostawcę.
     *
     * @var list<string>
     */
    public const HOSTY = ['api.cloudflare.com'];

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** @param list<string> $adresy pełne, publiczne adresy wariantów */
    public function __construct(public array $adresy) {}

    public function handle(): void
    {
        $adresy = array_values(array_unique(array_filter($this->adresy)));

        if ($adresy === []) {
            return;
        }

        $zona = (string) config('kuking.media.cdn_purge.zone_id');
        $token = (string) config('kuking.media.cdn_purge.token');

        if ($zona === '' || $token === '') {
            // TEN WPIS SAM NIE WYSTARCZA I TRZEBA TO POWIEDZIEĆ WPROST.
            //
            // Brak konfiguracji jest normalny lokalnie i w testach, ale na
            // produkcji znaczy, że skasowane zdjęcia dalej się otwierają —
            // a wygląda to identycznie jak działające czyszczenie. Sam
            // `Log::warning` tego nie zamyka z dwóch powodów:
            //
            //   * kanał alarmowy `blad_webhook` ma w `config/logging.php`
            //     poziom `error` USTAWIONY NA SZTYWNO, więc ostrzeżenia nie
            //     przyjmuje w ogóle — wpis ląduje wyłącznie na stderr, wśród
            //     wszystkiego innego;
            //   * zadanie kończy się SUKCESEM, więc nie ma go w `failed_jobs`
            //     i żadna czujka go nie widzi.
            //
            // Trwałym sygnałem jest sonda `cdn` w `/health`
            // (`HealthController::sprawdzCzyszczenieCdn()`): jedno zdanie,
            // które nie gaśnie samo, dzwoni na webhook z odstępem i nie
            // wywraca ANI JEDNEGO kasowania zdjęcia. Ten wpis zostaje jako
            // ślad w dzienniku: mówi, ILU adresów dotyczyła konkretna,
            // pominięta próba — czego `/health` nie wie.
            Log::warning('Czyszczenie cache CDN pominięte — brak konfiguracji', [
                'adresow' => count($adresy),
            ]);

            return;
        }

        $adres = str_replace(
            '{zone}',
            $zona,
            (string) config('kuking.media.cdn_purge.endpoint'),
        );

        // Obcy host = zadanie pada, zanim token wyjdzie. Wyjątek, nie cichy
        // `return`: nieudane czyszczenie ma zostać w `failed_jobs`. Adresu
        // w komunikacie nie ma — bywa wklejany razem z tokenem.
        if (! DozwolonyHostApi::zgodny($adres, self::HOSTY)) {
            throw new \RuntimeException(
                'CLOUDFLARE_PURGE_ENDPOINT wskazuje host spoza Cloudflare — czyszczenia cache nie wysłano. '
                .'Dozwolone: '.implode(', ', self::HOSTY).'.',
            );
        }

        // Cloudflare przyjmuje najwyżej 30 adresów na żądanie.
        foreach (array_chunk($adresy, 30) as $partia) {
            $odpowiedz = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->post($adres, ['files' => $partia]);

            if ($odpowiedz->failed()) {
                // Wyjątek, nie `return false`: kolejka ma to ponowić, a po
                // wyczerpaniu prób zostawić ślad w `failed_jobs`.
                throw new \RuntimeException(
                    'Cloudflare odmówił czyszczenia cache: HTTP '.$odpowiedz->status(),
                );
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        // Ostatnia linia: po wyczerpaniu prób w logu musi zostać KTÓRYCH
        // adresów nie udało się wyczyścić — inaczej nie da się tego dokończyć
        // ręcznie, a przy wymazaniu konta ktoś musi to dokończyć.
        Log::error('Nie udało się wyczyścić cache CDN po skasowaniu zdjęć', [
            'adresy' => $this->adresy,
            'error' => $e?->getMessage() ?? 'brak wyjątku (przekroczony limit czasu)',
        ]);
    }
}
