<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Cała logika TOTP w jednym miejscu (issue #12, część 2FA).
 *
 * Kontroler ma być cienki (AGENTS.md §4) — generowanie sekretu, sprawdzanie
 * kodu, kody zapasowe i rysowanie QR-a mieszkają tutaj, nie w
 * TwoFactorSettingsController ani w kontrolerze logowania. Dzięki temu dają
 * się przetestować bez HTTP i nie da się ich przypadkiem ominąć drugim
 * kontrolerem.
 *
 * DLACZEGO `pragmarx/google2fa`
 * Jedyna nowa zależność potrzebna do samego TOTP: ciągnie za sobą wyłącznie
 * `paragonie/constant_time_encoding` (porównanie sekretów w stałym czasie —
 * bez tego porównanie kodów byłoby podatne na atak czasowy). Dojrzała
 * (v9, wydania od 2014), szeroko używana biblioteka bez zależności
 * sieciowych — liczy kody lokalnie, zgodnie z RFC 6238.
 *
 * DLACZEGO DOSZEDŁ `bacon/bacon-qr-code`
 * Ekran włączenia 2FA pokazuje kod QR. Rozwiązanie „wyślij sekret do
 * zewnętrznego API generującego obrazek QR" (np. Google Charts) wyciekałoby
 * DRUGI SKŁADNIK logowania do serwisu trzeciej strony — nie do przyjęcia dla
 * funkcji, która ma CHRONIĆ konto. `bacon/bacon-qr-code` rysuje SVG lokalnie,
 * bez żadnego wywołania sieciowego, i ciągnie za sobą tylko jedną zależność
 * (`dasprid/enum`).
 */
class TwoFactorAuthenticator
{
    private Google2FA $engine;

    private readonly Randomizer $random;

    public function __construct(?Randomizer $random = null)
    {
        $this->engine = new Google2FA;
        $this->random = $random ?? new Randomizer(new Secure);
    }

    /**
     * Klucz limitu prób drugiego składnika — JEDEN na konto (issue #1314).
     *
     * Kod pada nie tylko na ekranie logowania (`TwoFactorChallengeController`),
     * ale też przy cofaniu usunięcia konta (`AccountDeletionController`). Oba
     * ekrany liczą próby w TYM SAMYM koszyku: osobne koszyki dawałyby
     * zgadującemu podwójny budżet na sześć cyfr jednego konta.
     */
    public static function kluczLimituProb(User $user): string
    {
        return 'weryfikacja-2fa|'.$user->getKey();
    }

    /**
     * Sesja po PIERWSZYM składniku (hasło, link, Google, Facebook) — issue #931.
     *
     * Obok identyfikatora konta zapisujemy odcisk jego stanu z tej chwili.
     * Sam identyfikator przeżywał reset hasła i „wyloguj inne urządzenia”:
     * `User::invalidateSessions()` kasuje sesje po `user_id`, a oczekująca
     * sesja jest jeszcze sesją gościa, więc stary pierwszy krok dało się
     * dokończyć kodem zapasowym bez znajomości nowego hasła.
     *
     * @return array<string, mixed>
     */
    public static function oczekujaceLogowanie(User $user): array
    {
        return [
            'logowanie.2fa.user_id' => $user->getKey(),
            'logowanie.2fa.odcisk' => self::odciskOczekujacegoLogowania($user),
        ];
    }

    /**
     * Czy oczekujące logowanie nadal pasuje do stanu konta (issue #931).
     *
     * Brak odcisku (sesja sprzed tej poprawki) też jest odmową — zaczęcie
     * logowania od nowa kosztuje chwilę, przepuszczenie kosztuje konto.
     */
    public static function oczekujaceLogowanieAktualne(User $user, mixed $odcisk): bool
    {
        return is_string($odcisk) && hash_equals(self::odciskOczekujacegoLogowania($user), $odcisk);
    }

    /**
     * Odcisk zmienia się przy każdym zdarzeniu, które ma odwołać wcześniejsze
     * potwierdzenie pierwszego składnika:
     * - `password` — zmiana i reset hasła;
     * - `remember_token` — rotuje go `invalidateSessions()`, czyli „wyloguj
     *   inne urządzenia”, ban, zawieszenie, zgłoszenie usunięcia (a że token
     *   nie wraca, stary krok nie odżywa po przywróceniu konta);
     * - `status` — każda inna zmiana stanu konta;
     * - `two_factor_confirmed_at` — wyłączenie i ponowne włączenie 2FA.
     *
     * HMAC z kluczem aplikacji, bo sesje leżą w bazie: odcisk nie może
     * zdradzać hasha hasła ani tokenu.
     */
    private static function odciskOczekujacegoLogowania(User $user): string
    {
        return hash_hmac('sha256', implode("\0", [
            'logowanie-2fa',
            (string) $user->getKey(),
            (string) $user->getAuthPassword(),
            (string) $user->getRememberToken(),
            (string) $user->status,
            (string) $user->two_factor_confirmed_at?->toIso8601String(),
        ]), (string) config('app.key'));
    }

    /**
     * @return array{0: int, 1: int} [maksimum prób, minuty do odblokowania]
     */
    public static function limitProb(): array
    {
        [$max, $minuty] = explode(',', (string) config('kuking.limits.two_factor'));

        return [(int) $max, (int) $minuty];
    }

    /**
     * Nowy sekret TOTP — losowy, jeszcze niczyj.
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Adres `otpauth://` do zakodowania w QR-ie.
     *
     * NIE jest to URL do zewnętrznego generatora obrazków (stare wersje
     * biblioteki tak robiły, przez Google Charts — dawno wycofane). To sam
     * standard TOTP: aplikacja uwierzytelniająca czyta go lokalnie.
     */
    public function otpAuthUri(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl(
            config('kuking.two_factor.issuer'),
            $user->email,
            $secret,
        );
    }

    /**
     * QR jako inline SVG — bez zapisu na dysk, bez wywołania sieciowego,
     * bez zależności od JavaScriptu (to zwykły znacznik `<svg>` w HTML-u).
     */
    public function qrCodeSvg(string $otpAuthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($otpAuthUri);

        // BaconQrCode dodaje na początku deklarację XML (do niczego nie
        // służy w środku strony HTML, a część walidatorów ją tam odrzuca),
        // więc zostawiamy sam znacznik `<svg>...</svg>`.
        $poczatek = mb_strpos($svg, '<svg');

        return $poczatek === false ? $svg : mb_substr($svg, $poczatek);
    }

    /**
     * Sprawdzenie kodu z aplikacji, z ochroną przed powtórzeniem.
     *
     * `verifyKeyNewer` z Google2FA robi DWIE rzeczy naraz: sprawdza kod
     * w oknie ±`window` kroków ORAZ odrzuca kod, jeśli jego znacznik czasu
     * nie jest NOWSZY niż `$ostatnioZaakceptowany`. Bez tego drugiego
     * warunku ten sam sześciocyfrowy kod, ważny przez całe okno tolerancji,
     * dałoby się użyć drugi raz — a scenariusz jest realny: ktoś podgląda
     * kod przez ramię i zdąża go wpisać, zanim aplikacja wygeneruje następny.
     *
     * PUŁAPKA W BIBLIOTECE, na którą trzeba uważać: `verifyKeyNewer`
     * z `$oldTimestamp = null` (czyli DOKŁADNIE to, co mamy przy PIERWSZYM
     * użyciu kodu — kolumna jest wtedy pusta) zwraca `true` zamiast liczby.
     * Zapisanie `true` do `two_factor_last_used_at` psułoby ochronę przed
     * powtórzeniem NA ZAWSZE: każde kolejne wywołanie dostawałoby znacznik
     * „1" zamiast prawdziwego czasu, czyli warunek „nowszy niż" byłby
     * spełniony przez dowolny prawdziwy znacznik czasu Uniksa. Dlatego
     * podajemy `0`, nie `null`, gdy konto jeszcze nie ma zapisanego znacznika
     * — dla biblioteki to nadal „każdy pasujący kod jest nowszy niż zero",
     * ale dostajemy z powrotem PRAWDZIWĄ liczbę do zapisania.
     */
    public function verifyCode(User $user, string $secret, string $code): bool
    {
        return DB::transaction(function () use ($user, $secret, $code): bool {
            $swiezy = self::podBlokada($user);

            if ($swiezy === null) {
                return false;
            }

            // Sekret mógł zostać w międzyczasie wymieniony — ktoś włączył 2FA
            // od nowa w drugiej karcie, `zaczniejDwuskladnikowe()` nadpisuje
            // `two_factor_secret` i zeruje znacznik. Kod policzony ze starego
            // sekretu nie ma wtedy prawa przejść, choćby pasował do liczb.
            if ($swiezy->two_factor_secret !== $secret) {
                return false;
            }

            $dopasowanyCzas = $this->engine->verifyKeyNewer(
                $secret,
                $code,
                $swiezy->two_factor_last_used_at ?? 0,
                (int) config('kuking.two_factor.window'),
            );

            if ($dopasowanyCzas === false) {
                return false;
            }

            $swiezy->forceFill(['two_factor_last_used_at' => $dopasowanyCzas])->save();
            self::przepiszNaWolajacego($user, ['two_factor_last_used_at' => $dopasowanyCzas]);

            return true;
        });
    }

    /**
     * Nowy komplet kodów zapasowych, w postaci jawnej — do pokazania RAZ.
     *
     * Format „XXXXX-XXXXX”, alfabet 32 znaków bez 0/O i 1/I.
     * Dziesięć niezależnych znaków daje 50 bitów — więcej niż górna granica
     * starego formatu: 8 × log2(36), czyli 41,36 bitu po zamianie na wersaliki.
     * Weryfikacja starych kompletów pozostaje bez zmian.
     */
    public function generateBackupCodes(): array
    {
        $ile = (int) config('kuking.two_factor.recovery_codes');

        return collect(range(1, $ile))
            ->map(function (): string {
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $code = '';
                for ($i = 0; $i < 10; $i++) {
                    $code .= $alphabet[$this->random->getInt(0, strlen($alphabet) - 1)];
                }

                return substr($code, 0, 5).'-'.substr($code, 5);
            })
            ->all();
    }

    /**
     * Kody zapasowe do zapisania w bazie — WYŁĄCZNIE jako skróty.
     *
     * @param  array<int, string>  $kodyJawne
     * @return array<int, string>
     */
    public function hashBackupCodes(array $kodyJawne): array
    {
        return array_values(array_map(
            static fn (string $kod): string => Hash::make($kod),
            $kodyJawne,
        ));
    }

    /**
     * Zużycie kodu zapasowego — kod znika z listy, więc działa dokładnie RAZ.
     *
     * Porównujemy przez `Hash::check`, bo w bazie nie ma nic jawnego do
     * porównania wprost — zgodnie z wymogiem „kody zapasowe jako skróty,
     * nie jawnie".
     */
    public function consumeBackupCode(User $user, string $podanyKod): bool
    {
        return DB::transaction(function () use ($user, $podanyKod): bool {
            $swiezy = self::podBlokada($user);

            if ($swiezy === null) {
                return false;
            }

            $hashe = $swiezy->two_factor_backup_codes ?? [];
            $znormalizowany = Str::upper(trim($podanyKod));

            foreach ($hashe as $indeks => $hash) {
                // `Hash::check` na bcroście jest CELOWO wolne (~50-100 ms na
                // porównanie), więc przy pełnej liście kodów trzymamy blokadę
                // wiersza nawet sekundę. To jest świadomy koszt: przed tym
                // ekranem stoi limiter prób, konto jest jedno, a poprawność
                // zużycia kodu jest ważniejsza niż te milisekundy.
                if (! Hash::check($znormalizowany, $hash)) {
                    continue;
                }

                unset($hashe[$indeks]);
                $pozostale = array_values($hashe);

                $swiezy->forceFill(['two_factor_backup_codes' => $pozostale])->save();
                self::przepiszNaWolajacego($user, ['two_factor_backup_codes' => $pozostale]);

                return true;
            }

            return false;
        });
    }

    /**
     * Czy kod zapasowy pasuje — BEZ zużycia.
     *
     * Dla miejsc, w których poprawny kod ma otworzyć tylko informację, a nie
     * akcję: cofnięcie usunięcia konta, którego nie ma czego cofać
     * (`AccountDeletionController::cancel`). Zużycie kodu przy odmowie
     * zabierałoby człowiekowi kod ratunkowy za nic. Normalizacja ta sama co
     * w `consumeBackupCode()`; blokady nie trzeba, bo nic tu nie zapisujemy.
     */
    public function backupCodeMatches(User $user, string $podanyKod): bool
    {
        $znormalizowany = Str::upper(trim($podanyKod));

        foreach ($user->two_factor_backup_codes ?? [] as $hash) {
            if (Hash::check($znormalizowany, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Świeży wiersz konta, zablokowany do końca transakcji.
     *
     * TO JEST SEDNO POPRAWKI A6-03. Obie metody wyżej brały stan 2FA
     * z PRZEKAZANEGO obiektu `User` i zapisywały wynik z powrotem, bez
     * sprawdzenia, czy w międzyczasie ktoś tym stanem nie ruszył. Dwa
     * nakładające się przebiegi czytały więc ten sam stan sprzed zużycia
     * i oba go akceptowały:
     *
     *   TOTP            A i B odczytują pusty znacznik, A zapisuje czas kodu,
     *                   B akceptuje TEN SAM kod na starym znaczniku;
     *   kody zapasowe   A i B odczytują komplet [kod1, kod2], A zużywa kod1
     *                   i zapisuje [kod2], B zużywa kod2 ze STAREGO kompletu
     *                   i zapisuje [kod1] — zużyty kod1 WRACA na listę.
     *
     * `lockForUpdate()` w transakcji zamyka oba przypadki naraz: drugi
     * przebieg czeka na pierwszy i czyta stan JUŻ po zużyciu. Nie potrzeba
     * do tego Redisa, globalnej blokady ani osobnej usługi.
     */
    private static function podBlokada(User $user): ?User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->first();
    }

    /**
     * Wynik zapisu przenosimy też na obiekt, który dostaliśmy — inaczej
     * wywołujący zostaje z modelem sprzed zużycia i przy następnym `save()`
     * cofnąłby naszą zmianę. `syncOriginalAttributes` pilnuje, żeby nie
     * uznać za „zmienione" niczego poza tym, co faktycznie zapisaliśmy.
     *
     * @param  array<string, mixed>  $atrybuty
     */
    private static function przepiszNaWolajacego(User $user, array $atrybuty): void
    {
        foreach ($atrybuty as $nazwa => $wartosc) {
            $user->setAttribute($nazwa, $wartosc);
        }

        $user->syncOriginalAttributes(array_keys($atrybuty));
    }
}
