<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Nie pokazuj mi tego więcej" — ukrycie JEDNEGO wspomnienia (issue #34).
 *
 * DLACZEGO TO NIE JEST USUNIĘCIE
 * Wpis zostaje w archiwum profilu i zostaje opublikowany dla tych, którzy go
 * widzieli. Znika wyłącznie z bloku „Rok temu…" na stronie głównej.
 * „Nie przypominaj mi o tym" i „usuń to" to dwie różne prośby i nie wolno
 * ich mylić — zwłaszcza przy wpisie, który boli, ale którego człowiek
 * na pewno nie chce stracić.
 *
 * BRAMKA PRZEZ POLICY, NIE PRZEZ ADRES
 * Wspomnienie jest zawsze własnym wpisem oglądającego, ale identyfikator
 * wpisu jest publiczny (widać go w linku), więc bez `authorize` dałoby się
 * schować komuś jego wpis z jego strony głównej. UUID w adresie to nie
 * autoryzacja (AGENTS.md §7).
 */
class WspomnienieController extends Controller
{
    public function ukryj(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $post->forceFill(['hide_as_memory' => true])->save();

        return back()->with(
            'status',
            'Nie pokażemy Ci już tego wspomnienia. Wpis zostaje w Twoim archiwum — nic nie zniknęło.',
        );
    }
}
