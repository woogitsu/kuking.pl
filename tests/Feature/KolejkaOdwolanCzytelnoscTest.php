<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CZYTELNOŚĆ KOLEJKI ODWOŁAŃ — `/admin/odwolania`
 * (zgłoszenie właściciela z 10 września, ze zrzutu z produkcji).
 *
 * DWIE USTERKI ZE ZRZUTU, KTÓRE TE TESTY PILNUJĄ NA ZAWSZE:
 *
 *  1. „powód: tresci-dla-doroslych" — surowy kod z bazy pokazany
 *     człowiekowi. Polska nazwa („Nagość albo przemoc (punkt 5)") leżała
 *     w `PodstawaDecyzji` od początku i szła już w tej postaci do autora
 *     treści; brakowało jej tylko na ekranie moderatora. Panel jest ekranem
 *     roboczym, ale kod z bazy nie staje się przez to czytelny — żeby go
 *     zrozumieć, trzeba znać schemat tabeli.
 *
 *  2. „decyzję podjął(-ęła) Mateusz" — konstrukcja zakładająca rodzaj.
 *     Ukośnika ani nawiasu z końcówką nie da się przeczytać na głos
 *     (AGENTS.md §11, `docs/brand/COPY_STYLE.md` §2). PR #235 usunął
 *     jedenaście takich miejsc z serwisu i tego jednego nie objął.
 *
 * Do tego asercja na HIERARCHIĘ: karta ma mieć cytat odróżniony od
 * nagłówków i formularz odcięty od czytania. To jedyna rzecz z tego zrzutu,
 * której nie da się sprawdzić inaczej niż po znaczniku — gdyby ktoś wrócił
 * do ośmiu bloków jednej wagi, ten test padnie.
 */
class KolejkaOdwolanCzytelnoscTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Appeal} */
    private function odwolanieZDecyzja(string $kodPowodu = 'tresci-dla-doroslych'): array
    {
        $admin = $this->admin();
        $autor = $this->user('basia');

        $decyzja = ModerationAction::create([
            'moderator_id' => $admin->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => $kodPowodu,
            'user_message' => 'Ukryliśmy ten wpis, bo zdjęcie pokazuje więcej, niż trzeba.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Na zdjęciu jest schab w panierce, a nie nic innego. Proszę o ponowne obejrzenie.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        return [$admin, $odwolanie];
    }

    public function test_surowy_kod_powodu_nie_pojawia_sie_na_ekranie(): void
    {
        [$admin] = $this->odwolanieZDecyzja('tresci-dla-doroslych');

        $odpowiedz = $this->actingAs($admin)->get(route('admin.appeals'))->assertOk();

        $odpowiedz->assertDontSee('tresci-dla-doroslych');
        $odpowiedz->assertSee('Nagość albo przemoc (punkt 5)');
    }

    public function test_kod_powodu_zapisany_jako_powod_zgloszenia_tez_dostaje_polska_nazwe(): void
    {
        // Decyzje sprzed wprowadzenia słownika mają w `reason_code` klucz
        // z `Report::REASONS` (`sexual`, `harassment`, `copyright`) —
        // `PodstawaDecyzji::SYNONIMY` odwzorowuje je na podstawę, więc i one
        // nie mają prawa pokazać kodu.
        [$admin] = $this->odwolanieZDecyzja('sexual');

        $this->actingAs($admin)->get(route('admin.appeals'))->assertOk()
            ->assertDontSee('powód: sexual')
            ->assertSee('Nagość albo przemoc (punkt 5)');
    }

    public function test_kod_techniczny_serwisu_tez_ma_polska_nazwe(): void
    {
        // `appeal_overturned` wpisuje sam serwis przy cofnięciu decyzji
        // (`ResolveAppeal`) i nie jest podstawą z listy — bez własnej
        // etykiety wyszedłby na ekran surowy.
        [$admin] = $this->odwolanieZDecyzja('appeal_overturned');

        $this->actingAs($admin)->get(route('admin.appeals'))->assertOk()
            ->assertDontSee('appeal_overturned')
            ->assertSee('Cofnięcie decyzji po odwołaniu');
    }

    public function test_ekran_nie_zaklada_rodzaju_osoby_ukosnikiem_ani_nawiasem(): void
    {
        [$admin] = $this->odwolanieZDecyzja();

        $tresc = (string) $this->actingAs($admin)->get(route('admin.appeals'))->assertOk()->getContent();

        // Dokładnie ta konstrukcja ze zrzutu.
        $this->assertStringNotContainsString('podjął(-ęła)', $tresc);

        // I szerzej: żadnej końcówki rodzajowej w nawiasie ani po ukośniku
        // — „podjął(-ęła)", „ugotowała/ugotował", „dostałeś/aś".
        $this->assertDoesNotMatchRegularExpression(
            '/\p{L}+(\(-?[ła]\p{L}*\)|\/(a|aś|as)\b)/u',
            $tresc,
            'Konstrukcji zakładającej rodzaj nie da się przeczytać na głos (COPY_STYLE §2).',
        );

        // Informacja nie zniknęła razem z konstrukcją — nazwa osoby, która
        // wydała decyzję, jest moderatorowi potrzebna (karencja na
        // podtrzymanie własnej decyzji, `ResolveAppeal`).
        $this->assertStringContainsString('decyzję podjęto', $tresc);
    }

    public function test_karta_ma_hierarchie_zamiast_osmiu_blokow_jednej_wagi(): void
    {
        [$admin] = $this->odwolanieZDecyzja();

        $tresc = (string) $this->actingAs($admin)->get(route('admin.appeals'))->assertOk()->getContent();

        // Treść, którą się CZYTA, jest cytatem — nie kolejnym akapitem.
        $this->assertStringContainsString('odwolanie-cytat', $tresc);
        // Formularz jest odcięty od czytania.
        $this->assertStringContainsString('odwolanie-odpowiedz', $tresc);
        // Nagłówki sekcji są etykietami bloku, nie czwartym tytułem karty.
        $this->assertStringContainsString('odwolanie-etykieta', $tresc);

        // Tytuł karty zostaje JEDYNYM napisem w rozmiarze tytułu: „Co pisze
        // ta osoba" nie ma już prawa stać na `text-title-sm`.
        $this->assertDoesNotMatchRegularExpression(
            '/<h3[^>]*text-title-sm/u',
            $tresc,
            'Nagłówek sekcji w rozmiarze tytułu karty to dokładnie to zlewanie się tekstu, '
            .'które ta zmiana naprawia.',
        );
    }
}
