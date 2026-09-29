<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportowOsoby;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ImportujPrzepisZAdresu;
use App\Models\ImportPrzepisu;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zlecenie „zaimportuj przepis z tego adresu” (V2, D-300, #28).
 *
 * Wysłanie formularza NIE pobiera strony i NIE woła modelu — tylko zapisuje
 * trwałe zlecenie i zadanie w kolejce, a człowiek od razu widzi ekran
 * postępu. Pobranie strony (robots.txt, przekierowania, DNS), Poppler-owe
 * i modelowe kroki idą w zadaniu `ImportujPrzepisZAdresu`, więc jedna wolna
 * strona nie zajmuje procesu WWW, a zerwane połączenie po wysłaniu niczego
 * nie przerywa.
 *
 * W JEDNEJ TRANSAKCJI, pod blokadą osoby (jak `ZlecImportPrzepisu`):
 *
 *   1. powtórzone wysłanie tego samego formularza (`klucz_wyslania`) oddaje
 *      istniejące zlecenie — bez drugiego zadania i bez drugiego miejsca
 *      w limicie;
 *   2. miejsce we wspólnym limicie 5 dziennie / 30 miesięcznie
 *      (`proby_importu`) — po jego wyczerpaniu zlecenie w ogóle nie
 *      powstaje, człowiek dostaje zdanie przy polu adresu;
 *   3. wiersz zlecenia z adresem (`importy_przepisow.source_url`) — stąd
 *      zadanie odtwarza wejście po restarcie workera; adres znika z wiersza,
 *      gdy zlecenie się kończy;
 *   4. zadanie w kolejce — ten sam outbox co przy odczycie zdjęcia (#1977):
 *      kolejka bazodanowa na tym samym połączeniu, bez `after_commit`.
 *
 * Zgoda na wysłanie tekstu strony do modelu jedzie w zadaniu jako jawna flaga
 * z tego jednego formularza — zgoda na zdjęcia kartek jej nie zastępuje (#2031).
 */
final class ZlecImportZAdresu
{
    public function __construct(private readonly LimitImportowOsoby $limit) {}

    /**
     * @throws ImportOdrzucony limit osoby
     * @throws BladDlaCzlowieka próba z tym kluczem już istnieje, ale nie jest zleceniem z adresu
     */
    public function handle(User $osoba, string $adres, bool $zgodaAi, ?string $kluczWyslania = null): ImportPrzepisu
    {
        $klucz = $kluczWyslania !== null && Str::isUuid($kluczWyslania) ? $kluczWyslania : (string) Str::uuid7();

        try {
            return DB::transaction(function () use ($osoba, $adres, $zgodaAi, $klucz): ImportPrzepisu {
                $this->limit->zablokuj($osoba);

                if ($juz = $this->zTegoWyslania($osoba, $klucz)) {
                    return $juz;
                }

                $proba = $this->limit->rezerwuj($osoba, ImportPrzepisu::ZRODLO_URL, $klucz);

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
                    'zrodlo' => ImportPrzepisu::ZRODLO_URL,
                    'status' => ImportPrzepisu::STATUS_OCZEKUJE,
                    'source_url' => trim($adres),
                    'klucz_wyslania' => $klucz,
                ])->save();

                DB::table('proby_importu')->where('id', $proba['id'])->update(['import_id' => $zlecenie->getKey()]);

                ImportujPrzepisZAdresu::dispatch((string) $zlecenie->getKey(), $zgodaAi);

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
