<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Exports\ExportFileNames;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateUserExport;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Twoje dane" — eksport i usunięcie konta.
 *
 * To jest w MVP, nie "kiedyś". Powody są dwa i oba ważne:
 *  - prawny: RODO art. 15, 17 i 20,
 *  - produktowy: obietnica "Twoje przepisy nie zginą" jest wiarygodna tylko
 *    wtedy, gdy da się je z serwisu wyjąć. Garnek.pl i Durszlak.pl zniknęły
 *    z treściami użytkowników; nie powtarzamy tego.
 *
 * Usunięcie konta jest DWUETAPOWE: konto przechodzi w stan `pending_delete`
 * i przez 30 dni da się je odzyskać. Nieodwracalne usunięcie po jednym
 * kliknięciu byłoby okrutne wobec osoby, która pomyliła przycisk.
 *
 * Odzyskanie NIE dzieje się tutaj. Zgłoszenie usunięcia wylogowuje od razu
 * (patrz niżej), więc formularz „Twoje dane" jest dla tej osoby niedostępny
 * — cofnięcie idzie przez publiczny `AccountDeletionController`, tym samym
 * wzorcem identyfikacji co formularz odwołań dla zablokowanych kont
 * (issue #10). Egzekucję karencji po 30 dniach wykonuje komenda
 * `kuking:usun-wygasle-konta` (`App\Domain\Users\Actions\EraseAccountData`).
 */
class DataSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $exports = $request->user()->dataExports()->latest()->limit(5)->get();

        return view('pages.settings.data', [
            'exports' => $exports,
            'graceDays' => (int) config('kuking.account.delete_grace_days'),

            // Adres pobrania jest generowany dopiero na tym ekranie i tylko dla
            // paczek, które naprawdę da się pobrać. Podpis wygasa razem z paczką,
            // więc nie da się zapamiętać linku „na później”.
            'downloadUrls' => $exports
                ->filter(fn (DataExport $export): bool => $export->isDownloadable())
                ->mapWithKeys(fn (DataExport $export): array => [
                    $export->getKey() => URL::temporarySignedRoute(
                        'settings.data.download',
                        $export->expires_at,
                        ['export' => $export->getKey()],
                    ),
                ])
                ->all(),
        ]);
    }

    /**
     * Pobranie gotowej paczki.
     *
     * Trasa ma middleware `signed`, więc sam adres musi być podpisany przez
     * Kuking i nie może być przedawniony. To jednak NIE JEST autoryzacja —
     * podpis mówi tylko „ten link wystawiliśmy my”. Dlatego niżej sprawdzamy
     * jeszcze dwie rzeczy:
     *
     *  1. czy pobiera WŁAŚCICIEL paczki (przekazany komuś link nic nie da),
     *  2. czy paczka nadal jest do pobrania (`ready` i przed `expires_at`).
     *
     * Odpowiedzią na cudzą paczkę jest 404, a nie 403 — nie potwierdzamy nawet
     * tego, że taki eksport istnieje.
     */
    public function download(Request $request, DataExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->getKey(), 404);
        abort_unless($export->isDownloadable(), 404);
        abort_if($export->disk === null || $export->object_key === null, 404);

        $disk = Storage::disk($export->disk);

        abort_unless($disk->exists($export->object_key), 404);

        AuditLogEntry::record('data.export_downloaded', $request->user(), $export, ip: $request->ip());

        return $disk->download($export->object_key, ExportFileNames::archiveFile($export));
    }

    public function requestExport(Request $request): RedirectResponse
    {
        $user = $request->user();

        $pending = $user->dataExports()
            ->whereIn('status', [DataExport::STATUS_QUEUED, DataExport::STATUS_PROCESSING])
            ->exists();

        if ($pending) {
            return back()->with('status', 'Przygotowanie paczki z Twoimi danymi już trwa. Napiszemy, gdy będzie gotowa.');
        }

        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        GenerateUserExport::dispatch((string) $export->getKey());

        AuditLogEntry::record('data.export_requested', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Przygotowujemy paczkę z Twoimi danymi. To może potrwać kilkanaście minut — napiszemy na Twój adres e-mail, gdy będzie gotowa.',
        );
    }

    /**
     * ZAKRES USUNIĘCIA WYBIERA CZŁOWIEK (D-022).
     *
     * Haczyk „usuń także moje treści" jest DOMYŚLNIE ODHACZONY i dlatego
     * nie ma tu żadnej reguły `required`: brak pola w żądaniu to poprawna,
     * najczęstsza odpowiedź, znacząca „zostaw teksty". `boolean` pilnuje
     * tylko, żeby nie dało się wcisnąć tam czegoś innego niż tak/nie.
     *
     * Wybór idzie do KOLUMNY, nie do sesji ani do zadania w kolejce —
     * egzekucja jest 30 dni później (D-022, punkt 2).
     */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
            'usun_tresci' => ['nullable', 'boolean'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby potwierdzić, że to Ty.',
            'confirm.accepted' => 'Zaznacz, że rozumiesz, co się stanie.',
            'usun_tresci.boolean' => 'Zaznacz haczyk albo zostaw go pustym.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'To hasło jest nieprawidłowe.']);
        }

        $zakres = $request->boolean('usun_tresci')
            ? User::DELETE_SCOPE_EVERYTHING
            : User::DELETE_SCOPE_MINIMUM;

        $user->markForDeletion($zakres);

        // Zakres w audycie, bo to jest jedyny zapis tego, CO człowiek wybrał
        // i kiedy. Gdyby ktoś kiedyś zapytał „dlaczego moje przepisy
        // zniknęły" (albo „dlaczego NIE zniknęły"), odpowiedź musi dać się
        // znaleźć bez zgadywania.
        AuditLogEntry::record(
            'account.delete_requested',
            $user,
            $user,
            metadata: ['zakres' => $zakres],
            ip: $request->ip(),
        );

        $days = (int) config('kuking.account.delete_grace_days');

        $coZTekstami = $zakres === User::DELETE_SCOPE_EVERYTHING
            ? "Po {$days} dniach usuniemy też Twoje przepisy, wpisy, komentarze, wykonania i zeszyty — tak jak mówi zaznaczony haczyk. "
            : "Po {$days} dniach Twoje przepisy, wpisy i komentarze zostaną w serwisie bez Twojego nazwiska, podpisane „Użytkownik usunięty”. ";

        // Wylogowujemy w TYM SAMYM żądaniu, nie czekamy, aż zrobi to
        // `EnsureAccountIsActive` przy kolejnym wejściu (audyt A8).
        //
        // Bez tego przeglądarka i tak szła za przekierowaniem niżej, ale po
        // drodze middleware widziało jeszcze zalogowane, oznaczone do
        // usunięcia konto — wylogowywało je SAMO i PODMIENIAŁO to
        // przekierowanie na ekran logowania z zupełnie INNYM komunikatem.
        // Flash ustawiony tutaj nigdy nie docierał do człowieka: widział
        // tylko komunikat z `LoginController::komunikatOdmowy()`, który do
        // niedawna w ogóle nie wspominał o istnieniu drogi powrotu.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status',
            "Konto zostało oznaczone do usunięcia i zostałeś/aś wylogowany/a. Masz {$days} dni, żeby zmienić zdanie — "
            .'zrobisz to na stronie „Cofnij usunięcie konta” ('.route('account.delete.cancel').'), podając e-mail '
            .'albo nazwę użytkownika i hasło. Jeśli nie pamiętasz hasła, najpierw je zresetuj — to też zadziała. '
            .$coZTekstami,
        );
    }
}
