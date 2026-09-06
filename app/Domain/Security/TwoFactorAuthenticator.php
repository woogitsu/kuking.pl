<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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

    public function __construct()
    {
        $this->engine = new Google2FA;
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
        $dopasowanyCzas = $this->engine->verifyKeyNewer(
            $secret,
            $code,
            $user->two_factor_last_used_at ?? 0,
            (int) config('kuking.two_factor.window'),
        );

        if ($dopasowanyCzas === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_at' => $dopasowanyCzas])->save();

        return true;
    }

    /**
     * Nowy komplet kodów zapasowych, w postaci jawnej — do pokazania RAZ.
     *
     * Format „XXXX-XXXX" (litery i cyfry, bez znaków łatwych do pomylenia
     * przy przepisywaniu z kartki — Str::random domyślnie miesza wielkość
     * liter i cyfry z alfanumerycznego zbioru).
     */
    public function generateBackupCodes(): array
    {
        $ile = (int) config('kuking.two_factor.recovery_codes');

        return collect(range(1, $ile))
            ->map(fn (): string => Str::upper(Str::random(4).'-'.Str::random(4)))
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
        $hashe = $user->two_factor_backup_codes ?? [];
        $znormalizowany = Str::upper(trim($podanyKod));

        foreach ($hashe as $indeks => $hash) {
            if (Hash::check($znormalizowany, $hash)) {
                unset($hashe[$indeks]);
                $user->forceFill(['two_factor_backup_codes' => array_values($hashe)])->save();

                return true;
            }
        }

        return false;
    }
}
