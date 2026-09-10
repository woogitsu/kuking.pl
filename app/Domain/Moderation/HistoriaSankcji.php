<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use Illuminate\Support\Collection;

/**
 * WCZEŚNIEJSZE SANKCJE AUTORA — PRZY SPRAWIE, KTÓRĄ MODERATOR WŁAŚNIE
 * ROZSTRZYGA (D-070, znalezisko MOD-02).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO MUSI BYĆ NA EKRANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `docs/legal/MODERATION_PLAYBOOK.md` §2 prowadzi eskalację regułami
 * „2. wystąpienie → blokada czasowa 7 dni", „3. → blokada trwała",
 * „przy powtórzeniu → …". Panel tych wystąpień NIE POKAZYWAŁ — podręcznik
 * przyznawał to wprost: „Dziś to pamięć moderatora, nie funkcja produktu".
 *
 * Skutek ma dwie warstwy i obie są poważne. Pierwsza: ta sama sprawa kończy
 * się inaczej w zależności od tego, czy człowiek pamięta. Druga, gorsza:
 * przy zmianie moderatora historia wypada z procesu CAŁKOWICIE, mimo że
 * siedzi w bazie od pierwszego dnia — nowa osoba nie ma jak wiedzieć,
 * że patrzy na trzecie wystąpienie, więc wystawia pierwsze ostrzeżenie
 * komuś, kto powinien dostać blokadę. W drugą stronę działa to równie źle:
 * blokada trwała za pierwsze przewinienie, bo „coś mi mówi, że już go
 * widziałem".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO NIE JEST DOSSIER — CO ŚWIADOMIE POMIJAMY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wąska osi czasu, wyłącznie to, co potrzebne do TEJ decyzji: data, rodzaj
 * decyzji, podstawa i wynik odwołania. Świadomie NIE MA:
 *
 *  - treści, której sprawa dotyczyła, ani odnośnika do niej — moderator
 *    rozstrzyga sprawę BIEŻĄCĄ, a nie tamtą; czytanie starych treści
 *    zamieniłoby podjęcie decyzji w przegląd konta;
 *  - notatek wewnętrznych i wiadomości wysłanych wtedy autorowi — to jest
 *    treść cudzej decyzji, a nie fakt o eskalacji;
 *  - czegokolwiek o zgłaszających te sprawy — do decyzji o eskalacji nie
 *    służą, a wypisanie ich zamieniłoby kartę sprawy w listę osób, które
 *    kogoś zgłosiły;
 *  - spraw ODRZUCONYCH (`no_action`) — patrz niżej, to jest najważniejsze
 *    pominięcie na tej liście.
 *
 * Powód jest jeden i nie jest kosmetyczny: `docs/INSPIRATION_DECISIONS.md`
 * (poz. 3.14) i cały ten produkt odrzucają liczenie ludziom punktów karnych.
 * Ekran, który przy każdej sprawie pokazuje wszystko, co ktoś kiedykolwiek
 * zrobił, nie jest narzędziem eskalacji — jest teczką i zmienia sposób,
 * w jaki człowiek patrzy na człowieka.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO ZGŁOSZENIA ODRZUCONE NIE WCHODZĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * `no_action` znaczy „sprawdziliśmy i nic się nie stało". Wpisanie tego na
 * osi sankcji byłoby liczeniem komuś zgłoszeń, których nie potwierdziliśmy,
 * czyli karą za bycie zgłaszanym. Przy jednej osobie zawziętej na drugą to
 * jest cały mechanizm nadużycia: pięć bezpodstawnych zgłoszeń robi na
 * ekranie „historię", a szóste dostaje surowszą decyzję „bo widać wzorzec".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WYNIK ODWOŁANIA JEST NA TEJ LIŚCIE OBOWIĄZKOWY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo decyzja COFNIĘTA w odwołaniu (`Appeal::STATUS_OVERTURNED`) nie jest
 * wystąpieniem — jest naszą pomyłką. Oś czasu bez tej kolumny liczyłaby
 * własne błędy jako przewinienia autora i eskalowała na ich podstawie, co
 * jest najgorszym możliwym sposobem użycia historii kar. Dlatego cofnięte
 * pozycje ZOSTAJĄ widoczne (moderator ma wiedzieć, że sprawa była), ale są
 * podpisane wprost i NIE LICZĄ SIĘ do `wystapienia()`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  LICZBA ZAPYTAŃ: STAŁA, NIE N+1
 * ────────────────────────────────────────────────────────────────────────
 *
 * Kolejka pokazuje do dwudziestu pięciu spraw na stronie, każda o innym
 * autorze, a autor leży w jednej z pięciu różnych tabel. Naiwne rozwiązanie
 * (`ModeratedContent::znajdz()` + `osoba()` per sprawa) to pięćdziesiąt
 * zapytań na odsłonę i rośnie z rozmiarem strony.
 *
 * Tutaj jest to zawsze: najwyżej PIĘĆ zapytań na rozwiązanie autorów
 * (jedno na typ celu, `ModeratedContent::autorzyCelow()`) + JEDNO na całą
 * historię wszystkich tych autorów naraz + JEDNO na odwołania do tych
 * decyzji. Siedem, niezależnie od tego, czy na stronie jest jedna sprawa
 * czy dwadzieścia pięć. Pilnuje tego
 * `HistoriaSankcjiAutoraTest::test_historia_dla_calej_strony_kolejki_nie_rosnie_z_liczba_spraw`.
 *
 * DLACZEGO NA LIŚCIE, A NIE NA OSOBNEJ KARCIE SPRAWY
 * Bo formularz decyzji stoi na liście i tam zapada decyzja. Osobny ekran
 * pojedynczej sprawy znaczyłby, że historię widzi ten, kto po nią kliknie —
 * a wtedy dokładnie nic się nie zmienia względem stanu, który to naprawia:
 * eskalacja dalej zależałaby od tego, czy człowiek pamiętał, że ma sprawdzić.
 */
final class HistoriaSankcji
{
    /**
     * Decyzje, które SĄ sankcją i liczą się do eskalacji.
     *
     * `no_action` i `unhide` poza listą: pierwsze znaczy „nic się nie
     * stało", drugie jest cofnięciem naszej własnej decyzji. Żadne z nich
     * nie jest wystąpieniem autora.
     *
     * @var list<string>
     */
    private const SANKCJE = [
        ModerationAction::ACTION_WARN,
        ModerationAction::ACTION_HIDE,
        ModerationAction::ACTION_REMOVE,
        ModerationAction::ACTION_SUSPEND,
        ModerationAction::ACTION_BAN,
    ];

    /**
     * Ile pozycji pokazujemy na karcie jednej sprawy.
     *
     * Sześć, bo podręcznik eskaluje do „3. wystąpienia" i przy trzecim
     * kończy blokadą trwałą — więc do decyzji potrzeba kilku ostatnich
     * pozycji, nie całego życia konta. Pełną liczbę wystąpień podajemy
     * osobno (`$wystapienia`), żeby ucięcie listy nie ukryło skali.
     */
    private const NA_KARCIE = 6;

    /**
     * Historia sankcji autorów wszystkich spraw z jednej strony kolejki.
     *
     * @param  iterable<Report>  $sprawy
     * @return array<string, HistoriaAutora> klucz: id zgłoszenia
     */
    public function dlaSpraw(iterable $sprawy): array
    {
        $sprawy = collect($sprawy);

        if ($sprawy->isEmpty()) {
            return [];
        }

        $autorzy = $this->autorzySpraw($sprawy);

        if ($autorzy === []) {
            return [];
        }

        $decyzje = $this->decyzjeAutorow(array_values(array_unique($autorzy)));

        $wynik = [];

        foreach ($sprawy as $sprawa) {
            $idAutora = $autorzy[(string) $sprawa->getKey()] ?? null;

            if ($idAutora === null) {
                continue;
            }

            // Sprawa BIEŻĄCA nigdy nie jest własną historią. Bez tego
            // warunku sprawa już rozpatrzona pokazywałaby pod formularzem
            // swoją własną decyzję jako „wcześniejszą sankcję" — czyli
            // pierwsze wystąpienie wyglądałoby jak drugie.
            $wlasne = $decyzje->get($idAutora, collect())
                ->reject(fn (ModerationAction $decyzja): bool => (string) $decyzja->report_id === (string) $sprawa->getKey())
                ->values();

            if ($wlasne->isEmpty()) {
                continue;
            }

            $wynik[(string) $sprawa->getKey()] = new HistoriaAutora(
                pozycje: $wlasne->take(self::NA_KARCIE)->all(),
                wszystkich: $wlasne->count(),
                wystapienia: $wlasne
                    ->reject(fn (ModerationAction $decyzja): bool => $this->cofnieta($decyzja))
                    ->count(),
            );
        }

        return $wynik;
    }

    /**
     * `[id zgłoszenia => id autora]` dla całej strony, w najwyżej pięciu
     * zapytaniach (jedno na typ celu).
     *
     * Przy oznaczeniach automatu autor jest już w wierszu
     * (`autor_tresci_id`, D-052) i nie pytamy o niego wcale — kolumna
     * powstała właśnie po to.
     *
     * @param  Collection<int, Report>  $sprawy
     * @return array<string, string>
     */
    private function autorzySpraw(Collection $sprawy): array
    {
        $doRozwiazania = [];
        $wynik = [];

        foreach ($sprawy as $sprawa) {
            if ($sprawa->autor_tresci_id !== null) {
                $wynik[(string) $sprawa->getKey()] = (string) $sprawa->autor_tresci_id;

                continue;
            }

            if ($sprawa->target_type === null || $sprawa->target_id === null) {
                continue;
            }

            $doRozwiazania[$sprawa->target_type][] = (string) $sprawa->target_id;
        }

        $autorzyCelow = ModeratedContent::autorzyCelow($doRozwiazania);

        foreach ($sprawy as $sprawa) {
            if (isset($wynik[(string) $sprawa->getKey()])) {
                continue;
            }

            $klucz = $sprawa->target_type.'|'.$sprawa->target_id;

            if (isset($autorzyCelow[$klucz])) {
                $wynik[(string) $sprawa->getKey()] = $autorzyCelow[$klucz];
            }
        }

        return $wynik;
    }

    /**
     * Sankcje wszystkich podanych osób — JEDNO zapytanie, plus jedno na
     * odwołania (`with`).
     *
     * `orderByDesc('created_at')`: najnowsza sankcja pierwsza, bo przy
     * eskalacji pyta się „co było ostatnio", a nie „od czego się zaczęło".
     * Drugi warunek porządku (`id`) z tego samego powodu, co w kolejce
     * zgłoszeń: przy remisie na sekundzie PostgreSQL nie obiecuje żadnej
     * kolejności, a `take(6)` odcinałoby wtedy losowe pozycje.
     *
     * @param  list<string>  $idAutorow
     * @return Collection<string, Collection<int, ModerationAction>>
     */
    private function decyzjeAutorow(array $idAutorow): Collection
    {
        return ModerationAction::query()
            ->whereIn('subject_user_id', $idAutorow)
            ->whereIn('action', self::SANKCJE)
            // Wynik odwołania rozstrzyga, czy pozycja jest wystąpieniem, czy
            // naszą cofniętą pomyłką — więc nie jest ozdobą i nie wolno go
            // dociągać osobno przy każdym wierszu.
            ->with(['authorAppeal'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ModerationAction $decyzja): string => (string) $decyzja->subject_user_id);
    }

    /** Czy tę decyzję cofnięto w odwołaniu — wtedy NIE jest wystąpieniem autora. */
    private function cofnieta(ModerationAction $decyzja): bool
    {
        return $decyzja->authorAppeal?->status === Appeal::STATUS_OVERTURNED;
    }
}
