<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POSTAWIENIE POZYCJI W KOLEJCE AUTOMATU (D-052).
 *
 * Cała konsekwencja wykrycia sygnału kończy się tutaj: powstaje JEDEN wiersz
 * w `reports` ze źródłem `automat`. Ta akcja świadomie NIE MA żadnej
 * z rzeczy, których automat robić nie może:
 *
 *  - nie dotyka statusu ani widoczności treści (poz. 3.6 i 3.16 —
 *    ciche ograniczanie zasięgu jest sprzeczne z art. 17 DSA);
 *  - nie tworzy `moderation_actions` i nie wysyła powiadomienia — autor
 *    NICZEGO się nie dowiaduje, bo nic mu się nie stało (poz. 3.10);
 *  - nie liczy, ile razy ktoś już był oznaczony, i niczego z tej liczby nie
 *    wyciąga (poz. 3.14 — żadnego wyciszania po N).
 *
 * JEDNO OZNACZENIE NA TREŚĆ, NA ZAWSZE
 * Pilnuje tego indeks `reports_jeden_automat_na_tresc`, a nie sprawdzenie
 * w PHP — bo to jest obietnica złożona moderatorowi („to nic takiego"
 * zamyka sprawę i nie wraca), a obietnica pilnowana wyłącznie `SELECT`-em
 * przed `INSERT`-em przecieka przy dwóch równoległych zadaniach z kolejki.
 * `SELECT` niżej i tak zostaje: daje ciepłą ścieżkę bez wyjątku w bazie,
 * dokładnie tak samo jak w `ReportContent`.
 */
final class OznaczDoPrzegladu
{
    /**
     * `Media` w typie wejścia to zdjęcie profilowe (issue #237) — celem jest
     * konkretny plik, nie konto, bo tylko wtedy „jedno oznaczenie na treść"
     * nie znaczy „pierwszy awatar tego konta i już nigdy więcej".
     *
     * @param  list<Sygnal>  $sygnaly  powody, dla których automat podniósł rękę
     * @return ?Report `null`, gdy nie ma czego oznaczać albo ta treść była już
     *                 oglądana przez automat (także wtedy, gdy moderator
     *                 wcześniej powiedział „to nic takiego")
     */
    public function handle(Post|Comment|Media $tresc, array $sygnaly): ?Report
    {
        if ($sygnaly === []) {
            return null;
        }

        $typ = ModeratedContent::typ($tresc);
        $autor = ModeratedContent::osoba($tresc);

        if ($typ === null) {
            return null;
        }

        // Najcięższy sygnał nadaje sprawie kwalifikację i miejsce w kolejce;
        // wszystkie powody i tak trafiają do `details`, bo moderator ma
        // zobaczyć CAŁY obraz, a nie samo hasło.
        //
        // SORTUJEMY TUTAJ, mimo że `WykrywaczSygnalow` oddaje już posortowaną
        // listę. Od D-055 sygnały przychodzą z DWÓCH źródeł (lokalne wzorce
        // i ocena modelem) i są sklejane w zadaniu — kolejność po sklejeniu
        // nie jest niczyją odpowiedzialnością, dopóki nie jest tutaj.
        usort($sygnaly, static fn (Sygnal $a, Sygnal $b): int => $b->waga() <=> $a->waga());

        $najciezszy = $sygnaly[0];

        if ($this->juzOgladane($typ, (string) $tresc->getKey())) {
            return null;
        }

        try {
            // Transakcja wokół jednego `INSERT`-a nie jest po atomowość, tylko
            // po to, żeby odbicie się o indeks nie zerwało transakcji
            // wołającego (PostgreSQL, 25P02) — ten sam powód i ten sam
            // kształt co w `ReportContent`.
            $zgloszenie = DB::transaction(fn (): Report => Report::create([
                'reporter_id' => null,
                'autor_tresci_id' => $autor?->getKey(),
                'source' => Report::SOURCE_AUTOMAT,
                'target_type' => $typ,
                'target_id' => $tresc->getKey(),
                'reason' => $najciezszy->kod,
                'details' => $this->opis($sygnaly),
                'status' => Report::STATUS_OPEN,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Drugie zadanie z kolejki zdążyło pierwsze. Dla kolejki
            // moderatora to jest ta sama, jedna pozycja.
            return null;
        }

        AuditLogEntry::record(
            action: 'content.flagged_by_automat',
            actor: null,
            subject: $zgloszenie,
            metadata: [
                'target_type' => $typ,
                'sygnaly' => array_map(static fn (Sygnal $s): string => $s->kod, $sygnaly),
            ],
        );

        return $zgloszenie;
    }

    /**
     * Sygnały DOŁOŻONE do jedynego oznaczenia tej treści (#829, #830).
     *
     * Od #829 zadanie zapisuje sygnały lokalne PRZED oceną modelem — wolny
     * dostawca nie może ich zabrać ze sobą. Ocena modelem przychodzi więc
     * do treści, która może już mieć oznaczenie, a `handle()` odbiłoby się
     * od „jedno oznaczenie na treść" i wynik modelu przepadłby po cichu.
     * To samo przy ocenie zdjęcia, które było gotowe dopiero po publikacji
     * (#830).
     *
     * Obietnica D-052 zostaje nietknięta: dalej JEDEN wiersz na treść.
     * Nowe powody dopisujemy do sprawy, która czeka na moderatora (`open`,
     * `triage`, `reviewing`), a cięższy sygnał przesuwa ją wyżej w kolejce.
     * Sprawy zamkniętej NIE otwieramy — „to nic takiego" nie wraca — ale
     * nowy powód, którego moderator nie widział, zostawia wpis w dzienniku,
     * a nie znika bez śladu.
     *
     * @param  list<Sygnal>  $sygnaly
     * @return array{0: Report, 1: list<Sygnal>}|null oznaczenie i sygnały,
     *                                                które naprawdę do niego trafiły
     *                                                (do alarmu — bez ponownego listu
     *                                                o tym samym)
     */
    public function dolacz(Post|Comment $tresc, array $sygnaly): ?array
    {
        if ($sygnaly === []) {
            return null;
        }

        $nowe = $this->handle($tresc, $sygnaly);

        if ($nowe !== null) {
            return [$nowe, $sygnaly];
        }

        $typ = ModeratedContent::typ($tresc);

        if ($typ === null) {
            return null;
        }

        return DB::transaction(function () use ($typ, $tresc, $sygnaly): ?array {
            $zgloszenie = Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->where('target_type', $typ)
                ->where('target_id', $tresc->getKey())
                ->lockForUpdate()
                ->first();

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

            if (! in_array($zgloszenie->status, [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING], true)) {
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

            $zgloszenie->update($zmiany);

            AuditLogEntry::record(
                action: 'content.flagged_by_automat',
                actor: null,
                subject: $zgloszenie,
                metadata: [
                    'target_type' => $typ,
                    'sygnaly' => array_map(static fn (Sygnal $s): string => $s->kod, $dolozone),
                    'dolozone' => true,
                ],
            );

            return [$zgloszenie, $dolozone];
        });
    }

    /**
     * Uwaga dla moderatora przy ISTNIEJĄCYM oznaczeniu, np. „ocena modelem
     * niepełna" (#829). Sama uwaga oznaczenia nie zakłada — brak czasu na
     * ocenę nie jest powodem, żeby ktoś oglądał czyjś obiad.
     */
    public function dopiszUwage(Post|Comment $tresc, string $uwaga): bool
    {
        $typ = ModeratedContent::typ($tresc);

        if ($typ === null) {
            return false;
        }

        return DB::transaction(function () use ($typ, $tresc, $uwaga): bool {
            $zgloszenie = Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->where('target_type', $typ)
                ->where('target_id', $tresc->getKey())
                ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
                ->lockForUpdate()
                ->first();

            if ($zgloszenie === null) {
                return false;
            }

            $zgloszenie->update([
                'details' => mb_substr($zgloszenie->details."\n— ".$uwaga, 0, 2000),
            ]);

            return true;
        });
    }

    /**
     * Czy automat już kiedyś oglądał tę treść.
     *
     * Pytanie obejmuje WSZYSTKIE statusy, także `rejected`. To jest sedno
     * obietnicy „odrzucone nie wraca": po „to nic takiego" wiersz zostaje
     * w tabeli jako pamięć decyzji człowieka, a nie jako sprawa do
     * rozpatrzenia. Ten sam wybór zrobił Discourse
     * (`docs/research/repos/discourse-discourse.md` §4.5).
     */
    private function juzOgladane(string $typ, string $id): bool
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_type', $typ)
            ->where('target_id', $id)
            ->exists();
    }

    /**
     * Powody po polsku — to jest tekst, który przeczyta moderator.
     *
     * Pierwsze zdanie mówi, czego ta pozycja NIE znaczy. Bez niego kolejka
     * automatu wygląda jak lista przewinień, a jest listą rzeczy do
     * sprawdzenia — i przy fali nowych kont ta różnica decyduje o tym, jak
     * człowiek po drugiej stronie ekranu traktuje sto pozycji dziennie.
     *
     * @param  list<Sygnal>  $sygnaly
     */
    private function opis(array $sygnaly): string
    {
        $linie = ['Automat oznaczył tę treść do przeglądu. Nikt jej nie zgłosił, treść jest widoczna normalnie, autor o niczym nie wie.'];

        foreach ($sygnaly as $sygnal) {
            $linie[] = '— '.$sygnal->powod;
        }

        return mb_substr(implode("\n", $linie), 0, 2000);
    }
}
