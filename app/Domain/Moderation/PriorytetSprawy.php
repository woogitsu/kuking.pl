<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;

/**
 * CO MODERATOR MA PRZECZYTAĆ NAJPIERW — wyliczone z danych, nie wpisane ręcznie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO BYŁO NIE TAK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `/admin/zgloszenia` sortowało wyłącznie `created_at DESC, id DESC`, czyli
 * „najnowsze na górze". Przy takim porządku zgłoszenie „Dotyczy dziecka"
 * sprzed dwóch dni leży POD dwudziestoma zgłoszeniami spamu z ostatniej
 * godziny — a spam to jedyna kategoria, która przychodzi falami. Im gorszy
 * dzień, tym głębiej schodzi rzecz, która nie może czekać.
 *
 * Kolejka automatu rozstrzygnęła ten sam problem już wcześniej i tamto
 * rozstrzygnięcie tu powtarzamy: `Report::WAGA` jest STAŁĄ W KODZIE,
 * wchodzącą do `ORDER BY CASE`, a nie kolumną w tabeli. Powód jest ten sam:
 * to jest REGUŁA PRODUKTU, nie fakt o wierszu. Zmiana listy kategorii ma być
 * jedną linijką w kodzie i jednym czerwonym testem, a nie migracją, która
 * przepisuje historyczne wiersze na nową skalę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  Z CZEGO LICZYMY PRIORYTET
 * ────────────────────────────────────────────────────────────────────────
 *
 * Z dwóch rzeczy, które przy zgłoszeniu JUŻ SĄ w wierszu — bez dodatkowego
 * zapytania i bez nowej kolumny:
 *
 *  1. `reason` — kategoria, którą wybrał zgłaszający (`Report::REASONS`);
 *  2. `source` — czy to zgłoszenie prawne w rozumieniu DSA art. 16, które
 *     niesie TERMIN odpowiedzi (art. 16 ust. 5), czy zwykłe społecznościowe.
 *
 * Lista P0 to DOKŁADNIE te same dwie rzeczy, które za pilne uznaje automat
 * (`App\Moderacja\KategorieModeracji::PILNE`: treść seksualna i wszystko, co
 * dotyczy dziecka). Jedna definicja „pilnego" na cały serwis, nie dwie —
 * inaczej pierwsza zmiana w jednej z nich rozjechałaby się z drugą i nikt by
 * tego nie zauważył, dopóki nie zdarzyłoby się coś złego akurat po tej
 * cichszej stronie. Te dwie kategorie mają w `resources/legal/zasady.md`
 * własną sekcję „Czego nie tolerujemy w ogóle".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PRIORYTET NIE TWIERDZI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Że sprawa JEST tym, czym nazwał ją zgłaszający. `reason` to jego słowo,
 * nie ustalony fakt — nikt tej treści jeszcze nie obejrzał. Priorytet mówi
 * więc wyłącznie: „to trzeba obejrzeć wcześniej", i NIC POZA TYM. Nie ukrywa
 * treści, nie ogranicza jej zasięgu, nie powiadamia autora i nie zmienia
 * niczego, co widzi czytelnik. Fałszywe P0 kosztuje moderatora jedno
 * kliknięcie i tyle — a odwrotny błąd (prawdziwe P0 pod falą spamu) kosztuje
 * dwa dni zwłoki przy najcięższej możliwej sprawie.
 *
 * Nadużycie — „zaznaczę »dotyczy dziecka«, żeby wskoczyć na górę" — jest
 * realne i świadomie nie budujemy na nie osobnego mechanizmu. Ogranicza je
 * to, co w bazie już stoi: `reports_one_open_per_pair` dopuszcza JEDNO
 * otwarte zgłoszenie na parę osoba–treść, a zgłoszenie społecznościowe
 * wymaga konta. Jedna osoba nie zrobi z tego fali.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE MA TU HISTORII SANKCJI AUTORA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo priorytet odpowiada na pytanie „czy to może poczekać do jutra", a na to
 * pytanie odpowiada RODZAJ SZKODY, nie kartoteka osoby. Recydywa jest
 * argumentem przy DECYZJI („trzecie zawieszenie, tym razem dłuższe"), nie
 * przy kolejności czytania: powtórzony spam recydywisty dalej jest spamem
 * i dalej nie robi się groźniejszy przez noc.
 *
 * Osobno stoi cena. `reports` nie ma kolumny z autorem zgłoszonej treści
 * (ma ją tylko oznaczenie automatu: `autor_tresci_id`), więc wpięcie
 * kartoteki do sortowania znaczyłoby: rozwiązanie celu każdego wiersza,
 * a potem policzenie jego sankcji — czyli dwa zapytania na pozycję na
 * ekranie, przy dwudziestu pięciu pozycjach na stronę. Płacimy to wtedy,
 * gdy będzie po co; dziś nie jest.
 */
final class PriorytetSprawy
{
    /** Nie może czekać do jutra. */
    public const P0 = 0;

    /** Dzisiaj — biegnie termin albo szkoda rośnie z czasem. */
    public const P1 = 1;

    /** Zwykła kolejka. */
    public const P2 = 2;

    /** Pozycja spraw, które już nie czekają — za wszystkimi otwartymi. */
    public const NIE_CZEKA = 3;

    /**
     * Kategoria zgłoszenia → priorytet. Czego tu nie ma, jest P2.
     *
     * @var array<string, int>
     */
    public const MAPOWANIE = [
        // P0 — ta sama lista, co `KategorieModeracji::PILNE`.
        'minor' => self::P0,
        'sexual' => self::P0,

        // P1 — szkoda rośnie z każdą godziną, w której treść wisi.
        // Zgodne z wierszem P1 tabeli SLA w `docs/legal/MODERATION_PLAYBOOK.md`
        // („nękanie, mowa nienawiści, dane osobowe osób trzecich, nagość,
        // oszustwo (scam)").
        //
        //  • `scam` — wyłudzenie działa, dopóki odnośnik jest klikalny;
        //  • `harassment` i `hate` — celem jest konkretna osoba, która to
        //    czyta teraz, a nie „serwis";
        //  • `personal_data` — czyjegoś adresu nie da się odzobaczyć,
        //    a każda godzina to kolejne kopie;
        //
        // `scam` → P1 POTWIERDZONE PRZEZ WŁAŚCICIELA 25 września 2026
        // (D-236). Przy scaleniu było to jeszcze moim osądem — tabela SLA
        // w podręczniku wtedy tej kategorii nie wymieniała. Dziś wymienia:
        // wiersz P1 w `docs/legal/MODERATION_PLAYBOOK.md` §3 ma już
        // „oszustwo (scam)".
        'scam' => self::P1,
        'harassment' => self::P1,
        'hate' => self::P1,
        'personal_data' => self::P1,

        // `dangerous_advice` STOI W P2, ZGODNIE Z PODRĘCZNIKIEM, A NIE PO
        // MOJEMU. Pierwsza wersja tego pliku dawała mu P1 z argumentem, że
        // zła rada o weku albo o grzybach kończy się szpitalem. Tabela SLA
        // w `docs/legal/MODERATION_PLAYBOOK.md` stawia „niebezpieczne porady"
        // w P2 (72 godziny) i jest dokumentem operacyjnym właściciela, a mój
        // argument jest opinią, nie pomiarem. Rozjazd kodu z podręcznikiem
        // byłby gorszy od jednej i drugiej wersji z osobna: moderator czyta
        // podręcznik, a kolejkę ustawia kod.

        // Reszta (`spam`, `impersonation`, `copyright`, `other`) zostaje P2
        // przez `ELSE`. Nie wypisujemy jej, żeby nowy powód dopisany kiedyś
        // do `Report::REASONS` trafiał do zwykłej kolejki, a nie wywracał
        // sortowania na nieznanym kluczu.
    ];

    /**
     * Napisy dla moderatora. Krótkie, bo stoją w nagłówku karty obok
     * kategorii — i mówią, CO Z TYM ZROBIĆ, a nie „P0".
     *
     * „P0" jest w porządku w rozmowie zespołu i zostaje w kodzie; na ekranie
     * byłoby żargonem, którego nowa osoba w moderacji nie zna pierwszego dnia
     * (`docs/UX_50_PLUS.md` — nazwy mówią, co się stanie).
     *
     * @var array<int, string>
     */
    public const NAPISY = [
        self::P0 => 'Nie może czekać',
        self::P1 => 'Na dziś',
    ];

    /**
     * Priorytet POJEDYNCZEGO zgłoszenia — ta sama reguła, którą niżej
     * dostaje baza. Widok liczy tu, `ORDER BY` liczy tam, a rozjechać się
     * nie mają jak, bo obie drogi czytają `MAPOWANIE`.
     */
    public static function dla(Report $zgloszenie): int
    {
        $zPowodu = self::MAPOWANIE[(string) $zgloszenie->reason] ?? self::P2;

        // ZGŁOSZENIE PRAWNE MA PODŁOGĘ NA P1, NIEZALEŻNIE OD KATEGORII.
        //
        // Nie dlatego, że jest cięższe — „to nie jest treść tej osoby"
        // zgłoszone drogą prawną opisuje tę samą rzecz, co zgłoszone
        // społecznościowo. Dlatego, że niesie TERMIN: DSA art. 16 ust. 5 każe
        // bez zbędnej zwłoki zawiadomić zgłaszającego o decyzji, a termin,
        // który leci, jest właśnie tym, co odróżnia „dziś" od „w kolejce".
        //
        // To jest PODŁOGA, nie przypisanie: sprawa prawna z kategorii P0
        // zostaje P0 i nie da się jej tędy obniżyć.
        if ($zgloszenie->source === Report::SOURCE_LEGAL_NOTICE) {
            return min($zPowodu, self::P1);
        }

        return $zPowodu;
    }

    /**
     * Priorytet sprawy W KOLEJCE — czyli tylko dopóki nikt jej nie ruszył.
     *
     * `null` dla wszystkiego, co nie jest `open`. Pytanie „czy to może
     * poczekać do jutra" ma sens tylko dla sprawy, która czeka: rozstrzygnięte
     * P0 z zeszłego tygodnia z napisem „Nie może czekać" jest nieprawdą,
     * a w zakładce „Wszystkie" stało nad dzisiejszym otwartym zgłoszeniem.
     * Karta i `ORDER BY` (`wyrazenieSqlKolejki`) czytają tę samą regułę.
     */
    public static function wKolejce(Report $zgloszenie): ?int
    {
        return $zgloszenie->status === Report::STATUS_OPEN ? self::dla($zgloszenie) : null;
    }

    public static function napis(int $priorytet): ?string
    {
        return self::NAPISY[$priorytet] ?? null;
    }

    /**
     * To samo wyrażenie dla bazy: `CASE … END`, gotowe do `orderByRaw()`.
     *
     * DLACZEGO BUDOWANE, A NIE WPISANE JAKO STAŁY NAPIS
     * Bo wpisany napis jest DRUGĄ kopią `MAPOWANIE` — a dwie kopie jednej
     * listy rozjeżdżają się przy pierwszej zmianie, tylko że tutaj rozjazd
     * wyglądałby tak: karta pokazuje „Nie może czekać", a sprawa leży na
     * trzeciej stronie. Nikt by tego nie zgłosił, bo nikt by tam nie dotarł.
     *
     * Wartości idą jako PARAMETRY, nie przez sklejanie napisów. Wszystkie
     * pochodzą dziś ze stałych w tym pliku, więc nie ma czego wstrzyknąć —
     * ale zapytanie budowane sklejaniem jest wzorcem, który ktoś kiedyś
     * skopiuje w miejsce z danymi z formularza.
     *
     * @return array{0: string, 1: list<string|int>} wyrażenie i jego parametry
     */
    public static function wyrazenieSql(string $kolumnaPowodu = 'reason', string $kolumnaZrodla = 'source'): array
    {
        $parametry = [];
        $warunki = '';

        // Najpierw podłoga dla zgłoszeń prawnych, bo `CASE` bierze PIERWSZY
        // pasujący warunek: kategoria P0 musi wygrać z podłogą P1, więc
        // przypadki P0 stoją przed nią.
        foreach ([self::P0, self::P1, self::P2] as $poziom) {
            $powody = array_keys(array_filter(
                self::MAPOWANIE,
                static fn (int $p): bool => $p === $poziom,
            ));

            if ($powody !== []) {
                $warunki .= ' WHEN '.$kolumnaPowodu.' IN ('
                    .implode(', ', array_fill(0, count($powody), '?')).') THEN ?';
                $parametry = [...$parametry, ...$powody, $poziom];
            }

            if ($poziom === self::P0) {
                $warunki .= ' WHEN '.$kolumnaZrodla.' = ? THEN ?';
                $parametry[] = Report::SOURCE_LEGAL_NOTICE;
                $parametry[] = self::P1;
            }
        }

        return ['CASE'.$warunki.' ELSE ? END', [...$parametry, self::P2]];
    }

    /**
     * To samo, ale TYLKO DLA OTWARTYCH — do `ORDER BY` kolejki zgłoszeń.
     *
     * Sprawy w innym stanie dostają `NIE_CZEKA`, więc w „Wszystkie" stoją
     * za wszystkimi otwartymi i między sobą idą po dacie. Wydajność: `CASE`
     * nie ma indeksu i liczy się na każdym wierszu filtru. W MVP to jest
     * świadomie w porządku — zob. D-236.
     *
     * @return array{0: string, 1: list<string|int>}
     */
    public static function wyrazenieSqlKolejki(string $kolumnaStatusu = 'status'): array
    {
        [$wyrazenie, $parametry] = self::wyrazenieSql();

        return [
            'CASE WHEN '.$kolumnaStatusu.' = ? THEN ('.$wyrazenie.') ELSE ? END',
            [Report::STATUS_OPEN, ...$parametry, self::NIE_CZEKA],
        ];
    }
}
