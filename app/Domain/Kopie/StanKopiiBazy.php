<?php

declare(strict_types=1);

namespace App\Domain\Kopie;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Czy kopia bazy poza Railwayem NADAL POWSTAJE (issue #193, decyzja D-043).
 *
 * PO CO TO ISTNIEJE, SKORO KOPIĘ ROBI OSOBNY SERWIS
 * Tamten serwis (`docker/kopia/kopia-bazy.sh`) alarmuje, gdy jego przebieg
 * się nie udał. Nie zaalarmuje, gdy przebiegu NIE BYŁO — a to jest zupełnie
 * inny i groźniejszy stan: skasowany serwis, wyłączony harmonogram, wyczerpany
 * limit konta, zmieniona nazwa bucketu, wygasły token. Kod, który wtedy nie
 * chodzi, nie może o sobie donieść.
 *
 * #193 nazywa to najgorszym możliwym wynikiem tej pracy: „backup, który po
 * cichu przestał się robić — myślisz, że masz kopię". Ta klasa patrzy na to
 * z drugiej strony: nie sprawdza, czy przebieg się udał, tylko czy w buckecie
 * leży coś świeżego.
 *
 * DLACZEGO WIEK LICZYMY Z NAZWY PLIKU, A NIE Z `lastModified()`
 * Nazwa (`kuking-YYYYMMDD-HHMMSSZ.dump.cms`) mówi, KIEDY ZROBIONO ZRZUT.
 * `lastModified()` mówi, kiedy obiekt trafił do bucketu — a interesuje nas
 * wiek DANYCH, nie wiek pliku. Przy ponownej wysyłce starej kopii (albo
 * kopiowaniu bucketu) te dwie liczby się rozjeżdżają, i to `lastModified()`
 * kłamie na naszą korzyść, czyli w najgorszym możliwym kierunku.
 *
 * DLACZEGO TO NIE ZAGLĄDA DO ŚRODKA PLIKU
 * Bo nie może i nie powinno. Zrzut jest zaszyfrowany kluczem publicznym,
 * którego pary nie ma w żadnym środowisku uruchomieniowym — aplikacja
 * fizycznie nie potrafi go odczytać. Poprawność zawartości potwierdza
 * człowiek, ćwiczeniem odtworzenia (`docs/infra/KOPIE_I_ODTWORZENIE.md` §4),
 * a nie ta klasa.
 */
final class StanKopiiBazy
{
    /** Bucket nieskonfigurowany — czujka wyłączona, to NIE jest awaria. */
    public const WYLACZONA = 'wylaczona';

    /** Bucket odpowiada, ale nie ma w nim ani jednej kopii. */
    public const BRAK_KOPII = 'brak-kopii';

    /** Najnowsza kopia jest starsza niż próg. */
    public const PRZESTARZALA = 'przestarzala';

    /** Nie udało się nawet zapytać bucketu (token, sieć, zła nazwa). */
    public const NIEDOSTEPNY = 'niedostepny';

    /** Kopia jest i jest świeża. */
    public const AKTUALNA = 'aktualna';

    /**
     * @return array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int}
     */
    public function sprawdz(?Carbon $teraz = null): array
    {
        $teraz ??= Carbon::now('UTC');
        $prog = max(1, (int) config('kuking.kopie.maks_wiek_godzin'));

        $nazwaDysku = (string) config('kuking.kopie.dysk');
        $prefiks = (string) config('kuking.kopie.prefiks');

        // BRAK KONFIGURACJI = ZERO EFEKTU, ale POWIEDZIANE WPROST.
        // Ta sama umowa, co przy `LOG_BLAD_WEBHOOK_URL` w `bootstrap/app.php`:
        // pusta zmienna wyłącza mechanizm. Różnica jest w tym, że tutaj cisza
        // z powodu wyłączenia wygląda identycznie jak cisza z powodu „wszystko
        // w porządku" — więc stan „wyłączona" musi być osobną, nazwaną
        // wartością, a nie brakiem alarmu.
        if (blank(config("filesystems.disks.{$nazwaDysku}.bucket"))) {
            return ['stan' => self::WYLACZONA, 'wiek_godzin' => null, 'liczba' => 0, 'prog_godzin' => $prog];
        }

        try {
            $pliki = Storage::disk($nazwaDysku)->files($prefiks);
        } catch (Throwable) {
            // Komunikat wyjątku CELOWO nie wychodzi z tej metody. Poszedłby
            // dalej do alarmu na webhook, a tam potrafiłby wnieść nazwę
            // bucketu i fragment poświadczenia (audyt A6-01). Wołającemu
            // wystarczy, że bucket nie odpowiada.
            return ['stan' => self::NIEDOSTEPNY, 'wiek_godzin' => null, 'liczba' => 0, 'prog_godzin' => $prog];
        }

        $kopie = array_values(array_filter(
            $pliki,
            static fn (string $plik): bool => str_ends_with($plik, '.dump.cms'),
        ));

        if ($kopie === []) {
            return ['stan' => self::BRAK_KOPII, 'wiek_godzin' => null, 'liczba' => 0, 'prog_godzin' => $prog];
        }

        // Znacznik w nazwie jest sortowalny leksykograficznie, więc sortowanie
        // po nazwie to sortowanie po dacie — bez ani jednego zapytania więcej.
        sort($kopie);
        $najnowsza = (string) end($kopie);

        $czas = $this->czasZNazwy($najnowsza);

        if ($czas === null) {
            // Plik o nazwie, której nie rozumiemy, nie jest dowodem świeżości.
            // Traktujemy to jak brak kopii, a nie jak kopię — pomyłka w tę
            // stronę kosztuje jeden zbędny alarm, w drugą: fałszywy spokój.
            return ['stan' => self::BRAK_KOPII, 'wiek_godzin' => null, 'liczba' => count($kopie), 'prog_godzin' => $prog];
        }

        $wiek = (int) $czas->diffInHours($teraz, absolute: true);

        return [
            'stan' => $wiek > $prog ? self::PRZESTARZALA : self::AKTUALNA,
            'wiek_godzin' => $wiek,
            'liczba' => count($kopie),
            'prog_godzin' => $prog,
        ];
    }

    private function czasZNazwy(string $klucz): ?Carbon
    {
        if (preg_match('/kuking-(\d{8})-(\d{6})Z\.dump\.cms$/', $klucz, $trafienia) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('YmdHis', $trafienia[1].$trafienia[2], 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
