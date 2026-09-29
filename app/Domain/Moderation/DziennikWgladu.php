<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;

/**
 * Ślad wglądu moderatora w treść, której nie widać publicznie (D-333).
 *
 * NIE MA TU NOWEJ TABELI. Dziennik wglądów to zwykły `audit_log` — ta sama
 * retencja (12 miesięcy, `kuking:sprzataj-audyt`), ten sam skrót IP i ta sama
 * zasada co przy `admin.user_viewed` oraz `moderation.hidden_post_viewed`:
 * logujemy FAKT i AKTORA, nigdy treść.
 *
 * KIEDY WPIS POWSTAJE — I DLACZEGO TYLKO WTEDY
 * Wyłącznie gdy moderator otwiera coś, czego nie zobaczyłby bez roli:
 *  - zdjęcie, które jest celem zgłoszenia (`powod = zgloszenie`),
 *  - zdjęcie treści ukrytej przez moderację (`powod = ukryta_tresc`),
 *  - stronę przepisu ukrytego przez moderację.
 * Wyświetlenia publiczne, wejścia autora i wejścia moderatora w treść jawną
 * nie zostawiają nic — inaczej dziennik utonąłby w szumie (feed to setki
 * żądań o zdjęcia na stronę), a podstawa „wgląd z urzędu" nie obejmowałaby
 * zwykłego czytania.
 *
 * ZDJĘCIE: JEDEN WPIS NA GODZINĘ NA PARĘ (moderator, zdjęcie). Jedno otwarcie
 * sprawy w `/admin/sygnaly` to kilka żądań o ten sam plik (miniatura, wariant
 * duży, odświeżenie); wpis za każde z nich zalewałby dziennik bez żadnej
 * dodatkowej informacji. Okno liczymy po `created_at` istniejących wpisów, bez
 * pamięci podręcznej.
 */
final class DziennikWgladu
{
    public const ZDJECIE = 'moderation.media_viewed';

    public const PRZEPIS_UKRYTY = 'moderation.hidden_recipe_viewed';

    public const POWOD_ZGLOSZENIE = 'zgloszenie';

    public const POWOD_UKRYTA_TRESC = 'ukryta_tresc';

    /** Okno, w którym kolejne otwarcia tego samego zdjęcia są jednym wglądem. */
    public const OKNO_ZDJECIA_MINUTY = 60;

    public function zdjecie(User $moderator, Media $zdjecie, string $powod, ?string $ip): void
    {
        $juzZapisane = AuditLogEntry::query()
            ->where('actor_id', $moderator->getKey())
            ->where('action', self::ZDJECIE)
            ->where('subject_type', class_basename($zdjecie))
            ->where('subject_id', $zdjecie->getKey())
            ->where('created_at', '>', now()->subMinutes(self::OKNO_ZDJECIA_MINUTY))
            ->exists();

        if ($juzZapisane) {
            return;
        }

        AuditLogEntry::record(
            action: self::ZDJECIE,
            actor: $moderator,
            subject: $zdjecie,
            metadata: ['powod' => $powod],
            ip: $ip,
        );
    }

    /**
     * Strona przepisu ukrytego przez moderację, otwarta przez kogoś innego niż
     * autor. `RecipePolicy::view()` wpuszcza tam poza autorem wyłącznie
     * moderatora, więc każde takie wejście jest wglądem z urzędu.
     */
    public function przepis(Recipe $przepis, ?User $widz, ?string $ip): void
    {
        if ($widz === null
            || $przepis->status !== Recipe::STATUS_HIDDEN
            || $widz->getKey() === $przepis->author_id) {
            return;
        }

        AuditLogEntry::record(
            action: self::PRZEPIS_UKRYTY,
            actor: $widz,
            subject: $przepis,
            ip: $ip,
        );
    }
}
