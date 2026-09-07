<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jasny/ciemny wygląd — jeden przełącznik, dwa miejsca w interfejsie
 * (docs/DECISIONS.md, D-019): pełny wybór na `/ustawienia/czytelnosc`
 * (obok rozmiaru tekstu — to ta sama sprawa: czytelność) i szybki
 * przełącznik w stopce, widoczny na każdej stronie i dostępny też
 * dla gościa.
 *
 * ŚWIADOMIE JEDEN KONTROLER, NIE DWA
 * Stopka i ekran ustawień zapisują dokładnie ten sam wybór, więc mają
 * dzielić dokładnie ten sam kod zapisu — inaczej powstałyby dwa
 * niezależne miejsca decydujące "co to znaczy zmienić motyw", a to jest
 * właśnie powtarzająca się przyczyna błędów w tym repozytorium (reguła
 * w jednej warstwie, inna implementacja w drugiej).
 *
 * ZALOGOWANY: WYBÓR NA KONCIE
 * Tak samo jak `text_scale` (AccessibilitySettingsController) — ma być
 * taki sam po zalogowaniu się na innym urządzeniu.
 *
 * GOŚĆ: WYBÓR W CIASTECZKU, NIE W `localStorage`
 * `localStorage` wymaga JavaScriptu — a przy pierwszym renderze strona
 * i tak musiałaby wyjść z serwera w JAKIMŚ motywie, zanim skrypt zdąży
 * go poprawić, czyli dokładnie ten błysk złego wyglądu, którego ta
 * funkcja ma unikać. Ciasteczko jest czytane PRZEZ SERWER, zanim HTML
 * w ogóle powstanie, więc strona wychodzi już w wybranym motywie —
 * i działa bez JavaScriptu (AGENTS.md §5).
 *
 * COOKIE ZAPISUJEMY TAKŻE DLA ZALOGOWANEGO
 * Nie jest to źródło prawdy dla zalogowanego (tym jest kolumna na
 * koncie), ale bez tego wylogowanie się na tym samym urządzeniu
 * cofnęłoby wygląd do jasnego, mimo że właśnie ta osoba świadomie
 * wybrała ciemny — a to jest dokładnie to nieoczekiwane przełączenie
 * się motywu, na które narzeka zgłoszenie właściciela.
 */
class ThemeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', Rule::in(config('kuking.theme.options'))],
        ], [
            'theme.required' => 'Wybierz wygląd.',
            'theme.in' => 'Wybierz jeden z dostępnych wyglądów.',
        ]);

        if ($request->user()) {
            $request->user()->update(['theme' => $data['theme']]);
        }

        return back()->withCookie(cookie(
            config('kuking.theme.cookie'),
            $data['theme'],
            60 * 24 * 365, // rok — tyle samo co inne trwałe preferencje w serwisie
        ));
    }
}
