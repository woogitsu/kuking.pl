<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Http\Controllers\Controller;
use App\Models\WpisZgody;
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

    public function update(Request $request, PrzestawZgodeNaDigest $zgoda): RedirectResponse
    {
        $request->validate([
            'wants_weekly_digest' => ['nullable', 'boolean'],
            'memories_enabled' => ['nullable', 'boolean'],
        ]);

        $request->user()->update([
            // Wspomnienia „Rok temu gotowałaś…" (issue #34). Wyłączenie musi
            // być JEDNYM przełącznikiem i musi działać od razu — człowiek,
            // któremu wspomnienia zaczęły sprawiać ból, nie ma odklikiwać ich
            // po kolei ani szukać tego ustawienia w trzecim menu.
            'memories_enabled' => $request->boolean('memories_enabled'),
        ]);

        // ZGODA NA POCZTĘ IDZIE OSOBNO, PRZEZ KLASĘ DOMENOWĄ (D-072), a nie
        // razem z resztą w jednym `update()`.
        //
        // Wspomnienia są PREFERENCJĄ wyświetlania — wolno ją nadpisać
        // i zapomnieć poprzedni stan. Zgoda na tygodniowy digest jest
        // PODSTAWĄ PRAWNĄ wysyłki (art. 6 ust. 1 lit. a RODO), a art. 7
        // ust. 1 każe każdą jej zmianę umieć WYKAZAĆ — więc przechodzi przez
        // jedno miejsce, które przy realnej zmianie dopisuje wiersz do
        // `dziennik_zgod`. Zapis formularza BEZ ruszenia haczyka (ktoś zmienił
        // tylko wspomnienia) nie dopisuje niczego, patrz
        // `PrzestawZgodeNaDigest`.
        $zgoda->handle(
            $request->user(),
            $request->boolean('wants_weekly_digest'),
            WpisZgody::ZRODLO_USTAWIENIA,
        );

        return back()->with('status', 'Zapisane.');
    }
}
