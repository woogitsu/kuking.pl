<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Http\Controllers\Admin\ModerationController;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * KANDYDAT (audyt zewnętrzny, grupa 2): `reports.status` zmieniany bez
 * blokady — dwie karty tego samego moderatora (albo dwóch moderatorów)
 * decydują o tym samym zgłoszeniu niemal jednocześnie.
 *
 * KOD JUŻ TWIERDZI, ŻE TO NAPRAWIONO (audyt W3-09/W6-01, komentarz w
 * `RozstrzygnijZgloszenie`, dawniej `ModerationController::decide()`): `lockForUpdate()` + ponowne sprawdzenie
 * statusu POD BLOKADĄ. Ten test nie czyta komentarza na wiarę — MIERZY
 * dokładnie ten przeplot, o którym komentarz mówi, że go zamyka.
 *
 * DLACZEGO NIE PRZEZ HTTP DWA RAZY POD RZĄD
 * Zwykłe dwa kolejne żądania HTTP nie odtwarzają wyścigu: wiązanie modelu
 * trasy (`Report $report`) dla DRUGIEGO żądania i tak czyta ŚWIEŻY wiersz
 * z bazy, więc trafia w tani warunek na początku metody i nigdy nie dociera
 * do `lockForUpdate()`. Prawdziwy wyścig to sytuacja, w której OBA żądania
 * związały swój model, gdy zgłoszenie było jeszcze `open` — a to wymaga
 * przekazania do drugiego wywołania NIEODŚWIEŻONEGO obiektu `Report`,
 * dokładnie tak, jak zrobiłyby to dwa równoległe żądania HTTP.
 */
class ModerationDecideRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwie_niemal_jednoczesne_decyzje_nie_tworza_dwoch_wpisow(): void
    {
        $moderator = $this->moderator();
        Auth::login($moderator);
        $autor = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        $report = Report::create([
            'reporter_id' => $this->user('zglaszajacy')->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        // Dwie "karty" moderatora związały swój model, gdy zgłoszenie było
        // jeszcze `open` — dokładnie ten stan, w jakim znajdowałyby się dwa
        // niemal jednoczesne żądania HTTP w chwili wejścia do kontrolera.
        $widokKartyA = $report->fresh();
        $widokKartyB = $report->fresh();

        $daneA = ['action' => 'hide', 'reason_code' => 'inne', 'note' => 'Karta A'];
        $daneB = ['action' => 'remove', 'reason_code' => 'inne', 'note' => 'Karta B'];

        $controller = app(ModerationController::class);

        // KARTA A zapisuje decyzję jako pierwsza i commituje.
        $controller->decide($this->zadanie($moderator, $daneA, $widokKartyA), $widokKartyA);

        $this->assertSame(1, ModerationAction::query()->count());
        $this->assertSame(Report::STATUS_RESOLVED, $report->fresh()->status);

        // KARTA B dociera do `lockForUpdate()` z NIEODŚWIEŻONYM obiektem
        // ($widokKartyB->status wciąż pokazuje "open" w pamięci PHP) —
        // dokładnie ten scenariusz, o którym mówi komentarz klasy.
        $odpowiedzB = $controller->decide($this->zadanie($moderator, $daneB, $widokKartyB), $widokKartyB);

        // NIE WOLNO powstać drugiemu wpisowi w logu moderacji — to jest
        // istota naprawy W3-09/W6-01.
        $this->assertSame(1, ModerationAction::query()->count(), 'Druga, spóźniona decyzja nie ma prawa utworzyć drugiego wpisu w logu moderacji.');

        // Zgłoszenie zostaje przy decyzji KARTY A — druga nie ma prawa go nadpisać.
        $swiezy = $report->fresh();
        $this->assertSame(Report::STATUS_RESOLVED, $swiezy->status);
        $this->assertSame('Karta A', $swiezy->resolution_note, 'Decyzja Karty B nie ma prawa nadpisać decyzji Karty A.');

        // Karta B ma dostać czytelną odpowiedź "już rozstrzygnięte", nie 500.
        $this->assertTrue($odpowiedzB->getSession()->get('errors')->has('action'));
    }

    /**
     * Żądanie przechodzi przez `DecyzjaModeracyjnaRequest` tak jak na trasie
     * (#970, krok 2), ale z modelem, który „karta" związała wcześniej — stąd
     * ręczne podpięcie trasy zamiast wiązania z bazy.
     *
     * @param  array<string, string>  $dane
     */
    private function zadanie(User $moderator, array $dane, Report $widokKarty): DecyzjaModeracyjnaRequest
    {
        $request = DecyzjaModeracyjnaRequest::create('/admin/zgloszenia/x', 'POST', $dane);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $moderator);
        $route = (new Route('POST', '/admin/zgloszenia/{report}', []))->bind($request);
        $route->setParameter('report', $widokKarty);
        $request->setRouteResolver(fn () => $route);
        $request->validateResolved();

        return $request;
    }
}
