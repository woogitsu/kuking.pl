<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/powiadomienia` — wachlarz zapytań (N+1) na uzasadnieniach decyzji
 * moderacyjnych.
 *
 * CO BYŁO ZEPSUTE
 * `NotificationController::decyzje()` brało wiersze `moderation_actions`
 * JEDNYM zapytaniem na całą stronę i miało o tym komentarz mówiący wprost,
 * że chodzi o uniknięcie N+1. To była prawda o jednej warstwie i nieprawda
 * o całości: `UzasadnienieDecyzji::skadSprawa()` — wołane z widoku dla
 * każdego takiego powiadomienia — pyta `$decyzja->report?->wykrylAutomat()`
 * oraz `$decyzja->report?->reason`, bo bez tego nie da się napisać prawdy
 * wymaganej przez DSA art. 17 ust. 3 lit. b i c (czy sprawę zaczęło czyjeś
 * zgłoszenie, czy wskazał ją automat, i który). Relacja `report` nie była
 * doładowana, więc każde powiadomienie moderacyjne dokładało własne
 * `select * from reports where "id" = ?`.
 *
 * Strona mieści trzydzieści powiadomień, więc kosztowało to do trzydziestu
 * zapytań ponad plan — na ekranie, na który człowiek wchodzi najczęściej
 * ze wszystkich zalogowanych.
 *
 * METODA (ta sama co w `MiniaturyBezWachlarzaZapytanTest`): nie „ile zapytań
 * wypada", tylko „czy liczba ROŚNIE z liczbą wierszy". Próg na sztywno
 * zestarzałby się przy pierwszej uzasadnionej zmianie tego ekranu.
 *
 * ZMIERZONE PRZED POPRAWKĄ: 9 zapytań przy 2 powiadomieniach, 27 przy 20.
 * PO POPRAWCE: 8 i 8.
 */
class PowiadomieniaBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    /** Ile powiadomień mieści strona — `NotificationController::index()`. */
    private const PELNA_STRONA = 20;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_lista_powiadomien_nie_ma_wachlarza_zapytan_na_decyzje_moderacyjne(): void
    {
        $adresat = $this->user('adresatka');
        $moderator = $this->moderator();

        // MAŁO: dwa powiadomienia moderacyjne, każde o innej sprawie.
        $this->decyzjeZPowiadomieniami($adresat, $moderator, 2);
        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        // DUŻO: pełna strona. Zbioru NIE czyścimy — o wzrost właśnie chodzi,
        // a każde żądanie jest osobne, więc druga próba nie dolicza niczego
        // z pierwszej.
        $this->decyzjeZPowiadomieniami($adresat, $moderator, self::PELNA_STRONA - 2);

        $html = $this->actingAs($adresat)->get(route('notifications.index'))->assertOk()->getContent();

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): stała liczba zapytań
        // przechodziłaby także wtedy, gdyby uzasadnienie w ogóle przestało
        // się renderować — a wtedy nikt nie sięgałby po `report` i „N+1 by
        // nie było", bo nie byłoby ekranu. Tego zdania nie da się napisać
        // bez wiersza zgłoszenia, więc jego obecność dowodzi, że relacja,
        // o którą chodzi w pomiarze, naprawdę jest czytana.
        $this->assertStringContainsString(
            'Sprawa zaczęła się od zgłoszenia, które dostaliśmy od innej osoby.',
            $html,
            'Na ekranie nie ma uzasadnienia decyzji — pomiar niżej nie mierzy tego, o czym mówi.',
        );

        $this->assertSame(
            self::PELNA_STRONA,
            $adresat->notifications()->count(),
            'asercja kontrolna: powiadomień musi naprawdę być tyle, ile mierzymy',
        );

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą powiadomień moderacyjnych (N+1): {$maloZapytan} przy 2, "
            ."{$duzoZapytan} przy ".self::PELNA_STRONA.'. Zgłoszenie jest dociągane osobno dla każdego uzasadnienia.',
        );
    }

    /**
     * Sprawy od AUTOMATU — druga gałąź `skadSprawa()`, i psuje się osobno.
     *
     * Gałąź dla zgłoszenia od człowieka czyta z wiersza `reports` samo
     * `source`; gałąź automatu czyta jeszcze `reason`, żeby napisać, KTÓRE
     * narzędzie wskazało treść. Gdyby ktoś kiedyś zawęził tu kolumny
     * (`report:id,source`), pierwszy test w tym pliku przeszedłby bez
     * mrugnięcia, a człowiek dostałby zdanie o narzędziu do wychwytywania
     * spamu pod decyzją o zdjęciu ocenionym przez model (issue #368 —
     * kolumna spoza listy wraca jako `null`, bez błędu i bez śladu).
     */
    public function test_sprawy_wskazane_przez_automat_tez_nie_daja_wachlarza_zapytan(): void
    {
        $adresat = $this->user('adresat_automat');
        $moderator = $this->moderator();

        $this->decyzjeZPowiadomieniami($adresat, $moderator, 2, automat: true);
        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        $this->decyzjeZPowiadomieniami($adresat, $moderator, self::PELNA_STRONA - 2, automat: true);

        $html = $this->actingAs($adresat)->get(route('notifications.index'))->assertOk()->getContent();

        // Kontrola dodatnia dla TEJ gałęzi: zdanie o automacie brzmi inaczej
        // niż zdanie o zgłoszeniu od człowieka i bierze się z kolumny
        // `reports.reason`, nie z samego `source`.
        $this->assertStringContainsString(
            'narzędzie, które maszynowo ocenia publikowane treści i zdjęcia',
            $html,
            'Uzasadnienie nie mówi, które narzędzie wskazało treść — ta gałąź `skadSprawa()` nie została wykonana.',
        );

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($adresat)->get(route('notifications.index'))->assertOk(),
        );

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Sprawy od automatu: {$maloZapytan} zapytań przy 2 powiadomieniach, {$duzoZapytan} przy "
            .self::PELNA_STRONA.'.',
        );
    }

    /**
     * `$ile` spraw zakończonych decyzją moderacyjną, każda z własnym
     * zgłoszeniem i własnym powiadomieniem dla `$adresat`.
     *
     * Osobne zgłoszenie dla każdej sprawy jest wymogiem schematu (indeks
     * `moderation_actions_one_per_report` dopuszcza jedną decyzję na
     * zgłoszenie), ale ma tu też znaczenie dla pomiaru: dwadzieścia decyzji
     * wskazujących JEDNO zgłoszenie dałoby jedno dociągnięcie na całą
     * stronę, bo Eloquent trzyma już wczytany model w pamięci — czyli test
     * nie wykryłby usterki, której pilnuje.
     */
    private function decyzjeZPowiadomieniami(User $adresat, User $moderator, int $ile, bool $automat = false): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $wpis = Post::factory()->for($adresat, 'author')->create();

            $zgloszenie = Report::create(array_merge([
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'subject_user_id' => $adresat->getKey(),
                'status' => Report::STATUS_RESOLVED,
            ], $automat ? [
                'source' => Report::SOURCE_AUTOMAT,
                'autor_tresci_id' => $adresat->getKey(),
                'reason' => OcenaModelem::KOD,
            ] : [
                'source' => Report::SOURCE_COMMUNITY,
                'reporter_id' => $this->user()->getKey(),
                'reason' => 'spam',
            ]));

            $decyzja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'report_id' => $zgloszenie->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ]);

            $adresat->notifications()->create([
                'type' => Notification::TYPE_MODERATION,
                'actor_id' => $moderator->getKey(),
                'data' => ['action_id' => (string) $decyzja->getKey()],
            ]);
        }
    }
}
