<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;
use Carbon\CarbonInterface;

/**
 * „WZIĄŁEM DO PRZEGLĄDU" — I CO SIĘ DZIEJE, GDY MODERATOR SPRAWY NIE DOMKNIE
 * (D-070, znalezisko MOD-04).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  STAN SPRZED TEJ ZMIANY
 * ────────────────────────────────────────────────────────────────────────
 *
 * `Report::STATUS_REVIEWING` istniał od 5 września, `KolejkiPanelu` go liczył,
 * panel miał zakładkę „W trakcie" — i NIC tego statusu nie nadawało. Kod
 * prowadził sprawę z `open` prosto do `resolved`/`rejected`, więc zakładka
 * była stale pusta, a licznik zawsze pokazywał zero. Udawany workflow.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WDROŻONY, A NIE USUNIĘTY — SKORO MODERATORÓW JEST JEDEN LUB DWÓCH
 * ────────────────────────────────────────────────────────────────────────
 *
 * Sygnał „ktoś już to czyta" ma wartość dopiero przy dwóch osobach i to jest
 * prawda. Gdyby to był jedyny powód istnienia tego statusu, należałoby go
 * skasować — i taka była pierwsza odpowiedź na to znalezisko.
 *
 * Zmieniła ją funkcja z tej samej zmiany: NIEUSUWALNE OZNACZENIE
 * „P0 NIEPRZEJRZANE". Ma być niemożliwe do odklikania bez podjęcia sprawy —
 * czyli musi istnieć czynność „podejmuję tę sprawę", inna od wydania decyzji.
 * Bez niej moderator, który o 23:00 zobaczył P0, przeczytał treść i musi
 * zadzwonić po prawnika przed decyzją, ma dwa wyjścia: kliknąć decyzję,
 * której jeszcze nie podjął, albo patrzeć na alarm, który krzyczy o sprawie
 * już przeczytanej. Pierwsze psuje decyzję, drugie psuje alarm — a alarm,
 * który krzyczy o czymś zrobionym, przestaje być czytany w ciągu tygodnia.
 *
 * `reviewing` jest więc potrzebny nawet przy JEDNYM moderatorze, tylko znaczy
 * co innego niż „nie wchodź, zajęte": znaczy „ta sprawa jest u człowieka,
 * alarm może ucichnąć". Że przy dwóch osobach powie też tamto pierwsze,
 * jest miłym dodatkiem, nie uzasadnieniem.
 *
 * `triage` skasowaliśmy — tam nie było czego wdrażać. Nie ma etapu wstępnej
 * kwalifikacji, nie ma osoby, która by ją robiła, a przy jednym moderatorze
 * „kwalifikacja" i „przegląd" to ta sama czynność.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  SPRAWA NIEDOMKNIĘTA WRACA SAMA — BEZ ZADANIA W TLE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Skoro „wziąłem do przeglądu" wycisza alarm P0, to musi być NIEMOŻLIWE,
 * żeby wyciszyło go na zawsze. Człowiek zasypia, gubi wątek, wychodzi
 * z domu; sprawa oznaczona jako czytana i nie domknięta jest wtedy w gorszym
 * stanie niż sprawa nietknięta, bo dodatkowo nie widać, że czeka.
 *
 * Po {@see self::WYGASA_PO_GODZINACH} godzinach sprawa WRACA do kolejki
 * i z powrotem liczy się jako nieprzejrzana. Nie robi tego harmonogram
 * i nie robi tego kolejka zadań — robi to WARUNEK W ZAPYTANIU
 * (`Report::scopeNieprzejrzane()`). Trzy powody, wszystkie praktyczne:
 *
 *  1. Zadanie w tle, które nie chodzi (a przy Railwayu z jednym procesem
 *     bywa), zostawiłoby alarm wyciszony bez śladu — czyli awaria
 *     harmonogramu zamieniłaby się w cichą awarię bezpieczeństwa.
 *  2. Status w bazie przestawiany po czasie zgubiłby informację, KTO sprawę
 *     brał — a to jest właśnie ta wiedza, która przy dwóch osobach mówi,
 *     do kogo się zwrócić.
 *  3. `AGENTS.md` §3: bez zmierzonej potrzeby nie dokładamy mechanizmu.
 *     Warunek w `WHERE` kosztuje tu zero — indeks
 *     `reports_kolejka_priorytet_idx` i tak zawęża zbiór do spraw otwartych,
 *     a tych jest garść.
 *
 * OSIEM GODZIN, bo tyle trwa jeden dzień pracy. Sprawa wzięta rano i nie
 * domknięta do wieczora jest sprawą zapomnianą, a nie sprawą w toku. Krócej
 * (godzina, dwie) znaczyłoby, że alarm wraca w środku prawdziwej pracy nad
 * jedną trudną sprawą — czyli dokładnie wtedy, gdy najbardziej przeszkadza.
 * Dłużej (doba) przepuściłoby całą noc przy kategorii, której cel czasowy
 * podręcznik liczy w godzinach.
 */
final class Przeglad
{
    /**
     * Po tylu godzinach sprawa wzięta do przeglądu wraca do kolejki jako
     * nieprzejrzana. Stała, nie klucz w `config/kuking.php` — to jest reguła
     * produktu, a nie limit do przykręcania w środowisku (ten sam wybór, co
     * `DlugoscZawieszenia::MAX_DNI`).
     */
    public const WYGASA_PO_GODZINACH = 8;

    /**
     * Od kiedy przegląd rozpoczęty w danym momencie liczy się za wygasły.
     *
     * Jedno miejsce, z którego bierze granicę i zapytanie
     * (`Report::scopeNieprzejrzane()`), i widok (żeby nie pokazywał
     * „w przeglądzie" przy sprawie, którą kolejka liczy już jako czekającą).
     */
    public static function granica(): CarbonInterface
    {
        return now()->subHours(self::WYGASA_PO_GODZINACH);
    }

    /**
     * Czy przegląd tej sprawy jest jeszcze świeży — czyli czy naprawdę
     * ktoś ją teraz prowadzi.
     *
     * `false` dla sprawy, która w przeglądzie nigdy nie była: nie ma czego
     * uznawać za świeże.
     */
    public static function trwa(Report $sprawa): bool
    {
        if ($sprawa->status !== Report::STATUS_REVIEWING || $sprawa->przeglad_zaczety_o === null) {
            return false;
        }

        return $sprawa->przeglad_zaczety_o->greaterThan(self::granica());
    }

    /**
     * Sprawa oznaczona jako czytana, przy której czas minął.
     *
     * Osobna nazwa od `! trwa()`, bo widok mówi w tym przypadku coś
     * INNEGO niż przy sprawie nowej („wzięta do przeglądu 12 września
     * o 9:15 i nie domknięta — wróciła do kolejki"), a moderator ma prawo
     * wiedzieć, że to jego własna niedomknięta sprawa, nie nowe zgłoszenie.
     */
    public static function wygasl(Report $sprawa): bool
    {
        return $sprawa->status === Report::STATUS_REVIEWING && ! self::trwa($sprawa);
    }
}
