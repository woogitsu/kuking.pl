<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\OdczytajPrzepis;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Zlecenie „przepisz mi tę kartkę” (V2, D-298).
 *
 * KOLEJNOŚĆ JEST CAŁĄ OBIETNICĄ „ZDJĘCIE JEST ZAPISANE”:
 *
 *   1. zdjęcie przez zwykły potok (`StoreUploadedImage` — magic bytes,
 *      megapiksele, EXIF zdejmowany w tle),
 *   2. prywatny SZKIC z tym zdjęciem jako `source_scan_media_id`
 *      (`PublishRecipe`, `publish: false`, `visibility: private`),
 *   3. dopiero potem zlecenie — a przy limicie osoby albo wyczerpanym
 *      budżecie zlecenie od razu stoi jako `wstrzymany_limitem`.
 *
 * Cokolwiek się stanie z modelem, człowiek ma szkic ze zdjęciem kartki
 * i może wpisać przepis ręcznie. Import NIGDY nie publikuje — ta klasa woła
 * `PublishRecipe` wyłącznie z `publish: false` (test architektoniczny).
 */
final class ZlecImportPrzepisu
{
    public function __construct(
        private readonly StoreUploadedImage $zdjecia,
        private readonly PublishRecipe $przepisy,
        private readonly LimitImportowOsoby $limit,
        private readonly BudzetAi $budzet,
        private readonly PrzestawZgodeNaOdczytAi $zgoda,
    ) {}

    /** Czy przycisk „Przepisz z kartki” w ogóle istnieje (D-053: bez martwych przycisków). */
    public static function dostepnyOdczytZdjecia(): bool
    {
        return (bool) config('kuking.import.zrodla.zdjecie')
            && KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR);
    }

    /**
     * @throws BladDlaCzlowieka
     */
    public function handle(User $osoba, UploadedFile $zdjecie, ?string $kluczWyslania = null): ImportPrzepisu
    {
        $this->sprawdzWejscie($osoba);

        if ($kluczWyslania !== null) {
            $juz = $this->zTegoWyslania($osoba, $kluczWyslania);

            if ($juz !== null) {
                return $juz;
            }
        }

        $media = $this->zdjecia->handle($osoba, $zdjecie);

        $szkic = $this->przepisy->handle(
            author: $osoba,
            attributes: [
                'title' => 'Przepis z kartki — '.Czas::data(now()),
                'visibility' => 'private',
                'source_type' => Recipe::SOURCE_OWN,
                'source_scan_media_id' => $media->getKey(),
            ],
            publish: false,
            kluczWyslania: $kluczWyslania,
        );

        return $this->zapiszZlecenie($osoba, $szkic, $kluczWyslania);
    }

    /**
     * „Spróbuj jeszcze raz” / „Odczytaj jeszcze raz” — NOWE zlecenie dla tego
     * samego szkicu. Liczy się do limitu osoby.
     *
     * @throws BladDlaCzlowieka
     */
    public function ponow(User $osoba, ImportPrzepisu $poprzednie): ImportPrzepisu
    {
        $this->sprawdzWejscie($osoba);

        $szkic = $poprzednie->recipe;

        if (! $poprzednie->moznaPonowic() || $szkic === null || $szkic->status !== Recipe::STATUS_DRAFT) {
            throw new BladDlaCzlowieka('Tego odczytu nie da się już powtórzyć. Otwórz szkic i wpisz przepis ręcznie — zdjęcie kartki jest przy nim.');
        }

        return $this->zapiszZlecenie($osoba, $szkic, null);
    }

    private function sprawdzWejscie(User $osoba): void
    {
        if (! self::dostepnyOdczytZdjecia()) {
            throw new BladDlaCzlowieka('Odczytywanie przepisów ze zdjęć jest teraz wyłączone. Możesz wpisać przepis ręcznie i dodać do niego zdjęcie kartki.');
        }

        if (! $this->zgoda->udzielona($osoba)) {
            throw new BladDlaCzlowieka('Bez zgody na odczyt przez OpenAI możesz nadal dodać zdjęcie kartki do przepisu — tekst wpiszesz wtedy ręcznie.');
        }
    }

    private function zapiszZlecenie(User $osoba, Recipe $szkic, ?string $kluczWyslania): ImportPrzepisu
    {
        try {
            $zlecenie = DB::transaction(function () use ($osoba, $szkic, $kluczWyslania): ImportPrzepisu {
                $this->limit->zablokuj($osoba);

                [$status, $kod] = $this->stanStartowy($osoba);

                $zlecenie = new ImportPrzepisu;
                $zlecenie->forceFill([
                    'user_id' => $osoba->getKey(),
                    'recipe_id' => $szkic->getKey(),
                    'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
                    'status' => $status,
                    'kod_bledu' => $kod,
                    'klucz_wyslania' => $kluczWyslania,
                    'zakonczono_at' => $kod === null ? null : now(),
                ])->save();

                // ZLECENIE I ZADANIE W JEDNEJ TRANSAKCJI (#1977). Kolejka jest
                // bazodanowa, na tym samym połączeniu, z `after_commit => false`
                // (`config/queue.php`), więc INSERT do `jobs` zatwierdza się
                // razem z wierszem zlecenia albo cofa razem z nim — ten sam
                // outbox co `ZamowEksportDanych` i `StoreUploadedImage`.
                // `afterCommit()` NIE wystarczy: przenosi wysyłkę za commit,
                // czyli zostawia to samo okno. Zadanie zgubione inną drogą
                // (wyczyszczone `jobs`) domyka `kuking:odzyskaj-importy`.
                if ($status === ImportPrzepisu::STATUS_OCZEKUJE) {
                    OdczytajPrzepis::dispatch((string) $zlecenie->getKey());
                }

                return $zlecenie;
            });
        } catch (UniqueConstraintViolationException $e) {
            $juz = $kluczWyslania === null ? null : $this->zTegoWyslania($osoba, $kluczWyslania);

            if ($juz === null) {
                throw $e;
            }

            return $juz;
        }

        return $zlecenie;
    }

    /** @return array{0: string, 1: ?string} */
    private function stanStartowy(User $osoba): array
    {
        if ($this->limit->przekroczony($osoba) !== null) {
            return [ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM, ImportPrzepisu::KOD_LIMIT_OSOBY];
        }

        $szacunek = BudzetAi::szacunek(KlientLuna::ZADANIE_OCR) ?? 0;
        $brak = $this->budzet->brakMiejscaNa($szacunek);

        if ($brak !== null) {
            return [
                ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM,
                $brak === BudzetAi::ODMOWA_DZIEN ? ImportPrzepisu::KOD_BUDZET_DZIENNY : ImportPrzepisu::KOD_BUDZET_MIESIECZNY,
            ];
        }

        return [ImportPrzepisu::STATUS_OCZEKUJE, null];
    }

    private function zTegoWyslania(User $osoba, string $klucz): ?ImportPrzepisu
    {
        return ImportPrzepisu::query()
            ->where('user_id', $osoba->getKey())
            ->where('klucz_wyslania', $klucz)
            ->first();
    }
}
