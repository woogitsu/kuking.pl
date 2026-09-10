<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lista hostów, pod którymi ten serwis wolno odpytywać (ustalenie S2, D-071).
 *
 * DLACZEGO TO ISTNIEJE
 * Laravel bez `TrustHosts` odpowiada na DOWOLNĄ wartość nagłówka `Host`
 * i używa jej do budowy bezwzględnych adresów w trakcie żądania. Zmierzone
 * przed tą zmianą: żądanie z `X-Forwarded-Host: attacker.invalid` wracało 200,
 * a `url('/przepisy')` zwracało `http://attacker.invalid/przepisy` — łącznie
 * z linkiem w liście potwierdzającym NOWY adres e-mail, który powstaje
 * w kontekście żądania HTTP (`RequestEmailChange`). To jest powierzchnia,
 * którą OWASP opisuje jako zatruwanie linków resetu hasła i przekierowań.
 *
 * DWIE GRANICE, NIE JEDNA
 * Ta klasa zamyka nagłówek `Host`. Nagłówek `X-Forwarded-Host` jest zamknięty
 * OSOBNO i mocniej — po prostu wypadł z bitmaski zaufanych nagłówków
 * w `bootstrap/app.php`, więc aplikacja go w ogóle nie czyta. Uzasadnienie
 * obu ruchów stoi w D-071.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  WZORCE, NIE NAZWY — I DLACZEGO TO NIE JEST SZCZEGÓŁ
 * ────────────────────────────────────────────────────────────────────────
 *
 * `Request::setTrustedHosts()` w Symfony traktuje każdy element listy jako
 * WYRAŻENIE REGULARNE i dokleja mu tylko ograniczniki (`{...}i`) — bez
 * zakotwiczenia. Wpisanie tam gołego `kuking.pl` znaczyłoby więc „host,
 * w którym GDZIEKOLWIEK występuje `kuking` + dowolny znak + `pl`", czyli
 * także `kuking-pl.attacker.test`. Dlatego `wzorce()` zakotwicza każdą nazwę
 * na `^…$` i przepuszcza ją przez `preg_quote()`. Pilnuje tego test
 * `ZaufaneHostyTest::test_host_ktory_tylko_zawiera_nasza_nazwe_jest_odrzucany`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO JEST NA LIŚCIE I CO SIĘ STANIE, GDY TEGO ZABRAKNIE
 * ────────────────────────────────────────────────────────────────────────
 *
 *  `kuking.pl` — kanoniczny adres serwisu (`docs/infra/INFRA_DECISION.md`,
 *  sekcja „`www` vs apex"). Bez niego serwis przestaje działać w całości.
 *
 *  `www.kuking.pl` — Cloudflare przekierowuje go regułą 301 na apex, więc
 *  w normalnym ruchu do aplikacji NIE dochodzi. Jest tu, bo ta reguła żyje
 *  w cudzym panelu, a nie w tym repozytorium: domena jest zadeklarowana
 *  w `.railway/railway.ts` (Railway wystawia dla niej certyfikat), więc
 *  przy wyłączonym proxy Cloudflare albo skasowanej regule żądanie trafia
 *  wprost na origin. Wtedy człowiek ma zobaczyć stronę, a nie błąd 400.
 *
 *  `healthcheck.railway.app` — TO JEST WPIS, KTÓRY POTRAFI POŁOŻYĆ DEPLOY.
 *  Railway sprawdza gotowość nowego kontenera, wysyłając `GET /health`
 *  z DOKŁADNIE tym hostem, i dopiero po odpowiedzi 2xx przełącza na niego
 *  ruch (`.railway/railway.ts`, `healthcheckPath`). Host poza listą znaczy
 *  400, healthcheck bez odpowiedzi 2xx i deploy, który nigdy się nie kończy
 *  — objaw opisany wprost w dokumentacji Railwaya i w
 *  `docs/infra/DEPLOYMENT_RUNBOOK.md` (tabela usterek).
 *  Źródło: https://docs.railway.com/deployments/healthchecks
 *
 *  Host z `APP_URL` — nie duplikat `kuking.pl`, a obsługa POZOSTAŁYCH
 *  środowisk. `staging` dostaje `staging.kuking.pl`, a środowisko preview
 *  per pull request dostaje adres `*.up.railway.app`, który Railway
 *  wstrzykuje do `APP_URL` przez `RAILWAY_PUBLIC_DOMAIN`
 *  (`.railway/railway.ts`). Dzięki temu ta lista jest poprawna w każdym
 *  środowisku BEZ ustawiania czegokolwiek ręcznie — a to jest warunek,
 *  bez którego pierwszy deploy po tej zmianie padłby na preview.
 *
 *  `localhost`, `127.0.0.1`, `[::1]` — pętla zwrotna z WNĘTRZA kontenera.
 *  `Dockerfile` ma własny `HEALTHCHECK` (dla uruchomień poza Railwayem:
 *  `docker run`, compose, Fly), który odpytuje
 *  `http://127.0.0.1:$PORT/health`. Bez tych trzech wpisów obraz z
 *  `APP_ENV=production` uruchomiony poza Railwayem raportowałby się jako
 *  niezdrowy, mimo że działa. Koszt bezpieczeństwa jest tu zerowy: adres
 *  zbudowany na pętli zwrotnej nie prowadzi do żadnej maszyny napastnika,
 *  więc do zatrucia linku się nie nadaje.
 *
 *  `config('proxy.dodatkowe_hosty')` — ZAWÓR BEZPIECZEŃSTWA, domyślnie pusty.
 *  Jedyny scenariusz, w którym ta zmiana mogłaby zatrzymać wdrożenie, to
 *  zmiana hosta healthchecku po stronie Railwaya. Bez zaworu ratunkiem
 *  byłby wtedy nowy deploy z poprawionym kodem — czyli dokładnie ta rzecz,
 *  której przy zablokowanym healthchecku zrobić NIE DA SIĘ. Ze zaworem
 *  wystarczy dopisać zmienną `KUKING_ZAUFANE_HOSTY` w panelu Railwaya.
 *  Domyślna wartość (brak zmiennej) jest bezpieczna i działająca, więc nic
 *  nie trzeba ustawiać, żeby serwis wstał — patrz `config/proxy.php`.
 *
 * CZEGO NA LIŚCIE NIE MA, ŚWIADOMIE
 * Produkcyjnego adresu `*.up.railway.app`. Ruch użytkowników idzie przez
 * Cloudflare na `kuking.pl`, healthcheck ma własny host, a nasze testy dymne
 * (`.github/workflows/deploy.yml`) uderzają w `https://kuking.pl`. Wejście
 * na origin z pominięciem Cloudflare jest osobnym, większym ustaleniem (S1,
 * token krawędziowy `X-Kuking-Edge-Token`) i ta klasa go NIE rozstrzyga —
 * ale skoro tego hosta tu nie ma, przestaje on być drogą do zbudowania
 * adresu na cudzej domenie. Gdyby właściciel potrzebował wejść na origin
 * wprost (np. do diagnostyki), służy do tego zawór wyżej.
 */
final class ZaufaneHosty
{
    /** Kanoniczny adres serwisu — `docs/infra/INFRA_DECISION.md`. */
    public const KANONICZNY = 'kuking.pl';

    /** Przekierowywany na apex przez Cloudflare, ale kończy się na naszym origin. */
    public const WWW = 'www.kuking.pl';

    /** Host, z którego Railway odpytuje `/health` przy każdym deployu. */
    public const HEALTHCHECK_RAILWAY = 'healthcheck.railway.app';

    /** @var list<string> */
    private const PETLA_ZWROTNA = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Nazwy hostów — czytelna lista, bez składni wyrażeń regularnych.
     *
     * Ta metoda istnieje osobno od `wzorce()`, bo test ma sprawdzać, CO jest
     * dopuszczone, a nie jak wygląda po `preg_quote()`.
     *
     * @return list<string>
     */
    public static function nazwy(): array
    {
        $hosty = [
            self::KANONICZNY,
            self::WWW,
            self::HEALTHCHECK_RAILWAY,
            ...self::PETLA_ZWROTNA,
            self::zAppUrl(),
            ...self::dodatkowe(),
        ];

        // `getHost()` w Symfony sprowadza host do małych liter i obcina port,
        // zanim porówna go z listą — więc lista musi być w małych literach,
        // inaczej wpis z wielką literą nigdy by się nie dopasował.
        $hosty = array_map(
            static fn (?string $host): string => mb_strtolower(trim((string) $host)),
            $hosty,
        );

        return array_values(array_unique(array_filter($hosty, static fn (string $h): bool => $h !== '')));
    }

    /**
     * To, co dostaje `Request::setTrustedHosts()` — zakotwiczone wzorce.
     *
     * @return list<string>
     */
    public static function wzorce(): array
    {
        return array_map(
            // `preg_quote()` bez drugiego argumentu escapuje także `{` i `}`,
            // czyli ograniczniki, które Symfony dokleja samo.
            static fn (string $host): string => '^'.preg_quote($host).'$',
            self::nazwy(),
        );
    }

    /**
     * Host z `APP_URL` — jedyne źródło prawdy o tym, pod jakim adresem stoi
     * TO środowisko. Bez `APP_URL` (albo z wartością bez hosta) po prostu
     * nic nie dokładamy; lista zostaje z hostami kanonicznymi.
     */
    private static function zAppUrl(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** @return list<string> */
    private static function dodatkowe(): array
    {
        /** @var list<string> $dodatkowe */
        $dodatkowe = config('proxy.dodatkowe_hosty', []);

        return $dodatkowe;
    }
}
