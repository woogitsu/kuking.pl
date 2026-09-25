<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Jobs\PurgePublicMediaCache;
use Illuminate\Support\Facades\DB;

/**
 * Adresy czekające na wyczyszczenie z cache CDN (issue #959).
 *
 * Log nie wystarcza, bo z logu niczego się automatycznie nie dokończy.
 * Ta tabela tak: `kuking:wyczysc-zalegle-cdn` wysyła jej zawartość, gdy
 * konfiguracja Cloudflare jest na miejscu, a `/health` (`cdn_zalegle`)
 * świeci, dopóki cokolwiek w niej leży.
 */
final class ZalegleCzyszczeniaCdn
{
    public const TABELA = 'zalegle_czyszczenia_cdn';

    /** Tyle adresów na jeden przebieg `wyczysc()` — dziesięć żądań po 30. */
    public const PARTIA = 300;

    /** @param list<string> $adresy */
    public static function odloz(array $adresy): void
    {
        $teraz = now();

        // Cloudflare czyści tylko pełne adresy `http(s)://` — i tylko takie
        // przyjmie CHECK tabeli. Adresu względnego nie da się wyczyścić
        // nigdy, więc odłożenie go zablokowałoby resztę partii.
        $pelne = array_filter(
            array_unique($adresy),
            static fn (mixed $adres): bool => is_string($adres) && preg_match('#^https?://#', $adres) === 1,
        );

        $wiersze = array_map(
            static fn (string $adres): array => ['adres' => $adres, 'created_at' => $teraz],
            array_values($pelne),
        );

        if ($wiersze === []) {
            return;
        }

        // Ten sam adres odłożony drugi raz nic nie zmienia — UNIQUE (adres).
        DB::table(self::TABELA)->insertOrIgnore($wiersze);
    }

    public static function ile(): int
    {
        return DB::table(self::TABELA)->count();
    }

    /**
     * Wysyła najstarsze zaległe adresy i kasuje je DOPIERO po potwierdzeniu
     * Cloudflare. Porażka rzuca wyjątek, a wiersze zostają na następny
     * przebieg. Bez konfiguracji nie robi nic — inaczej zadanie odłożyłoby
     * adresy z powrotem, a my skasowalibyśmy je jako „wysłane".
     *
     * @return int ile adresów wyczyszczono
     */
    public static function wyczysc(int $limit = self::PARTIA): int
    {
        if (! PurgePublicMediaCache::skonfigurowane()) {
            return 0;
        }

        $wiersze = DB::table(self::TABELA)->orderBy('id')->limit($limit)->get(['id', 'adres']);

        if ($wiersze->isEmpty()) {
            return 0;
        }

        (new PurgePublicMediaCache($wiersze->pluck('adres')->all()))->handle();

        DB::table(self::TABELA)->whereIn('id', $wiersze->pluck('id')->all())->delete();

        return $wiersze->count();
    }
}
