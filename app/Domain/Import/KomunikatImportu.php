<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\ImportPrzepisu;

/**
 * Co człowiek czyta na ekranie postępu — po polsku, z tym, CO ZROBIĆ
 * (AGENTS.md §5, projekt §3.4). Każdy komunikat o niepowodzeniu mówi, że
 * zdjęcie jest zapisane: to jest prawda, bo szkic ze zdjęciem powstaje
 * przed zleceniem (D-298).
 */
final class KomunikatImportu
{
    /** @return array{tytul: string, tresc: string} */
    public static function dla(ImportPrzepisu $import): array
    {
        $naDzien = (int) config('kuking.import.limity.na_osobe_dzien');
        $naMiesiac = (int) config('kuking.import.limity.na_osobe_miesiac');

        return match ($import->status) {
            ImportPrzepisu::STATUS_OCZEKUJE, ImportPrzepisu::STATUS_W_TOKU => $import->proby > 1
                ? ['tytul' => 'To trwa dłużej niż zwykle', 'tresc' => 'Nie musisz czekać. Możesz zamknąć tę stronę — szkic znajdziesz w „Moich szkicach”, a zdjęcie kartki jest już przy nim zapisane.']
                : ['tytul' => 'Odczytujemy pismo', 'tresc' => 'Zwykle trwa to do minuty. Nie musisz czekać — możesz zamknąć tę stronę, szkic znajdziesz w „Moich szkicach”.'],
            ImportPrzepisu::STATUS_GOTOWY => ['tytul' => 'Szkic gotowy do sprawdzenia', 'tresc' => 'Ten tekst odczytał komputer. Porównaj każdą linijkę ze zdjęciem i popraw, co trzeba. Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.'],
            default => self::niepowodzenie((string) $import->kod_bledu, $naDzien, $naMiesiac),
        };
    }

    /** @return array{tytul: string, tresc: string} */
    private static function niepowodzenie(string $kod, int $naDzien, int $naMiesiac): array
    {
        return match ($kod) {
            ImportPrzepisu::KOD_LIMIT_OSOBY => ['tytul' => 'To już limit odczytów', 'tresc' => "Można odczytać {$naDzien} przepisów dziennie i {$naMiesiac} w miesiącu. Twoje zdjęcie jest zapisane w szkicu. Jutro rano będzie można dalej — albo wpisz przepis ręcznie już teraz."],
            ImportPrzepisu::KOD_BUDZET_DZIENNY => ['tytul' => 'Odczytywanie jest na dziś wstrzymane', 'tresc' => 'Wyczerpał się dzienny limit odczytów w serwisie. Twoje zdjęcie jest zapisane. Możesz wpisać przepis ręcznie już teraz albo wrócić jutro i kliknąć „Spróbuj jeszcze raz”.'],
            ImportPrzepisu::KOD_BUDZET_MIESIECZNY => ['tytul' => 'Odczytywanie jest w tym miesiącu wstrzymane', 'tresc' => 'Wyczerpał się miesięczny limit odczytów w serwisie. Twoje zdjęcie jest zapisane. Możesz wpisać przepis ręcznie już teraz albo wrócić w przyszłym miesiącu.'],
            ImportPrzepisu::KOD_BRAK_ZGODY => ['tytul' => 'Zgoda na odczyt jest wycofana', 'tresc' => 'Bez zgody na odczyt przez OpenAI nic nie wysłaliśmy. Twoje zdjęcie jest zapisane w szkicu — możesz wpisać przepis ręcznie.'],
            ImportPrzepisu::KOD_WYLACZONY => ['tytul' => 'Odczytywanie jest teraz wyłączone', 'tresc' => 'Nic nie zginęło — zdjęcie jest zapisane w szkicu. Możesz wpisać przepis ręcznie.'],
            ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY, ImportPrzepisu::KOD_ODPOWIEDZ_BLEDNA, ImportPrzepisu::KOD_BLAD_WEWNETRZNY => ['tytul' => 'Nie udało się odczytać przepisu', 'tresc' => 'Nic nie zginęło — zdjęcie jest zapisane. Kliknij „Spróbuj jeszcze raz” za kilka minut albo wpisz przepis ręcznie.'],
            ImportPrzepisu::KOD_NIECZYTELNE => ['tytul' => 'Nie umiemy odczytać tego zdjęcia', 'tresc' => 'Zrób je w dziennym świetle, prosto z góry, tak żeby kartka wypełniała cały kadr — i dodaj jeszcze raz. To zdjęcie zostaje zapisane w szkicu.'],
            ImportPrzepisu::KOD_ZDJECIE_NIEDOSTEPNE => ['tytul' => 'Nie udało się przygotować zdjęcia', 'tresc' => 'Zdjęcia nie dało się przygotować do odczytu. Spróbuj dodać je jeszcze raz albo wpisz przepis ręcznie.'],
            ImportPrzepisu::KOD_SZKIC_ZMIENIONY => ['tytul' => 'Szkic zmienił się w trakcie odczytu', 'tresc' => 'W tym szkicu jest już Twój tekst, więc niczego w nim nie nadpisaliśmy. Zdjęcie kartki jest przy szkicu.'],
            default => ['tytul' => 'Nie udało się odczytać przepisu', 'tresc' => 'Nic nie zginęło — zdjęcie jest zapisane. Możesz wpisać przepis ręcznie.'],
        };
    }
}
