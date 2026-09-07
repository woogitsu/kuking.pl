<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\Actions\FileReporterAppeal;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ModerationAction;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Odwołanie od decyzji moderacyjnej — droga dla ZGŁASZAJĄCEGO (issue #23,
 * DSA art. 20 ust. 1).
 *
 * DLACZEGO NIE `AppealController`
 * Tamten zna dwie drogi (sesja i hasło), obie zakładają, że odwołujący się
 * MA konto. Zgłaszający może go nie mieć wcale (art. 16 ust. 2 lit. c) —
 * jedyne, co o nim wiemy, leży w `Report`. Autoryzacją jest podpisany,
 * wygasający link z maila (`middleware('signed')` w trasie), nie sesja ani
 * hasło — tak samo jak przy pobraniu eksportu danych
 * (`Settings\DataSettingsController`).
 *
 * JEDNA METODA NA GET I POST
 * Formularz i wysyłka dzielą jeden adres i jedną trasę, żeby jeden podpisany
 * link z maila wystarczył na obie czynności — przeglądarka POST-uje
 * dokładnie na ten adres, z którego przyszła, więc podpis w query string
 * jest wciąż ten sam i wciąż ważny.
 */
class ReporterAppealController extends Controller
{
    public function __construct(private readonly FileReporterAppeal $zloz) {}

    public function handle(Request $request, Report $report): View|RedirectResponse
    {
        // `signed` w trasie już potwierdził, że link jest nasz i nie wygasł.
        // To tutaj sprawdza, że adres w ogóle ma sens: link generujemy
        // wyłącznie dla zgłoszeń prawnych z decyzją.
        abort_unless($report->jestZgloszeniemPrawnym(), 404);

        $decyzja = ModerationAction::query()->where('report_id', $report->getKey())->first();

        abort_if($decyzja === null, 404);

        if ($request->isMethod('post')) {
            return $this->store($request, $report);
        }

        return view('pages.appeals.reporter', [
            'zgloszenie' => $report,
            'decyzja' => $decyzja,
            'odwolanie' => $decyzja->reporterAppeal,
        ]);
    }

    private function store(Request $request, Report $report): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'body.required' => 'Napisz w kilku zdaniach, dlaczego uważasz decyzję za błędną.',
            'body.min' => 'Napisz trochę więcej — kilka zdań wystarczy, ale jedno słowo nam nie pomoże.',
            'body.max' => 'To za długie. Zmieść się w 2000 znaków — liczy się to, co najważniejsze.',
        ]);

        try {
            $this->zloz->handle($report, $data['body'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            return back()->withErrors(['body' => $blad->getMessage()])->withInput();
        }

        // Przekierowanie na TEN SAM, wciąż podpisany adres (`fullUrl()`
        // niesie oryginalny `signature` i `expires` z linku w mailu) —
        // dzięki temu strona po wysłaniu odwołania nadal się otwiera i
        // pokazuje jego status, zamiast 403 z braku podpisu.
        return redirect($request->fullUrl())->with(
            'status',
            'Odwołanie do nas trafiło. Odpowiemy w ciągu '
            .config('kuking.moderation.appeal_response_working_days')
            .' dni roboczych — na adres e-mail, z którego przyszło Twoje zgłoszenie.',
        );
    }
}
