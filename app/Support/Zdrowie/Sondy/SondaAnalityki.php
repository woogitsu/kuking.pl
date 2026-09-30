<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\AnalitykaCloudflare;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `analityka` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaAnalityki implements Sonda
{
    public function nazwa(): string
    {
        return 'analityka';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_ANALITYKA_BEZ_TOKENU;
    }

    /**
     * Analityka odwiedzin: czy rzecz, którą właśnie obiecaliśmy w DOKUMENCIE
     * PRAWNYM, ma czym działać (D-092).
     *
     * TEN SAM WYWÓD CO PRZY GOOGLE I FACEBOOKU, Z JEDNĄ RÓŻNICĄ, KTÓRA
     * PRZEWAŻA. Tamte dwie obietnice stoją w konfiguracji i widać je okiem:
     * przycisku „Wejdź kontem Google" albo nie ma na `/login`, i ktoś kiedyś
     * to zauważy. Tę obietnicę złożyliśmy w polityce prywatności — zdaniem
     * w czasie teraźniejszym, z datą — a jej niespełnienia NIE WIDAĆ NIGDZIE:
     * strona wygląda normalnie, w dzienniku serwera nie ma nic, przeglądarka
     * nie zgłasza usterki, bo skryptu po prostu nie ma w HTML-u. Właściciel,
     * który raz założył serwis w panelu Cloudflare, ma wszelkie powody sądzić,
     * że analityka działa. Do 12 września 2026 nie było ani jednego miejsca,
     * z którego dałoby się dowiedzieć, że nie działa.
     *
     * PYTAMY O ROZJAZD, NIE O BRAK TOKENU. Pusty token sam w sobie jest
     * poprawnym stanem: tak chodzi CI, tak chodzą testy, tak chodzi każde
     * środowisko preview i tak może chodzić produkcja, jeśli właściciel
     * analityki nie chce. Awarią jest dopiero para: dokument prawny obiecuje
     * + tokenu nie ma. Dlatego warunkiem jest treść polityki prywatności
     * (`AnalitykaCloudflare::obiecanaWDokumencie()`), a nie osobny przełącznik.
     *
     * I DLATEGO ŚWIADOMIE NIE MA TU WYŁĄCZNIKA W RODZAJU `KUKING_ANALITYKA=false`.
     * Przy Google i Facebooku wyłącznik znaczy „nie chcę tej drogi wejścia"
     * i nikogo nie okłamuje — dokument prawny o nich wtedy nie mówi. Tutaj
     * wyłącznik znaczyłby „niech polityka prywatności dalej opisuje
     * przetwarzanie, którego nie ma, tylko niech przestanie o tym mówić
     * healthcheck", czyli uczyłby uciszania sygnału zamiast prostowania
     * dokumentu. Uciszyć ten sygnał wolno DWOMA sposobami i oba są uczciwe:
     * wpisać token albo wykreślić obietnicę z polityki (wtedy trzeba też
     * usunąć beacon z layoutu — pilnuje tego `DokumentyPrawneNieKlamiaTest`
     * z drugiej strony).
     *
     * DLACZEGO `degraded`, A NIE 503. Bo `analityka` NIE JEST na liście
     * `KRYTYCZNE` i być nie może: serwis bez statystyki odwiedzin działa
     * w całości, a healthcheck oddający 503 już raz położył ten serwis.
     * Monitoring pilnuje TREŚCI odpowiedzi.
     *
     * DLACZEGO TYLKO NA PRODUKCJI. Lokalnie, w testach, w CI i na preview
     * pusty token jest stanem domyślnym i opisanym w `.env.example` — stały
     * `degraded` byłby tam szumem, który uczy ignorować to pole. To ta sama
     * lekcja co przy Turnstile.
     *
     * CZEGO TEN SYGNAŁ NIE ZŁAPIE — I TO JEST GRANICA, NIE PRZEOCZENIE.
     * Nie powie, czy token jest PRAWDZIWY (Cloudflare po cichu odrzuca
     * zdarzenia z nieznanym tokenem) ani czy w panelu wybrano wariant
     * zbierania danych obejmujący Unię Europejską. Obie te rzeczy dzieją się
     * w cudzym panelu i z kontenera nie da się ich zmierzyć — dlatego mówi
     * o nich zdanie z `AnalitykaCloudflare::komunikatBrakuTokenu()`
     * i sprawdzenie w KROKU 8F runbooka, które patrzy na realne liczby
     * w panelu, a nie na stan naszej konfiguracji.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! AnalitykaCloudflare::obiecanaWDokumencie()) {
            return;
        }

        if (AnalitykaCloudflare::wlaczona()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_ANALITYKA_BEZ_TOKENU,
            AnalitykaCloudflare::komunikatBrakuTokenu(),
        );
    }
}
