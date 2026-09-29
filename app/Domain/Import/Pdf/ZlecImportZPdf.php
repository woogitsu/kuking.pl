<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportowOsoby;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ImportujPrzepisZPdf;
use App\Models\ImportPrzepisu;
use App\Models\User;
use App\Support\Storage\PlikTymczasowyImportu;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zlecenie „zaimportuj przepis z tego pliku PDF” (V2, D-300, #28 etap 2).
 *
 * Wysłanie formularza NIE otwiera PDF-a Popplerem i NIE woła modelu — po
 * szybkich kontrolach (rozmiar, sygnatura) zapisuje plik na prywatny dysk
 * importu, trwałe zlecenie i zadanie w kolejce; człowiek od razu widzi ekran
 * postępu. `pdfinfo`, `pdftotext`, `pdftoppm` i ewentualny model idą
 * w zadaniu `ImportujPrzepisZPdf`.
 *
 *   0. plik ląduje na dysku PRZED transakcją (zapis do zdalnego bucketu nie
 *      trzyma blokady osoby). Gdy transakcja się nie uda albo wygra
 *      powtórzone wysłanie, ten plik jest kasowany od razu;
 *   W JEDNEJ TRANSAKCJI, pod blokadą osoby (jak `ZlecImportZAdresu`):
 *   1. powtórzone wysłanie tego samego formularza (`klucz_wyslania`) oddaje
 *      istniejące zlecenie — bez drugiego zadania i drugiego miejsca w limicie;
 *   2. miejsce we wspólnym limicie 5 dziennie / 30 miesięcznie;
 *   3. wiersz zlecenia ze ścieżką pliku (`plik_tymczasowy`) — stąd zadanie
 *      odtwarza wejście po restarcie workera;
 *   4. zadanie w kolejce (outbox: ta sama baza, bez `after_commit`).
 *
 * Zgoda na wysłanie skanu do modelu jedzie w zadaniu jako jawna flaga z tego
 * jednego formularza — zgoda na zdjęcia kartek jej nie zastępuje (#2031).
 * PDF z warstwą tekstu nie opuszcza serwisu w ogóle.
 */
final class ZlecImportZPdf
{
    public function __construct(
        private readonly LimitImportowOsoby $limit,
        private readonly TekstZPdf $tekst,
        private readonly PlikTymczasowyImportu $pliki,
    ) {}

    /**
     * @throws ImportOdrzucony plik nie jest PDF-em w limicie rozmiaru albo limit osoby
     * @throws BladDlaCzlowieka próba z tym kluczem już istnieje, ale nie jest zleceniem z PDF-a; dysk odmówił zapisu
     */
    public function handle(User $osoba, string $sciezkaPliku, bool $zgodaAi, ?string $kluczWyslania = null): ImportPrzepisu
    {
        $klucz = $kluczWyslania !== null && Str::isUuid($kluczWyslania) ? $kluczWyslania : (string) Str::uuid7();

        // Powtórzone wysłanie tego formularza: nic nie zapisujemy drugi raz.
        if ($juz = $this->zTegoWyslania($osoba, $klucz)) {
            return $juz;
        }

        $this->tekst->sprawdzWstepnie($sciezkaPliku);

        $plik = $this->pliki->zapisz($sciezkaPliku);

        if ($plik === null) {
            throw new BladDlaCzlowieka('Nie udało się przyjąć pliku. Wybierz go jeszcze raz za chwilę albo wpisz przepis ręcznie.');
        }

        try {
            $zlecenie = $this->zapiszZlecenie($osoba, $plik, $zgodaAi, $klucz);
        } catch (Throwable $e) {
            $this->pliki->skasuj($plik);

            throw $e;
        }

        if ($zlecenie->plik_tymczasowy !== $plik) {
            // Wygrało inne wysłanie tego samego formularza — nasz plik jest zbędny.
            $this->pliki->skasuj($plik);
        }

        return $zlecenie;
    }

    private function zapiszZlecenie(User $osoba, string $plik, bool $zgodaAi, string $klucz): ImportPrzepisu
    {
        try {
            return DB::transaction(function () use ($osoba, $plik, $zgodaAi, $klucz): ImportPrzepisu {
                $this->limit->zablokuj($osoba);

                if ($juz = $this->zTegoWyslania($osoba, $klucz)) {
                    return $juz;
                }

                $proba = $this->limit->rezerwuj($osoba, ImportPrzepisu::ZRODLO_PDF, $klucz);

                if ($proba === null) {
                    $miesiac = $this->limit->przekroczony($osoba) === LimitImportowOsoby::MIESIAC;

                    throw new ImportOdrzucony(
                        $miesiac ? ImportOdrzucony::LIMIT_OSOBY_MIESIAC : ImportOdrzucony::LIMIT_OSOBY,
                        ['limit' => (int) config($miesiac ? 'kuking.import.limity.na_osobe_miesiac' : 'kuking.import.limity.na_osobe_dzien')],
                    );
                }

                if ($proba['istnieje']) {
                    // Próba z tym kluczem jest, a zlecenia nie ma — dawne,
                    // synchroniczne wysłanie albo próba innego źródła.
                    throw new BladDlaCzlowieka('Ta próba importu została już przyjęta. Otwórz formularz ponownie, jeśli chcesz rozpocząć nową próbę.');
                }

                $zlecenie = new ImportPrzepisu;
                $zlecenie->forceFill([
                    'user_id' => $osoba->getKey(),
                    'zrodlo' => ImportPrzepisu::ZRODLO_PDF,
                    'status' => ImportPrzepisu::STATUS_OCZEKUJE,
                    'plik_tymczasowy' => $plik,
                    'klucz_wyslania' => $klucz,
                ])->save();

                DB::table('proby_importu')->where('id', $proba['id'])->update(['import_id' => $zlecenie->getKey()]);

                ImportujPrzepisZPdf::dispatch((string) $zlecenie->getKey(), $zgodaAi);

                return $zlecenie;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dwa równoległe wysłania tego samego formularza — drugie przegrało
            // z unikalnym `(user_id, klucz_wyslania)`.
            $juz = $this->zTegoWyslania($osoba, $klucz);

            if ($juz === null) {
                throw $e;
            }

            return $juz;
        }
    }

    private function zTegoWyslania(User $osoba, string $klucz): ?ImportPrzepisu
    {
        return ImportPrzepisu::query()
            ->where('user_id', $osoba->getKey())
            ->where('klucz_wyslania', $klucz)
            ->first();
    }
}
