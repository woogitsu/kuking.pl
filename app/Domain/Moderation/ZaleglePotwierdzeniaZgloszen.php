<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;
use App\Models\User;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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
     * Ile spraw (zgłoszeń prawnych bez konta) przegląda jeden przebieg. Sufit
     * chroni przed pełnym skanem przy awarii poczty, gdy zaległości rosną;
     * poniżej niego wynik jest dokładny. Po obcięciu idzie wpis do logu.
     */
    public const SUFIT_PRZEGLADANYCH_SPRAW = 5000;

    private const PARTIA_SPRAW = 200;

    /**
     * Numery zaległych spraw (zgłoszenia prawne bez konta), przy których
     * dosyłka przestała ponawiać list, bo osiągnęły sufit porażek.
     *
     * Sprawy idą partiami (`chunkById`), a liczniki porażek partii czyta jeden
     * `Cache::many()` — liczba zapytań rośnie z partiami, nie ze sprawami.
     *
     * @return list<string>
     */
    public static function numeryNaSuficie(int $sufit = self::SUFIT_PRZEGLADANYCH_SPRAW): array
    {
        $trafione = [];
        $przejrzane = 0;
        $obcieto = false;

        self::zapytanie()->whereNull('reporter_id')
            ->chunkById(self::PARTIA_SPRAW, function ($partia) use (&$trafione, &$przejrzane, &$obcieto, $sufit): bool {
                $zapas = $sufit - $przejrzane;

                if ($partia->count() > $zapas) {
                    $partia = $partia->take($zapas);
                    $obcieto = true;
                }

                $przejrzane += $partia->count();

                $wstrzymane = array_flip(PotwierdzenieZgloszeniaNielegalnejTresci::wstrzymaneSposrod(
                    $partia->map(fn (Report $sprawa): string => (string) $sprawa->getKey())->all(),
                ));

                foreach ($partia as $sprawa) {
                    if (isset($wstrzymane[(string) $sprawa->getKey()])) {
                        $trafione[] = $sprawa;
                    }
                }

                if ($przejrzane >= $sufit) {
                    $obcieto = $obcieto || self::zapytanie()->whereNull('reporter_id')->count() > $przejrzane;

                    return false;
                }

                return true;
            }, 'id');

        if ($obcieto) {
            Log::warning('Liczenie spraw na suficie prób potwierdzenia DSA obcięto do '.$sufit.' spraw — liczba może być zaniżona.');
        }

        usort($trafione, fn (Report $a, Report $b): int => $a->created_at <=> $b->created_at);

        return array_map(fn (Report $sprawa): string => (string) ($sprawa->numer_sprawy ?? $sprawa->getKey()), $trafione);
    }

    public static function ileNaSuficie(): int
    {
        return count(self::numeryNaSuficie());
    }
}
