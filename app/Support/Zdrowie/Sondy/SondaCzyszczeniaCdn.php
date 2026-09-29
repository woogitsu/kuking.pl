<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Jobs\PurgePublicMediaCache;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `cdn` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaCzyszczeniaCdn implements Sonda
{
    public function nazwa(): string
    {
        return 'cdn';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_CZYSZCZENIE_CDN_WYLACZONE;
    }

    /**
     * Czy czyszczenie cache CDN jest w ogóle włączone (audyt G-03).
     *
     * CO TA SONDA MIERZY, A CZEGO NIE
     * Mierzy JEDNO: czy `App\Jobs\PurgePublicMediaCache` ma z czym pójść do
     * Cloudflare. Nie mierzy, czy przed zdjęciami stoi CDN, ani czy on
     * cokolwiek trzyma — to żyje w panelu Cloudflare, nie w repozytorium
     * (issue #120), i z tego kontenera nie da się tego sprawdzić.
     *
     * DLACZEGO TO WYSTARCZY, ŻEBY BYŁO WARTO
     * Bo bez tych dwóch zmiennych czyszczenie NIE ZADZIAŁA NIGDY, niezależnie
     * od odpowiedzi na tamte pytania — a dowiedzieć się o tym dziś nie ma
     * skąd. `MediaController` wysyła dla treści publicznej `Cache-Control:
     * public, max-age=...` (dziś 150 s) i na przekierowaniu, i — przez
     * `ResponseCacheControl` — na odpowiedzi z bajtami, czyli WPROST zaprasza
     * pośrednika do trzymania kopii. Kasowanie zdjęcia po decyzji moderacyjnej
     * albo żądaniu z RODO liczy na to, że ktoś tę kopię potem usunie.
     *
     * TYLKO PRODUKCJA. Lokalnie i w testach nie ma żadnego CDN-u i pusta
     * konfiguracja jest tam stanem poprawnym — mówi o tym wprost komentarz
     * przy `kuking.media.cdn_purge`. Sygnał, który świeci wszędzie, jest
     * szumem uczącym ignorować całe pole `checks` (ta sama lekcja co przy
     * Turnstile i analityce).
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $zona = (string) config('kuking.media.cdn_purge.zone_id');
        $token = (string) config('kuking.media.cdn_purge.token');

        if ($zona !== '' && $token !== '') {
            $powod = PurgePublicMediaCache::powodZlegoAdresu();

            if ($powod === null) {
                return;
            }

            // Nazwa złej części, bez adresu — ten komunikat idzie do logu
            // i na webhook, a zmienna bywa wklejana razem z tokenem.
            throw new KontrolaZdrowiaNieprzeszla(
                Powody::POWOD_CZYSZCZENIE_CDN_ZLY_ADRES,
                'Czyszczenie cache CDN NIE DZIAŁA: CLOUDFLARE_PURGE_ENDPOINT razem z CLOUDFLARE_ZONE_ID '
                ."nie dają adresu czyszczenia Cloudflare ({$powod}). Zadanie odmawia wysłania tokenu "
                .'i każde czyszczenie ląduje w failed_jobs. Usuń CLOUDFLARE_PURGE_ENDPOINT (wartość '
                .'domyślna jest poprawna) i sprawdź, czy CLOUDFLARE_ZONE_ID to sam identyfikator strefy.',
            );
        }

        // Stary, JEDYNY dysk zdjęć z publicznym adresem (`r2_legacy`). Gdy jest
        // w użyciu, wyłączone czyszczenie znaczy co innego niż zwykle: tam
        // adres pliku nie ma podpisu i nie wygasa, więc kopia w cache nie jest
        // ograniczona przez `max-age` żadnego przekierowania. Ten stan wymaga
        // innej czynności człowieka (dokończyć `kuking:przenies-zdjecia` i
        // zdjąć domenę ze starego bucketu — issue #120), więc dopisujemy go do
        // komunikatu, choć kod powodu zostaje jeden: naprawa zaczyna się tak
        // samo, od panelu Cloudflare.
        $staryBucketPubliczny = filled(config('filesystems.disks.r2_legacy.bucket'))
            && filled(config('filesystems.disks.r2_legacy.url'));

        // KOMUNIKAT IDZIE DO LOGU I NA WEBHOOK, NIE DO ODPOWIEDZI. W JSON-ie
        // publicznym zostaje sam kod — tak jak przy wszystkich pozostałych
        // sondach, bo `/health` czyta każdy.
        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_CZYSZCZENIE_CDN_WYLACZONE,
            'Czyszczenie cache CDN po skasowaniu zdjęcia jest WYŁĄCZONE '
            .'(brak CLOUDFLARE_ZONE_ID albo CLOUDFLARE_PURGE_TOKEN). '
            .'Skasowane zdjęcia mogą się dalej otwierać z cache Cloudflare, '
            .'a adresy do wyczyszczenia czekają w `zalegle_czyszczenia_cdn` (sonda `cdn_zalegle`). '
            .($staryBucketPubliczny
                ? 'UWAGA: skonfigurowany jest jeszcze stary, PUBLICZNY bucket '
                  .'`r2_legacy` — tam adres pliku nie wygasa, więc okna narażenia '
                  .'nie zamyka żaden `max-age`. Dokończ `kuking:przenies-zdjecia` '
                  .'i zdejmij domenę ze starego bucketu (issue #120). '
                : 'Stary publiczny bucket `r2_legacy` nie jest skonfigurowany, '
                  .'więc dotyczy to wyłącznie odpowiedzi trasy `media.show` '
                  .'i ich `max-age`. ')
            .'Albo uzupełnij obie zmienne, albo świadomie zostaw wyłączone — '
            .'ale wtedy wiedz, że „skasowane" znaczy „skasowane z bucketu".',
        );
    }
}
