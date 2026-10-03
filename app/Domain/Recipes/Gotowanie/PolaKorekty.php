<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Models\CookedEvent;
use App\Models\RecipeHint;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;

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
 *     `cooked_events.note` wyświetlany przy przepisie. Dopóki niewygasła
 *     prośba czeka na odpowiedź albo kucharz się zgodził, poprawka UWAGI
 *     podmieniłaby po cichu tekst, na który wyrażono zgodę. Prośba przestaje
 *     blokować po wygaśnięciu; przyjęta wskazówka nadal blokuje do wycofania.
 *     Czas i opis zmian wskazówki nie dotyczą.
 *  2. OTWARTE ZGŁOSZENIE do moderacji tego wykonania. Moderator ocenia tekst,
 *     który widział; poprawka w trakcie sprawy nadpisałaby dowód. Tekstów
 *     (uwaga, opis zmian) nie ruszamy do rozstrzygnięcia; czas jest liczbą
 *     poza zgłoszeniem, więc zostaje do poprawy.
 *     Zgłoszenie powiązanej wskazówki chroni tylko wspólną uwagę (#2884),
 *     także po wycofaniu zgody; nie dotyczy opisu zmian ani czasu.
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

    public const POWOD_UWAGA_CHRONIONA = 'Tej uwagi nie można teraz zmienić. Czas i opis zmian nadal możesz poprawić.';

    /**
     * @return array<string, string> pole => powód, dla pól zablokowanych
     */
    public static function zablokowane(CookedEvent $wykonanie): array
    {
        $zablokowane = [];

        $wskazowkaAktywna = RecipeHint::query()
            ->where('cooked_event_id', $wykonanie->getKey())
            ->where(static function (Builder $query): void {
                $query->where('status', RecipeHint::STATUS_ACCEPTED)
                    ->orWhere(static function (Builder $proposed): void {
                        $proposed->where('status', RecipeHint::STATUS_PROPOSED)
                            ->where('created_at', '>', RecipeHint::granicaWygasniecia());
                    });
            })
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

        // Wskazówka nie ma kopii uwagi. Wycofanie zgody usuwa ją z przepisu,
        // ale nie zmienia tekstu, który moderacja jeszcze musi rozpatrzyć.
        $zgloszenieWskazowkiOtwarte = Report::query()
            ->where('target_type', 'recipe_hint')
            ->whereIn('target_id', RecipeHint::query()->select('id')->where('cooked_event_id', $wykonanie->getKey()))
            ->whereIn('status', Report::STATUSY_OTWARTE)
            ->exists();

        if ($zgloszenieWskazowkiOtwarte) {
            $zablokowane['note'] ??= self::POWOD_UWAGA_CHRONIONA;
        }

        return $zablokowane;
    }
}
