<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\RequestAccountDeletion;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\Exports\WynikZamowieniaEksportu;
use App\Domain\Users\Exports\ZamowEksportDanych;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProsbaOUsuniecieKontaRequest;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Poczta;
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
    /**
     * Jedna treść na dwie drogi dojścia do tego samego faktu: „paczka już się
     * robi" (D-078). Pierwsza droga to `exists()` przed wstawieniem, druga —
     * konflikt na indeksie `data_exports_one_active_per_user` przy dwóch
     * równoległych żądaniach. Człowiek nie ma prawa rozpoznać, którą z nich
     * trafił, więc zdanie musi być JEDNO; dwie kopie tego samego komunikatu
     * rozjechałyby się przy pierwszej korekcie tekstu.
     */
    private const JUZ_TRWA = 'Przygotowanie paczki z Twoimi danymi już trwa. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.';

    /**
     * Dopisek o liście — TYLKO gdy poczta naprawdę wysyła (`Poczta::dziala()`,
     * issue #820). Do 23 września 2026 każdy z trzech komunikatów niżej
     * obiecywał „napiszemy na Twój adres e-mail" bezwarunkowo: przy
     * `MAIL_MAILER=log` człowiek czekał na list, który nie powstanie, a przy
     * awarii poczty — na list, którego nikt nie ponawiał. Miejscem, gdzie
     * gotowość widać zawsze, jest sekcja „Twoje paczki”, więc to ona stoi
     * w zdaniu głównym, a e-mail jest dodatkiem.
     */
    private static function obietnicaListu(): string
    {
        return Poczta::dziala() ? ' Napiszemy też do Ciebie e-mail, gdy paczka będzie gotowa.' : '';
    }

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

    /**
     * Zamówienie paczki — sam przypadek użycia (rekord, zadanie w kolejce,
     * dwuklik, ponowienie porzuconego eksportu, dziennik) jest
     * w `ZamowEksportDanych` (issue #970). Tutaj tylko zdanie dla człowieka.
     */
    public function requestExport(Request $request, ZamowEksportDanych $zamow): RedirectResponse
    {
        return back()->with('status', match ($zamow->handle($request->user(), $request->ip())) {
            WynikZamowieniaEksportu::Przyjety => 'Przygotowujemy paczkę z Twoimi danymi. To może potrwać kilkanaście minut. '
                .'Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.'.self::obietnicaListu(),
            WynikZamowieniaEksportu::JuzTrwa => self::JUZ_TRWA.self::obietnicaListu(),
            WynikZamowieniaEksportu::Ponowiony => 'Przygotowanie paczki z Twoimi danymi trwało dłużej, niż powinno, więc właśnie ponowiliśmy '
                .'zlecenie. Gotową paczkę znajdziesz tutaj, w sekcji „Twoje paczki”.'.self::obietnicaListu(),
        });
    }

    /**
     * Zgłoszenie usunięcia konta. Reguły pól — `ProsbaOUsuniecieKontaRequest`,
     * transakcja z rejestrem RODO i dziennik — `RequestAccountDeletion`
     * (issue #970). Tutaj hasło, odpowiedź i wylogowanie.
     */
    public function requestDeletion(ProsbaOUsuniecieKontaRequest $request, RequestAccountDeletion $zglos): RedirectResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->validated('password'), $user->password)) {
            return back()->withErrors(['password' => 'Wpisz poprawne hasło, żeby potwierdzić usunięcie konta.'])
                ->withInput($request->only('usun_tresci'));
        }

        $zakres = $request->zakres();

        try {
            $zglos->handle($user, $zakres, $request->ip());
        } catch (OdmowaOstatniegoAdministratora) {
            // Ostatni czynny administrator (#1016). Transakcja wycofana:
            // konto czynne, bez sprawy w rejestrze i bez wpisu w audycie.
            return back()->withErrors([
                'confirm' => 'Jesteś ostatnim czynnym administratorem serwisu. Zanim usuniesz konto, '
                    .'nadaj rolę administratora innemu czynnemu kontu — bez tego nikt nie rozpatrzy odwołań.',
            ])->withInput($request->only('usun_tresci'));
        } catch (BladDlaCzlowieka $blad) {
            // Świeży stan pod blokadą mówi, że konto już jest w usuwaniu
            // (drugie kliknięcie, druga karta — #980). Nic nie zapisano.
            return back()->withErrors(['confirm' => $blad->getMessage()]);
        }

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
            "Konto zostało oznaczone do usunięcia i wylogowaliśmy Cię. Masz {$days} dni, żeby zmienić zdanie — "
            .'zrobisz to na stronie „Cofnij usunięcie konta” ('.route('account.delete.cancel').'), podając e-mail '
            .'albo nazwę użytkownika i hasło. Jeśli nie pamiętasz hasła, najpierw je zresetuj — to też zadziała. '
            .$coZTekstami,
        );
    }
}
