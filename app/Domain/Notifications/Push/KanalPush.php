<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\Notification;

/**
 * Czy Web Push w ogóle działa i których powiadomień dotyczy (D-303).
 *
 * BRAK KLUCZA = FUNKCJI NIE MA. Bez klucza VAPID (albo przy awaryjnie
 * wyłączonej fladze `kuking.notifications.zewnetrzne.wlaczone`) nie ma
 * ekranu ustawień, przycisku ani wysyłki. Nikt nie zobaczy przycisku, który
 * nic nie robi (D-053).
 *
 * PUSHEM IDĄ TYLKO ODZEWY NA WŁASNE TREŚCI — lista jest jawna i wąska
 * (`docs/product/RETENTION_LOOPS.md` §3.2, wiersz „Web Push (V1)"):
 *  - „Ugotowałem" z mojego przepisu,
 *  - odpowiedź na mój komentarz (albo w rozmowie pod moją treścią),
 *  - odpowiedź na moje pytanie.
 * Obserwowanie, zapis do zeszytu, powitanie, moderacja — tylko w serwisie.
 * Nowy rodzaj dopisuje się tutaj ŚWIADOMIE, nie przez „wszystko oprócz".
 *
 * Granice z AGENTS.md §1 (własna akcja, zamknięte konto, blokada) obowiązują
 * tu same z siebie: push powstaje wyłącznie z wiersza, który `NotifyUser`
 * już zapisał, a przed wysyłką przechodzi przez `Notification::visibleTo()`.
 */
final class KanalPush
{
    /** @var list<string> */
    public const TYPY = [
        Notification::TYPE_COOKED,
        Notification::TYPE_REPLY,
        Notification::TYPE_COMMENT,
    ];

    /**
     * Czy pokazać ekran i przycisk oraz planować wysyłkę. Wystarczy klucz
     * PUBLICZNY: web nie wysyła, więc nie dostaje klucza prywatnego
     * (`.railway/railway.ts`, zasada najmniejszych uprawnień z #1013).
     */
    public static function dostepny(): bool
    {
        return (bool) config('kuking.notifications.zewnetrzne.wlaczone', true)
            && self::kluczPubliczny() !== null;
    }

    /** Czy ten proces może podpisać wysyłkę — worker ma oba klucze. */
    public static function mozeWysylac(): bool
    {
        return self::dostepny() && filled(config('kuking.push.vapid_private_key'));
    }

    public static function kluczPubliczny(): ?string
    {
        $klucz = config('kuking.push.vapid_public_key');

        return is_string($klucz) && trim($klucz) !== '' ? trim($klucz) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function dotyczy(string $typ, array $data): bool
    {
        return match ($typ) {
            Notification::TYPE_COOKED, Notification::TYPE_REPLY => true,
            // Zwykły komentarz pod wpisem — tylko w serwisie. Pushem idzie
            // wyłącznie ODPOWIEDŹ na pytanie (`question_answer`).
            Notification::TYPE_COMMENT => ($data['question_answer'] ?? false) === true,
            default => false,
        };
    }

    /**
     * Czy adres subskrypcji wskazuje na znaną usługę push.
     *
     * Adres podaje przeglądarka, czyli każdy, kto wyśle żądanie. Bez tej
     * listy nasz serwer wysyłałby POST pod dowolny adres — także wewnętrzny
     * (SSRF). Tylko `https`, bez loginu w adresie, bez niestandardowego portu.
     */
    public static function hostDozwolony(string $endpoint): bool
    {
        $czesci = parse_url($endpoint);

        if (! is_array($czesci)
            || ($czesci['scheme'] ?? null) !== 'https'
            || isset($czesci['user'])
            || isset($czesci['pass'])
            || (isset($czesci['port']) && $czesci['port'] !== 443)
            || ! isset($czesci['host'])) {
            return false;
        }

        $host = strtolower($czesci['host']);

        foreach ((array) config('kuking.push.dozwolone_hosty', []) as $dozwolony) {
            $dozwolony = strtolower((string) $dozwolony);

            if ($dozwolony !== '' && ($host === $dozwolony || str_ends_with($host, '.'.$dozwolony))) {
                return true;
            }
        }

        return false;
    }
}
