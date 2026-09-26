<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Tag;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Metryki doboru bez profilowania (issue #1814, D-281, próg rewizji z D-275).
 *
 * PO CO
 * D-275 zamknął listę reguł doboru i zostawił jedną furtkę: do rozmowy
 * o rankingu wolno wrócić, gdy liczby przekroczą progi. Ta klasa jest tymi
 * liczbami — wyłącznie AGREGATY z tabel, które i tak istnieją: `posts`,
 * `first_post_events`, `comments`, `cooked_events`, `post_tags`.
 *
 * CZEGO TU NIE MA I NIE BĘDZIE
 *  - logu wyświetleń ani nowego zdarzenia (minimalizacja danych, polityka
 *    prywatności, konstrukcja `product_signals`);
 *  - identyfikatora wpisu ani nazwy osoby w wyniku — wychodzą same liczby;
 *  - ukryć i reakcji „Smakowicie wygląda" (D-278, D-280: nie są źródłem
 *    analityki). Strażnik: `MetrykiDoboruTest::test_nie_czyta_ukryc_ani_reakcji`.
 *
 * TRZECI PRÓG BEZ LOGU WYŚWIETLEŃ (decyzja właściciela 26.09, D-281)
 * Propozycja z #1814 brzmiała „> 30% autorów bez pierwszej strony w 7 dni".
 * Kto był na pierwszej stronie, wie tylko log wyświetleń — którego nie ma.
 * Wskaźnik zastępczy liczymy z samych `posts`, bo pierwsza strona „Świeżo
 * z Kuking" jest DETERMINISTYCZNA (D-276): to najnowszy wpis każdej z
 * `feed.page_size` osób, które publikowały ostatnio. Wpis stoi więc na
 * pierwszej stronie od publikacji do chwili, gdy po nim opublikuje
 * `page_size` INNYCH osób (albo sam autor doda nowszy — wtedy stoi nowy).
 * Dla każdego autora sumujemy ten czas; „praktycznie bez pierwszej strony"
 * to autor, któremu wyszło mniej niż `minut_na_pierwszej_stronie`.
 * Przybliżenie pomija bramki działające per widz (blokady, ukrycia) i zdjęcia
 * moderacyjne w trakcie — mierzy sam ruch publikacji, czyli dokładnie to, co
 * zapycha pierwszą stronę.
 *
 * Z liczb wyłączone są konta z `CookEligibility::excludedUserIds()`
 * (gospodarz, konta zalążkowe i zamknięte) — mają mierzyć społeczność.
 * Czasy trwania liczymy w godzinach (`interval 'N hours'`), nie dniach —
 * `interval 'day'` zależy od strefy sesji Postgresa (patrz `PowrotPoDniach`).
 */
final class MetrykiDoboru
{
    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    /** @return array<string, mixed> */
    public function wszystkie(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $wykluczeni = $this->eligibility->excludedUserIds();

        return [
            'pierwsze_wpisy_z_odpowiedzia_24h' => $this->pierwszeWpisyZOdpowiedzia($teraz, $wykluczeni),
            'autorzy_ponownie_28_dni' => $this->autorzyPonownie($teraz, $wykluczeni),
            'udzial_najaktywniejszych_10_procent' => $this->udzialNajaktywniejszych($teraz, $wykluczeni),
            'autorzy_dziennie' => $this->autorzyDziennie($teraz, $wykluczeni),
            'publiczne_z_tagiem' => $this->publiczneZTagiem($teraz, $wykluczeni),
            'bez_pierwszej_strony_7_dni' => $this->bezPierwszejStrony($teraz, $wykluczeni),
            'tygodnie_danych' => $this->tygodnieDanych($teraz, $wykluczeni),
            'progi' => config('kuking.metryki'),
        ];
    }

    /**
     * Odsetek pierwszych wpisów (z `first_post_events`) z ostatnich 30 dni,
     * pod którymi w ciągu 24 godzin odpowiedział człowiek: komentarz innej
     * osoby albo „Ugotowałem" przy przepisie, na który wpis wskazuje. Tylko
     * wpisy, którym doba już minęła — młodszy „jeszcze nie" to nie „nie".
     *
     * @param  list<string>  $wykluczeni
     * @return array{mianownik: int, licznik: int, procent: float|null}
     */
    private function pierwszeWpisyZOdpowiedzia(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $wpisy = DB::table('first_post_events')
            ->join('posts', 'posts.id', '=', 'first_post_events.post_id')
            ->whereNull('posts.deleted_at')
            ->whereNotNull('posts.published_at')
            ->where('posts.published_at', '>=', $teraz->subHours(30 * 24))
            ->where('posts.published_at', '<=', $teraz->subHours(24))
            ->whereNotIn('posts.author_id', $wykluczeni);

        $mianownik = (clone $wpisy)->count();
        $licznik = $wpisy
            ->where(fn ($odpowiedz) => $odpowiedz
                ->whereExists(fn ($c) => $c->selectRaw('1')->from('comments')
                    ->whereColumn('comments.post_id', 'posts.id')
                    ->whereColumn('comments.author_id', '!=', 'posts.author_id')
                    ->where('comments.status', Comment::STATUS_PUBLISHED)
                    ->whereNull('comments.deleted_at')
                    ->whereRaw("comments.created_at <= posts.published_at + interval '24 hours'")
                    ->whereNotExists($this->kontoZalazkowe('comments.author_id')))
                ->orWhereExists(fn ($u) => $u->selectRaw('1')->from('cooked_events')
                    ->whereNotNull('posts.recipe_id')
                    ->whereColumn('cooked_events.recipe_id', 'posts.recipe_id')
                    ->whereColumn('cooked_events.user_id', '!=', 'posts.author_id')
                    ->whereColumn('cooked_events.created_at', '>=', 'posts.published_at')
                    ->whereRaw("cooked_events.created_at <= posts.published_at + interval '24 hours'")
                    ->whereNotExists($this->kontoZalazkowe('cooked_events.user_id'))))
            ->count();

        return $this->udzial($licznik, $mianownik);
    }

    /**
     * Odsetek autorów, którzy po pierwszym wpisie opublikowali kolejny
     * w ciągu 28 dni. Kohorta: pierwszy wpis 28–56 dni temu (każdy miał pełne
     * 28 dni na powrót).
     *
     * @param  list<string>  $wykluczeni
     * @return array{mianownik: int, licznik: int, procent: float|null}
     */
    private function autorzyPonownie(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $kohorta = DB::table('first_post_events')
            ->join('posts as pierwszy', 'pierwszy.id', '=', 'first_post_events.post_id')
            ->whereNotNull('pierwszy.published_at')
            ->where('pierwszy.published_at', '>=', $teraz->subHours(56 * 24))
            ->where('pierwszy.published_at', '<', $teraz->subHours(28 * 24))
            ->whereNotIn('first_post_events.author_id', $wykluczeni);

        $mianownik = (clone $kohorta)->count();
        $licznik = $kohorta
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('posts as kolejny')
                ->whereColumn('kolejny.author_id', 'first_post_events.author_id')
                ->whereColumn('kolejny.id', '!=', 'pierwszy.id')
                ->whereNull('kolejny.deleted_at')
                ->whereColumn('kolejny.published_at', '>', 'pierwszy.published_at')
                ->whereRaw("kolejny.published_at <= pierwszy.published_at + interval '672 hours'"))
            ->count();

        return $this->udzial($licznik, $mianownik);
    }

    /**
     * Jaką część publicznych wpisów z 30 dni napisało 10% najaktywniejszych
     * autorów (co najmniej jedna osoba). „Najaktywniejsi" = najwięcej WŁASNYCH
     * wpisów — to liczba, której nikt tu nie sortuje w interfejsie.
     *
     * @param  list<string>  $wykluczeni
     * @return array{autorow: int, wpisow: int, najaktywniejszych: int, procent: float|null}
     */
    private function udzialNajaktywniejszych(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $naAutora = $this->publiczne($teraz->subHours(30 * 24), $teraz, $wykluczeni)
            ->groupBy('posts.author_id')
            ->selectRaw('count(*) as ile')
            ->pluck('ile')
            ->map(fn ($ile): int => (int) $ile)
            ->sortDesc()
            ->values();

        $autorow = $naAutora->count();
        $wpisow = (int) $naAutora->sum();
        $najaktywniejszych = $autorow === 0 ? 0 : max(1, (int) ceil($autorow * 0.1));
        $ich = (int) $naAutora->take($najaktywniejszych)->sum();

        return [
            'autorow' => $autorow,
            'wpisow' => $wpisow,
            'najaktywniejszych' => $najaktywniejszych,
            'procent' => $this->udzial($ich, $wpisow)['procent'],
        ];
    }

    /**
     * Ile różnych osób opublikowało publiczny wpis danego dnia (czas polski)
     * — to jest głębokość pierwszej rundy Odkrywania. Ostatnie 28 pełnych dni,
     * bez dzisiejszego (niepełny zaniżałby średnią).
     *
     * @param  list<string>  $wykluczeni
     * @return array{dni: array<string, int>, srednia_28_dni: float}
     */
    private function autorzyDziennie(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $dzisiaj = CarbonImmutable::parse($teraz)->setTimezone(Czas::strefa())->startOfDay();
        $od = $dzisiaj->subDays(28);
        $dzien = 'date('.Czas::wStrefieCzlowieka('posts.published_at').')';

        $zBazy = $this->publiczne($od, $dzisiaj, $wykluczeni)
            ->where('posts.published_at', '<', $dzisiaj)
            ->groupByRaw($dzien)
            ->selectRaw("{$dzien} as dzien, count(distinct posts.author_id) as autorow")
            ->pluck('autorow', 'dzien');

        $dni = [];
        for ($d = $od; $d->lessThan($dzisiaj); $d = $d->addDay()) {
            $dni[$d->toDateString()] = (int) ($zBazy[$d->toDateString()] ?? 0);
        }

        return ['dni' => $dni, 'srednia_28_dni' => round(array_sum($dni) / 28, 1)];
    }

    /**
     * Odsetek publicznych wpisów z 30 dni z co najmniej jednym aktywnym tagiem
     * (warunek ukrywania tagów w interfejsie: 60%, `progi.publiczne_z_tagiem`).
     *
     * @param  list<string>  $wykluczeni
     * @return array{mianownik: int, licznik: int, procent: float|null}
     */
    private function publiczneZTagiem(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $wpisy = $this->publiczne($teraz->subHours(30 * 24), $teraz, $wykluczeni);
        $mianownik = (clone $wpisy)->count();
        $licznik = $wpisy->whereExists(fn ($q) => $q->selectRaw('1')->from('post_tags')
            ->join('tags', 'tags.id', '=', 'post_tags.tag_id')
            ->whereColumn('post_tags.post_id', 'posts.id')
            ->where('tags.status', Tag::STATUS_ACTIVE))
            ->count();

        return $this->udzial($licznik, $mianownik);
    }

    /**
     * Wskaźnik zastępczy trzeciego progu (opis w nagłówku klasy, D-281).
     *
     * Autorzy: kto opublikował publiczny wpis od 8 do 1 doby temu (każdy wpis
     * miał co najmniej dobę na „stanie" na pierwszej stronie). Czas na
     * pierwszej stronie liczymy do teraz, z ruchu publikacji od początku okna.
     *
     * @param  list<string>  $wykluczeni
     * @return array{mianownik: int, licznik: int, procent: float|null, miejsc_na_stronie: int, prog_minut: int}
     */
    private function bezPierwszejStrony(CarbonImmutable $teraz, array $wykluczeni): array
    {
        $miejsc = max(1, (int) config('kuking.feed.page_size'));
        $progMinut = (int) config('kuking.metryki.minut_na_pierwszej_stronie');
        $poczatek = $teraz->subHours(8 * 24);
        $koniecOkna = $teraz->subHours(24);

        // Tylko dwie kolumny i tylko z tego okna — kilkaset wierszy przy
        // ~60 autorach dziennie. Pętla niżej przerywa po `page_size` innych
        // autorach, więc praca jest liniowa w liczbie wpisów.
        $wpisy = $this->publiczne($poczatek, $teraz, $wykluczeni)
            ->orderBy('posts.published_at')
            ->orderBy('posts.id')
            ->get(['posts.author_id', 'posts.published_at'])
            ->map(fn ($w): array => ['autor' => (string) $w->author_id, 'kiedy' => CarbonImmutable::parse($w->published_at)->getTimestamp()])
            ->all();

        $sekund = [];
        $ile = count($wpisy);
        for ($i = 0; $i < $ile; $i++) {
            $wpis = $wpisy[$i];
            if ($wpis['kiedy'] > $koniecOkna->getTimestamp()) {
                continue;
            }

            $koniec = $teraz->getTimestamp();
            $inni = [];
            for ($j = $i + 1; $j < $ile; $j++) {
                if ($wpisy[$j]['autor'] === $wpis['autor']) {
                    // Nowszy wpis tej osoby zastępuje ten na pierwszej stronie.
                    $koniec = $wpisy[$j]['kiedy'];
                    break;
                }
                $inni[$wpisy[$j]['autor']] = true;
                if (count($inni) >= $miejsc) {
                    $koniec = $wpisy[$j]['kiedy'];
                    break;
                }
            }

            $sekund[$wpis['autor']] = ($sekund[$wpis['autor']] ?? 0) + max(0, $koniec - $wpis['kiedy']);
        }

        $bez = count(array_filter($sekund, fn (int $s): bool => $s < $progMinut * 60));

        return [...$this->udzial($bez, count($sekund)), 'miejsc_na_stronie' => $miejsc, 'prog_minut' => $progMinut];
    }

    /**
     * Ile pełnych tygodni minęło od pierwszego publicznego wpisu społeczności
     * (próg „≥ 8 tygodni danych").
     *
     * @param  list<string>  $wykluczeni
     */
    private function tygodnieDanych(CarbonImmutable $teraz, array $wykluczeni): int
    {
        $pierwszy = Post::query()->publiclyVisible()->whereNotIn('posts.author_id', $wykluczeni)->min('posts.published_at');

        return $pierwszy === null ? 0 : intdiv(max(0, $teraz->getTimestamp() - CarbonImmutable::parse($pierwszy)->getTimestamp()), 7 * 86_400);
    }

    /**
     * Publiczne, opublikowane wpisy z okna, bez kont wyłączonych z liczb.
     *
     * @param  list<string>  $wykluczeni
     * @return Builder<Post>
     */
    private function publiczne(CarbonImmutable $od, CarbonImmutable $do, array $wykluczeni): Builder
    {
        return Post::query()
            ->publiclyVisible()
            ->where('posts.published_at', '>=', $od)
            ->where('posts.published_at', '<=', $do)
            ->whereNotIn('posts.author_id', $wykluczeni);
    }

    /** Konto zalążkowe (`is_seeded`) — jego odpowiedź nie jest odpowiedzią człowieka. */
    private function kontoZalazkowe(string $kolumna): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('users')
            ->whereColumn('users.id', $kolumna)
            ->where('users.is_seeded', true);
    }

    /** @return array{mianownik: int, licznik: int, procent: float|null} */
    private function udzial(int $licznik, int $mianownik): array
    {
        return [
            'mianownik' => $mianownik,
            'licznik' => $licznik,
            'procent' => $mianownik === 0 ? null : round(100 * $licznik / $mianownik, 1),
        ];
    }
}
