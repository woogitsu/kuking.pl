<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kontrola SAMYCH PLIKÓW słownika tagów — bez bazy danych i bez seedera
 * (D-026).
 *
 * PO CO OSOBNY TEST NA PLIK DANYCH
 * Bo najczęstszy błąd w takiej liście jest niewidoczny okiem: alias równy
 * NAZWIE KANONICZNEJ innego tagu. Taki wiersz nie jest „drobną
 * niekonsekwencją" — on zmusza seeder do rozstrzygnięcia, którego z dwóch
 * pojęć się pozbyć (patrz `TagSeeder`, scalanie), i przy złym rozstrzygnięciu
 * zabiera komuś tag. Przy pierwszej wersji pliku uzupełnień było ich PIĘĆ,
 * wszystkie znalezione dopiero tym pomiarem: „koperek" był już nazwą
 * kanoniczną, a ja wpisałem go jako alias „kopru"; „krupnik" był nazwą,
 * a ja wpisałem go jako alias „zupy krupnik".
 *
 * Ten test czyta DOKŁADNIE te pliki, które czyta seeder, więc podmiana
 * słownika na nową wersję jest sprawdzana, zanim ktokolwiek uruchomi
 * `db:seed` — i zanim nowa wersja trafi na produkcję.
 *
 * ZAKRESY LICZBOWE, NIE DOKŁADNE LICZBY
 * Kolejna wersja słownika ma prawo dodać albo usunąć hasła — test ma
 * łapać „plik zniknął / wczytał się w połowie", a nie każdą redakcyjną
 * zmianę. Dokładne liczby są sprawdzane w `TagSeederZeSlownikaTest`, gdzie
 * mają inny sens: tam pytanie brzmi „czy WSZYSTKO z pliku trafiło do bazy".
 */
class SlownikTagowTest extends TestCase
{
    private const KATEGORIE = [
        'potrawy', 'wypieki', 'skladniki', 'przygotowanie', 'przetwory',
        'okazje', 'sezon', 'regiony', 'kuchnie-swiata', 'diety',
        'okolicznosci', 'sprzet', 'pamiec',
    ];

    /**
     * Zakazane w nazwach i aliasach: nazwy marek i język dietetyczny
     * z obietnicą skutku. Nie jest to „lista wulgaryzmów" (ta jest osobno,
     * w `FiltrWulgaryzmow`) — to reguła redakcyjna z briefu słownika, ta
     * sama, którą łamały tagi „fit" i „dieta odchudzająca" z poprzedniej
     * bazy (dlatego ich w plikach nie ma).
     */
    private const ZAKAZANE = [
        'fit', 'zero kalorii', 'spalanie', 'odchudza', 'leczy', 'oczyszcza',
        'detoks', 'superfood', 'winiary', 'thermomix', 'termomix', 'knorr',
        'maggi', 'na odporność',
    ];

    /**
     * @return list<array{plik: string, nazwa: string, kategoria: string, aliasy: list<string>}>
     */
    private function wpisy(): array
    {
        $wpisy = [];

        foreach (['slownik-tagow.json', 'slownik-tagow-uzupelnienia.json'] as $plik) {
            $surowe = file_get_contents(database_path('seeders/dane/'.$plik));
            $this->assertIsString($surowe, "Nie da się wczytać {$plik}.");

            $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($dane);
            $this->assertIsArray($dane['tagi'] ?? null, "{$plik} nie ma tablicy `tagi`.");

            foreach ($dane['tagi'] as $tag) {
                $this->assertIsArray($tag);
                $this->assertIsString($tag['nazwa'] ?? null);
                $this->assertIsString($tag['kategoria'] ?? null);

                $aliasy = $tag['aliasy'] ?? [];
                $this->assertIsArray($aliasy);

                $wpisy[] = [
                    'plik' => $plik,
                    'nazwa' => $tag['nazwa'],
                    'kategoria' => $tag['kategoria'],
                    'aliasy' => array_map('strval', $aliasy),
                ];
            }
        }

        return $wpisy;
    }

    /**
     * KONTROLA. Pliki naprawdę mają zawartość tej skali — bez tego każdy
     * pomiar niżej przechodziłby na pustej liście.
     */
    public function test_slownik_ma_spodziewana_skale(): void
    {
        $wpisy = $this->wpisy();
        $aliasy = array_merge(...array_map(fn (array $w): array => $w['aliasy'], $wpisy));

        $this->assertGreaterThanOrEqual(1200, count($wpisy), 'Słownik ma mniej tagów, niż powinien — czy plik wczytał się cały?');
        $this->assertLessThanOrEqual(2000, count($wpisy));
        $this->assertGreaterThanOrEqual(1500, count($aliasy), 'Słownik ma mniej aliasów, niż powinien.');

        // Pole `uwagi` to jedyne miejsce, w którym zapisano ROZSTRZYGNIĘCIA
        // autora słownika (dlaczego „żur" i „żurek" nie są aliasami, dlaczego
        // „pyzy" nie prowadzą do „klusek na parze"). Bez niego kolejna osoba
        // powtórzy te same pytania i najprawdopodobniej odpowie inaczej.
        $glowny = json_decode((string) file_get_contents(database_path('seeders/dane/slownik-tagow.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($glowny);
        $this->assertIsArray($glowny['uwagi'] ?? null, 'Słownik stracił pole `uwagi`.');
        $this->assertGreaterThanOrEqual(20, count($glowny['uwagi']), 'Uwagi ze słownika zostały skrócone albo wyczyszczone.');
    }

    /** Nazwa kanoniczna nie może wystąpić dwa razy — ani w jednym pliku, ani między plikami. */
    public function test_zadna_nazwa_kanoniczna_nie_powtarza_sie(): void
    {
        $widziane = [];
        $powtorzone = [];

        foreach ($this->wpisy() as $wpis) {
            $klucz = Tag::znormalizujNazwe($wpis['nazwa']);

            if (isset($widziane[$klucz])) {
                $powtorzone[] = "„{$wpis['nazwa']}” ({$widziane[$klucz]} i {$wpis['plik']})";
            }

            $widziane[$klucz] = $wpis['plik'];
        }

        $this->assertSame([], $powtorzone, 'Ta sama nazwa kanoniczna występuje więcej niż raz.');
    }

    /**
     * WŁAŚCIWY POMIAR. Alias nie może być nazwą kanoniczną innego tagu ani
     * być współdzielony przez dwa tagi — `tag_aliases.normalized_alias` jest
     * `UNIQUE` w całej tabeli, więc drugi z nich i tak by nie wszedł, tylko
     * po cichu (raport seedera) zamiast tutaj, na wprost.
     */
    public function test_aliasy_nie_koliduja_z_nazwami_ani_ze_soba(): void
    {
        $wpisy = $this->wpisy();

        $nazwy = [];
        foreach ($wpisy as $wpis) {
            $nazwy[Tag::znormalizujNazwe($wpis['nazwa'])] = $wpis['nazwa'];
        }

        $wlasciciele = [];
        $bledy = [];

        foreach ($wpisy as $wpis) {
            $nazwaTagu = Tag::znormalizujNazwe($wpis['nazwa']);

            foreach ($wpis['aliasy'] as $alias) {
                $klucz = Tag::znormalizujNazwe($alias);

                if ($klucz === $nazwaTagu) {
                    $bledy[] = "alias „{$alias}” jest własną nazwą tagu „{$wpis['nazwa']}”";

                    continue;
                }

                if (isset($nazwy[$klucz])) {
                    $bledy[] = "alias „{$alias}” (tag „{$wpis['nazwa']}”) jest nazwą kanoniczną tagu „{$nazwy[$klucz]}”";
                }

                if (isset($wlasciciele[$klucz])) {
                    $bledy[] = "alias „{$alias}” należy do dwóch tagów: „{$wlasciciele[$klucz]}” i „{$wpis['nazwa']}”";
                }

                $wlasciciele[$klucz] = $wpis['nazwa'];
            }
        }

        $this->assertSame([], $bledy);
    }

    /**
     * Długości i kształt — kolumny `tags.name`, `tags.normalized_name`,
     * `tag_aliases.alias` i `tag_aliases.normalized_alias` mają po 30 znaków
     * (migracja `create_tags_tables`), więc dłuższe hasło nie jest „brzydkie",
     * tylko niemożliwe do zapisania.
     */
    public function test_nazwy_i_aliasy_miesza_sie_w_kolumnach(): void
    {
        $bledy = [];

        foreach ($this->wpisy() as $wpis) {
            foreach ([$wpis['nazwa'], ...$wpis['aliasy']] as $tekst) {
                $dlugosc = mb_strlen($tekst);

                if ($dlugosc < 2 || $dlugosc > 30) {
                    $bledy[] = "„{$tekst}” ma {$dlugosc} znaków (dozwolone 2–30)";
                }

                if (trim($tekst) !== $tekst || str_contains($tekst, '  ')) {
                    $bledy[] = "„{$tekst}” ma zbędne białe znaki";
                }

                // Slug musi się zmieścić w `tags.slug` (40 znaków, CHECK
                // `^[a-z0-9-]{1,40}$`) i nie może wyjść pusty.
                $slug = Tag::slugDlaNazwy($tekst);
                if ($slug === '' || mb_strlen($slug) > 40 || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
                    $bledy[] = "„{$tekst}” daje slug „{$slug}”, którego baza nie przyjmie";
                }
            }
        }

        $this->assertSame([], $bledy);
    }

    /** Kategoria techniczna musi być jedną z trzynastu — inna nie ma znaczenia w raporcie importu. */
    public function test_kazdy_tag_ma_znana_kategorie(): void
    {
        $obce = [];

        foreach ($this->wpisy() as $wpis) {
            if (! in_array($wpis['kategoria'], self::KATEGORIE, true)) {
                $obce[] = "„{$wpis['nazwa']}” → „{$wpis['kategoria']}”";
            }
        }

        $this->assertSame([], $obce);
    }

    /**
     * Reguła redakcyjna: bez marek i bez języka dietetycznego z obietnicą
     * skutku. Dopasowanie po GRANICY SŁOWA, nie przez `str_contains` —
     * inaczej „konfitura" jest zakazana, bo zawiera litery „fit", a „kuchnia
     * łęczycka" bo zawiera „leczy". Pierwsza wersja tego pomiaru miała
     * dokładnie ten błąd i zgłosiła cztery nieistniejące problemy.
     */
    public function test_slownik_nie_obiecuje_zdrowia_i_nie_reklamuje_marek(): void
    {
        $trafienia = [];

        foreach ($this->wpisy() as $wpis) {
            foreach ([$wpis['nazwa'], ...$wpis['aliasy']] as $tekst) {
                foreach (self::ZAKAZANE as $zakazane) {
                    $wzorzec = '/(?<![\p{L}])'.preg_quote($zakazane, '/').'(?![\p{L}])/ui';

                    if (preg_match($wzorzec, $tekst) === 1) {
                        $trafienia[] = "„{$tekst}” zawiera „{$zakazane}”";
                    }
                }
            }
        }

        $this->assertSame([], $trafienia);
    }

    /**
     * KONTROLA POMIARU WYŻEJ. Gdyby wzorzec był zbyt luźny (`str_contains`),
     * ten test by go złapał: „konfitura" MA w środku litery „fit", a mimo to
     * jest poprawnym hasłem, i słownik ją zawiera.
     */
    public function test_kontrola_granicy_slowa_w_zakazach(): void
    {
        $nazwy = array_map(fn (array $w): string => $w['nazwa'], $this->wpisy());

        $this->assertContains('konfitura', $nazwy, 'Słownik stracił „konfiturę” — pomiar zakazów nie ma już czego kontrolować.');

        $wzorzec = '/(?<![\p{L}])fit(?![\p{L}])/ui';
        $this->assertSame(0, preg_match($wzorzec, 'konfitura'), 'Wzorzec zakazu łapie „konfiturę”, czyli jest zbyt luźny.');
        $this->assertSame(1, preg_match($wzorzec, 'obiad fit'), 'Wzorzec zakazu nie łapie „fit” jako osobnego słowa — nie mierzy nic.');
    }

    /**
     * Transliteracja nazwy (`Str::ascii`, alias automatyczny z SPEC §1.2) nie
     * może wskoczyć na nazwę kanoniczną INNEGO tagu. Gdyby tak było, seeder
     * musiałby rozstrzygać kolizję przy każdym uruchomieniu — a dwa różne
     * pojęcia, które po odjęciu polskich znaków wyglądają tak samo, nie są
     * kandydatami do scalenia (D-021: „`zurek` i `żurek` to DWA tagi").
     */
    public function test_transliteracje_nazw_nie_wchodza_na_cudze_nazwy(): void
    {
        $wpisy = $this->wpisy();

        $nazwy = [];
        foreach ($wpisy as $wpis) {
            $nazwy[Tag::znormalizujNazwe($wpis['nazwa'])] = $wpis['nazwa'];
        }

        $kolizje = [];

        foreach ($wpisy as $wpis) {
            $wlasna = Tag::znormalizujNazwe($wpis['nazwa']);
            $bezZnakow = Tag::znormalizujNazwe(Str::ascii($wpis['nazwa']));

            if ($bezZnakow === $wlasna) {
                continue;
            }

            if (isset($nazwy[$bezZnakow])) {
                $kolizje[] = "„{$wpis['nazwa']}” bez polskich znaków to „{$nazwy[$bezZnakow]}”, osobny tag";
            }
        }

        $this->assertSame([], $kolizje);
    }
}
