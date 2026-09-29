<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\KolejkiPanelu;
use App\Domain\Moderation\WynikZamknieciaGrupy;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * „To nic takiego" dla CAŁEJ grupy oznaczeń automatu — reguły, blokada
 * i transakcja jako jeden przypadek użycia.
 *
 * Wyjęte z `Admin\SygnalyController::odrzucGrupe()` bez zmiany zachowania
 * (issue #970): ta sama kolejność sprawdzeń, ta sama blokada wierszy, te same
 * rozstrzygnięcia. Wejście HTTP (rola, pola formularza) sprawdza kontroler;
 * tu przychodzą już zwalidowane dane. Zdań dla moderatora akcja nie zna —
 * oddaje `WynikZamknieciaGrupy`. Awaria bazy leci wyjątkiem, po wycofaniu
 * transakcji w całości; kontroler decyduje, co człowiek wtedy zobaczy.
 *
 * Jedna decyzja zamyka wszystkie otwarte oznaczenia jednego konta; każde
 * dostaje WŁASNY wiersz w `moderation_actions` (log odpowiada na pytanie
 * „co się stało z TĄ treścią"). Nie powiadamiamy autora: `no_action` znaczy,
 * że tej osobie NIC się nie stało. Odrzucone oznaczenie nie wraca — indeks
 * `reports_jeden_automat_na_tresc` nie pozwoli automatowi postawić drugiego.
 *
 * ZAKRES TO ZBIÓR Z EKRANU, NIE „WSZYSTKO, CO JEST OTWARTE TERAZ" (#1059):
 * znacznik stanu (`stan_ile`, `stan_najnowsze`) mówi, czy grupa urosła od
 * wyświetlenia strony; gdy tak, nie zamykamy niczego. Serwer i tak wybiera
 * wiersze SAM — autor, otwarty status, pod blokadą.
 */
final class ZamknijGrupeSygnalow
{
    /** Powód w logu przy zamknięciu grupy — patrz `PodstawaDecyzji`: kod spoza listy nie dostaje numeru punktu i tak ma być. */
    public const POWOD = 'automat-falszywy-alarm';

    /**
     * Otwarte oznaczenia automatu — jedno miejsce, w którym rozstrzyga się „co jeszcze czeka".
     *
     * @return Builder<Report>
     */
    public static function otwarte(): Builder
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING]);
    }

    /**
     * @param  string  $autor  identyfikator konta autora oznaczonych treści albo `brak`
     * @param  int  $stanIle  ile otwartych oznaczeń miała grupa na ekranie
     * @param  string  $stanNajnowsze  identyfikator najnowszego z nich
     */
    public function handle(User $moderator, string $autor, int $stanIle, string $stanNajnowsze, ?string $notatka, ?string $ip): WynikZamknieciaGrupy
    {
        $autorId = $autor === 'brak' ? null : $autor;

        // WŁASNYCH OZNACZEŃ NIE ZAMYKASZ (audyt A5-11). Bez tego moderator
        // zamykał jednym kliknięciem wszystkie oznaczenia automatu przy
        // własnych treściach, zanim zobaczył je ktoś inny z zespołu — ten sam
        // konflikt interesów, który przy zgłoszeniach od ludzi blokuje
        // `ReportPolicy::decide` (`SPRAWA_O_CIEBIE`). `strtolower`, bo
        // PostgreSQL porównuje UUID bez względu na wielkość liter: `ABC…`
        // w polu trafiłoby w ten sam wiersz, a zwykłe `===` by go przepuściło.
        //
        // Sama wielkość liter nie wystarcza: PostgreSQL przyjmuje UUID także
        // bez myślników i w klamrach (`{…}`), a oba zapisy trafiają w ten sam
        // wiersz. Dlatego najpierw wymagamy postaci kanonicznej — formularz
        // i tak wysyła tylko ją albo `brak`.
        if ($autorId !== null && ! Str::isUuid($autorId)) {
            return new WynikZamknieciaGrupy(WynikZamknieciaGrupy::NIEZNANA_GRUPA);
        }

        if ($autorId !== null && strtolower($autorId) === strtolower((string) $moderator->getKey())) {
            return new WynikZamknieciaGrupy(WynikZamknieciaGrupy::WLASNE);
        }

        [$ile, $urosla] = DB::transaction(function () use ($autorId, $moderator, $notatka, $ip, $stanIle, $stanNajnowsze): array {
            $oznaczenia = self::otwarte()
                ->when($autorId === null,
                    fn ($q) => $q->whereNull('autor_tresci_id'),
                    fn ($q) => $q->where('autor_tresci_id', $autorId),
                )
                // Blokada wiersza z tego samego powodu co w `decide()`:
                // dwie karty moderatora nie mogą wydać dwóch decyzji do
                // jednego oznaczenia (`moderation_actions` ma UNIQUE na
                // `report_id`).
                ->lockForUpdate()
                ->get();

            // Dopisane po odczycie strony (#1059). Nic jeszcze nie
            // zapisaliśmy, więc wyjście tutaj zostawia grupę dokładnie taką,
            // jaka była.
            if ($this->grupaUrosla($oznaczenia, $autorId, $stanIle, $stanNajnowsze)) {
                return [0, true];
            }

            foreach ($oznaczenia as $oznaczenie) {
                ModerationAction::create([
                    'moderator_id' => $moderator->getKey(),
                    'report_id' => $oznaczenie->getKey(),
                    'target_type' => $oznaczenie->target_type,
                    'target_id' => $oznaczenie->target_id,
                    'subject_user_id' => $oznaczenie->autor_tresci_id,
                    'action' => ModerationAction::ACTION_NONE,
                    'reason_code' => self::POWOD,
                    'note' => $notatka ?? 'Automat się pomylił — treść zostaje bez zmian.',
                ]);
            }

            // JEDEN masowy UPDATE zamiast zapisu po wierszu (audyt B4 W3).
            // Zapis po wierszu odpalał hak `saved` i przeliczał liczniki
            // panelu przy KAŻDYM oznaczeniu — pod blokadą całej grupy.
            // Masowy UPDATE nie odpala zdarzeń modelu, więc liczniki
            // odświeżamy jawnie, raz, po commicie.
            if ($oznaczenia->isNotEmpty()) {
                Report::query()->whereKey($oznaczenia->modelKeys())->update([
                    'status' => Report::STATUS_REJECTED,
                    'resolution_note' => $notatka,
                    'resolved_by' => $moderator->getKey(),
                    'resolved_at' => now(),
                ]);

                app(KolejkiPanelu::class)->odswiez();
            }

            $ile = $oznaczenia->count();

            // Wpis zbiorczy jest CZĘŚCIĄ tej decyzji, więc stoi w jej
            // transakcji, jak `moderation.decided` w `ModerationController`
            // (D-249, #1343). Awaria dziennika cofa decyzje i statusy
            // razem z nim, a ponowienie daje jeden komplet — zamiast
            // zamkniętej grupy bez wpisu, której ponowienie już nie
            // znajdzie. Zero zamkniętych to zero decyzji: nie ma czego
            // zapisywać.
            if ($ile > 0) {
                AuditLogEntry::record(
                    action: 'moderation.automat_dismissed',
                    actor: $moderator,
                    metadata: ['autor_tresci_id' => $autorId, 'ile' => $ile],
                    ip: $ip,
                );
            }

            return [$ile, false];
        });

        if ($urosla) {
            return new WynikZamknieciaGrupy(WynikZamknieciaGrupy::UROSLA);
        }

        if ($ile === 0) {
            return new WynikZamknieciaGrupy(WynikZamknieciaGrupy::PUSTA);
        }

        return new WynikZamknieciaGrupy(WynikZamknieciaGrupy::ZAMKNIETO, $ile);
    }

    /**
     * Czy grupa jest inna niż ta, którą moderator widział (#1059).
     *
     * Najnowsze z ekranu musi być oznaczeniem automatu z TEJ grupy. Nowsze od
     * niego (po `created_at`, a przy tej samej sekundzie po identyfikatorze —
     * UUIDv7 rośnie z czasem) oznacza, że automat coś dopisał. Liczba ponad
     * `stan_ile` łapie dopisanie, którego kolejność nie odróżni (ta sama
     * chwila, losowa część UUID). Mniej niż `stan_ile` jest w porządku — to
     * oznaczenia zamknięte w międzyczasie przez kogoś innego.
     *
     * @param  EloquentCollection<int, Report>  $oznaczenia  otwarte oznaczenia grupy, pod blokadą
     */
    private function grupaUrosla(EloquentCollection $oznaczenia, ?string $autorId, int $stanIle, string $stanNajnowsze): bool
    {
        $znacznik = Report::query()
            ->whereKey($stanNajnowsze)
            ->where('source', Report::SOURCE_AUTOMAT)
            ->when($autorId === null,
                fn ($q) => $q->whereNull('autor_tresci_id'),
                fn ($q) => $q->where('autor_tresci_id', $autorId),
            )
            ->first(['id', 'created_at']);

        $granica = $znacznik?->created_at;

        if ($znacznik === null || $granica === null || $oznaczenia->count() > $stanIle) {
            return true;
        }

        $najnowszeId = (string) $znacznik->getKey();

        return $oznaczenia->contains(static fn (Report $r): bool => $r->created_at === null
            || $r->created_at->greaterThan($granica)
            || ($r->created_at->equalTo($granica) && strcmp((string) $r->getKey(), $najnowszeId) > 0));
    }
}
