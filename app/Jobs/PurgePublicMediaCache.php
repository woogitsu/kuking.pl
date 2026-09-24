<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Media\ZalegleCzyszczeniaCdn;
use App\Logging\BezpiecznyBlad;
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
            // NA PRODUKCJI ADRESY NIE MOGĄ PRZEPAŚĆ (#959).
            //
            // Do tego issue był tu sam `Log::warning` i `return`: zadanie
            // kończyło się sukcesem, nie trafiało do `failed_jobs`, a po
            // uzupełnieniu konfiguracji nikt już nie wiedział, które skasowane
            // zdjęcia dalej siedzą w cache. Teraz adresy idą do tabeli
            // `zalegle_czyszczenia_cdn`, skąd `kuking:wyczysc-zalegle-cdn`
            // (co kwadrans) wyśle je, gdy konfiguracja wróci. Świeci to
            // w `/health` dwa razy: `cdn` (brak konfiguracji) i `cdn_zalegle`
            // (są adresy do dokończenia).
            //
            // Sukces zadania, nie wyjątek: kasowanie zdjęcia i wymazanie
            // konta nie mają prawa się wywrócić dlatego, że nie ma czym
            // wyczyścić cudzego cache — wystarczy, że niczego nie gubimy.
            //
            // Poza produkcją nie ma CDN-u i pusta konfiguracja jest stanem
            // poprawnym — tam zostaje sam wpis w logu, bez tabeli.
            $odlozone = app()->environment('production');

            if ($odlozone) {
                ZalegleCzyszczeniaCdn::odloz($adresy);
            }

            Log::warning('Czyszczenie cache CDN pominięte — brak konfiguracji', [
                'adresow' => count($adresy),
                'odlozone' => $odlozone,
            ]);

            return;
        }

        $adres = str_replace(
            '{zone}',
            $zona,
            (string) config('kuking.media.cdn_purge.endpoint'),
        );

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

            // 2xx to jeszcze nie zgoda: Cloudflare mówi o powodzeniu polem
            // `success` (#959). `false` albo brak pola = ponowienie, bez
            // przepisywania treści odpowiedzi do logu.
            if ($odpowiedz->json('success') !== true) {
                throw new \RuntimeException(
                    'Cloudflare nie potwierdził czyszczenia cache (brak `success: true`): HTTP '.$odpowiedz->status(),
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
            'error' => $e !== null ? BezpiecznyBlad::kontekst($e) : 'brak wyjątku (przekroczony limit czasu)',
        ]);

        // Log to ślad, nie naprawa (#959). Tabela zaległych daje ponowienie
        // bez człowieka: `kuking:wyczysc-zalegle-cdn` spróbuje znowu za
        // kwadrans, a `/health` świeci, dopóki się nie uda. Własna awaria
        // zapisu nie może zasłonić wpisu wyżej.
        try {
            ZalegleCzyszczeniaCdn::odloz($this->adresy);
        } catch (\Throwable $blad) {
            Log::error('Nie udało się odłożyć adresów do ponownego czyszczenia cache CDN', [
                'wyjatek' => $blad::class,
            ]);
        }
    }

    /** Czy są OBIE wartości potrzebne do rozmowy z Cloudflare. */
    public static function skonfigurowane(): bool
    {
        return (string) config('kuking.media.cdn_purge.zone_id') !== ''
            && (string) config('kuking.media.cdn_purge.token') !== '';
    }
}
