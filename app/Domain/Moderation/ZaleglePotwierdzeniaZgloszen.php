<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;
use App\Models\User;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jedna definicja „zaległego potwierdzenia przyjęcia zgłoszenia" (DSA art. 16
 * ust. 4) i „sprawy na suficie prób listu" (#2218, D-333).
 *
 * Stała tu, a nie w komendzie dosyłki, bo pytają o nią TRZY miejsca:
 * dosyłka (`kuking:dosylaj-potwierdzenia-zgloszen`), sonda informacyjna
 * `/health` i komenda operatora `kuking:potwierdz-zgloszenie-inna-droga`.
 * Trzy kopie warunków rozjechałyby się przy pierwszej zmianie — i wtedy
 * `/health` mówiłby o innych sprawach, niż alarmuje dosyłka.
 *
 * Uzasadnienie każdego warunku zaległości: nagłówek
 * `DosylajPotwierdzeniaZgloszen` („czego komenda nie rusza", punkty 1–4).
 */
final class ZaleglePotwierdzeniaZgloszen
{
    /**
     * Zaległość = sprawa z ADRESATEM (konto w serwisie albo adres e-mail
     * zgłoszenia prawnego), bez znacznika potwierdzenia i bez doręczonej
     * decyzji.
     *
     * @return Builder<Report>
     */
    public static function zapytanie(): Builder
    {
        return Report::query()
            ->whereNull('receipt_sent_at')
            ->whereNull('decision_sent_at')
            ->where(function (Builder $adresat): void {
                $adresat->whereHas('reporter', fn (Builder $konto) => $konto->where('status', '!=', User::STATUS_ERASED))
                    ->orWhere(fn (Builder $prawne) => $prawne
                        ->whereNull('reporter_id')
                        ->where('source', Report::SOURCE_LEGAL_NOTICE)
                        ->whereNotNull('notifier_email'));
            });
    }

    /**
     * Numery zaległych spraw (zgłoszenia prawne bez konta), przy których
     * dosyłka przestała ponawiać list, bo osiągnęły sufit porażek.
     *
     * @return list<string>
     */
    public static function numeryNaSuficie(): array
    {
        $numery = [];

        foreach (self::zapytanie()->whereNull('reporter_id')->orderBy('created_at')->get(['id', 'numer_sprawy']) as $sprawa) {
            if (PotwierdzenieZgloszeniaNielegalnejTresci::ponawianieWstrzymane((string) $sprawa->getKey())) {
                $numery[] = (string) ($sprawa->numer_sprawy ?? $sprawa->getKey());
            }
        }

        return $numery;
    }

    public static function ileNaSuficie(): int
    {
        return count(self::numeryNaSuficie());
    }
}
