<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Decyzje autozapisu kreatora (issue #1387, krok 9).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania. Klasa nie zna Livewire'a, worka błędów, bazy ani
 * requestu; odpowiada na pytania „czy zapisywać”, „czy dopisać wersję”
 * i „czy ten zapis już był w tym żądaniu”. Kolejność kroków samego zapisu
 * i wszystko, co dotyka Livewire'a, zostaje w komponencie:
 *
 *   1. identyfikatory zapisanych kroków i przepis (autoryzacja przy KAŻDYM zapisie),
 *   2. zdjęcia w kolejce,
 *   3. nazwa (`maNazwe`),
 *   4. walidacja SUROWYCH pól — przed `persist()`, inaczej długi tytuł
 *      kończy się SQL 22001, a wiersz bywa po cichu przycięty (#528),
 *   5. `PublishRecipe` z `RewizjaTresci::oczekiwana()` — obrona przed
 *      nadpisaniem nowszej wersji z innej karty.
 *
 * Instancja żyje tyle, co jedno żądanie Livewire'a (komponent tworzy ją
 * leniwie i nie serializuje jej do migawki), więc `zapisanoWTymZadaniu`
 * nie przechodzi do następnego żądania.
 */
final class AutozapisKreatora
{
    /**
     * Zmiany, po których autozapis nie ma sensu: numer kroku, sam stan
     * plakietki (zapis sam go zmienia) i licznik kluczy wierszy.
     */
    private const ZMIANY_BEZ_ZAPISU = ['step', 'saveState', 'saveMessage', 'rowCounter'];

    private bool $zapisanoWTymZadaniu = false;

    /** Czy hook `updated()` ma pominąć tę zmianę (nie zapisywać, nie czyścić błędów). */
    public static function pominZmiane(string $wlasciwosc): bool
    {
        return in_array($wlasciwosc, self::ZMIANY_BEZ_ZAPISU, true);
    }

    /** Bez nazwy (co najmniej 3 znaki po przycięciu) `PublishRecipe` nie utworzy przepisu. */
    public static function maNazwe(string $tytul): bool
    {
        return mb_strlen(trim($tytul)) >= StanZapisu::MIN_ZNAKOW_NAZWY;
    }

    /**
     * Livewire wysyła zmianę pola i kliknięcie „Zapisz zmiany” jednym
     * żądaniem: `updated()` zapisał już treść autozapisem (bez wersji).
     * Świadomy zapis nie może przez to zgubić swojej wersji — wersję
     * trzeba dopisać osobno, ale tylko po UDANYM zapisie. Czy przepis jest
     * opublikowany, sprawdza dopiero komponent, po ponownej autoryzacji
     * (`existingRecipe()`); ta metoda jest pierwszym, tanim warunkiem.
     */
    public static function mogeDopisacWersje(bool $wersja, string $stan): bool
    {
        return $wersja && $stan === StanZapisu::ZAPISANY;
    }

    /** Wynik zapisu w żądaniu, w którym szkic już się zapisał: prawda tylko przy stanie „zapisany”. */
    public static function wynikPowtorzonegoZapisu(string $stan): bool
    {
        return $stan === StanZapisu::ZAPISANY;
    }

    /** Czy szkic już zapisał się w TYM żądaniu („Dalej” po zmianie pola to jedno żądanie, dwa wywołania). */
    public function zapisanoWTymZadaniu(): bool
    {
        return $this->zapisanoWTymZadaniu;
    }

    public function oznaczZapisano(): void
    {
        $this->zapisanoWTymZadaniu = true;
    }
}
