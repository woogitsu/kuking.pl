<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Comment;
use App\Models\Profile;
use App\Models\Recipe;

/**
 * Czego dotyczy adres wklejony w zgłoszeniu prawnym.
 *
 * Wyjęte z `ZgloszenieNielegalnejTresciController`, bo o to samo pyta
 * `ReportPolicy::decide()` (audyt B2-02): zgłoszenie, którego cel nie został
 * rozpoznany przy przyjęciu, dalej może dotyczyć moderatora, który je otwiera.
 *
 * NIEUDANA PRÓBA NIE JEST BŁĘDEM. Ktoś wkleja link z pamięci albo ze zrzutu
 * ekranu, treść mogła już zniknąć, adres może być z innego serwisu.
 * Zgłoszenie i tak musi zostać przyjęte — moderator zobaczy wtedy sam adres.
 *
 * CO ROZPOZNAJEMY
 *  - `/przepisy/{slug}` (i dawne `/przepis/…`) → przepis,
 *  - `/wpisy/{uuid}`, `/pytania/{uuid}` → wpis,
 *  - `/ugotowane/{uuid}` → wykonanie,
 *  - `/@{login}` i każda podstrona profilu → konto (B2-02),
 *  - `#komentarz-{uuid}` przy dowolnej z tych stron → komentarz (B2-02).
 *    Do 25 września 2026 taki adres wskazywał wpis nadrzędny, więc
 *    moderator, który napisał komentarz pod cudzym wpisem, rozstrzygał
 *    o własnym komentarzu.
 */
final class CelZAdresuZgloszenia
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return array{0: string|null, 1: string|null} typ celu i jego identyfikator
     */
    public static function rozpoznaj(string $adres): array
    {
        $adres = trim($adres);

        $komentarz = self::komentarzZFragmentu($adres);

        if ($komentarz !== null) {
            return ['comment', $komentarz];
        }

        $sciezka = parse_url($adres, PHP_URL_PATH);

        if (! is_string($sciezka)) {
            return [null, null];
        }

        $segmenty = array_values(array_filter(
            array_map(rawurldecode(...), explode('/', $sciezka)),
            fn (string $s): bool => $s !== '',
        ));

        if ($segmenty === []) {
            return [null, null];
        }

        if (str_starts_with($segmenty[0], '@')) {
            $profil = Profile::poNazwie(mb_substr($segmenty[0], 1));

            return $profil === null ? [null, null] : ['user', (string) $profil->user_id];
        }

        if (count($segmenty) < 2) {
            return [null, null];
        }

        [$pierwszy, $drugi] = $segmenty;

        return match ($pierwszy) {
            'przepis', 'przepisy' => ['recipe', self::idPrzepisu($drugi)],
            'wpis', 'wpisy', 'pytania' => ['post', self::uuidAlbo($drugi)],
            'ugotowane' => ['cooked_event', self::uuidAlbo($drugi)],
            default => [null, null],
        };
    }

    /**
     * Komentarz tylko wtedy, gdy naprawdę istnieje — nieistniejący
     * identyfikator we fragmencie nie może zabrać zgłoszeniu strony, którą
     * rozpoznamy ze ścieżki.
     */
    private static function komentarzZFragmentu(string $adres): ?string
    {
        $fragment = parse_url($adres, PHP_URL_FRAGMENT);

        if (! is_string($fragment) || preg_match('/^komentarz-(.+)$/', $fragment, $m) !== 1) {
            return null;
        }

        $id = self::uuidAlbo($m[1]);

        if ($id === null || ! Comment::withTrashed()->whereKey($id)->exists()) {
            return null;
        }

        return $id;
    }

    private static function idPrzepisu(string $segment): ?string
    {
        // Adres przepisu niesie slug, nie UUID — trzeba go przetłumaczyć.
        return Recipe::query()->where('slug', $segment)->value('id')
            ?? self::uuidAlbo($segment);
    }

    private static function uuidAlbo(string $segment): ?string
    {
        return preg_match(self::UUID, $segment) === 1 ? $segment : null;
    }
}
