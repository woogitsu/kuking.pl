<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Moderation\Actions\ZabezpieczDowodCsam;
use App\Domain\Moderation\ModeratedContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use App\Support\Komunikat;
use App\Support\ZabezpieczoneDowody;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „CSAM — natychmiast ukryj i zabezpiecz” (D-333, 1 października 2026).
 *
 * Trzy ekrany, bez JavaScriptu:
 *  1. `create` — potwierdzenie. NIE pokazuje treści ani zdjęcia: moderator
 *     wie, o co chodzi, z kolejki albo ze strony treści, a ekran ma tylko
 *     powiedzieć, co się stanie, i poprosić o jawne potwierdzenie;
 *  2. `store` — wykonuje `ZabezpieczDowodCsam`;
 *  3. `wynik` — co zrobiono i INSTRUKCJA zgłoszenia do organów (tekst
 *     z `docs/flota/CSAM_JEDNA_KARTKA.md` i playbooku §7.1). Serwis nic
 *     nie wysyła na zewnątrz.
 */
class ZabezpieczenieDowoduController extends Controller
{
    /** Nazwa rodzaju treści po polsku — do nagłówków, bez treści i bez cytatu. */
    public const NAZWY = [
        'post' => 'wpis',
        'recipe' => 'przepis',
        'comment' => 'komentarz',
        'media' => 'zdjęcie',
    ];

    public function __construct(private readonly ZabezpieczDowodCsam $zabezpiecz) {}

    public function create(Request $request, string $typ, string $id): View|RedirectResponse
    {
        $this->authorize('secureCsam', User::class);

        $cel = ModeratedContent::znajdz($typ, $id, zUsunietymi: true) ?? abort(404);

        $juz = ZabezpieczenieDowodu::query()->where('target_type', $typ)->where('target_id', $id)->first();

        if ($juz !== null) {
            return redirect()->route('admin.csam.wynik', $juz)
                ->with(Komunikat::sukces('Ta treść jest już zabezpieczona jako dowód. Poniżej instrukcja zgłoszenia.'));
        }

        $zgloszenie = $this->zgloszenie($request, $typ, $id);
        $osoba = ModeratedContent::osoba($cel);

        return view('pages.admin.csam.potwierdz', [
            'typ' => $typ,
            'id' => $id,
            'nazwa' => self::NAZWY[$typ],
            'autor' => $osoba?->displayName(),
            'zgloszenie' => $zgloszenie,
            'powrot' => $zgloszenie !== null ? route('admin.reports') : null,
        ]);
    }

    public function store(Request $request, string $typ, string $id): RedirectResponse
    {
        $this->authorize('secureCsam', User::class);

        $data = $request->validate([
            'potwierdzam' => ['accepted'],
            'note' => ['nullable', 'string', 'max:2000'],
            'zgloszenie' => ['nullable', 'uuid'],
        ], [
            'potwierdzam.accepted' => 'Zaznacz potwierdzenie. To działanie od razu zdejmuje treść z serwisu i blokuje konto autora, więc nie zrobimy go bez Twojego wyraźnego „tak”.',
            'note.max' => 'Notatka jest za długa. Zmieść się w 2000 znakach — bez opisu samego materiału.',
        ]);

        try {
            $wynik = $this->zabezpiecz->handle(
                moderator: $request->user(),
                typ: $typ,
                id: $id,
                reportId: $data['zgloszenie'] ?? null,
                note: $data['note'] ?? null,
                ip: $request->ip(),
            );
        } catch (BladDlaCzlowieka $blad) {
            return back()->withInput()->withErrors(['potwierdzam' => $blad->getMessage()]);
        }

        return redirect()->route('admin.csam.wynik', $wynik->zabezpieczenieId)
            ->with('csam_pominiete', $wynik->zdjecPominietych)
            ->with('csam_powod_braku_blokady', $wynik->powodBrakuBlokady);
    }

    public function wynik(string $zabezpieczenie): View
    {
        $this->authorize('secureCsam', User::class);

        $wpis = ZabezpieczenieDowodu::query()->with('subjectUser')->findOrFail($zabezpieczenie);

        $zdjec = ZabezpieczenieDowodu::query()
            ->where('moderation_action_id', $wpis->moderation_action_id)
            ->where('target_type', 'media')
            ->count();

        return view('pages.admin.csam.wynik', [
            'wpis' => $wpis,
            'nazwa' => self::NAZWY[$wpis->target_type] ?? $wpis->target_type,
            'zdjec' => $zdjec,
            'autor' => $wpis->subjectUser,
            'kontoZablokowane' => $wpis->subjectUser?->status === User::STATUS_BANNED,
            'pominiete' => (int) session('csam_pominiete', 0),
            'powodBrakuBlokady' => session('csam_powod_braku_blokady'),
            'kontoChronione' => $wpis->subject_user_id !== null && ZabezpieczoneDowody::konto($wpis->subject_user_id),
        ]);
    }

    /** Zgłoszenie z adresu (`?zgloszenie=`), o ile jest otwarte i dotyczy tej treści. */
    private function zgloszenie(Request $request, string $typ, string $id): ?Report
    {
        $reportId = $request->query('zgloszenie');

        if (! is_string($reportId) || ! preg_match('/^[0-9a-f-]{36}$/i', $reportId)) {
            return null;
        }

        $zgloszenie = Report::query()->find($reportId);

        return $zgloszenie !== null && $zgloszenie->isOpen()
            && $zgloszenie->target_type === $typ && $zgloszenie->target_id === $id
            ? $zgloszenie
            : null;
    }
}
