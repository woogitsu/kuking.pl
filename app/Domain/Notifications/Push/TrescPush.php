<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\Notification;
use App\Support\Odmiana;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tekst jednego pushu — także wtedy, gdy zbiera kilka powiadomień.
 *
 * Zasady języka z `docs/product/RETENTION_LOOPS.md` §3.3: imię konkretnej
 * osoby, nazwa potrawy, zero pilności, jedno zdanie i jeden link. Zdania
 * te same co na liście powiadomień (`pages/notifications.blade.php`), bez
 * założenia rodzaju („X — ugotowane z Twojego przepisu", nie „ugotowała").
 *
 * GRUPOWANIE: kilka powiadomień czekających naraz (cisza nocna, limit) daje
 * JEDEN push — najnowsze zdanie i liczbę pozostałych — a nie pięć pushy za
 * pięć komentarzy. Stały `tag` sprawia, że nowy push zastępuje na urządzeniu
 * poprzedni, zamiast układać się w stos.
 *
 * Link zawsze prowadzi na `/powiadomienia`: tam zadziała cała logika
 * „co jeszcze istnieje" (usunięte wykonanie, ukryty komentarz), a push nie
 * wysyła nikogo pod adres treści, której może już nie być.
 */
final class TrescPush
{
    public const TAG = 'kuking-powiadomienia';

    /**
     * @param  Collection<int, Notification>  $powiadomienia  od najnowszego
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function zbuduj(Collection $powiadomienia): array
    {
        $pierwsze = $powiadomienia->first();
        $zdanie = $pierwsze instanceof Notification ? self::zdanie($pierwsze) : 'Masz nowe powiadomienie.';
        $reszta = $powiadomienia->count() - 1;

        if ($reszta > 0) {
            $zdanie .= ' Do tego '.self::inne($reszta).'.';
        }

        return [
            'title' => 'Kuking',
            'body' => $zdanie,
            'url' => route('notifications.index', absolute: false),
            'tag' => self::TAG,
        ];
    }

    public static function zdanie(Notification $powiadomienie): string
    {
        $kto = $powiadomienie->actor?->displayName() ?? 'Ktoś';
        $data = is_array($powiadomienie->data) ? $powiadomienie->data : [];

        return match ($powiadomienie->type) {
            Notification::TYPE_COOKED => $kto.' — ugotowane z Twojego przepisu „'
                .Str::limit((string) ($data['recipe_title'] ?? 'przepis'), 80).'”.',
            Notification::TYPE_COMMENT => $kto.' — odpowiedź na Twoje pytanie.',
            Notification::TYPE_REPLY => $kto.' — nowa odpowiedź.',
            default => 'Masz nowe powiadomienie.',
        };
    }

    /** „1 inne powiadomienie", „3 inne powiadomienia", „5 innych powiadomień". */
    public static function inne(int $ile): string
    {
        return $ile.' '.Odmiana::rzeczownik($ile, 'inne powiadomienie', 'inne powiadomienia', 'innych powiadomień');
    }
}
