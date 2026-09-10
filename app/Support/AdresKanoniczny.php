<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Adres do listu budowany z KONFIGURACJI, nigdy z nagłówków żądania (D-071).
 *
 * DLACZEGO TO NIE JEST NADMIAROWE OBOK `TrustHosts`
 * `TrustHosts` mówi „na tych hostach wolno nas odpytywać". Ta klasa mówi
 * „niezależnie od tego, co przyszło w żądaniu, link w liście prowadzi na
 * `APP_URL`". Różnica jest istotna dokładnie tam, gdzie stawka jest
 * najwyższa: link z `LinkDoLogowania` DAJE SESJĘ, a jest to nasza główna
 * droga wejścia dla osób 60+ (D-056). Dla takiego linku „host był na liście"
 * jest gwarancją słabszą niż „host w ogóle nie zależał od żądania" — lista
 * ma kilka pozycji (apex, `www`, host healthchecku, pętla zwrotna), a
 * kanoniczny adres jest jeden.
 *
 * Druga, przyziemniejsza korzyść: `TrustHosts` w Laravelu jest wyłączony
 * w środowisku `local` i w testach (`TrustHosts::shouldSpecifyTrustedHosts()`).
 * Gdyby zabezpieczeniem treści listów była tylko lista hostów, nie dałoby się
 * jej sprawdzić testem tam, gdzie testy chodzą — a znalezisko S2 mówi wprost
 * o linkach w listach.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO PRZEZ WYMUSZENIE KORZENIA, A NIE PRZEZ SKLEJENIE ADRESU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Najprostsze wyobrażalne rozwiązanie — wygenerować adres normalnie,
 * a potem podmienić w nim `schemat://host` na kanoniczny — jest tu BŁĘDNE
 * i to cicho. Dwa z trzech linków są PODPISANE (`verificationUrl()`,
 * `URL::signedRoute()`), a podpis liczy się z całego adresu razem z hostem.
 * Podmiana hosta po wygenerowaniu unieważniłaby podpis, więc każdy taki link
 * kończyłby się komunikatem „link wygasł" — czyli usterką widoczną dopiero
 * u człowieka, który już nie może wejść na konto.
 *
 * Dlatego korzeń i schemat są wymuszane PRZED wygenerowaniem adresu
 * i przywracane po nim. Ustawienie jest globalne dla generatora adresów,
 * więc `finally` nie jest ozdobą: bez niego wyjątek z generatora zostawiłby
 * wymuszony korzeń na resztę żądania (albo na resztę zadań przetwarzanych
 * przez tego samego workera) i wszystkie adresy w serwisie zaczęłyby
 * wskazywać `APP_URL`.
 *
 * PRZYWRACAMY DO `null`, NIE DO POPRZEDNIEJ WARTOŚCI, bo `UrlGenerator` nie
 * wystawia gettera na wymuszony korzeń, a w tej aplikacji nikt inny go nie
 * ustawia — `null` znaczy „wróć do zachowania domyślnego, czyli korzeń
 * z żądania". Gdyby kiedyś pojawiło się drugie miejsce wymuszające korzeń,
 * to założenie przestanie być prawdziwe i trzeba je będzie zmienić TUTAJ,
 * w jednym miejscu.
 *
 * CO SIĘ DZIEJE BEZ `APP_URL`
 * Nic — adres powstaje tak jak dotąd. Zmienna `APP_URL` jest ustawiona
 * w każdym środowisku (`.railway/railway.ts`, `.env.example`), ale ta klasa
 * nie ma prawa wywalić wysyłki listu z powodu braku konfiguracji: skutkiem
 * byłby błąd zadania w kolejce, czyli list, którego nikt nie dostał.
 */
final class AdresKanoniczny
{
    /**
     * Zbuduj adres, mając wymuszony kanoniczny korzeń i schemat z `APP_URL`.
     *
     * @param  callable(): string  $generator
     */
    public static function zbuduj(callable $generator): string
    {
        $korzen = rtrim((string) config('app.url'), '/');
        $schemat = parse_url($korzen, PHP_URL_SCHEME);

        if ($korzen === '' || ! is_string($schemat) || $schemat === '') {
            return (string) $generator();
        }

        URL::useOrigin($korzen);
        URL::forceScheme($schemat);

        try {
            return (string) $generator();
        } finally {
            URL::useOrigin(null);
            URL::forceScheme(null);
        }
    }
}
