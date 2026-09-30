<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\RozliczenieOdczytu;
use App\Domain\Import\Url\OdczytajPrzepisZAdresu;
use App\Domain\Import\Url\PublicznyAdresZrodla;
use App\Domain\Posts\KontoNieMozePublikowac;
use App\Models\ImportPrzepisu;
use App\Models\PrzepisZImportu;
use App\Moderacja\ExceptionContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import przepisu z adresu strony poza żądaniem WWW (V2, D-300, #28).
 *
 * KOLEJKA `low`, jak odczyt zdjęcia kartki (D-298): za moderacją, nie przed
 * zdjęciami i listami. Wejście — adres — czytane z wiersza zlecenia
 * (`importy_przepisow.source_url`), więc zadanie nie zależy od niczego, co
 * żyło tylko w procesie WWW. Zgoda na wysłanie tekstu strony do modelu
 * jedzie w samym zadaniu (`$zgodaAi`): to zgoda z jednego formularza, nie
 * z ustawień konta (#2031).
 *
 * JEDNA PRÓBA, ŚWIADOMIE (`$tries = 1`). Pobranie strony bywa ponawiane
 * przez człowieka (nowe wysłanie formularza, nowe miejsce w limicie), ale
 * płatne wywołanie modelu nie może pójść drugi raz dla tej samej próby:
 * rezerwacja budżetu ma klucz `(próba, 1)` i ponowienie odmówiłoby jej
 * jako powtórzonej. Zadanie zabite w środku wraca po `retry_after`, widzi
 * przekroczony limit prób i kończy się w `failed()` — tam rezerwacja jest
 * domykana (niewysłana wraca do budżetu, wysłana idzie w wydatki).
 *
 * WYNIK: prywatny szkic przez `ZapiszSzkicZImportu` (nigdy publikacja).
 * Strona, która zabrania pobierania (robots.txt) albo nie ma przepisu, daje
 * szkic z samym źródłem i zlecenie `gotowy`. Każda inna odmowa to zlecenie
 * `nieudany` z kodem odmowy — zdanie dla człowieka wylicza z niego
 * `KomunikatImportu`. Adres znika z wiersza zlecenia w każdym stanie
 * końcowym.
 */
class ImportujPrzepisZAdresu implements ShouldQueue
{
    use Queueable;

    /** Odmowy, po których człowiek dostaje szkic z samym źródłem zamiast błędu. */
    private const KODY_SZKICU_BEZ_TRESCI = [ImportOdrzucony::ROBOTS_ZABRANIA, ImportOdrzucony::BRAK_PRZEPISU];

    /** Pobranie strony do 25 s + jedno żądanie do modelu do 110 s + zapis. */
    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public string $importId, public bool $zgodaAi = false)
    {
        $this->onQueue((string) config('kuking.import.kolejka', 'low'));
    }

    public function handle(OdczytajPrzepisZAdresu $odczyt, ZapiszSzkicZImportu $zapiszSzkic): void
    {
        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null || $zlecenie->jestKoncowy()) {
            return;
        }

        $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_W_TOKU, 'rozpoczeto_at' => $zlecenie->rozpoczeto_at ?? now()])->save();

        $adres = (string) $zlecenie->source_url;
        $probaId = DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('id');

        if ($adres === '' || $probaId === null || ! $zlecenie->zAdresu()) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY);

            return;
        }

        if (! (bool) config('kuking.import.url.wlaczony')) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_WYLACZONY);

            return;
        }

        try {
            $strona = $odczyt->handle($adres, $zlecenie->user, $this->zgodaAi, (string) $probaId);
        } catch (ImportOdrzucony $e) {
            if (! in_array($e->kod, self::KODY_SZKICU_BEZ_TRESCI, true)) {
                $this->zakoncz($zlecenie, self::kodZlecenia($e->kod));

                return;
            }

            $this->zapisz($zlecenie, $zapiszSzkic, 'bez_tresci', null, PublicznyAdresZrodla::z($adres), $e->kod);

            return;
        }

        $this->zapisz($zlecenie, $zapiszSzkic, $strona->droga, $strona->przepis, $strona->url, null);
    }

    /**
     * Zadanie padło (timeout, wyjątek, przekroczony limit prób) — zlecenie
     * nie może zostać w `w_toku` na zawsze, a rezerwacja budżetu nie może
     * wisieć: niewysłana wraca do budżetu, wysłana idzie w wydatki całą
     * kwotą. Powtórzone `failed()` niczego nie liczy drugi raz.
     */
    public function failed(?Throwable $blad): void
    {
        $probaId = DB::table('proby_importu')->where('import_id', $this->importId)->value('id');

        if ($probaId !== null) {
            app(RozliczenieOdczytu::class)->zamknijPorzucone((string) $probaId);
        }

        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null || $zlecenie->jestKoncowy()) {
            return;
        }

        Log::warning('Import przepisu z adresu nie powiódł się.', [
            'import_id' => $this->importId,
            ...($blad === null ? ['stage' => 'import_adres_failed'] : ExceptionContext::forStage($blad, 'import_adres_failed')),
        ]);

        $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY);
    }

    /** Kod odmowy odczytu strony → kod zlecenia (zamknięta lista w CHECK). */
    private static function kodZlecenia(string $kod): string
    {
        return match (true) {
            in_array($kod, ImportPrzepisu::KODY_ADRESU, true) => $kod,
            $kod === ImportOdrzucony::BRAK_ZGODY_AI => ImportPrzepisu::KOD_BRAK_ZGODY,
            $kod === ImportOdrzucony::BUDZET_AI => ImportPrzepisu::KOD_BUDZET_DZIENNY,
            $kod === ImportOdrzucony::MODEL_NIEDOSTEPNY => ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY,
            default => ImportPrzepisu::KOD_BLAD_WEWNETRZNY,
        };
    }

    /** Szkic i `gotowy` w jednej transakcji — nie ma szkicu bez stanu końcowego ani odwrotnie. */
    private function zapisz(
        ImportPrzepisu $zlecenie,
        ZapiszSzkicZImportu $zapiszSzkic,
        string $droga,
        ?OdczytanyPrzepis $przepis,
        string $adresZrodla,
        ?string $kodBezTresci,
    ): void {
        try {
            DB::transaction(function () use ($zlecenie, $zapiszSzkic, $droga, $przepis, $adresZrodla): void {
                $biezace = ImportPrzepisu::query()->whereKey($zlecenie->getKey())->lockForUpdate()->first();

                // Domknięte w międzyczasie (odzyskiwanie po czasie) — stan końcowy już jest.
                if ($biezace === null || $biezace->jestKoncowy()) {
                    return;
                }

                $recipe = $zapiszSzkic->handle(
                    autor: $zlecenie->user,
                    zrodlo: PrzepisZImportu::ZRODLO_URL,
                    droga: $droga,
                    przepis: $przepis,
                    sourceUrl: $adresZrodla,
                    tytulZastepczy: 'Przepis ze strony '.(string) parse_url($adresZrodla, PHP_URL_HOST),
                );

                $zlecenie->forceFill([
                    'recipe_id' => $recipe->getKey(),
                    'status' => ImportPrzepisu::STATUS_GOTOWY,
                    'kod_bledu' => null,
                    'source_url' => null,
                    'zakonczono_at' => now(),
                ])->save();

                DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->update([
                    'status' => 'gotowy',
                    'recipe_id' => $recipe->getKey(),
                    'updated_at' => now(),
                ]);
            });
        } catch (KontoNieMozePublikowac) {
            // #2189: zawieszone konto nie dopisuje treści (dostęp tylko do odczytu).
            // Transakcja wróciła w całości; zlecenie kończy się jawnie i bez ponawiania.
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY);

            return;
        }

        Log::info('Import przepisu zakończony szkicem.', [
            'stage' => 'import_przepisu',
            'zrodlo' => 'url',
            'droga' => $droga,
            'kod' => $kodBezTresci,
        ]);
    }

    private function zakoncz(ImportPrzepisu $zlecenie, string $kod): void
    {
        $zlecenie->forceFill([
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => $kod,
            'source_url' => null,
            'zakonczono_at' => now(),
        ])->save();

        DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->update([
            'status' => 'nieudany',
            'updated_at' => now(),
        ]);
    }
}
