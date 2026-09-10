<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
     * @param  list<Sygnal>  $sygnaly  powody, dla których automat podniósł rękę
     * @return ?Report `null`, gdy nie ma czego oznaczać albo ta treść była już
     *                 oglądana przez automat (także wtedy, gdy moderator
     *                 wcześniej powiedział „to nic takiego")
     */
    public function handle(Post|Comment $tresc, array $sygnaly): ?Report
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
