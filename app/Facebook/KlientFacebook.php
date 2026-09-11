<?php

declare(strict_types=1);

namespace App\Facebook;

use App\Models\User;
use App\Support\Facebook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wejście kontem Facebooka — cienki klient OAuth 2.0 na
 * `Illuminate\Support\Facades\Http` (issue #259, D-069, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO BEZ `laravel/socialite` — PRÓG POWROTU ZOSTAŁ PRZEKROCZONY
 *  I ODPOWIEDŹ JEST NADAL „NIE", ALE JUŻ NIE JEDNOGŁOŚNIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * D-069 odrzuciło pakiet i nazwało próg powrotu: „drugi dostawca tożsamości
 * ALBO pierwsza zmiana po stronie Google, której nie da się obsłużyć zmianą
 * jednej stałej". Drugi dostawca to właśnie ten plik, więc pytanie trzeba
 * było zadać, a nie pominąć (runbook §7.5 przechodzi przez nie argument po
 * argumencie).
 *
 * Dwa argumenty z tamtej tabeli przy Facebooku SŁABNĄ: używalibyśmy dwóch
 * sterowników, nie jednego, a PKCE (który przy Google trzeba było dopisywać
 * na wnętrznościach pakietu) tutaj nie wchodzi w grę wcale (patrz niżej).
 * Jeden ODWRACA SIĘ przeciw nam i jest uczciwie zapisany jako cena:
 * **Graph API wygasa co dwa lata, a utrzymywana biblioteka podnosiłaby numer
 * wersji za nas.**
 *
 * Decyduje jednak to, co przy Facebooku jest NAJGROŹNIEJSZE, a co pakiet
 * ukrywa za wygodnym akcesorem: `getEmail()` w Socialite **może oddać
 * `null`** i **nigdy** nie mówi, czy adres jest potwierdzony. Przy Google
 * wygodne `getEmail()` było tylko pokusą; tutaj jest odpowiedzią FAŁSZYWĄ,
 * bo zachęca do zaufania wartości, której zaufać nie wolno. Do tego
 * sterownik Facebooka w Socialite standardowo sięga po adres zdjęcia
 * profilowego, którego nie chcemy wcale (D-061), więc pakiet trzeba by
 * odchudzać, a nie używać.
 *
 * Cała rozmowa z Facebookiem to jedno przekierowanie, jeden `GET` po token
 * i jeden `GET` po profil — trzy wywołania `Http::`, nie framework
 * (AGENTS.md §3 wymienia „kolejną bibliotekę, gdy Laravel ma to
 * w standardzie" wśród zakazów).
 *
 * **Nowy próg powrotu do pakietu:** trzeci dostawca tożsamości ALBO pierwsza
 * sytuacja, w której podniesienie wersji Graph API wymaga u nas więcej niż
 * zmiany jednej stałej w `App\Support\Facebook`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE MA TU PKCE, A JEST `appsecret_proof`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przy Google PKCE jest od pierwszego dnia (D-069) i jest tam realnym
 * zabezpieczeniem, bo Google go DOKUMENTUJE i sprawdza. Dokumentacja Meta
 * dla ręcznej drogi logowania („Manually Build a Login Flow") wymienia
 * `state` i nie mówi o `code_challenge` ani słowa.
 *
 * Wysłanie parametru, o którym nie wiemy, czy dostawca go sprawdza, jest
 * gorsze niż jego brak: nieznane parametry Meta po prostu ignoruje, więc
 * dostalibyśmy komentarz „mamy PKCE" przy zerowej ochronie — czyli
 * zabezpieczenie na papierze, najdroższy rodzaj. Zostaje `state`
 * porównywany `hash_equals`, jednorazowy i wiązany z sesją; kod
 * autoryzacyjny jest przy tym bez naszego sekretu bezużyteczny, bo wymiana
 * idzie serwer-serwer.
 *
 * Dokładamy za to rzecz, której Google nie ma: **`appsecret_proof`** —
 * HMAC-SHA256 tokenu dostępu liczony sekretem aplikacji, dopinany do
 * wywołania Graph API. Meta wprowadziła go właśnie po to, żeby token
 * wykradziony z naszego serwera nie dał się użyć bez sekretu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ZASADA NADRZĘDNA: AWARIA FACEBOOKA ODSYŁA NA HASŁO, NIE ZAMYKA DRZWI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cokolwiek pójdzie nie tak — timeout, 5xx, zły sekret, odpowiedź
 * w nieznanym kształcie — oddajemy `null`, a kontroler odsyła człowieka na
 * ekran logowania ze zdaniem po polsku i z hasłem oraz linkiem e-mail przed
 * oczami (D-056). Nigdy: pusty ekran, nigdy angielski komunikat od
 * dostawcy, nigdy przycisk, który milczy.
 *
 * JEDEN PRZYPADEK NIE JEST AWARIĄ I DLATEGO NIE ODDAJE `null`: brak adresu
 * e-mail. Tożsamość wraca wtedy z `email: null`, a rozstrzyga to kontroler
 * osobnym ekranem — bo to nie jest usterka, tylko normalny stan cudzego
 * konta (runbook §7.2).
 */
final class KlientFacebook
{
    /**
     * Adres, na który odsyłamy człowieka po zgodę.
     *
     * `auth_type` NIE JEST TU USTAWIANE i to jest decyzja. Dokumentacja Meta
     * przewiduje `auth_type=rerequest` do ponownego pytania o odrzucone
     * uprawnienie, ale sama ostrzega: „if someone is actively choosing not to
     * grant a specific permission to an app they are unlikely to change
     * their mind, even in the face of continued prompting". Jedna prośba,
     * jasne zdanie i droga dalej — nie pętla dopraszania (D-053).
     */
    public function adresZgody(string $state, string $adresPowrotu): string
    {
        return Facebook::adresAutoryzacji().'?'.http_build_query([
            'client_id' => Facebook::identyfikatorKlienta(),
            'redirect_uri' => $adresPowrotu,
            'response_type' => 'code',
            'scope' => Facebook::ZAKRES,
            'state' => $state,
        ]);
    }

    /**
     * Kod autoryzacyjny → tożsamość. `null`, gdy cokolwiek nie zagra.
     *
     * `null` nie mówi, CO nie zagrało, i to jest celowe: człowiek przed
     * ekranem ma zrobić w każdym z tych przypadków dokładnie to samo
     * (spróbować jeszcze raz albo wejść hasłem), a odpowiedź „bo Facebook
     * oddał 400" nie pomaga mu w niczym. Przyczyna idzie do dziennika,
     * dla właściciela.
     */
    public function wymienKod(string $kod, string $adresPowrotu): ?TozsamoscFacebook
    {
        $token = $this->token($kod, $adresPowrotu);

        if ($token === null) {
            return null;
        }

        return $this->tozsamosc($token);
    }

    /**
     * Wymiana kodu na token dostępu.
     *
     * Meta przyjmuje to żądanie jako `GET` z parametrami w adresie i tak jest
     * opisane w jej dokumentacji. Sekret idzie więc w adresie żądania
     * serwer-serwer po TLS — nie w adresie przeglądarki człowieka i nie
     * w żadnym logu (adresu tego żądania nigdzie nie zapisujemy).
     */
    private function token(string $kod, string $adresPowrotu): ?string
    {
        $limit = Facebook::limitCzasu();

        try {
            $odpowiedz = Http::connectTimeout(min($limit, 3))
                ->timeout($limit)
                ->get(Facebook::adresTokenu(), [
                    'client_id' => Facebook::identyfikatorKlienta(),
                    'client_secret' => Facebook::sekretKlienta(),
                    'redirect_uri' => $adresPowrotu,
                    'code' => $kod,
                ]);
        } catch (ConnectionException $e) {
            return $this->nieUdalo('Facebook nie odpowiedział na wymianę kodu autoryzacyjnego.', [
                'wyjatek' => $e::class,
            ]);
        } catch (Throwable $e) {
            // `Throwable`, nie `Exception`: nie zgadujemy, czym potrafi się
            // wywrócić cudzy klient HTTP. Wejście hasłem jest ważniejsze niż
            // nasza pewność co do tego, co poszło nie tak.
            return $this->nieUdalo('Wymiana kodu z Facebookiem wywróciła się w nieoczekiwany sposób.', [
                'wyjatek' => $e::class,
            ]);
        }

        if ($odpowiedz->failed()) {
            /*
             * TU LĄDUJE ZŁY SEKRET APLIKACJI I ZŁY ADRES POWROTU — czyli dwa
             * najczęstsze błędy konfiguracji. Meta dopasowuje adres powrotu
             * DOKŁADNIE, znak w znak; jeden ukośnik różnicy i człowiek widzi
             * „URL Blocked" po stronie Facebooka albo my dostajemy tu 400.
             *
             * `Log::error`, nie `warning`: od tej chwili droga przez
             * Facebooka nie działa dla nikogo, a przycisk na ekranie wygląda
             * normalnie. Kodu błędu od Facebooka NIE pokazujemy człowiekowi —
             * jest po angielsku i mówi o naszej konfiguracji, nie o nim.
             */
            Log::error('Facebook odrzucił wymianę kodu — sprawdź konfigurację.', [
                'status' => $odpowiedz->status(),
                'blad' => (string) $odpowiedz->json('error.type', ''),
                'co_zrobic' => 'Sprawdź FACEBOOK_CLIENT_ID i FACEBOOK_CLIENT_SECRET (App ID i App Secret '
                    .'w panelu Meta) oraz to, czy adres powrotu jest wpisany w Facebook Login → Settings '
                    .'→ Valid OAuth Redirect URIs co do znaku '
                    .'(docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md §4.2).',
            ]);

            return null;
        }

        $token = $odpowiedz->json('access_token');

        if (! is_string($token) || $token === '') {
            return $this->nieUdalo('Odpowiedź Facebooka nie zawiera tokenu dostępu.', []);
        }

        return $token;
    }

    /**
     * Odczyt tożsamości z węzła `me`.
     *
     * TOKENU NIGDZIE NIE ZAPISUJEMY — żyje przez to jedno wywołanie. Po
     * zalogowaniu nie wołamy żadnego API Facebooka, ani razu, więc token
     * długoterminowy byłby trwałym pełnomocnictwem do cudzego konta,
     * leżącym w serwisie, który go do niczego nie używa (ten sam wywód co
     * przy tokenie odświeżania Google, D-069).
     */
    private function tozsamosc(string $token): ?TozsamoscFacebook
    {
        $limit = Facebook::limitCzasu();

        try {
            $odpowiedz = Http::connectTimeout(min($limit, 3))
                ->timeout($limit)
                ->get(Facebook::adresTozsamosci(), [
                    'fields' => Facebook::POLA,
                    'access_token' => $token,
                    // Patrz komentarz klasy: token wykradziony z naszego
                    // serwera jest bez sekretu aplikacji bezużyteczny.
                    'appsecret_proof' => hash_hmac('sha256', $token, Facebook::sekretKlienta()),
                ]);
        } catch (ConnectionException $e) {
            return $this->nieUdalo('Facebook nie odpowiedział na pytanie o tożsamość.', [
                'wyjatek' => $e::class,
            ]);
        } catch (Throwable $e) {
            return $this->nieUdalo('Odczyt tożsamości z Facebooka wywrócił się w nieoczekiwany sposób.', [
                'wyjatek' => $e::class,
            ]);
        }

        if ($odpowiedz->failed()) {
            Log::error('Facebook odrzucił odczyt tożsamości.', [
                'status' => $odpowiedz->status(),
                'blad' => (string) $odpowiedz->json('error.type', ''),
                'co_zrobic' => 'Jeśli to się powtarza, sprawdź, czy wersja Graph API w konfiguracji '
                    .'jeszcze żyje (FACEBOOK_GRAPH_WERSJA, dziś '.Facebook::wersjaGrafu().') — wersje '
                    .'Meta wygasają po około dwóch latach, a wywołania spadają wtedy cicho na starszą.',
            ]);

            return null;
        }

        $identyfikator = trim((string) $odpowiedz->json('id', ''));

        if ($identyfikator === '') {
            return $this->nieUdalo('Odpowiedź Facebooka jest bez identyfikatora konta.', []);
        }

        /*
         * ADRES MOŻE NIE PRZYJŚĆ I TO NIE JEST AWARIA (runbook §7.2).
         *
         * Normalizujemy go tą samą metodą co wszędzie indziej (małe litery,
         * bez spacji), a puste pole zamieniamy na `null` — bo puste pole
         * i „nie ma adresu" to u nas ta sama sytuacja, a dwa sposoby jej
         * zapisania rozjechałyby się przy pierwszym warunku.
         */
        $email = User::normalizeEmail((string) $odpowiedz->json('email', ''));

        return new TozsamoscFacebook(
            identyfikator: $identyfikator,
            email: $email === '' ? null : $email,
            // Graph API oddaje `name` (imię i nazwisko). Pytamy „jak mamy Cię
            // nazywać", więc na ekranie lepiej wygląda samo imię — pierwszy
            // wyraz. Cała reszta i tak nigdzie nie jest zapisywana.
            imie: Str::before(trim((string) $odpowiedz->json('name', '')), ' '),
        );
    }

    /** Jednorazowa, losowa wartość do `state`. */
    public static function losowaWartosc(): string
    {
        return Str::random(64);
    }

    /**
     * Jedno miejsce, w którym powstaje „nie udało się": zawsze ze wpisem
     * w dzienniku, nigdy w ciszy. Bez adresu e-mail, bez tokenu
     * i bez identyfikatora konta (AGENTS.md §7).
     *
     * @param  array<string, mixed>  $kontekst
     */
    private function nieUdalo(string $powod, array $kontekst): null
    {
        Log::warning($powod.' Odsyłamy człowieka na logowanie hasłem.', $kontekst);

        return null;
    }
}
