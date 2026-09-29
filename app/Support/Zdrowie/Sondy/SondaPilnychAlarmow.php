<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Models\Report;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `alarmy_moderacji` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaPilnychAlarmow implements Sonda
{
    public function nazwa(): string
    {
        return 'alarmy_moderacji';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_SLAD_ALARMOW_NIESPRAWDZALNY;
    }

    /**
     * Czy jakaś PILNA sprawa moderacyjna nie dotarła do nikogo (issue #1051).
     *
     * PO CO TO TU JEST
     * Bo do 22 września 2026 zgubiony alarm nie zostawiał ŻADNEGO śladu.
     * Oznaczenie automatu powstawało we własnej, zamkniętej transakcji,
     * a list do moderatora szedł linijkę później, poza nią; worker ubity
     * w tej szczelinie (`timeout = 30`, `tries = 1`, restart przy wdrożeniu)
     * zostawiał sprawę zapisaną i alarm niewysłany. Każda kolejna analiza
     * tej samej treści zatrzymywała się na `OznaczDoPrzegladu` i milczała,
     * więc zgubione zostawało zgubione — a dotyczy to JEDYNYCH dwóch
     * kategorii, przy których doba zwłoki jest realną szkodą: treści
     * seksualnych i wszystkiego, co dotyczy dziecka.
     *
     * Dochodzi do tego stan, który nie jest awarią kodu i którego żadne
     * ponowienie nie naprawi: pusty `KUKING_MODEL_ALARM_EMAIL`. Dziś na
     * produkcji kanał alarmowy jest z tego powodu wyłączony, a rejestracja
     * stoi otworem — więc sprawa, która tu przepadnie, nie dotrze NIGDZIE.
     * Ma własny kod powodu, bo operator naprawia to wpisaniem adresu,
     * a nie szukaniem błędu.
     *
     * KIEDY SONDA GAŚNIE — REGUŁA (issue #1051, po przeglądzie)
     * Liczy się sprawa z `Report::pilneDoDoslania()`, czyli:
     *
     *  1. OTWARTA. Sprawa rozstrzygnięta albo odrzucona ma za sobą decyzję
     *     człowieka — pytanie „czy ktoś o niej wie" ma już odpowiedź. Ślad
     *     w bazie zostaje (`pilneBezAlarmu()`), sonda nie. Dzięki temu
     *     zamknięcie sprawy w panelu gasi sondę bez SQL-a.
     *  2. MŁODSZA NIŻ `kuking.moderation.model.alarm_sonda_godzin` (72 h)
     *     od powstania. Komenda `kuking:doslij-pilne-alarmy` próbuje co
     *     godzinę; sprawa, której przez trzy doby nie udało się dosłać,
     *     i tak leży w kolejce panelu i w porannym podsumowaniu automatu.
     *     72, a nie 24: sprawa z piątku wieczorem ma świecić jeszcze
     *     w poniedziałek rano. Stałego czerwonego światła, którego nie da
     *     się zgasić inaczej niż ręką w bazie, operator uczy się nie widzieć.
     *
     * KOD POWODU WYNIKA Z KONFIGURACJI, NIE Z ZAPISANEGO STANU. Po wpisaniu
     * adresu sprawy mają jeszcze przez chwilę stan `bez_adresu` — do
     * najbliższego przebiegu komendy. Meldowanie wtedy
     * `kanal_alarmowy_wylaczony` wysłałoby operatora do ustawień, które już
     * poprawił. Pusty adres → `kanal_alarmowy_wylaczony`; adres jest, a sprawa
     * nadal bez alarmu → `pilny_alarm_nie_dotarl`.
     *
     * `alarmy_moderacji` NIE JEST na liście `KRYTYCZNE` — to samo, co przy
     * `listy`. Sprawa, o której nikt nie wie, nie jest powodem, żeby Railway
     * restartował serwis; jest powodem, żeby monitoring zapalił się na
     * czerwono i został taki, dopóki ktoś nie zajrzy.
     */
    public function sprawdz(): void
    {
        $okno = max(1, (int) config('kuking.moderation.model.alarm_sonda_godzin', 72));

        $bezAlarmu = Report::query()
            ->pilneDoDoslania()
            ->where('created_at', '>=', now()->subHours($okno))
            ->count();

        if ($bezAlarmu === 0) {
            return;
        }

        $adres = config('kuking.moderation.model.alarm_email');
        $wylaczony = ! is_string($adres) || $adres === '';

        throw new KontrolaZdrowiaNieprzeszla(
            $wylaczony ? Powody::POWOD_KANAL_ALARMOWY_WYLACZONY : Powody::POWOD_PILNY_ALARM_NIE_DOTARL,
            'Pilnych spraw moderacyjnych bez alarmu: '.$bezAlarmu.'. '
            .($wylaczony
                ? 'KUKING_MODEL_ALARM_EMAIL jest pusty — po wpisaniu adresu kuking:doslij-pilne-alarmy dośle je w ciągu godziny. '
                : 'kuking:doslij-pilne-alarmy ponawia co godzinę. Jeśli dziennik mówi o dobowym suficie alarmów (KUKING_MODEL_ALARM_SUFIT), sprawy czekają na jego odnowienie — to nie awaria poczty. ')
            .'Obejrzyj w panelu: /admin/sygnaly',
        );
    }
}
