<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * ZMIANA STATUSU WŁAŚCICIELA MA ZAWĘŻAĆ DOSTĘP, NIGDY GO ROZSZERZAĆ
 * (issue #1092, ta sama rodzina co P0 #941 — prywatność).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZEPSUTE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `CollectionPolicy::view()` sprawdzała status właściciela PRZED flagą
 * widoczności zeszytu:
 *
 *     if (! $collection->owner->jestDostepnyJakoAutor()) {
 *         return $user !== null && $user->isModerator();   // ← i koniec
 *     }
 *     ...
 *     return $collection->isPublic();                      // ← nigdy nie doszło
 *
 * Ta gałąź zwracała `isModerator()` dla KAŻDEGO zeszytu — także prywatnego,
 * bo o widoczność nikt jeszcze nie zapytał. Skutek jest dokładnie odwrotny
 * do zamierzonego:
 *
 *   * konto AKTYWNE  → moderator NIE widzi prywatnego zeszytu (403),
 *   * konto ZBANOWANE → moderator WIDZI ten sam prywatny zeszyt (200).
 *
 * Czyli zbanowanie właściciela — albo samo oznaczenie konta do kasacji
 * (`pending_delete`, stan, w który wchodzi się WŁASNYM kliknięciem „usuń
 * konto") — OTWIERAŁO jego prywatny zeszyt komuś, kto przedtem go nie
 * widział. Kara i procedura kasacji konta działały jak przyznanie
 * uprawnienia.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO POMIAR IDZIE W OBIE STRONY
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `docs/PULAPKI_TESTOW.md` pułapka 4: polityka, która odmawia WSZYSTKIM,
 * przechodzi każdy test złożony z samych odmów. Naprawa „prywatny zeszyt
 * zbanowanego jest zamknięty" da się zrobić jedną linijką `return false`
 * na górze `view()` — i wtedy znika też publiczny zeszyt każdej aktywnej
 * osoby, a moderator przestaje widzieć cokolwiek. Dlatego KAŻDEMU
 * przypadkowi odmowy towarzyszy tu przypadek wejścia, którego ta sama
 * poprawka nie ma prawa ruszyć.
 *
 * Trasa `collections.show` ma też wiersz w
 * `KazdaTrasaZIdentyfikatoremPodPolicyTest` (AGENTS.md §7, „UUID w adresie
 * NIE JEST autoryzacją") — dopisane tam wiersze mierzą to samo żądaniem
 * HTTP przez pełny stos.
 */
class StatusWlascicielaNieOtwieraPrywatnegoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEDNO #1092, kierunek „nie wyciekać".
     */
    public function test_zbanowanie_wlasciciela_nie_otwiera_moderatorowi_jego_prywatnego_zeszytu(): void
    {
        $wlasciciel = $this->user('wlascicielprywatny');
        $moderator = $this->moderator();

        $prywatny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Prywatny zeszyt do siebie',
            'visibility' => 'private',
        ]);

        // KONTROLA PRZED ZMIANĄ STANU: przy koncie aktywnym moderator tego
        // zeszytu nie widzi. To jest punkt odniesienia — bez niego nie da
        // się powiedzieć, czy zbanowanie cokolwiek ZMIENIŁO.
        $this->assertFalse(
            Gate::forUser($moderator)->allows('view', $prywatny),
            'Moderator widział prywatny zeszyt AKTYWNEJ osoby — wtedy ten test '
            .'mierzy coś innego niż wpływ statusu.',
        );

        $wlasciciel->ban();
        $prywatny->refresh()->load('owner');

        $this->assertFalse(
            Gate::forUser($moderator)->allows('view', $prywatny),
            'Zbanowanie właściciela OTWORZYŁO moderatorowi jego prywatny zeszyt. '
            .'Zmiana statusu rozszerzyła dostęp zamiast go zawęzić (#1092).',
        );

        // Ten sam pomiar przez pełny stos HTTP — Policy bywa obchodzona
        // przez kontroler, który pyta o coś innego.
        $this->actingAs($moderator)
            ->get(route('collections.show', $prywatny))
            ->assertForbidden();
    }

    /**
     * `pending_delete` to NIE jest kara — w ten stan konto wchodzi własnym
     * kliknięciem „usuwam konto". Tym bardziej nie ma prawa niczego otwierać.
     */
    public function test_oznaczenie_konta_do_kasacji_nie_otwiera_prywatnego_zeszytu(): void
    {
        $wlasciciel = $this->user('kasujeprywatny');
        $moderator = $this->moderator();

        $prywatny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Zeszyt przed kasacją konta',
            'visibility' => 'private',
        ]);

        $wlasciciel->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $prywatny->refresh()->load('owner');

        $this->assertFalse(
            Gate::forUser($moderator)->allows('view', $prywatny),
            '`pending_delete` otworzyło moderatorowi prywatny zeszyt (#1092).',
        );

        $this->actingAs($moderator)
            ->get(route('collections.show', $prywatny))
            ->assertForbidden();
    }

    /**
     * Obcy i gość nie wchodzą ani przed, ani po zmianie statusu — tu nic
     * się nie zmieniło i ma się nie zmienić.
     */
    public function test_obcy_i_gosc_nie_wchodza_na_prywatny_zeszyt_zbanowanego(): void
    {
        $wlasciciel = $this->user('wlascicielobcy');
        $obcy = $this->user('obcypatrzacy');

        $prywatny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Prywatny zeszyt',
            'visibility' => 'private',
        ]);

        $wlasciciel->ban();
        $prywatny->refresh()->load('owner');

        $this->assertFalse(Gate::forUser($obcy)->allows('view', $prywatny));
        $this->assertFalse(Gate::forUser(null)->allows('view', $prywatny));
    }

    /**
     * KONTROLA DODATNIA #1 — MODERATOR DALEJ WIDZI TO, CO MA WIDZIEĆ.
     *
     * Publiczny zeszyt zbanowanej osoby znika wszystkim POZA moderacją
     * (`ZeszytOsobyZbanowanejNieJestDostepnyTest`, ta sama reguła co
     * `UserPolicy::viewProfile()`). Gdyby poprawka #1092 zamknęła tę
     * ścieżkę, moderator nie miałby jak ocenić treści, za którą zbanował
     * konto — wyciek byłby naprawiony odmową dla wszystkich.
     */
    public function test_moderator_dalej_widzi_publiczny_zeszyt_zbanowanej_osoby(): void
    {
        $wlasciciel = $this->user('wlascicielpubliczny');
        $moderator = $this->moderator();
        $obcy = $this->user('obcypubliczny');

        $publiczny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Publiczny zeszyt do oceny',
            'visibility' => 'public',
        ]);

        $wlasciciel->ban();
        $publiczny->refresh()->load('owner');

        $this->assertTrue(
            Gate::forUser($moderator)->allows('view', $publiczny),
            'Moderator przestał widzieć PUBLICZNY zeszyt zbanowanej osoby — '
            .'poprawka #1092 odcięła moderację od treści, którą ma oceniać.',
        );

        $this->actingAs($moderator)
            ->get(route('collections.show', $publiczny))
            ->assertOk()
            ->assertSee('Publiczny zeszyt do oceny');

        // Druga strona tej samej reguły: dla obcego ten sam zeszyt zniknął.
        $this->assertFalse(Gate::forUser($obcy)->allows('view', $publiczny));
    }

    /**
     * KONTROLA DODATNIA #2 — SERWIS DALEJ DZIAŁA DLA ZWYKŁYCH LUDZI.
     *
     * Publiczny zeszyt aktywnej osoby widzi każdy, także gość; właściciel
     * widzi swój prywatny. Bez tych dwóch asercji cały plik przechodziłby
     * przy `view()` zwracającym `false` bezwarunkowo.
     */
    public function test_zwykla_widocznosc_zostaje_nietknieta(): void
    {
        $wlasciciel = $this->user('aktywnyzwykly');
        $obcy = $this->user('obcyzwykly');

        $publiczny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Publiczny zeszyt aktywnej osoby',
            'visibility' => 'public',
        ]);
        $prywatny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Prywatny zeszyt aktywnej osoby',
            'visibility' => 'private',
        ]);

        $this->assertTrue(Gate::forUser($obcy)->allows('view', $publiczny),
            'Obcy przestał widzieć publiczny zeszyt aktywnej osoby.');
        $this->assertTrue(Gate::forUser(null)->allows('view', $publiczny),
            'Gość przestał widzieć publiczny zeszyt aktywnej osoby.');
        $this->assertTrue(Gate::forUser($wlasciciel)->allows('view', $prywatny),
            'Właściciel przestał widzieć własny prywatny zeszyt.');

        $this->actingAs($obcy)
            ->get(route('collections.show', $publiczny))
            ->assertOk();
        $this->actingAs($wlasciciel)
            ->get(route('collections.show', $prywatny))
            ->assertOk();
    }

    /**
     * KONTROLA DODATNIA #3 — WŁAŚCICIEL NIE TRACI WŁASNEGO ZESZYTU PRZEZ
     * WŁASNY STATUS.
     *
     * Bramka właściciela stoi w `view()` NAJWYŻEJ, przed wszystkim innym.
     * Osoba, która kliknęła „usuwam konto", ma w okresie karencji dojść do
     * swoich danych — inaczej rozmyślenie się byłoby niewykonalne.
     */
    public function test_wlasciciel_w_trakcie_kasacji_konta_dalej_widzi_swoj_prywatny_zeszyt(): void
    {
        $wlasciciel = $this->user('kasujesiesam');

        $prywatny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Mój zeszyt',
            'visibility' => 'private',
        ]);

        $wlasciciel->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $prywatny->refresh()->load('owner');

        $this->assertTrue(
            Gate::forUser($wlasciciel->refresh())->allows('view', $prywatny),
            'Właściciel stracił dostęp do własnego prywatnego zeszytu przez własny status.',
        );
    }

    /**
     * BLOKADA DALEJ WYCINA ZABLOKOWANEGO Z PUBLICZNEGO ZESZYTU (issue #41).
     *
     * Warunek blokady stoi po poprawce NIŻEJ niż flaga widoczności —
     * ten test pilnuje, że przy okazji przenoszenia nie wypadł z drogi.
     */
    public function test_blokada_dalej_wycina_zablokowanego_z_publicznego_zeszytu(): void
    {
        $wlasciciel = $this->user('blokujacy');
        $zablokowany = $this->user('zablokowanyktos');

        $wlasciciel->blocking()->attach($zablokowany->getKey());

        $publiczny = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Publiczny zeszyt blokującego',
            'visibility' => 'public',
        ]);

        $this->assertFalse(Gate::forUser($zablokowany)->allows('view', $publiczny),
            'Blokada przestała działać na zeszycie (#41).');
    }
}
