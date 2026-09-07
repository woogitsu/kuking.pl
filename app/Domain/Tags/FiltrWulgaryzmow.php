<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use Illuminate\Support\Str;

/**
 * Minimalna lokalna lista wulgaryzmów — blokuje TWORZENIE nowego tagu
 * (R1 §8, w odpowiedzi na SPEC §1.10).
 *
 * DLACZEGO MINIMALNA, A NIE „ODPORNA NA OBEJŚCIA"
 * SPEC §1.10 opisuje pełny schemat: severity × typ × wyjątki kontekstowe,
 * z obroną przed zamianą znaków (0/o, 1/i), rozdzielaniem spacjami
 * i polską fleksją. Niezależny przegląd tej specyfikacji (R1 §8) ocenił to
 * jako przerost inżynierski przy 20–50 kontach zamkniętej bety: pełny
 * stemming dla polskiego jest osobnym, nietrywialnym problemem
 * inżynierskim (AGENTS.md §3 — „zmierzona, udokumentowana potrzeba" zanim
 * się to zbuduje), a ryzyko celowego, wyrafinowanego obchodzenia filtra
 * przez ludzi znanych właścicielowi na starcie jest bliskie zeru.
 *
 * Dlatego: krótka, ręcznie utrzymywana lista dokładnych tokenów, bez
 * wariantów leetspeak i bez morfologii. Rozbudowa (severity, typ, wyjątki
 * kontekstowe z SPEC §1.10) zostaje udokumentowanym, ale NIE zbudowanym
 * następnym krokiem — do uruchomienia dopiero, gdy pojawi się pierwszy
 * realny przypadek obejścia.
 *
 * DLACZEGO TAG JEST TRAKTOWANY OSTRZEJ NIŻ TREŚĆ WPISU
 * Tag jest publiczną etykietą indeksowaną przez wyszukiwarkę i ma własną
 * stronę (`/tag/{slug}`) — wyższa ekspozycja niż wolny tekst wpisu, który
 * wymaga wejścia pod konkretną treść. Ten filtr blokuje wyłącznie
 * TWORZENIE nowego tagu (w `App\Domain\Tags\Actions\ResolveTagsForPost`
 * i w `TagSeeder`) — treść wpisu ma osobną, poza zakresem tego zadania,
 * warstwę moderacji (SPEC §1.11).
 *
 * DOPASOWANIE PO CAŁYCH TOKENACH, NIGDY `str_contains()`
 * SPEC §1.10 nazywa to wprost: dopasowanie substringu zamiast tokenu
 * blokowałoby niewinne słowa zawierające zakazany ciąg wewnątrz innego
 * słowa (tzw. efekt Scunthorpe). Tag dzieli się na tokeny po spacji
 * i myślniku, a każdy token jest sprawdzany OSOBNO i W CAŁOŚCI.
 *
 * NORMALIZACJA TUTAJ CELOWO UŻYWA `Str::ascii()` (usuwa diakrytyki) —
 * W ODRÓŻNIENIU od `Tag::znormalizujNazwe()`, które go NIE używa.
 * To nie jest niespójność: to są dwa różne pytania. `Tag::znormalizujNazwe()`
 * odpowiada na „czy to jest ten sam tag" (gdzie `zurek` i `żurek` MUSZĄ
 * pozostać różne — D-021). Ten filtr odpowiada na „czy to jest to samo
 * wulgarne słowo" — a tu akurat forma bez polskich znaków i z nimi to
 * wciąż jedno słowo, więc `unaccent`-podobne złagodzenie pomaga, zamiast
 * szkodzić (R1 §8).
 */
final class FiltrWulgaryzmow
{
    /**
     * Rdzenie słów, PO transliteracji i małych literach. Krótka lista,
     * ręcznie utrzymywana — patrz komentarz klasy.
     *
     * „PO TRANSLITERACJI" TO NIE OPIS, A WARUNEK POPRAWNOŚCI.
     * Hasło zapisane tutaj z polskim znakiem nie zablokuje NIGDY niczego:
     * do porównania dochodzi już `pedal`, nie `pedał`. Dwa hasła stały tu
     * właśnie w ten sposób (`pedał`, `jebnięty`), więc lista wyglądała na
     * dłuższą, niż była — 40 pozycji, z których działało 38.
     * `Tests\Unit\FiltrWulgaryzmowBezMartwychHaselTest` pilnuje teraz tego
     * niezmiennika dla każdej pozycji, także dla tych dopisanych w przyszłości.
     *
     * @var list<string>
     */
    private const SLOWA = [
        'kurwa', 'kurwy', 'kurwo', 'kurwe', 'chuj', 'chuja', 'chuju', 'chujem',
        'chujowy', 'chujowa', 'pierdol', 'pierdole', 'pierdolony', 'pierdolona',
        'jebac', 'jebany', 'jebana', 'zajebisty', 'zajebista', 'spierdalaj',
        'pizda', 'pizdy', 'cipa', 'cipy', 'dziwka', 'dziwki', 'kutas', 'kutasa',
        'skurwysyn', 'skurwysynu', 'huj', 'huja', 'jebnij', 'jebniety',
        'pedal', 'pedaly', 'ciota', 'menda', 'debil', 'debilu', 'idiota',
        'kretyn', 'kretynie', 'gnida', 'gnoj', 'gnoju', 'szmata', 'szmato',
    ];

    public static function zawieraNiedozwoloneSlowo(string $fraza): bool
    {
        $znormalizowana = mb_strtolower(Str::ascii($fraza));
        $tokeny = preg_split('/[\s-]+/u', $znormalizowana, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokeny === false) {
            return false;
        }

        foreach ($tokeny as $token) {
            if (in_array($token, self::SLOWA, true)) {
                return true;
            }
        }

        return false;
    }
}
