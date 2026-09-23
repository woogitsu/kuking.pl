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
     * @return ?Report `null` WYŁĄCZNIE wtedy, gdy nie ma czego oznaczać (brak
     *                 sygnałów, nieznany typ celu). Gdy treść była już
     *                 oglądana przez automat, wraca ISTNIEJĄCY wiersz — patrz
     *                 „DLACZEGO NIE `null`" niżej (issue #1051).
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

        /*
         * DLACZEGO OBIE DROGI POWROTU ODDAJĄ ISTNIEJĄCY WIERSZ, A NIE `null`
         * (issue #1051).
         *
         * Do 22 września 2026 oddawały `null`, a `PrzeanalizujTresc`
         * i `PrzeanalizujAwatar` (od D-240 bez modelu) miały ten sam warunek:
         * `if ($oznaczenie !== null) { $alarm->handle(...); }`. Skutek był
         * taki, że ISTNIENIE wiersza w `reports` WYŁĄCZAŁO alarm — czyli
         * dokładnie w sytuacji, w której sprawa już jest zapisana, nikt się
         * o niej nie dowiadywał. Wystarczyło, żeby worker zginął w szczelinie
         * między zatwierdzeniem transakcji niżej a wywołaniem alarmu
         * (`timeout = 30`, `tries = 1`, restart przy wdrożeniu), a każda
         * kolejna analiza tej treści zatrzymywała się na sprawdzeniu
         * „automat już to oglądał" i milczała. Zgubione zostawało zgubione.
         *
         * Wiersz oddany zamiast `null` nie tworzy drugiego alarmu:
         * `AlarmujModeratora` pyta o `alarm_pilny_zlecony_at` i przy
         * zleconym już alarmie nie robi nic. Kolejka moderatora dostaje tak
         * czy tak JEDNĄ pozycję — tego pilnuje indeks
         * `reports_jeden_automat_na_tresc`, nie ten zwrot.
         */
        $juz = $this->istniejace($typ, (string) $tresc->getKey());

        if ($juz !== null) {
            return $juz;
        }

        try {
            /*
             * OBOWIĄZEK ALARMU ZAPISANY RAZEM ZE SPRAWĄ, W JEDNEJ TRANSAKCJI
             * (issue #1051).
             *
             * `alarm_pilny_stan = ZALEGLY` nie jest ozdobą ani pamiątką —
             * jest JEDYNYM miejscem w bazie, z którego da się odtworzyć, że
             * ta sprawa była pilna. Pilność żyje w liście obiektów `Sygnal`
             * w pamięci workera; do `reports` trafia sam kod powodu
             * (`automat_model`), identyczny dla sprawy pilnej i niepilnej.
             * Gdyby ten zapis stał LINIJKĘ NIŻEJ, poza transakcją, miałby tę
             * samą szczelinę co alarm, którego pilnuje.
             *
             * Transakcja wokół jednego `INSERT`-a nie jest po atomowość
             * samego wiersza — jest po to, żeby odbicie się o indeks nie
             * zerwało transakcji wołającego (PostgreSQL, 25P02) — ten sam
             * powód i ten sam kształt co w `ReportContent`.
             */
            $zgloszenie = DB::transaction(function () use ($autor, $typ, $tresc, $najciezszy, $sygnaly): Report {
                $wiersz = new Report([
                    'reporter_id' => null,
                    'autor_tresci_id' => $autor?->getKey(),
                    'source' => Report::SOURCE_AUTOMAT,
                    'target_type' => $typ,
                    'target_id' => $tresc->getKey(),
                    'reason' => $najciezszy->kod,
                    'details' => $this->opis($sygnaly),
                    'status' => Report::STATUS_OPEN,
                ]);

                // POZA MASOWYM PRZYPISANIEM, jak `numer_sprawy`: to jest
                // rozstrzygnięcie serwera o tym, czy sprawa jest pilna, a nie
                // dana z jakiegokolwiek formularza. Żadna trasa nie prowadzi
                // do tej akcji — ale `$fillable` jest umową na przyszłość,
                // nie opisem dzisiejszych wywołań.
                $wiersz->alarm_pilny_stan = $this->pilne($sygnaly) ? Report::ALARM_ZALEGLY : null;
                $wiersz->save();

                return $wiersz;
            });
        } catch (UniqueConstraintViolationException) {
            // Drugie zadanie z kolejki zdążyło pierwsze. Dla kolejki
            // moderatora to jest ta sama, jedna pozycja — ale wiersz oddajemy,
            // żeby alarm miał na czym pracować (powód wyżej).
            return $this->istniejace($typ, (string) $tresc->getKey());
        }

        /*
         * WPIS POMOCNICZY (D-249, klasa 2) — i to jest tu warunek alarmu,
         * nie kosmetyka. Sprawa jest już zatwierdzona i ma pełny własny ślad
         * w `reports` (`source = automat`, powód, opis sygnałów, `created_at`,
         * `alarm_pilny_stan`). Gołe `record()` za transakcją rzucało przy
         * awarii dziennika PRZED powrotem do `PrzeanalizujTresc`, a tamten
         * blankietowy `catch` połykał wyjątek — czyli awaria `audit_log`
         * zjadała pilny alarm o sprawie, która już stoi w kolejce
         * (issue #1051). Teraz awaria idzie do `report()` z nazwą braku,
         * a alarm idzie dalej.
         */
        AuditLogEntry::recordBezWywracania(
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
     * Oznaczenie, które automat postawił przy tej treści wcześniej — albo
     * `null`, gdy jeszcze go nie ma.
     *
     * Pytanie obejmuje WSZYSTKIE statusy, także `rejected`. To jest sedno
     * obietnicy „odrzucone nie wraca": po „to nic takiego" wiersz zostaje
     * w tabeli jako pamięć decyzji człowieka, a nie jako sprawa do
     * rozpatrzenia. Ten sam wybór zrobił Discourse
     * (`docs/research/repos/discourse-discourse.md` §4.5). Nowe oznaczenie
     * przy takiej treści NIE POWSTAJE — zmienił się tylko zwrot: był `true`
     * bez wiersza, jest wiersz (issue #1051).
     */
    private function istniejace(string $typ, string $id): ?Report
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('target_type', $typ)
            ->where('target_id', $id)
            ->first();
    }

    /**
     * Czy wśród sygnałów jest choć jeden, który nie może czekać do
     * jutrzejszego podsumowania.
     *
     * Ta sama reguła, którą stosuje `AlarmujModeratora` — i to jest jedyny
     * powód, dla którego stoi tu osobno: obie klasy muszą odpowiadać na to
     * pytanie IDENTYCZNIE, bo jedna zapisuje obowiązek, a druga go
     * wykonuje. Rozjazd wyglądałby tak, że wiersz mówi „zaległy alarm",
     * a alarm uważa sprawę za niepilną i nigdy tego stanu nie zdejmie.
     *
     * @param  list<Sygnal>  $sygnaly
     */
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
