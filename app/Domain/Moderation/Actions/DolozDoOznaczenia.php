<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SYGNAŁY DOŁOŻONE DO JEDYNEGO OZNACZENIA TREŚCI (#829, #830).
 *
 * Od #829 zadanie zapisuje sygnały lokalne PRZED oceną modelem — wolny
 * dostawca nie może ich zabrać ze sobą. Ocena modelem przychodzi więc do
 * treści, która może już mieć oznaczenie, a `OznaczDoPrzegladu::handle()`
 * odbiłoby się od „jedno oznaczenie na treść" i wynik modelu przepadłby po
 * cichu. To samo przy ocenie zdjęcia, które było gotowe dopiero po
 * publikacji (#830).
 *
 * Obietnica D-052 zostaje nietknięta: dalej JEDEN wiersz na treść. Nowe
 * powody dopisujemy do sprawy, która czeka na moderatora (`open`, `triage`,
 * `reviewing`), a cięższy sygnał przesuwa ją wyżej w kolejce. Sprawy
 * zamkniętej NIE otwieramy — „to nic takiego" nie wraca — ale nowy powód,
 * którego moderator nie widział, zostawia wpis w dzienniku, a nie znika bez
 * śladu.
 *
 * OSOBNA KLASA, A NIE METODA W `OznaczDoPrzegladu`, I NIE CZYTA ZNACZENIA
 * ZWROTU `handle()`. `handle()` oddawało `null` dla treści już oglądanej;
 * po #1051 oddaje ISTNIEJĄCY otwarty wiersz (żeby alarm nie ginął). Gdyby
 * „nie-null" znaczyło tu „nowe oznaczenie, wszystko zapisane", po zmianie
 * #1051 nowe sygnały przestałyby się dopisywać do istniejącej sprawy. Nowe
 * oznaczenie poznajemy więc po `wasRecentlyCreated`, a istniejący wiersz
 * wyszukujemy sami — zachowanie jest takie samo przy obu wersjach
 * `handle()`.
 */
final class DolozDoOznaczenia
{
    private const OTWARTE = [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING];

    public function __construct(private readonly OznaczDoPrzegladu $oznacz) {}

    /**
     * @param  list<Sygnal>  $sygnaly
     * @return array{0: Report, 1: list<Sygnal>}|null oznaczenie i sygnały,
     *                                                które naprawdę do niego trafiły
     *                                                (do alarmu — bez ponownego listu
     *                                                o tym samym)
     */
    public function handle(Post|Comment $tresc, array $sygnaly): ?array
    {
        if ($sygnaly === []) {
            return null;
        }

        $typ = ModeratedContent::typ($tresc);

        if ($typ === null) {
            return null;
        }

        if (! $this->istnieje($typ, $tresc)) {
            $nowe = $this->oznacz->handle($tresc, $sygnaly);

            if ($nowe !== null && $nowe->wasRecentlyCreated) {
                return [$nowe, $sygnaly];
            }

            // Równoległe zadanie zdążyło postawić oznaczenie pierwsze —
            // dopisujemy się do niego jak do każdego istniejącego.
        }

        $wynik = DB::transaction(function () use ($typ, $tresc, $sygnaly): ?array {
            $zgloszenie = $this->zapytanie($typ, $tresc)->lockForUpdate()->first();

            if ($zgloszenie === null) {
                return null;
            }

            $opis = (string) $zgloszenie->details;
            $dolozone = [];

            foreach ($sygnaly as $sygnal) {
                if (! str_contains($opis, '— '.$sygnal->powod)) {
                    $opis .= "\n— ".$sygnal->powod;
                    $dolozone[] = $sygnal;
                }
            }

            if ($dolozone === []) {
                return null;
            }

            if (! in_array($zgloszenie->status, self::OTWARTE, true)) {
                // Bez treści powodu — to opis cudzej treści (AGENTS.md §7).
                Log::warning('Nowy sygnał automatu nie trafił do kolejki: moderator już zamknął oznaczenie tej treści.', [
                    'report_id' => $zgloszenie->getKey(),
                    'sygnaly' => array_map(static fn (Sygnal $s): string => $s->kod, $dolozone),
                    'stage' => 'automat_sprawa_zamknieta',
                ]);

                return null;
            }

            usort($dolozone, static fn (Sygnal $a, Sygnal $b): int => $b->waga() <=> $a->waga());

            $zmiany = ['details' => mb_substr($opis, 0, 2000)];

            if ($dolozone[0]->waga() > (Report::WAGA[$zgloszenie->reason] ?? 0)) {
                $zmiany['reason'] = $dolozone[0]->kod;
            }

            // OBOWIĄZEK ALARMU W TEJ SAMEJ TRANSAKCJI CO SYGNAŁ (#1051).
            // Pilny sygnał dołożony do sprawy, która alarmu jeszcze nie
            // miała, zapisuje `ZALEGLY` razem z opisem — tak jak
            // `OznaczDoPrzegladu` przy nowym oznaczeniu. Worker ubity między
            // zatwierdzeniem a `AlarmujModeratora` zostawia wtedy ślad, który
            // widzi sonda `alarmy_moderacji` w `/health`,
            // zamiast pilnej sprawy bez listu i bez śladu.
            if ($zgloszenie->alarm_pilny_stan === null && $this->pilne($dolozone)) {
                $zgloszenie->alarm_pilny_stan = Report::ALARM_ZALEGLY;
            }

            $zgloszenie->fill($zmiany)->save();

            return [$zgloszenie, $dolozone];
        });

        if ($wynik === null) {
            return null;
        }

        // WPIS POMOCNICZY (D-249, klasa 2), jak w `OznaczDoPrzegladu`: sprawa
        // ma pełny własny ślad w `reports`, a awaria dziennika nie może
        // cofnąć dołożonych sygnałów ani zjeść alarmu.
        AuditLogEntry::recordBezWywracania(
            action: 'content.flagged_by_automat',
            actor: null,
            subject: $wynik[0],
            metadata: [
                'target_type' => $typ,
                'sygnaly' => array_map(static fn (Sygnal $s): string => $s->kod, $wynik[1]),
                'dolozone' => true,
            ],
        );

        return $wynik;
    }

    /** @param  list<Sygnal>  $sygnaly */
    private function pilne(array $sygnaly): bool
    {
        foreach ($sygnaly as $sygnal) {
            if ($sygnal->pilny) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uwaga dla moderatora przy ISTNIEJĄCYM, otwartym oznaczeniu, np. „ocena
     * modelem niepełna" (#829). Sama uwaga oznaczenia nie zakłada — brak
     * czasu na ocenę nie jest powodem, żeby ktoś oglądał czyjś obiad.
     *
     * `$znacznik` to stały początek uwagi: gdy już stoi w opisie, drugiej nie
     * dopisujemy (ponowna analiza po gotowości zdjęcia nie mnoży linii).
     */
    public function uwaga(Post|Comment $tresc, string $uwaga, ?string $znacznik = null): bool
    {
        $typ = ModeratedContent::typ($tresc);

        if ($typ === null) {
            return false;
        }

        return DB::transaction(function () use ($typ, $tresc, $uwaga, $znacznik): bool {
            $zgloszenie = $this->zapytanie($typ, $tresc)
                ->whereIn('status', self::OTWARTE)
                ->lockForUpdate()
                ->first();

            if ($zgloszenie === null || str_contains((string) $zgloszenie->details, $znacznik ?? $uwaga)) {
                return false;
            }

            $zgloszenie->update([
                'details' => mb_substr($zgloszenie->details."\n— ".$uwaga, 0, 2000),
            ]);

            return true;
        });
    }

    private function istnieje(string $typ, Post|Comment $tresc): bool
    {
        return $this->zapytanie($typ, $tresc)->exists();
    }

    /** @return Builder<Report> */
    private function zapytanie(string $typ, Post|Comment $tresc): Builder
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_type', $typ)
            ->where('target_id', $tresc->getKey());
    }
}
