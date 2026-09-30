<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

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
 * ZDJĘCIE: JEDEN WPIS NA GODZINĘ NA (moderator, zdjęcie, powód, sprawy).
 * Jedno otwarcie sprawy w `/admin/sygnaly` to kilka żądań o ten sam plik
 * (miniatura, wariant duży, odświeżenie); wpis za każde z nich zalewałby
 * dziennik bez żadnej dodatkowej informacji. Powód i sprawy (identyfikatory
 * zgłoszeń albo treści) są częścią klucza, bo wgląd w INNĄ sprawę o to samo
 * zdjęcie w tej samej godzinie to osobny wgląd i nie wolno go zgubić
 * (przegląd integracyjny D-333). Okno liczymy po `created_at` istniejących
 * wpisów, bez pamięci podręcznej; zapytanie idzie indeksem
 * `audit_log_actor_idx` i woła się wyłącznie dla wglądu z urzędu.
 *
 * „BEZ ROLI" rozstrzyga `jakZwykleKonto()`: to samo konto z rolą `user`,
 * nigdzie niezapisane. Wpis powstaje tylko wtedy, gdy ono by NIE weszło.
 */
final class DziennikWgladu
{
    public const ZDJECIE = 'moderation.media_viewed';

    public const PRZEPIS_UKRYTY = 'moderation.hidden_recipe_viewed';

    public const POWOD_ZGLOSZENIE = 'zgloszenie';

    public const POWOD_UKRYTA_TRESC = 'ukryta_tresc';

    /** Treść jawna z nazwy, ale niewidoczna bez roli (np. konto autora zbanowane). */
    public const POWOD_ROLA = 'rola_moderatora';

    /** Okno, w którym kolejne otwarcia tego samego zdjęcia są jednym wglądem. */
    public const OKNO_ZDJECIA_MINUTY = 60;

    /** Najwięcej identyfikatorów spraw w jednym wpisie. */
    public const MAKS_SPRAW = 10;

    /**
     * To samo konto BEZ roli obsługi — do pytania „czy zobaczyłby to bez
     * roli". Kopia w pamięci, nigdy niezapisywana; pole sterujące `role`
     * ustawiamy `forceFill`, bo nie jest w `$fillable` (AGENTS.md §7).
     */
    public static function jakZwykleKonto(User $konto): User
    {
        $kopia = clone $konto;
        $kopia->forceFill(['role' => User::ROLE_USER]);

        return $kopia;
    }

    /**
     * @param  list<string>  $sprawy
     */
    public function zdjecie(User $moderator, Media $zdjecie, string $powod, ?string $ip, array $sprawy = []): void
    {
        $metadata = ['powod' => $powod, 'sprawy' => array_values($sprawy)];

        $juzZapisane = AuditLogEntry::query()
            ->where('actor_id', $moderator->getKey())
            ->where('action', self::ZDJECIE)
            ->where('subject_type', class_basename($zdjecie))
            ->where('subject_id', $zdjecie->getKey())
            ->where('created_at', '>', now()->subMinutes(self::OKNO_ZDJECIA_MINUTY))
            ->whereRaw('metadata = ?::jsonb', [json_encode($metadata, JSON_THROW_ON_ERROR)])
            ->exists();

        if ($juzZapisane) {
            return;
        }

        AuditLogEntry::record(
            action: self::ZDJECIE,
            actor: $moderator,
            subject: $zdjecie,
            metadata: $metadata,
            ip: $ip,
        );
    }

    /**
     * Przepis niewidoczny bez roli obsługi (ukryty, zdjęty, konto autora
     * zbanowane), otwarty przez moderatora, który nie jest autorem. Wołane
     * PO `authorize('view')` ze strony przepisu, trybu gotowania i API.
     * Zwykły widz nie płaci nic: pierwszy warunek to `isModerator()`.
     */
    public function przepis(Recipe $przepis, ?User $widz, ?string $ip): void
    {
        if ($widz === null
            || ! $widz->isModerator()
            || $widz->getKey() === $przepis->author_id
            || Gate::forUser(self::jakZwykleKonto($widz))->allows('view', $przepis)) {
            return;
        }

        AuditLogEntry::record(
            action: self::PRZEPIS_UKRYTY,
            actor: $widz,
            subject: $przepis,
            metadata: [
                'powod' => in_array($przepis->status, [Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED], true)
                    ? self::POWOD_UKRYTA_TRESC
                    : self::POWOD_ROLA,
                'status' => $przepis->status,
            ],
            ip: $ip,
        );
    }
}
