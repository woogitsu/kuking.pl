<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Report;

/**
 * „P0 NIEPRZEJRZANE" — OZNACZENIE, KTÓREGO NIE DA SIĘ ODKLIKNĄĆ (D-070).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TO JEST
 * ────────────────────────────────────────────────────────────────────────
 *
 * Jedna liczba: ile spraw krytycznych (P0 — CSAM, groźba życia, aktywny
 * doxxing) czeka jeszcze na człowieka. Stoi w pasku panelu
 * (`components/panel-moderacji.blade.php`), czyli na KAŻDYM ekranie
 * `/admin/**`, niezależnie od wybranej zakładki i filtra — bo sprawa
 * krytyczna nie może zależeć od tego, czy moderator ma otwartą tę zakładkę,
 * na której leży.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO BEZ CACHE, W PRZECIWIEŃSTWIE DO PIĘCIU LICZNIKÓW MENU
 * ────────────────────────────────────────────────────────────────────────
 *
 * `KolejkiPanelu` liczy pięć kolejek POZA ścieżką żądania i ma do tego
 * mocne uzasadnienie (pięć `COUNT(*)` na każdą odsłonę, najdroższe wtedy,
 * gdy kolejki są pełne). Tutaj świadomie robimy odwrotnie i to nie jest
 * niekonsekwencja:
 *
 *  1. TO JEDNO ZAPYTANIE, NIE PIĘĆ, i ma pod sobą indeks postawiony
 *     dokładnie pod nie (`reports_kolejka_priorytet_idx`, warunek
 *     częściowy na sprawy otwarte). Koszt jest stały i nie rośnie
 *     z historią tabeli.
 *
 *  2. STARE „0" JEST TU KŁAMSTWEM O NAJWYŻSZEJ CENIE. `KolejkiPanelu`
 *     rozstrzygnął to samo pytanie odwrotnie („licznik ukryty jest lepszy
 *     niż licznik kłamiący") i tam była to prawda: brak plakietki przy
 *     „Odwołania" znaczy najwyżej pięć minut opóźnienia. Tutaj pusty albo
 *     nieodświeżony cache znaczyłby, że zgłoszenie CSAM sprzed dwóch minut
 *     NIE POKAZUJE SIĘ, a moderator patrzy na ekran, który mówi „nic
 *     pilnego". Nie ma progu wydajności, przy którym warto kupić taki
 *     stan — a przy jednym–dwóch moderatorach ekranów `/admin/**`
 *     otwiera się kilkadziesiąt razy na dobę, nie kilkadziesiąt tysięcy.
 *
 *  3. Odświeżanie przy zapisie (`KolejkiPanelu::odswiez()` ze zdarzeń
 *     modeli) nie rozwiązałoby tego, bo cache trzeba by wtedy przeliczać
 *     także przy UPŁYWIE CZASU: sprawa wzięta do przeglądu i nie domknięta
 *     wraca do „nieprzejrzanych" bez żadnego zapisu w bazie
 *     (`App\Domain\Moderation\Przeglad`). Cache musiałby wygasać częściej,
 *     niż to cokolwiek oszczędza.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE DA SIĘ TEGO ODKLIKNĄĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie ma przycisku „ukryj", nie ma zapisu w sesji ani w `localStorage`,
 * nie ma wersji „przypomnij później". Liczba schodzi do zera dokładnie
 * dwiema drogami: sprawa zostaje ROZSTRZYGNIĘTA albo ktoś ją WEŹMIE DO
 * PRZEGLĄDU — a wzięcie do przeglądu wygasa po ośmiu godzinach i alarm
 * wraca. Obie drogi wymagają podjęcia sprawy przez człowieka i obie
 * zostawiają ślad w bazie.
 *
 * Trzecia droga — obniżenie priorytetu z uzasadnieniem
 * (`Report::zmienPriorytet()`) — też gasi alarm i ma prawo: to jest
 * człowiek mówiący „przeczytałem, to nie jest P0", z powodem zapisanym
 * w kolumnie i z nazwiskiem przy nim. To jest podjęcie sprawy, nie
 * odklikanie jej.
 */
final class PilneSprawy
{
    /**
     * Ile spraw krytycznych czeka na człowieka.
     *
     * WSZYSTKIE ŹRÓDŁA, także oznaczenia automatu. Mapowanie powodów nie
     * daje automatowi P0 (patrz `PriorytetSprawy::MAPOWANIE`), ale moderator
     * może podnieść ręcznie oznaczenie, w którym zobaczył coś poważnego —
     * a alarm, który by takiej sprawy nie zobaczył, byłby gorszy niż brak
     * alarmu, bo uczyłby, że „pusty pasek" znaczy „nic pilnego".
     */
    public function ile(): int
    {
        return Report::query()
            ->where('priorytet', PriorytetSprawy::P0)
            ->nieprzejrzane()
            ->count();
    }

    /**
     * Najdłużej czekająca sprawa krytyczna — do zdania „czeka od …".
     *
     * Osobne zapytanie, wołane WYŁĄCZNIE wtedy, gdy `ile() > 0`, czyli
     * w stanie, który ma być prawie nigdy. Napis „P0 nieprzejrzane: 1" bez
     * informacji, jak długo to leży, nie mówi moderatorowi tego, co jest
     * tu najważniejsze: czy sprawa przyszła minutę temu, czy przespał ją
     * całą noc.
     */
    public function najstarsza(): ?Report
    {
        return Report::query()
            ->where('priorytet', PriorytetSprawy::P0)
            ->nieprzejrzane()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
