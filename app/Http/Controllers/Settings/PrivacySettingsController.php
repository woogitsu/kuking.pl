<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\UpdatePrivacySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacySettingsController extends Controller
{
    /**
     * Ile zablokowanych osób pokazuje jedno wejście na stronę (#1366).
     *
     * Wcześniej szła CAŁA lista — każde wejście, także po to, żeby zmienić
     * jedną zgodę, pobierało wszystkie blokady z awatarami i rysowało przy
     * każdej formularz. Tabela `blocks` nie ma limitu, więc koszt rósł bez
     * końca.
     */
    public const BLOKAD_NA_STRONE = 20;

    public function edit(Request $request): View
    {
        return view('pages.settings.privacy', [
            // KURSOR PO `blocked_id`, NIE `paginate()`.
            //
            // Klucz główny `blocks (blocker_id, blocked_id)` daje tę kolejność
            // wprost z indeksu, a kursor nie liczy wszystkich wierszy (COUNT)
            // ani nie przewija OFFSET-em. `blocked_id` jest unikalny w obrębie
            // jednej osoby blokującej, więc kolejność jest stabilna i żadna
            // pozycja nie powtórzy się ani nie zgubi między stronami — także
            // gdy ktoś w międzyczasie zdejmie blokadę z poprzedniej strony.
            //
            // Kotwica `#zablokowane`: „Pokaż więcej osób" ma zostawić człowieka
            // przy liście, a nie na górze formularza zgód.
            'blocked' => $request->user()->blocking()
                ->with('profile.avatar')
                ->orderBy('blocks.blocked_id')
                ->cursorPaginate(self::BLOKAD_NA_STRONE)
                ->fragment('zablokowane'),
        ]);
    }

    public function update(Request $request, UpdatePrivacySettings $settings): RedirectResponse
    {
        $request->mergeIfMissing(['wants_weekly_digest' => '0', 'memories_enabled' => '0']);
        $request->validate([
            'wants_weekly_digest' => ['nullable', 'boolean'],
            'memories_enabled' => ['nullable', 'boolean'],
            'original_digest' => ['required', 'boolean'],
            'original_memories' => ['required', 'boolean'],
        ], [
            'original_digest.*' => 'Otwórz aktualne ustawienia i wybierz ponownie zgodę na tygodniowy e-mail.',
            'original_memories.*' => 'Otwórz aktualne ustawienia i wybierz ponownie ustawienie wspomnień.',
        ]);

        $settings->handle(
            $request->user(),
            $request->boolean('wants_weekly_digest'),
            $request->boolean('memories_enabled'),
            $request->boolean('original_digest'),
            $request->boolean('original_memories'),
        );

        return back()->with('status', 'Zapisane.');
    }
}
