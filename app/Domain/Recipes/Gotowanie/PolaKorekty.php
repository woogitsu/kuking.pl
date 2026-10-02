<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Models\CookedEvent;
use App\Models\RecipeHint;
use App\Models\Report;

/**
 * Które pola własnego wykonania wolno teraz poprawić (#2459).
 *
 * Pierwszy etap korekty obejmuje wyłącznie uwagę (`note`), opis zmian
 * (`changes_note`) i rzeczywisty czas (`actual_minutes`). Reszta wykonania —
 * zdjęcia, odpowiedzi o trudności i powtórzeniu, dzień i chwila gotowania,
 * kucharz, przepis, przypięta wersja — nie ma tu żadnej drogi zapisu.
 *
 * DWIE BLOKADY, OBIE NA ŚWIEŻYM STANIE (wołający trzyma wiersz wykonania):
 *
 *  1. WSKAZÓWKA (#2352). Wskazówka od gotujących nie kopiuje tekstu — to
 *     `cooked_events.note` wyświetlany przy przepisie. Dopóki prośba czeka na
 *     odpowiedź albo kucharz się zgodził, poprawka UWAGI podmieniłaby po cichu
 *     tekst, na który wyrażono zgodę. Uwaga zostaje więc zablokowana do chwili,
 *     gdy kucharz odpowie „Nie”, wycofa zgodę albo autor wycofa prośbę.
 *     Czas i opis zmian wskazówki nie dotyczą.
 *  2. OTWARTE ZGŁOSZENIE do moderacji tego wykonania. Moderator ocenia tekst,
 *     który widział; poprawka w trakcie sprawy nadpisałaby dowód. Tekstów
 *     (uwaga, opis zmian) nie ruszamy do rozstrzygnięcia; czas jest liczbą
 *     poza zgłoszeniem, więc zostaje do poprawy.
 *
 * Zablokowane pole zostaje w bazie bez zmian, a formularz pokazuje jego
 * zapisaną treść i powód — nic nie znika po cichu.
 */
final class PolaKorekty
{
    public const POLA = ['note', 'changes_note', 'actual_minutes'];

    public const POWOD_WSKAZOWKA = 'Ta uwaga jest teraz wskazówką przy przepisie albo czeka na Twoją odpowiedź w tej sprawie. '
        .'Żeby ją poprawić, najpierw odpowiedz „Nie” na prośbę albo wycofaj zgodę na tej stronie wykonania.';

    public const POWOD_ZGLOSZENIE = 'Ktoś zgłosił to wykonanie i moderacja jeszcze nie skończyła sprawy, więc tekstu na razie nie można zmienić. '
        .'Czas nadal możesz poprawić. Jeśli chcesz coś wyjaśnić, dopisz komentarz pod wykonaniem.';

    /**
     * @return array<string, string> pole => powód, dla pól zablokowanych
     */
    public static function zablokowane(CookedEvent $wykonanie): array
    {
        $zablokowane = [];

        $wskazowkaAktywna = RecipeHint::query()
            ->where('cooked_event_id', $wykonanie->getKey())
            ->whereIn('status', [RecipeHint::STATUS_PROPOSED, RecipeHint::STATUS_ACCEPTED])
            ->exists();

        if ($wskazowkaAktywna) {
            $zablokowane['note'] = self::POWOD_WSKAZOWKA;
        }

        $zgloszenieOtwarte = Report::query()
            ->where('target_type', 'cooked_event')
            ->where('target_id', $wykonanie->getKey())
            ->whereIn('status', Report::STATUSY_OTWARTE)
            ->exists();

        if ($zgloszenieOtwarte) {
            $zablokowane['note'] ??= self::POWOD_ZGLOSZENIE;
            $zablokowane['changes_note'] = self::POWOD_ZGLOSZENIE;
        }

        return $zablokowane;
    }
}
