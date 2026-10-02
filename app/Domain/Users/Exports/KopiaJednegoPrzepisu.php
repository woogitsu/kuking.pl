<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Przenośna kopia JEDNEGO własnego przepisu, bez pełnego eksportu konta (#2531,
 * V2, decyzja właściciela z 2.10.2026).
 *
 * Mała paczka ZIP w istniejącym formacie (`dane.json` z `o_tym_pliku`,
 * `przepisy`, `wpisy`, `kolekcje`; ten sam `WersjaFormatuPaczki` i to samo
 * mapowanie pól co pełna paczka — `PrzepisDoPaczki`), więc obecny podgląd
 * importu (`PodgladPaczkiEksportu`) ją wczytuje, jako prywatny szkic i po
 * osobnym potwierdzeniu. Obok czytelna strona `przepis.html` (dwuklik, bez
 * internetu) i krótka instrukcja `CZYTAJ_TO.txt`.
 *
 * CZEGO KOPIA NIE ZAWIERA — i to jest granica, nie niedopatrzenie:
 *  - zdjęć (ani głównego, ani skanu, ani przy krokach; ścieżki są `null`),
 *  - profilu, adresu e-mail, sesji, zgód, relacji, komentarzy, cudzych
 *    wykonań, pozostałych przepisów, zeszytów i wersji,
 *  - tytułu cudzego oryginału („Moja wersja”).
 *
 * WŁASNOŚĆ SPRAWDZANA TU, nie tylko w Policy: wywołanie wprost z domeny nie
 * wyda kopii cudzego przepisu. UUID ani adres nie są prawem do eksportu.
 *
 * PLIK TYMCZASOWY: powstaje w katalogu tymczasowym systemu, wysyła go
 * kontroler i kasuje zaraz po wysłaniu (`deleteFileAfterSend`); nic nie trafia
 * do magazynu plików, do bazy ani pod stały adres. Kopia nie zostawia śladu w
 * bazie, więc nie ma osobnej retencji danych.
 */
final class KopiaJednegoPrzepisu
{
    public const PLIK_DANYCH = 'dane.json';

    public const PLIK_DO_CZYTANIA = 'przepis.html';

    public const PLIK_INSTRUKCJI = 'CZYTAJ_TO.txt';

    /**
     * @return array{sciezka: string, nazwa: string} ścieżka pliku tymczasowego do wysłania i skasowania
     *
     * @throws AuthorizationException gdy przepis nie należy do osoby
     * @throws RuntimeException gdy nie da się zbudować archiwum
     */
    public function zbuduj(User $autor, Recipe $przepis, ?Carbon $kiedy = null): array
    {
        if ($przepis->author_id !== $autor->getKey()) {
            throw new AuthorizationException('Kopię można pobrać tylko dla własnego przepisu.');
        }

        $kiedy ??= Carbon::now();

        $przepis->load(['ingredients.ingredient', 'ingredients.unit', 'steps']);
        $autor->loadMissing('profile');
        $przepis->setRelation('author', $autor);

        $dane = $this->dane($przepis, $kiedy);
        $json = json_encode($dane, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $html = view('exports.recipe', [
            'recipe' => $przepis,
            'heroPhoto' => null,
            'scanPhoto' => null,
            'stepPhotos' => [],
            'comments' => [],
            'kopiaJednego' => true,
        ])->render();

        // Z sygnaturą UTF-8 (BOM), jak README pełnej paczki: stary Notatnik
        // bez niej rozsypałby polskie znaki.
        $instrukcja = "\xEF\xBB\xBF".view('exports.kopia-przepisu-readme', [
            'tytul' => $przepis->title,
            'kiedy' => $kiedy,
            'nazwaPliku' => self::PLIK_DO_CZYTANIA,
        ])->render();

        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-kopia-');

        if ($sciezka === false) {
            throw new RuntimeException('Nie udało się przygotować pliku tymczasowego.');
        }

        $zip = new ZipArchive;

        if ($zip->open($sciezka, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sciezka);

            throw new RuntimeException('Nie udało się utworzyć archiwum.');
        }

        $zip->addFromString(self::PLIK_INSTRUKCJI, $instrukcja);
        $zip->addFromString(self::PLIK_DO_CZYTANIA, $html);
        $zip->addFromString(self::PLIK_DANYCH, $json);

        if (! $zip->close()) {
            @unlink($sciezka);

            throw new RuntimeException('Nie udało się zapisać archiwum.');
        }

        return ['sciezka' => $sciezka, 'nazwa' => $this->nazwaPliku($przepis, $kiedy)];
    }

    /**
     * Zawartość `dane.json` tej kopii — do podglądu przed pobraniem i do pliku.
     *
     * @return array<string, mixed>
     */
    public function dane(Recipe $przepis, Carbon $kiedy): array
    {
        $przepis->loadMissing(['ingredients.ingredient', 'ingredients.unit', 'steps']);

        return [
            'o_tym_pliku' => [
                'serwis' => 'Kuking.pl',
                // Ten sam numer układu co pełna paczka: importer czyta oba tak samo.
                'wersja_formatu' => WersjaFormatuPaczki::AKTUALNA,
                'rodzaj_paczki' => 'jeden_przepis',
                'wygenerowano' => $kiedy->toIso8601String(),
                'format' => 'JSON, kodowanie UTF-8, daty w formacie ISO 8601',
                'co_zawiera' => 'Jeden Twój przepis w postaci tekstu: nazwę, opis, składniki z grupami i uwagami, kroki z minutnikami oraz to, skąd masz przepis. Wraz z wersją do czytania bez internetu (przepis.html).',
                'czego_nie_zawiera' => 'Zdjęć (ani głównego, ani skanu, ani przy krokach), komentarzy innych osób, danych konta (profilu, adresu e-mail, sesji, zgód), relacji, cudzych wykonań, pozostałych przepisów i zeszytów oraz wcześniejszych wersji tego przepisu.',
                'jak_wczytac' => 'Ustawienia, „Twoje dane”, „Wczytaj swoją paczkę”: wybierz ten plik ZIP, sprawdź podgląd i kliknij „Wczytaj zaznaczone”. Przepis wróci jako prywatny szkic.',
            ],
            'przepisy' => [
                PrzepisDoPaczki::pola(
                    $przepis,
                    self::PLIK_DO_CZYTANIA,
                    static fn (mixed $data): ?string => PrzepisDoPaczki::dataIso($data),
                    static fn (?string $mediaId): ?string => null,
                ),
            ],
            // Wymagane przez importer, zawsze puste: kopia nie niesie wpisów ani zeszytów.
            'wpisy' => [],
            'kolekcje' => [],
        ];
    }

    private function nazwaPliku(Recipe $przepis, Carbon $kiedy): string
    {
        $czesc = Str::limit(Str::slug((string) $przepis->title), 50, '');

        return 'kuking-przepis-'.($czesc === '' ? 'kopia' : $czesc).'-'.$kiedy->format('Y-m-d').'.zip';
    }
}
