<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Tag;
use App\Models\User;
use App\Support\FrazaWyszukiwania;
use Illuminate\Support\Str;

/**
 * Okno listy „Twoje tagi” — co z niej widać teraz (#858).
 *
 * DLACZEGO TO NIE JEST ZWYKŁA PAGINACJA
 * Zwykła paginacja pokazuje kolejną stronę i o poprzedniej zapomina. Tu nie
 * wolno zapomnieć: formularz zapisuje RÓŻNICĘ względem stanu z chwili
 * otwarcia (#854), a ten stan jest zapisany w tokenie `form_scope` jako
 * lista pokazanych tagów. Gdyby doładowanie ZASTĘPOWAŁO tę listę, zapis po
 * doładowaniu liczyłby różnicę wyłącznie względem ostatniej porcji, czyli
 * uznałby, że wszystko z porcji pierwszej człowiek właśnie odznaczył.
 * To jest dokładnie ta usterka, którą #854 naprawiło, tylko o szerszym
 * zasięgu — dlatego tu lista w tokenie ROŚNIE i nigdy się nie podmienia.
 *
 * DRUGA RZECZ, KTÓRĄ NAJŁATWIEJ TU ZEPSUĆ
 * Tag schowany przez filtr nadal jest w tokenie jako „pokazany”. Gdyby widok
 * przestał go wysyłać, zapis policzyłby go jako odznaczony i odobserwował
 * temat, którego człowiek nawet nie widział na ekranie. Dlatego klucz `ukryte`
 * niesie wybory, które muszą pojechać na serwer w polach ukrytych — wybór
 * idzie niezależnie od tego, co filtr akurat pokazuje.
 *
 * TRZECIA: TAG POKAZANY DOPIERO TERAZ, A JUŻ OBSERWOWANY
 * Doładowanie dokłada do tokenu także DATY istniejących relacji z nowej
 * porcji. Gdyby te tagi wróciły narysowane jako niezaznaczone, token
 * mówiłby „obserwowany”, formularz „niezaznaczony”, a różnica — „usuń”.
 * Dlatego nowo pokazany, już obserwowany tag wchodzi do wyboru z automatu.
 */
final class TagFollowWindow
{
    /**
     * Ile pozycji dokłada jedno naciśnięcie „Pokaż kolejne…”.
     *
     * Dwadzieścia, bo pomiar z `OBSERWOWANIE_TAGOW_2026-09-20.md` daje około
     * 84 px na pozycję przy 320 px — czyli porcja to mniej więcej dwa
     * i pół ekranu telefonu, a nie osiem i pół, jak cała setka. Liczba jest
     * wyborem, nie wynikiem: nie zmierzyłem, po ilu pozycjach ludzie
     * przestają przewijać.
     */
    public const PORCJA = 20;

    /** Tyle samo co w wyszukiwarce — dłuższej frazy i tak nikt nie wpisze. */
    public const MAKS_FRAZA = 120;

    public function __construct(private readonly TagFollowForm $forms) {}

    /**
     * Składa wszystko, co widok musi wiedzieć o liście — i mintuje token
     * rozszerzony o pozycje pokazane właśnie teraz.
     *
     * @param  string|null  $token  `form_scope` z poprzedniego wysłania
     * @param  array<int,mixed>|null  $wyslane  `tags[]` z poprzedniego wysłania;
     *                                          `null` znaczy „pierwsze otwarcie”,
     *                                          a `[]` znaczy „człowiek nie zaznaczył nic”
     * @param  bool  $przeglada  czy to człowiek nacisnął „pokaż…”, czy tylko
     *                           wróciła odmowa walidacji
     * @return array<string,mixed>
     */
    public function zloz(User $user, string $szukaj, mixed $ile, ?string $token, ?array $wyslane, bool $przeglada = false): array
    {
        $followed = $user->followedTags()->get();
        $daty = $followed->mapWithKeys(
            fn (Tag $tag): array => [$tag->getKey() => (string) $tag->pivot->created_at],
        )->all();

        $scope = $this->forms->decode($user, $token);
        $pokazane = $scope === null ? [] : array_values(array_filter($scope['shown'], 'is_string'));
        $odniesienie = $scope['followed'] ?? [];

        // Wszechświat tego ekranu jest SUMĄ obserwowanych i promowanych —
        // uzasadnienie stoi w `TagFollowController::edit()` od czasów D-021
        // i filtrowanie go nie zmienia. Tag ukryty albo scalony po otwarciu
        // formularza wypada z tej sumy i nie wraca jako aktywna opcja.
        $pelne = $followed->concat(Tag::promowane()->get())->unique('id')->sortBy('name')->values();

        $szukaj = mb_substr(trim($szukaj), 0, self::MAKS_FRAZA);
        $fraza = self::normalizuj($szukaj);
        $pasuje = fn (Tag $tag): bool => $fraza === '' || str_contains(self::normalizuj($tag->name), $fraza);

        // ODMOWA WALIDACJI NIE OTWIERA OKNA SZERZEJ (#854).
        // Zakres rośnie WYŁĄCZNIE wtedy, gdy człowiek nacisnął „Pokaż…”.
        // Gdyby rósł także przy powrocie z błędem, tag obserwowany w innej
        // karcie po otwarciu tego formularza wchodziłby do punktu odniesienia
        // — a stąd już tylko krok do usunięcia go przy zapisie. Przy odmowie
        // widać dokładnie to, co widać było przed wysłaniem.
        //
        // Ograniczenie dotyczy WYŁĄCZNIE tego, co wolno narysować. Licznik
        // i przycisk „Pokaż kolejne…” liczą się od pełnej listy — inaczej po
        // odmowie walidacji przycisk znikałby i człowiek zostawał z oknem,
        // którego nie ma jak otworzyć bez przeładowania strony z ręki.
        $dostepne = $pelne;
        if (! $przeglada && $scope !== null) {
            $wPokazanych = $pelne->filter(
                fn (Tag $tag): bool => in_array($tag->getKey(), $pokazane, true),
            )->values();
            // Gdyby wszystkie pokazane hasła zniknęły ze wszechświata,
            // ograniczenie zostawiłoby pusty ekran bez powodu widocznego
            // dla człowieka. Wtedy formularz otwiera się od nowa.
            $dostepne = $wPokazanych->isEmpty() ? $pelne : $wPokazanych;
        }
        $dostepneId = $dostepne->pluck('id')->all();

        $pasujace = $dostepne->filter($pasuje)->values();
        $pasujacychPelnych = $pelne->filter($pasuje)->count();

        // Okno nigdy nie schodzi poniżej jednej porcji i nigdy nie przekracza
        // wszechświata — wartość z żądania jest podpowiedzią, nie rozkazem.
        $ile = max(self::PORCJA, min(is_numeric($ile) ? (int) $ile : 0, max(1, $pelne->count())));
        $widoczne = $pasujace->take($ile)->values();
        $widoczneId = $widoczne->pluck('id')->all();

        // ROZSZERZENIE, NIE PODMIANA. Suma, nigdy przypisanie.
        $nowe = array_values(array_diff($widoczneId, $pokazane));
        $pokazane = array_values(array_unique([...$pokazane, ...$nowe]));
        foreach ($nowe as $id) {
            if (array_key_exists($id, $daty)) {
                $odniesienie[$id] = $daty[$id];
            }
        }

        $wybrane = $wyslane === null
            ? array_keys($daty)
            : array_values(array_filter($wyslane, 'is_string'));
        // Nowo pokazany, już obserwowany tag wraca zaznaczony — patrz
        // „TRZECIA” w komentarzu klasy. Tylko przy ROZSZERZANIU istniejącego
        // formularza: gdy token przepadł albo go nie było, formularz otwiera
        // się od nowa i pusty wybór człowieka ma zostać pusty (#855), a nie
        // odrosnąć z bazy.
        if ($scope !== null) {
            $wybrane = array_values(array_unique([...$wybrane, ...array_intersect($nowe, array_keys($daty))]));
        }

        return [
            'tags' => $widoczne,
            'wybrane' => $wybrane,
            // Wybory, które trzeba donieść na serwer poza widoczną listą.
            // Tylko z tego, co token już zna: tag spoza `shown` wywróciłby
            // zapis na kontroli „Wybierz tagi z tej listy”.
            'ukryte' => array_values(array_diff(
                array_intersect($wybrane, $pokazane, $dostepneId),
                $widoczneId,
            )),
            'formScope' => $this->forms->encode($user, $pokazane, $odniesienie),
            'szukaj' => $szukaj,
            'ile' => max(self::PORCJA, count($widoczneId)),
            'pozostalo' => max(0, $pasujacychPelnych - $widoczne->count()),
            'dojdzie' => min(self::PORCJA, max(0, $pasujacychPelnych - $widoczne->count())),
            'wszystkich' => $pelne->count(),
            'pasujacych' => $pasujacychPelnych,
        ];
    }

    /** Ile pokazać po naciśnięciu „Pokaż kolejne…”. */
    public static function nastepneOkno(mixed $ile): int
    {
        return (is_numeric($ile) ? (int) $ile : self::PORCJA) + self::PORCJA;
    }

    /**
     * Ta sama reguła co wyszukiwarka i podpowiedzi tagów
     * (`App\Support\FrazaWyszukiwania::normalizuj()`) — `Str::ascii` robi
     * z polskimi znakami to, co `unaccent` w bazie, więc
     * „zurek” znajduje „Żurek”. To nie jest przypadkowe podobieństwo: ekran,
     * który szuka inaczej niż wyszukiwarka obok, uczy dwóch różnych nawyków.
     *
     * Dopasowanie jest PODCIĄGIEM, nie podobieństwem. Wyszukiwarka wolno
     * zgaduje literówki, bo szuka w nieznanym zbiorze; tu człowiek patrzy na
     * własną listę i oczekuje, że wpisane litery po prostu się w nazwie
     * znajdą (UX_50_PLUS: przewidywalność przed bogactwem).
     */
    public static function normalizuj(string $fraza): string
    {
        return FrazaWyszukiwania::normalizuj(mb_substr($fraza, 0, self::MAKS_FRAZA));
    }
}
