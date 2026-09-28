<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\User;
use App\Moderacja\ModelChwilowoNiedostepny;
use Illuminate\Support\Facades\DB;

/** Jedno płatne wywołanie dla URL/PDF pod tą samą księgą budżetu co OCR kartki. */
final class PlatnyOdczytImportu
{
    public function __construct(
        private readonly BudzetAi $budzet,
        private readonly KlientLuna $klient,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tresc
     * @param  array<string, mixed>  $schemat
     * @return array<string, mixed>
     */
    public function odczytaj(
        User $osoba,
        bool $chceZgody,
        string $probaId,
        string $zadanie,
        string $instrukcja,
        array $tresc,
        string $nazwaSchematu,
        array $schemat,
    ): array {
        // Zgoda na zdjęcie kartki NIE obejmuje tekstu cudzej strony ani skanu
        // PDF. Każdy płatny import ma własną, jawną zgodę z formularza.
        if (! $chceZgody) {
            throw new ImportOdrzucony(ImportOdrzucony::BRAK_ZGODY_AI);
        }
        if (! KlientLuna::skonfigurowany($zadanie)) {
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }

        $szacunek = BudzetAi::szacunek($zadanie);
        if ($szacunek === null) {
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }
        // Okno kontekstu obejmuje cały request, także wszystkie strony skanu.
        $rezerwacja = $this->budzet->zarezerwuj($szacunek, $probaId, 1);
        if (! $rezerwacja instanceof Rezerwacja) {
            throw new ImportOdrzucony($rezerwacja === BudzetAi::ODMOWA_POWTORZONA
                ? ImportOdrzucony::MODEL_NIEDOSTEPNY : ImportOdrzucony::BUDZET_AI);
        }

        // Dowód zgody na to jedno wysłanie pozostaje przy próbie (retencja
        // księgi), bez rozszerzania dawnej zgody na zdjęcia kartek.
        if (DB::table('proby_importu')->where('id', $probaId)->where('user_id', $osoba->getKey())
            ->update(['zgoda_ai_at' => now(), 'updated_at' => now()]) !== 1) {
            $this->budzet->zwolnij($rezerwacja);
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }
        if (! $this->budzet->oznaczWyslana($rezerwacja)) {
            $this->budzet->zwolnij($rezerwacja);
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }

        try {
            $odpowiedz = $this->klient->odczytaj($zadanie, $instrukcja, $tresc, $nazwaSchematu, $schemat);
        } catch (ModelChwilowoNiedostepny) {
            // Żądanie mogło dotrzeć do dostawcy: cała rezerwacja jest wydatkiem.
            $this->budzet->rozlicz($rezerwacja, null);
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }

        if ($odpowiedz === null) {
            $this->budzet->zwolnij($rezerwacja);
            throw new ImportOdrzucony(ImportOdrzucony::MODEL_NIEDOSTEPNY);
        }

        $cennik = Cennik::zKonfiguracji();
        $koszt = $odpowiedz->maUsage() && $cennik !== null
            ? $cennik->koszt((int) $odpowiedz->tokenyWejscia, (int) $odpowiedz->tokenyWyjscia)
            : null;
        $this->budzet->rozlicz($rezerwacja, $koszt);

        if ($odpowiedz->dane === null) {
            throw new ImportOdrzucony(ImportOdrzucony::BRAK_PRZEPISU);
        }

        return $odpowiedz->dane;
    }
}
