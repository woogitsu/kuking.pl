<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * EKRAN-ROZDROŻE USTAWIEŃ — spis wszystkich ekranów ustawień pod adresem,
 * który człowiek zgaduje bez pomocy: `/ustawienia`.
 *
 * PO CO TO POWSTAŁO (issue #344, koszt przyjęty świadomie w D-168)
 * Napis „Ustawienia" — i w nawigacji bocznej na komputerze, i w rzędzie akcji
 * własnego profilu — prowadził na `settings.accessibility`, czyli na ekran
 * o nagłówku „Czytelność". Człowiek naciskał „Ustawienia" i lądował na czymś,
 * co nazywa się inaczej; spis pozostałych ośmiu ekranów ratował sytuację, ale
 * dopiero po przeczytaniu całej strony. D-168 zapisało to jako koszt do
 * zdjęcia osobną decyzją — i ta decyzja właśnie zapadła.
 *
 * DLACZEGO TO NIE JEST PRZEKIEROWANIE NA „CZYTELNOŚĆ"
 * Bo wtedy problem wracałby w całości: adres byłby nowy, a ekran ten sam.
 * Rozdroże ma pokazać WSZYSTKIE ekrany naraz i nie wybierać za nikogo.
 *
 * DLACZEGO KONTROLER BEZ DANYCH
 * Spis ekranów mieszka w `resources/views/components/ustawienia-nawigacja.blade.php`
 * — w JEDNYM miejscu, wspólnym dla wszystkich ekranów ustawień. Przeniesienie
 * go tutaj zrobiłoby z rozdroża drugie źródło prawdy o tym, jakie ekrany
 * istnieją, czyli dokładnie ten rozjazd, przed którym tamten komponent
 * powstał. Kontroler nie ma więc czego podać widokowi i to jest cecha,
 * nie brak.
 *
 * AUTORYZACJA
 * Trasa siedzi w grupie `auth` i nie przyjmuje ŻADNEGO identyfikatora —
 * nie ma tu czyjegoś zasobu, który dałoby się podmienić w adresie, więc nie
 * ma czego przepuszczać przez Policy (AGENTS.md §7). Ekran pokazuje wyłącznie
 * nazwy ekranów, te same dla każdego zalogowanego; wszystko, co dotyczy
 * konkretnego konta, stoi dopiero na ekranach docelowych i każdy z nich
 * pilnuje tego sam.
 */
class SettingsIndexController extends Controller
{
    public function __invoke(): View
    {
        return view('pages.settings.index');
    }
}
