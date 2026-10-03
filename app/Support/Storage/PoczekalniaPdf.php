<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prywatna poczekalnia PDF-a, z którego człowiek wybiera strony przed odczytem
 * (#2535, V2, decyzja właściciela z 2.10.2026).
 *
 * BEZ BAZY DANYCH, jak `MagazynPaczek`: katalog osoby na prywatnym dysku importu,
 * w nim katalog tokenu — `<katalog_wyboru>/<id osoby>/<token>/` z plikiem
 * `plik.pdf`, miniaturami `m-N.jpg` i małym opisem `meta.json` (stan, liczba
 * stron, fragmenty tekstu stron). Token z adresu nie jest autoryzacją: ścieżkę
 * składamy z identyfikatora ZALOGOWANEJ osoby, więc cudzy token niczego nie
 * wskaże, a zmiana pliku (nowe wysłanie) to nowy token, więc dawny podgląd i
 * dawny wybór przestają obowiązywać.
 *
 * RETENCJA (D-300, ADR retencji): zatwierdzenie, odrzucenie, usunięcie konta i
 * najpóźniej `kuking.import.pdf.wybor_stron_godziny` od zapisu pliku
 * (`kuking:odzyskaj-importy`). Najwyżej `wybor_stron_max_oczekujacych` pozycji
 * naraz na osobę — najstarsza ustępuje nowej, więc nie ma nieograniczonego
 * archiwum. Miniatury nigdy nie mają publicznego adresu: serwuje je kontroler
 * po sprawdzeniu właściciela, z `Cache-Control: private, no-store`.
 *
 * Przygotowanie podglądu (Poppler) idzie w zadaniu `PrzygotujPodgladPdf`:
 * proces WWW nie ma `proc_open` (#2293).
 */
final class PoczekalniaPdf
{
    public const STAN_PRZYGOTOWANIE = 'przygotowanie';

    public const STAN_GOTOWY = 'gotowy';

    public const STAN_BLAD = 'blad';

    public function __construct(private readonly PlikTymczasowyImportu $pliki) {}

    private function dysk(): Filesystem
    {
        return $this->pliki->dysk();
    }

    private function katalogBazowy(): string
    {
        return trim((string) config('kuking.import.pdf.katalog_wyboru', 'import-pdf-wybor'), '/');
    }

    /** Katalog tokenu tej osoby albo `null` dla niepoprawnego tokenu. */
    private function katalog(string $osobaId, string $token): ?string
    {
        if (! Str::isUuid($token) || ! Str::isUuid($osobaId)) {
            return null;
        }

        return $this->katalogBazowy().'/'.strtolower($osobaId).'/'.strtolower($token);
    }

    /**
     * Przyjmuje plik z żądania: kontrole wstępne (rozmiar, sygnatura) rzuca
     * `TekstZPdf::sprawdzWstepnie()` u wołającego. Zwraca token nowej pozycji.
     *
     * @return string|null token albo `null`, gdy dysk odmówił zapisu
     */
    public function przyjmij(User $osoba, string $sciezkaLokalna): ?string
    {
        $this->zrobMiejsce($osoba);

        $token = (string) Str::uuid7();
        $katalog = $this->katalog((string) $osoba->getKey(), $token);

        $strumien = @fopen($sciezkaLokalna, 'rb');

        if ($strumien === false || $katalog === null) {
            return null;
        }

        try {
            $zapisano = $this->dysk()->put($katalog.'/plik.pdf', $strumien);
        } catch (Throwable) {
            $zapisano = false;
        } finally {
            if (is_resource($strumien)) {
                fclose($strumien);
            }
        }

        if (! $zapisano) {
            $this->zapomnij($osoba, $token);

            return null;
        }

        $this->zapiszOpis((string) $osoba->getKey(), $token, ['stan' => self::STAN_PRZYGOTOWANIE]);

        return $token;
    }

    /**
     * Opis pozycji tej osoby albo `null`, gdy jej nie ma (zły token, cudzy,
     * przeterminowana). Przeterminowana jest tu od razu kasowana.
     *
     * @return array{stan: string, strony?: int, fragmenty?: list<string>, komunikat?: string}|null
     */
    public function opis(User $osoba, string $token): ?array
    {
        $katalog = $this->katalog((string) $osoba->getKey(), $token);

        if ($katalog === null) {
            return null;
        }

        try {
            if (! $this->dysk()->exists($katalog.'/plik.pdf')) {
                return null;
            }

            if ($this->przeterminowany($katalog.'/plik.pdf')) {
                $this->dysk()->deleteDirectory($katalog);

                return null;
            }

            $json = $this->dysk()->get($katalog.'/meta.json');
        } catch (Throwable) {
            return null;
        }

        if (! is_string($json)) {
            return ['stan' => self::STAN_PRZYGOTOWANIE];
        }

        $opis = json_decode($json, true);

        return is_array($opis) && isset($opis['stan']) && is_string($opis['stan']) ? $opis : ['stan' => self::STAN_PRZYGOTOWANIE];
    }

    /** @param array<string, mixed> $opis */
    public function zapiszOpis(string $osobaId, string $token, array $opis): void
    {
        $katalog = $this->katalog($osobaId, $token);

        if ($katalog === null) {
            return;
        }

        $this->dysk()->put($katalog.'/meta.json', json_encode($opis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function zapiszMiniature(string $osobaId, string $token, int $strona, string $jpeg): void
    {
        $katalog = $this->katalog($osobaId, $token);

        if ($katalog !== null && $strona >= 1) {
            $this->dysk()->put($katalog.'/m-'.$strona.'.jpg', $jpeg);
        }
    }

    /** Zawartość miniatury strony tej osoby albo `null`. */
    public function miniatura(User $osoba, string $token, int $strona): ?string
    {
        if ($strona < 1 || $this->opis($osoba, $token) === null) {
            return null;
        }

        $katalog = $this->katalog((string) $osoba->getKey(), $token);

        try {
            $tresc = $katalog === null ? null : $this->dysk()->get($katalog.'/m-'.$strona.'.jpg');
        } catch (Throwable) {
            return null;
        }

        return is_string($tresc) && $tresc !== '' ? $tresc : null;
    }

    /**
     * Kopia robocza PDF-a w katalogu tymczasowym systemu (Poppler czyta ścieżkę).
     * Wołający kasuje ją przez `PlikTymczasowyImportu::usunLokalna()`.
     */
    public function plikLokalny(string $osobaId, string $token): ?string
    {
        $katalog = $this->katalog($osobaId, $token);

        if ($katalog === null) {
            return null;
        }

        try {
            $zrodlo = $this->dysk()->readStream($katalog.'/plik.pdf');
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($zrodlo)) {
            return null;
        }

        $lokalna = tempnam(sys_get_temp_dir(), 'kuking-pdf-');

        if ($lokalna === false) {
            fclose($zrodlo);

            return null;
        }

        $cel = fopen($lokalna, 'wb');

        if ($cel === false || stream_copy_to_stream($zrodlo, $cel) === false) {
            fclose($zrodlo);
            if (is_resource($cel)) {
                fclose($cel);
            }
            @unlink($lokalna);

            return null;
        }

        fclose($zrodlo);
        fclose($cel);
        @chmod($lokalna, 0600);

        return $lokalna;
    }

    /** Kasuje pozycję (plik, miniatury, opis) — po zatwierdzeniu, odrzuceniu albo błędzie. */
    public function zapomnij(User $osoba, string $token): void
    {
        $katalog = $this->katalog((string) $osoba->getKey(), $token);

        if ($katalog !== null) {
            try {
                $this->dysk()->deleteDirectory($katalog);
            } catch (Throwable) {
                // Zostanie po nią nocne sprzątanie po retencji.
            }
        }
    }

    /** Wymazanie konta: nic z poczekalni tej osoby nie zostaje. */
    public function zapomnijWszystkie(User $osoba): void
    {
        if (! Str::isUuid((string) $osoba->getKey())) {
            return;
        }

        try {
            $this->dysk()->deleteDirectory($this->katalogBazowy().'/'.strtolower((string) $osoba->getKey()));
        } catch (Throwable) {
            // Najpóźniej po retencji zabierze je nocne sprzątanie.
        }
    }

    /**
     * Kasuje pozycje wszystkich osób starsze niż `wybor_stron_godziny`.
     *
     * @return int liczba skasowanych pozycji
     */
    public function sprzatnijPrzeterminowane(): int
    {
        $skasowane = 0;

        try {
            foreach ($this->dysk()->directories($this->katalogBazowy()) as $katalogOsoby) {
                foreach ($this->dysk()->directories($katalogOsoby) as $katalogTokenu) {
                    $plik = $katalogTokenu.'/plik.pdf';

                    if (! $this->dysk()->exists($plik) || $this->przeterminowany($plik)) {
                        $this->dysk()->deleteDirectory($katalogTokenu);
                        $skasowane++;
                    }
                }

                if ($this->dysk()->allFiles($katalogOsoby) === []) {
                    $this->dysk()->deleteDirectory($katalogOsoby);
                }
            }
        } catch (Throwable) {
            return $skasowane;
        }

        return $skasowane;
    }

    /** Tokeny oczekujących pozycji osoby, od najstarszej. */
    private function zrobMiejsce(User $osoba): void
    {
        $maks = max(1, (int) config('kuking.import.pdf.wybor_stron_max_oczekujacych', 3));
        $katalogOsoby = $this->katalogBazowy().'/'.strtolower((string) $osoba->getKey());

        try {
            $pozycje = [];

            foreach ($this->dysk()->directories($katalogOsoby) as $katalogTokenu) {
                $plik = $katalogTokenu.'/plik.pdf';
                $pozycje[$katalogTokenu] = $this->dysk()->exists($plik) ? $this->dysk()->lastModified($plik) : 0;
            }

            asort($pozycje);

            while (count($pozycje) >= $maks) {
                $najstarsza = array_key_first($pozycje);
                $this->dysk()->deleteDirectory($najstarsza);
                unset($pozycje[$najstarsza]);
            }
        } catch (Throwable) {
            // Brak miejsca w poczekalni nie może zablokować wysłania pliku.
        }
    }

    private function przeterminowany(string $plik): bool
    {
        $godziny = max(1, (int) config('kuking.import.pdf.wybor_stron_godziny', 2));

        return $this->dysk()->lastModified($plik) < now()->subHours($godziny)->getTimestamp();
    }
}
