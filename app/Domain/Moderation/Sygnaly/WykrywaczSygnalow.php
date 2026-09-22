<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Sygnaly;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * WYKRYWACZ, KTÓRY PODNOSI RĘKĘ — NIE STRAŻNIK, KTÓRY ZAMYKA DRZWI (D-052).
 *
 * Ta klasa CZYTA treść i oddaje listę powodów po polsku. Nie zapisuje niczego,
 * nie ukrywa, nie ogranicza zasięgu i nie wie, co się dalej z jej wynikiem
 * stanie. Konsekwencje kończą się na jednej pozycji w kolejce moderatora
 * (`OznaczDoPrzegladu`), a treść stoi w serwisie dokładnie tak samo jak
 * przedtem — autor i czytelnicy nie widzą ŻADNEJ różnicy.
 *
 * KOGO TEN SERWIS MA NIE ZŁAPAĆ — to jest ważniejsze niż to, kogo ma złapać
 * Nasi ludzie przychodzą grupą z innego serwisu kulinarnego: rejestrują się
 * tego samego dnia, część z jednego łącza (koło gospodyń, biblioteka, dom
 * seniora), i od razu przenoszą archiwum — dziesiątki przepisów w godzinę,
 * wklejanych z notatnika, czasem TE SAME u kilku osób, bo krążyły w tej
 * grupie latami. Każdy „oczywisty" sygnał spamowy — wiele kont z jednego IP,
 * seria wpisów, ta sama treść u różnych kont, zerowy czas pisania — trafia
 * w te osoby CELNIE i w ich pierwszym dniu.
 *
 * Dlatego tutaj nie ma ani jednego z tych czterech sygnałów, mimo że przy
 * setkach kont wreszcie miałyby dane. Zostały trzy, które opisują zachowanie
 * JEDNEGO konta wobec JEGO WŁASNEJ treści — pełne uzasadnienie każdego,
 * z listą spodziewanych fałszywych alarmów: `docs/legal/SYGNALY_AUTOMATU.md`.
 *
 * ZAKRES: WPISY I KOMENTARZE
 * Przepis nie przechodzi tędy — jego tekst leży w trzech tabelach
 * (`recipes`, `recipe_steps`, `recipe_ingredients`), a spam w serwisie
 * kulinarnym prawie zawsze ląduje tam, gdzie jest najszybciej: w polu
 * „napisz kilka słów" i pod cudzym zdjęciem. Rozszerzenie na przepisy jest
 * opisane w dokumencie jako osobny krok, nie jako przeoczenie.
 */
final class WykrywaczSygnalow
{
    public const KOD_POWTORZENIE = 'automat_powtorzenie';

    public const KOD_ODNOSNIK = 'automat_odnosnik';

    public const KOD_WZORZEC = 'automat_wzorzec';

    /**
     * Ile znaków tekstu bierzemy do porównania podobieństwa.
     *
     * `similar_text()` jest kwadratowa względem długości, a wpis może mieć
     * kilka tysięcy znaków. Przy przenoszeniu archiwum jedna osoba wrzuca
     * dziesiątki takich wpisów w godzinę, więc porównanie każdego z każdym
     * bez sufitu potrafiłoby zająć worker na minuty. Pierwsze 1500 znaków
     * wystarcza, żeby odróżnić „to samo drugi raz" od „inny przepis".
     */
    private const LIMIT_POROWNANIA = 1500;

    /**
     * ZNANE WZORCE OGŁOSZENIOWE — wzorzec, zdanie dla moderatora.
     *
     * Lista jest krótka i celowo nie zawiera słów, które w serwisie
     * kulinarnym mają niewinne życie. Nie ma tu „okazja", „promocja" ani
     * „sprzedam" — przepis na przetwory i ogłoszenie o nadmiarze śliwek
     * z działki wyglądają wtedy tak samo.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const WZORCE = [
        'zarobek' => [
            '/(zarabiaj|zarobisz|zarobki\s+z\s+domu|dodatkow[ya]\s+zar[oó]b|szybki\s+zarobek|bez\s+wychodzenia\s+z\s+domu|praca\s+zdaln[ae]\s+od\s+zaraz)/iu',
            'Treść obiecuje zarobek — typowy wzorzec ogłoszenia, nie przepisu.',
        ],
        'finanse' => [
            '/(kryptowalut|bitcoin|inwestuj\s|szybka\s+po[zż]yczk|kredyt\s+bez\s+za[sś]wiadcze|kasyno|zak[lł]ady\s+bukmacher)/iu',
            'Treść dotyczy pieniędzy, pożyczek albo hazardu — nie ma to nic wspólnego z gotowaniem.',
        ],
        'komunikator' => [
            '#(t\.me/|wa\.me/)#iu',
            'Treść zaprasza do rozmowy przez komunikator poza serwisem.',
        ],
    ];

    /**
     * Słowa, po których dziewięć cyfr staje się numerem telefonu.
     *
     * BEZ TEGO WARUNKU AUTOMAT OZNACZAŁBY PRZEPISY. „Piecz w 180, potem 200,
     * na koniec 220" to trzy grupy po trzy cyfry — czyli dokładnie kształt
     * numeru telefonu. Zmierzone na wzorcu bez tego warunku: przepis na
     * chleb zapala sygnał.
     */
    private const SLOWA_KONTAKTOWE = '/(tel\.|telefon|zadzwo|dzwo[nń]|kontaktow|sms|whats\s?app|telegram|viber|messenger)/iu';

    /** Numer w kształcie „600 123 456", „600-123-456", „600123456". */
    private const GRUPA_DZIEWIECIU_CYFR = '/(?<!\d)\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}(?!\d)/u';

    /** Numer z kierunkowym — sam w sobie wystarczy, bo w przepisie nie ma po co stać. */
    private const NUMER_Z_KIERUNKOWYM = '/(?:\+|00)\s?48[\s.\-]?\d{3}[\s.\-]?\d{3}[\s.\-]?\d{3}(?!\d)/u';

    /**
     * Wszystkie powody, dla których TA treść trafia do przeglądu.
     *
     * Pusta lista znaczy „nic nie zauważyliśmy" i jest normalnym, najczęstszym
     * wynikiem. Kolejność: od najcięższego sygnału, bo pierwszy z listy
     * nadaje sprawie kwalifikację i miejsce w kolejce.
     *
     * @return list<Sygnal>
     */
    public function dla(Post|Comment $tresc): array
    {
        $tekst = trim((string) $tresc->body);
        $autor = $tresc->author;

        if ($tekst === '' || ! $autor instanceof User) {
            return [];
        }

        $sygnaly = array_values(array_filter([
            $this->wzorzecSpamu($tekst),
            $this->odnosnikUSwiezegoKonta($tekst, $autor, $tresc),
            $this->powtorzonaTresc($tekst, $autor, $tresc),
        ]));

        usort($sygnaly, static fn (Sygnal $a, Sygnal $b): int => $b->waga() <=> $a->waga());

        return $sygnaly;
    }

    /**
     * SYGNAŁ 1 — znany wzorzec ogłoszenia.
     *
     * Jedyny z trzech, który patrzy WYŁĄCZNIE na tekst: nie pyta bazy, nie
     * zależy od wieku konta i działa tak samo przy dwudziestu, jak przy
     * dwudziestu tysiącach kont.
     */
    private function wzorzecSpamu(string $tekst): ?Sygnal
    {
        foreach (self::WZORCE as [$wzorzec, $powod]) {
            if (preg_match($wzorzec, $tekst) === 1) {
                return new Sygnal(self::KOD_WZORZEC, $powod);
            }
        }

        if ($this->wygladaJakNumerTelefonu($tekst)) {
            return new Sygnal(
                self::KOD_WZORZEC,
                'W treści jest numer telefonu podany do kontaktu.',
            );
        }

        return null;
    }

    private function wygladaJakNumerTelefonu(string $tekst): bool
    {
        if (preg_match(self::NUMER_Z_KIERUNKOWYM, $tekst) === 1) {
            return true;
        }

        return preg_match(self::SLOWA_KONTAKTOWE, $tekst) === 1
            && preg_match(self::GRUPA_DZIEWIECIU_CYFR, $tekst) === 1;
    }

    /**
     * SYGNAŁ 2 — odnośnik zewnętrzny w pierwszych treściach świeżego konta.
     *
     * DWA WARUNKI NARAZ, I TO NIE JEST OSTROŻNOŚĆ NA ZAPAS. Samo „nowe konto"
     * oznaczyłoby całą falę osób przechodzących do nas grupą. Sam „odnośnik"
     * oznaczyłby każdego, kto uczciwie podaje źródło przepisu. Dopiero jedno
     * i drugie razem opisuje kształt, o który chodzi: konto założone po to,
     * żeby wkleić adres.
     *
     * Domeny, Z KTÓRYCH ludzie do nas przychodzą, są wyjęte
     * (`moderation.sygnaly.domeny_bez_sygnalu`) — odnośnik do starego profilu
     * w Garnku jest w pierwszym wpisie rzeczą OCZEKIWANĄ, a nie podejrzaną.
     */
    private function odnosnikUSwiezegoKonta(string $tekst, User $autor, Post|Comment $tresc): ?Sygnal
    {
        $domeny = $this->obceDomeny($tekst);

        if ($domeny === []) {
            return null;
        }

        $dni = (int) config('kuking.moderation.sygnaly.swieze_konto_dni');
        $ile = (int) config('kuking.moderation.sygnaly.swieze_konto_tresci');

        if ($autor->created_at === null || $autor->created_at->lt(now()->subDays($dni))) {
            return null;
        }

        if ($this->pozycjaWDorobku($autor, $tresc) > $ile) {
            return null;
        }

        $wiek = max(0, (int) $autor->created_at->diffInDays(now()));

        return new Sygnal(self::KOD_ODNOSNIK, sprintf(
            'Odnośnik do %s w jednej z pierwszych treści konta założonego %s.',
            implode(', ', array_slice($domeny, 0, 3)),
            $wiek === 0 ? 'dzisiaj' : 'przed '.$wiek.' '.$this->odmianaDni($wiek),
        ));
    }

    /**
     * SYGNAŁ 3 — ta sama (albo prawie ta sama) treść drugi raz w krótkim czasie.
     *
     * PORÓWNUJEMY WYŁĄCZNIE W OBRĘBIE JEDNEGO KONTA. Powtórzenie między
     * RÓŻNYMI kontami jest w naszej społeczności normalne: ten sam przepis na
     * sernik krążył w kole gospodyń przez trzydzieści lat i teraz wchodzi tu
     * pięcioma drogami naraz. Wykrywacz, który by to łapał, oznaczałby całą
     * falę migracyjną, a nie spamera.
     */
    private function powtorzonaTresc(string $tekst, User $autor, Post|Comment $tresc): ?Sygnal
    {
        $minZnakow = (int) config('kuking.moderation.sygnaly.powtorzenie_min_znakow');
        $znormalizowany = $this->znormalizuj($tekst);

        // Krótkie powtórzenia to uprzejmości pod cudzymi wpisami, nie spam.
        if (mb_strlen($znormalizowany) < $minZnakow) {
            return null;
        }

        $od = $this->czas($tresc)->copy()->subMinutes(
            (int) config('kuking.moderation.sygnaly.powtorzenie_minut'),
        );

        foreach ($this->wczesniejszeTresci($autor, $tresc, $od) as $poprzednia) {
            $inny = $this->znormalizuj((string) $poprzednia->body);

            if ($inny === '' || ! $this->podobne($znormalizowany, $inny)) {
                continue;
            }

            $minuty = max(0, (int) $this->czas($poprzednia)->diffInMinutes($this->czas($tresc)));

            return new Sygnal(self::KOD_POWTORZENIE, $minuty === 0
                ? 'Ta sama treść drugi raz w tej samej minucie.'
                : 'Ta sama treść drugi raz w ciągu '.$minuty.' '.$this->odmianaMinut($minuty).'.');
        }

        return null;
    }

    /**
     * Najnowsze treści tego samego autora z okna porównania.
     *
     * DWA ZAPYTANIA Z TWARDYM LIMITEM, NIE PRZEGLĄD TABELI. Oba idą po
     * istniejących indeksach — `posts_author_published_idx (author_id,
     * published_at DESC, id DESC)` i `comments_author_idx (author_id,
     * created_at DESC)` — więc koszt nie rośnie z liczbą wpisów w serwisie,
     * tylko z liczbą wpisów TEJ osoby w ostatniej godzinie. Limit ucina
     * nawet to.
     *
     * @return Collection<int, Post|Comment>
     */
    private function wczesniejszeTresci(User $autor, Post|Comment $tresc, CarbonInterface $od): Collection
    {
        $limit = (int) config('kuking.moderation.sygnaly.powtorzenie_limit_porownan');

        $wpisy = Post::query()
            ->where('author_id', $autor->getKey())
            ->when($tresc instanceof Post, fn ($q) => $q->whereKeyNot($tresc->getKey()))
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('published_at', '>=', $od)
            ->where('published_at', '<=', $this->czas($tresc))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $komentarze = Comment::query()
            ->where('author_id', $autor->getKey())
            ->when($tresc instanceof Comment, fn ($q) => $q->whereKeyNot($tresc->getKey()))
            ->where('status', Comment::STATUS_PUBLISHED)
            ->where('created_at', '>=', $od)
            ->where('created_at', '<=', $this->czas($tresc))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $wpisy->concat($komentarze);
    }

    /**
     * Którą z kolei treścią tego konta jest ta — licząc wpisy i komentarze.
     *
     * `COUNT` po tych samych dwóch indeksach co wyżej i z sufitem: pytamy
     * tylko o to, czy pozycja mieści się w pierwszej trójce, więc nie ma
     * powodu liczyć dalej niż do progu.
     */
    private function pozycjaWDorobku(User $autor, Post|Comment $tresc): int
    {
        $do = $this->czas($tresc);

        $wpisy = Post::query()
            ->where('author_id', $autor->getKey())
            ->where('status', Post::STATUS_PUBLISHED)
            ->where('published_at', '<=', $do)
            ->count();

        $komentarze = Comment::query()
            ->where('author_id', $autor->getKey())
            ->where('status', Comment::STATUS_PUBLISHED)
            ->where('created_at', '<=', $do)
            ->count();

        return $wpisy + $komentarze;
    }

    /**
     * Domeny spoza serwisu wymienione w tekście.
     *
     * Bierzemy tylko adresy w postaci, w jakiej człowiek je wkleja —
     * `https://…` i `www.…`. Gołe „cos.pl" świadomie NIE liczy się jako
     * odnośnik: w zdaniu „u nas mówi się na to «pierogi z blachy.pl»"
     * wyglądałoby identycznie, a nie prowadzi nigdzie.
     *
     * @return list<string>
     */
    private function obceDomeny(string $tekst): array
    {
        preg_match_all('#(?:https?://|www\.)([\p{L}0-9.\-]+)#iu', $tekst, $trafienia);

        $wlasne = array_map(
            static fn (string $domena): string => mb_strtolower(ltrim(trim($domena), '.')),
            (array) config('kuking.moderation.sygnaly.domeny_bez_sygnalu', []),
        );

        $wynik = [];

        foreach ($trafienia[1] ?? [] as $host) {
            $host = mb_strtolower(rtrim($host, '.'));
            $host = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

            if ($host === '' || in_array($host, $wynik, true)) {
                continue;
            }

            foreach ($wlasne as $domena) {
                if ($domena !== '' && ($host === $domena || str_ends_with($host, '.'.$domena))) {
                    continue 2;
                }
            }

            $wynik[] = $host;
        }

        return $wynik;
    }

    /**
     * Tekst sprowadzony do postaci, w której da się go porównać.
     *
     * Bez wielkości liter, bez podwójnych spacji, bez znaków przestankowych —
     * bo „ta sama treść" znaczy dla człowieka to samo zdanie, a nie ten sam
     * ciąg bajtów. Bez tego dopisany wykrzyknik wystarczyłby, żeby ominąć
     * sygnał.
     */
    private function znormalizuj(string $tekst): string
    {
        $tekst = mb_strtolower(trim($tekst));
        $tekst = preg_replace('/[\p{P}\p{S}]+/u', ' ', $tekst) ?? $tekst;

        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    private function podobne(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $a = mb_substr($a, 0, self::LIMIT_POROWNANIA);
        $b = mb_substr($b, 0, self::LIMIT_POROWNANIA);

        $dluzszy = max(mb_strlen($a), mb_strlen($b));
        $krotszy = min(mb_strlen($a), mb_strlen($b));

        // Teksty różniące się długością o więcej niż próg nie mogą już
        // przekroczyć progu podobieństwa — a `similar_text()` na nich jest
        // najdroższa. Odrzucamy je, zanim ją wywołamy.
        $prog = (float) config('kuking.moderation.sygnaly.powtorzenie_podobienstwo');

        if ($dluzszy === 0 || $krotszy / $dluzszy < $prog) {
            return false;
        }

        similar_text($a, $b, $procent);

        return $procent / 100 >= $prog;
    }

    private function czas(Post|Comment $tresc): CarbonInterface
    {
        return $tresc instanceof Post
            ? ($tresc->published_at ?? $tresc->created_at)
            : $tresc->created_at;
    }

    /**
     * „w ciągu 1 minuty", „w ciągu 4 minut".
     *
     * Po „w ciągu" idzie dopełniacz, więc odmiana jest dwuwartościowa i nie
     * potrzebuje pełnej reguły liczebnikowej. Zdanie dla moderatora ma być
     * po polsku — te zdania czyta się kilkaset razy dziennie.
     */
    private function odmianaMinut(int $ile): string
    {
        return $ile === 1 ? 'minuty' : 'minut';
    }

    /** „przed 1 dniem", „przed 5 dniami". */
    private function odmianaDni(int $ile): string
    {
        return $ile === 1 ? 'dniem' : 'dniami';
    }
}
