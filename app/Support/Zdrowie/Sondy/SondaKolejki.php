<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sonda `kolejka` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaKolejki implements Sonda
{
    public function nazwa(): string
    {
        return 'kolejka';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_ZADANIA_NIEUDANE;
    }

    /**
     * Czy w `failed_jobs` leżą nieudane zadania kolejki, o których dziś nie
     * dowiaduje się nikt sam z siebie.
     *
     * D-057 §4 (`docs/DECISIONS.md`) ustaliło to WPROST przy okazji sufitu
     * tygodniowego podsumowania: „Jedyne miejsce, które w ogóle liczy
     * `failed_jobs`, to `kuking:sprawdz-poczte`, uruchamiane ręcznie."
     * Zdanie było prawdziwe do tego sprawdzenia — teraz przynajmniej
     * ZEWNĘTRZNY monitoring `/health` (i webhook błędów, przez `check()`)
     * może to zauważyć bez logowania się na serwer.
     *
     * CZEGO TO NIE ROBI (ŚWIADOMIE)
     * Nie mówi, KTÓRE zadanie padło ani dlaczego — treść `failed_jobs.exception`
     * bywa pełnym śladem stosu z argumentami wywołań, czyli dokładnie tym,
     * czego `WebhookBleduHandler` i `check()` unikają gdzie indziej. Diagnozę
     * daje `php artisan queue:failed` z powłoki serwera, nie trasa publiczna.
     *
     * POWŁOKI SERWERA NA RAILWAY NIE MA — i dlatego to zdanie było przez
     * dziesięć dni ślepym zaułkiem: `/health` mówił `degraded`, a jedyna
     * odpowiedź na pytanie „które zadanie" stała za ścianą. Od issue #599
     * jest druga droga, TEŻ nie publiczna: `/admin/kolejka`, za rolą `admin`
     * (`UserPolicy::diagnozujKolejke`). Ona także nie pokazuje ładunku ani
     * treści wyjątku — tylko nazwy klas i liczby.
     * To sprawdzenie ma jedno zadanie: powiedzieć „coś tam leży, zajrzyj" —
     * publiczna odpowiedź niesie tylko kod, nigdy liczbę ani treść.
     *
     * DOKĄD ODESŁAĆ CZŁOWIEKA, KTÓRY TO ZOBACZY
     * `queue:failed` mówi, ŻE coś padło, i nic więcej — a najczęstszy odruch
     * po jego przeczytaniu, czyli `queue:retry`, jest przy liście z żetonem
     * ODPOWIEDZIĄ ZŁĄ: żeton resetu hasła żyje `config/auth.php` → `expire`
     * minut od WYSTAWIENIA, więc ponowienie po dniach wysyła człowiekowi
     * martwy link. Dlatego komunikat niżej (widoczny w dzienniku serwera,
     * nie w publicznej odpowiedzi) prowadzi do `kuking:martwe-zadania`, która
     * rozdziela żetony żywe od martwych i bez jawnego przełącznika niczego
     * nie kasuje.
     *
     * DLACZEGO CZYTAMY TABELĘ, A NIE RUSZAMY KOLEJKI
     * Wyłącznie `SELECT COUNT(*)` — bez `queue:retry`, bez kasowania, bez
     * dotykania `app/Jobs` ani `app/Mail`. Naprawa cichej utraty listów to
     * osobna praca (issue #234); to sprawdzenie tylko CZYTA to, co tamta
     * praca też czyta.
     */
    public function sprawdz(): void
    {
        try {
            $nieudane = DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            // Nie zgadujemy: gdy samo ZAPYTANIE się nie udaje, prawdziwą
            // przyczyną jest niemal na pewno ta sama awaria bazy, którą i tak
            // zgłasza sprawdzenie `database` — powód `zadania_nieudane`
            // (poniżej) mówiłby wtedy o czymś, czego wcale nie zmierzyliśmy.
            throw new KontrolaZdrowiaNieprzeszla(Powody::POWOD_BAZA, $e->getMessage(), $e);
        }

        if ($nieudane === 0) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_ZADANIA_NIEUDANE,
            "W tabeli `failed_jobs` jest {$nieudane} nieudanych zadań kolejki. "
                .'KTÓRE to zadania i co je przewróciło, widać bez powłoki serwera: '
                .'panel moderacji → „Kolejka zadań" (`/admin/kolejka`, rola `admin`). '
                .'Co to jest i kogo dotyczy: `php artisan kuking:martwe-zadania` '
                .'(niczego nie kasuje bez `--skasuj`). Do kogo nie doszedł list: '
                .'`php artisan kuking:kto-nie-dostal-listu`.',
        );
    }
}
