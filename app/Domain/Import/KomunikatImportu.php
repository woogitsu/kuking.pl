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
        $komunikat = self::podstawowy($import);

        // #2521: gotowy, ale niepełny — nie wygląda jak zwykły gotowy wynik.
        if ($import->status === ImportPrzepisu::STATUS_GOTOWY) {
            $niepelny = $import->pominieteWImporcie();

            if ($niepelny !== null) {
                return [
                    'tytul' => $niepelny->niepelny() ? 'Szkic gotowy, ale import jest niepełny' : 'Szkic gotowy, są pola do uzupełnienia',
                    'tresc' => $niepelny->komunikat().' '.$komunikat['tresc'],
                ];
            }
        }

        return $komunikat;
    }

    /** @return array{tytul: string, tresc: string} */
    private static function podstawowy(ImportPrzepisu $import): array
    {
        if ($import->zAdresu()) {
            return self::dlaAdresu($import);
        }

        if ($import->zPdf()) {
            return self::dlaPdf($import);
        }

        return match ($import->status) {
            ImportPrzepisu::STATUS_OCZEKUJE, ImportPrzepisu::STATUS_W_TOKU => $import->proby > 1
                ? ['tytul' => 'To trwa dłużej niż zwykle', 'tresc' => 'Nie musisz czekać. Możesz zamknąć tę stronę — szkic znajdziesz w „Moich szkicach”, a zdjęcie kartki jest już przy nim zapisane.']
                : ['tytul' => 'Odczytujemy pismo', 'tresc' => 'Zwykle trwa to do minuty. Nie musisz czekać — możesz zamknąć tę stronę, szkic znajdziesz w „Moich szkicach”.'],
            ImportPrzepisu::STATUS_GOTOWY => ['tytul' => 'Szkic gotowy do sprawdzenia', 'tresc' => 'Ten tekst odczytał komputer. Porównaj każdą linijkę ze zdjęciem i popraw, co trzeba. Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.'],
            default => self::niepowodzenie($import),
        };
    }

    /** @return array{tytul: string, tresc: string} */
    private static function niepowodzenie(ImportPrzepisu $import): array
    {
        $kod = (string) $import->kod_bledu;

        return match ($kod) {
            ImportPrzepisu::KOD_LIMIT_OSOBY => self::limitOsoby($import),
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

    /** @return array{tytul: string, tresc: string} */
    private static function limitOsoby(ImportPrzepisu $import): array
    {
        // Starszy kod nie zapisywał okresu. Pokazujemy wyłącznie OBECNY limit,
        // tylko przy faktycznie wstrzymanym zleceniu; nie zgadujemy przyczyny.
        $osoba = $import->status === ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM
            ? $import->user()->first()
            : null;
        $okres = $osoba === null ? null : app(LimitImportowOsoby::class)->obecnaBlokada($osoba);

        $tresc = match ($okres) {
            LimitImportowOsoby::MIESIAC => 'W tym miesiącu wykorzystano limit odczytów. Po rozpoczęciu następnego miesiąca możesz spróbować ponownie, jeśli odczytywanie będzie dostępne.',
            LimitImportowOsoby::DZIEN => 'Dziś wykorzystano limit odczytów. Po rozpoczęciu następnego dnia możesz spróbować ponownie, jeśli odczytywanie będzie dostępne.',
            default => 'Możesz spróbować ponownie, jeśli odczytywanie jest dostępne.',
        };

        return [
            'tytul' => $okres === null ? 'Możesz spróbować ponownie' : 'To już limit odczytów',
            'tresc' => $tresc.' Twoje zdjęcie jest zapisane w szkicu. Możesz też wpisać przepis ręcznie już teraz.',
        ];
    }

    /**
     * Import z adresu strony (#28): wynik jest szkicem BEZ zdjęcia, a szkic
     * powstaje dopiero na końcu, więc żaden z tych tekstów nie obiecuje
     * „zapisanego zdjęcia”. Zdania odmów są te same, co przy adresie
     * odrzuconym od razu (`ImportOdrzucony::KOMUNIKATY`) — jedno źródło słów.
     *
     * @return array{tytul: string, tresc: string}
     */
    private static function dlaAdresu(ImportPrzepisu $import): array
    {
        $odmowa = static fn (string $kod): string => ImportOdrzucony::KOMUNIKATY[$kod];

        return match ($import->status) {
            ImportPrzepisu::STATUS_OCZEKUJE, ImportPrzepisu::STATUS_W_TOKU => [
                'tytul' => 'Pobieramy stronę',
                'tresc' => 'Zwykle trwa to kilkanaście sekund. Nie musisz czekać — możesz zamknąć tę stronę, a gotowy szkic znajdziesz w „Moich szkicach”.',
            ],
            ImportPrzepisu::STATUS_GOTOWY => $import->drogaOdczytu() === 'bez_tresci'
                ? ['tytul' => 'Szkic ze źródłem gotowy', 'tresc' => 'Nie pobraliśmy przepisu z tej strony — strona nie pozwala na pobieranie albo nie ma na niej przepisu. Adres zapisaliśmy w szkicu jako źródło: skopiuj tekst przepisu i wklej go w polu „Przygotowanie”.']
                : ['tytul' => 'Szkic gotowy do sprawdzenia', 'tresc' => 'Ten tekst odczytał komputer. Porównaj go ze stroną i popraw, co trzeba. Opis przygotowania napisz własnymi słowami, zanim opublikujesz — nic się nie opublikuje samo.'],
            default => match ((string) $import->kod_bledu) {
                ImportPrzepisu::KOD_BRAK_ZGODY => ['tytul' => 'Do odczytu tej strony potrzebna jest zgoda', 'tresc' => $odmowa(ImportOdrzucony::BRAK_ZGODY_AI)],
                ImportPrzepisu::KOD_BUDZET_DZIENNY, ImportPrzepisu::KOD_BUDZET_MIESIECZNY => ['tytul' => 'Odczytywanie jest teraz wstrzymane', 'tresc' => $odmowa(ImportOdrzucony::BUDZET_AI)],
                ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY => ['tytul' => 'Odczyt chwilowo nie działa', 'tresc' => $odmowa(ImportOdrzucony::MODEL_NIEDOSTEPNY)],
                ImportPrzepisu::KOD_WYLACZONY => ['tytul' => 'Import ze stron jest teraz wyłączony', 'tresc' => 'Nic nie zginęło. Możesz wpisać przepis ręcznie.'],
                default => in_array((string) $import->kod_bledu, ImportPrzepisu::KODY_ADRESU, true)
                    ? ['tytul' => 'Nie udało się pobrać przepisu ze strony', 'tresc' => $odmowa((string) $import->kod_bledu)]
                    : ['tytul' => 'Nie udało się pobrać przepisu ze strony', 'tresc' => 'Nic nie zginęło. Wklej adres jeszcze raz za kilka minut albo wpisz przepis ręcznie.'],
            },
        };
    }

    /**
     * Import z wysłanego pliku PDF (#28, etap 2): jak przy adresie wynik jest
     * szkicem BEZ zdjęcia, a szkic powstaje dopiero na końcu. Zdania odmów
     * są te z `ImportOdrzucony::KOMUNIKATY` (`:mb`, `:strony` z konfiguracji).
     *
     * @return array{tytul: string, tresc: string}
     */
    private static function dlaPdf(ImportPrzepisu $import): array
    {
        $odmowa = static fn (string $kod): string => str_replace(
            [':mb', ':strony'],
            [(string) (int) config('kuking.import.pdf.max_mb'), (string) min(5, max(1, (int) config('kuking.import.pdf.max_stron')))],
            ImportOdrzucony::KOMUNIKATY[$kod],
        );

        return match ($import->status) {
            ImportPrzepisu::STATUS_OCZEKUJE, ImportPrzepisu::STATUS_W_TOKU => [
                'tytul' => 'Odczytujemy plik',
                'tresc' => 'Zwykle trwa to do minuty. Nie musisz czekać — możesz zamknąć tę stronę, a gotowy szkic znajdziesz w „Moich szkicach”.',
            ],
            ImportPrzepisu::STATUS_GOTOWY => $import->drogaOdczytu() === 'ocr'
                ? ['tytul' => 'Szkic gotowy do sprawdzenia', 'tresc' => 'Ten tekst odczytał komputer ze skanu. Porównaj każdą linijkę ze swoim plikiem i popraw, co trzeba. Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.']
                : ['tytul' => 'Szkic gotowy do sprawdzenia', 'tresc' => 'Ten tekst odczytał komputer z Twojego pliku. Porównaj go z plikiem i popraw, co trzeba. Nic się nie opublikuje, dopóki nie klikniesz „Opublikuj”.'],
            default => match ((string) $import->kod_bledu) {
                ImportPrzepisu::KOD_BRAK_ZGODY => ['tytul' => 'Do odczytu skanu potrzebna jest zgoda', 'tresc' => $odmowa(ImportOdrzucony::BRAK_ZGODY_AI)],
                ImportPrzepisu::KOD_BUDZET_DZIENNY, ImportPrzepisu::KOD_BUDZET_MIESIECZNY => ['tytul' => 'Odczytywanie jest teraz wstrzymane', 'tresc' => $odmowa(ImportOdrzucony::BUDZET_AI)],
                ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY => ['tytul' => 'Odczyt chwilowo nie działa', 'tresc' => $odmowa(ImportOdrzucony::MODEL_NIEDOSTEPNY)],
                ImportPrzepisu::KOD_WYLACZONY => ['tytul' => 'Import z plików PDF jest teraz wyłączony', 'tresc' => 'Nic nie zginęło. Możesz wpisać przepis ręcznie.'],
                default => in_array((string) $import->kod_bledu, ImportPrzepisu::KODY_PDF, true)
                    ? ['tytul' => 'Nie udało się odczytać pliku', 'tresc' => $odmowa((string) $import->kod_bledu)]
                    : ['tytul' => 'Nie udało się odczytać pliku', 'tresc' => 'Nic nie zginęło. Dodaj plik jeszcze raz za kilka minut albo wpisz przepis ręcznie.'],
            },
        };
    }
}
