<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

/** Linki są dodatkiem do zwykłego tekstu, nigdy interpretacją HTML autora. */
final class LinkiWTekscie
{
    public static function bezpiecznyAdres(string $adres): ?string
    {
        if (strlen($adres) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/u', $adres)
            || preg_match('/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $adres)
            || preg_match('/%0[ad]/i', $adres) || str_contains($adres, '…')) {
            return null;
        }

        if (str_starts_with(strtolower($adres), 'www.')) {
            $adres = 'https://'.$adres;
        }

        $czesci = parse_url($adres);
        if ($czesci === false || ! in_array(strtolower($czesci['scheme'] ?? ''), ['http', 'https'], true)
            || isset($czesci['user']) || isset($czesci['pass']) || empty($czesci['host'])
            || filter_var($adres, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $adres;
    }

    public static function wewnetrzny(string $adres): bool
    {
        $cel = parse_url($adres);
        $aplikacja = parse_url((string) config('app.url'));
        if ($cel === false || $aplikacja === false) {
            return false;
        }
        $port = static fn (array $url): int => $url['port'] ?? (strtolower($url['scheme'] ?? '') === 'https' ? 443 : 80);

        return strtolower($cel['host'] ?? '') === strtolower($aplikacja['host'] ?? '')
            && strtolower($cel['scheme'] ?? '') === strtolower($aplikacja['scheme'] ?? '')
            && $port($cel) === $port($aplikacja);
    }

    public static function render(string $tekst): HtmlString
    {
        preg_match_all('~(?<![\pL\pN_@])(?:https?://|www\.)[^\s<>"\'`„“”«»‘’]+~iu', $tekst, $trafienia, PREG_OFFSET_CAPTURE);
        $html = '';
        $offset = 0;
        foreach ($trafienia[0] as [$kandydat, $start]) {
            $html .= e(substr($tekst, $offset, $start - $offset));
            $etykieta = rtrim($kandydat, '.,;:!?');
            foreach ([')' => '(', ']' => '[', '}' => '{'] as $koniec => $poczatek) {
                while (str_ends_with($etykieta, $koniec) && substr_count($etykieta, $koniec) > substr_count($etykieta, $poczatek)) {
                    $etykieta = substr($etykieta, 0, -1);
                }
            }
            $adres = self::bezpiecznyAdres($etykieta);
            if ($adres === null) {
                $html .= e($kandydat);
            } else {
                // Porównujemy pełny origin z konfiguracją, nie prefiks ani nagłówek Host.
                $href = self::wewnetrzny($adres) ? $adres : route('links.external', ['cel' => Crypt::encryptString($adres)]);
                $html .= '<a class="link-w-tresci" href="'.e($href).'" rel="nofollow ugc">'.e($etykieta).'</a>'.e(substr($kandydat, strlen($etykieta)));
            }
            $offset = $start + strlen($kandydat);
        }

        return new HtmlString($html.e(substr($tekst, $offset)));
    }
}
