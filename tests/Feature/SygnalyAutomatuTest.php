<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Sygnaly\WykrywaczSygnalow;
use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WYKRYWACZ PODEJRZANYCH TREŚCI (D-052).
 *
 * DWA RODZAJE TESTÓW I TEN DRUGI JEST WAŻNIEJSZY
 * Testy „łapie" pilnują, żeby narzędzie w ogóle działało. Testy „NIE łapie"
 * pilnują, żeby nie zalało kolejki — a przy jednym moderatorze i fali osób
 * przechodzących do nas z Garnek.pl to jest jedyny sposób, w jaki to
 * narzędzie może naprawdę zaszkodzić.
 *
 * Przypadki fali migracyjnej mają w nazwach słowo `fala`, bo to jest ta
 * grupa, której ten wykrywacz ma NIE dotknąć: rejestracje w tym samym
 * tygodniu, archiwum wklejane hurtem, te same stare przepisy u kilku osób
 * i odnośniki do starego profilu w Garnku.
 *
 * TRZECI RODZAJ: GRANICA Z poz. 3.6
 * Oznaczenie ma być NIEWIDZIALNE poza kolejką moderatora. Treść zostaje na
 * miejscu dla autora i dla obcego, autor nie dostaje żadnego powiadomienia.
 * To nie jest szczegół implementacji — to jest cała treść tej decyzji.
 */
class SygnalyAutomatuTest extends TestCase
{
    use RefreshDatabase;

    /** Tekst dłuższy niż próg 40 znaków, żeby sygnał powtórzenia miał się o co oprzeć. */
    private const DLUGI = 'Rosół z kury zagrodowej, gotowany na wolnym ogniu przez cztery godziny, z korzeniem pietruszki.';

    private function osoba(string $login, int $konoZalozoneDniTemu = 400): User
    {
        $user = $this->user($login);
        $user->forceFill(['created_at' => now()->subDays($konoZalozoneDniTemu)])->save();

        return $user->refresh();
    }

    private function wpis(User $autor, string $tekst, ?CarbonInterface $kiedy = null, string $widocznosc = Post::VISIBILITY_PUBLIC): Post
    {
        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => $tekst,
            'visibility' => $widocznosc,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $kiedy ?? now(),
        ]);
    }

    private function komentarz(User $autor, Post $pod, string $tekst): Comment
    {
        return Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $pod->getKey(),
            'body' => $tekst,
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    private function analizuj(Post|Comment $tresc): void
    {
        dispatch_sync(new PrzeanalizujTresc(
            $tresc instanceof Post ? PrzeanalizujTresc::TYP_WPIS : PrzeanalizujTresc::TYP_KOMENTARZ,
            (string) $tresc->getKey(),
        ));
    }

    /** @return Collection<int, Report> */
    private function oznaczenia()
    {
        return Report::query()->where('source', Report::SOURCE_AUTOMAT)->get();
    }

    // ---------------------------------------------------------------
    // ŁAPIE
    // ---------------------------------------------------------------

    public function test_ta_sama_tresc_drugi_raz_pod_rzad_trafia_do_kolejki(): void
    {
        $autorka = $this->osoba('powtarzajaca');

        $pierwszy = $this->wpis($autorka, self::DLUGI, now()->subMinutes(4));
        $drugi = $this->wpis($autorka, self::DLUGI, now());

        $this->analizuj($pierwszy);
        $this->analizuj($drugi);

        $oznaczenia = $this->oznaczenia();

        $this->assertCount(1, $oznaczenia, 'Powtórzenie miało dać dokładnie jedno oznaczenie — na drugim wpisie.');
        $this->assertSame((string) $drugi->getKey(), $oznaczenia->first()->target_id);
        $this->assertSame(WykrywaczSygnalow::KOD_POWTORZENIE, $oznaczenia->first()->reason);

        // POWÓD MA BYĆ ZDANIEM PO POLSKU, NIE KODEM ANI LICZBĄ PUNKTÓW.
        // Moderator, który dostaje „score: 7.4", zgaduje, czego szukać.
        $this->assertStringContainsString(
            'w ciągu 4 minut',
            (string) $oznaczenia->first()->details,
            'Powód nie mówi człowiekowi, ile czasu minęło między jedną treścią a drugą.',
        );
    }

    public function test_odnosnik_w_pierwszym_wpisie_swiezego_konta_trafia_do_kolejki(): void
    {
        $nowa = $this->osoba('swieza', 2);

        $wpis = $this->wpis($nowa, 'Zapraszam serdecznie na https://mojsklepzgarnkami.example/oferta — same okazje.');
        $this->analizuj($wpis);

        $oznaczenia = $this->oznaczenia();

        $this->assertCount(1, $oznaczenia);
        $this->assertSame(WykrywaczSygnalow::KOD_ODNOSNIK, $oznaczenia->first()->reason);
        $this->assertStringContainsString('mojsklepzgarnkami.example', (string) $oznaczenia->first()->details);
        $this->assertStringContainsString('przed 2 dniami', (string) $oznaczenia->first()->details);
    }

    public function test_zarobki_z_domu_i_numer_telefonu_trafiaja_do_kolejki(): void
    {
        $autor = $this->osoba('ogloszeniodawca');

        $zarobki = $this->wpis($autor, 'Zarabiaj z domu nawet 8000 miesięcznie, napisz po szczegóły.');
        $telefon = $this->wpis($autor, 'Ciasta na zamówienie, tel. 600 100 200, odbiór osobisty.');

        $this->analizuj($zarobki);
        $this->analizuj($telefon);

        $oznaczenia = $this->oznaczenia();

        $this->assertCount(2, $oznaczenia);
        $this->assertSame(
            [WykrywaczSygnalow::KOD_WZORZEC, WykrywaczSygnalow::KOD_WZORZEC],
            $oznaczenia->pluck('reason')->all(),
        );
    }

    // ---------------------------------------------------------------
    // NIE ŁAPIE — TO JEST WAŻNIEJSZA POŁOWA
    // ---------------------------------------------------------------

    public function test_fala_z_garnka_wklejone_archiwum_nie_jest_sygnalem(): void
    {
        // Jedna osoba przenosi swoje przepisy z innego serwisu: kilkanaście
        // RÓŻNYCH tekstów w kilkanaście minut. To jest zachowanie DOCELOWE
        // tego produktu, a wygląda dokładnie jak seria wpisów spamera.
        $pani = $this->osoba('przenoszacaarchiwum', 1);

        $przepisy = [
            'Sernik wiedeński na kruchym spodzie, pieczony w kąpieli wodnej przez godzinę i kwadrans.',
            'Pierogi ruskie z twarogiem i ziemniakami, ciasto zagniatane na gorącej wodzie z olejem.',
            'Zupa ogórkowa na żeberkach, z kiszonymi ogórkami startymi na grubych oczkach tarki.',
            'Kotlety mielone z bułką namoczoną w mleku, smażone na klarowanym maśle na średnim ogniu.',
            'Kompot z suszu na Wigilię, gotowany z jabłkiem, gruszką, śliwką i odrobiną goździków.',
        ];

        foreach ($przepisy as $index => $tekst) {
            $this->analizuj($this->wpis($pani, $tekst, now()->subMinutes(30 - $index * 5)));
        }

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Automat oznaczył osobę przenoszącą własne archiwum — czyli dokładnie tę, dla której ten serwis powstał.',
        );
    }

    public function test_fala_z_garnka_ten_sam_przepis_u_pieciu_kont_nie_jest_sygnalem(): void
    {
        // Przepis krążący w kole gospodyń od trzydziestu lat wchodzi do
        // serwisu pięcioma drogami naraz, w tym samym tygodniu, bo cała
        // grupa przenosi się razem. Porównanie MIĘDZY kontami oznaczyłoby
        // tu pięć niewinnych osób jednym ruchem.
        $wspolnyPrzepis = 'Ciasto marchewkowe z orzechami włoskimi i cynamonem, pieczone w foremce keksowej.';

        foreach (['zofia', 'halina', 'krystyna', 'jadwiga', 'teresa'] as $login) {
            $this->analizuj($this->wpis($this->osoba($login, 3), $wspolnyPrzepis));
        }

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Ta sama treść u różnych kont zapaliła sygnał — to jest kształt całej fali migracyjnej, nie spamu.',
        );
    }

    public function test_fala_z_garnka_odnosnik_do_starego_profilu_nie_jest_sygnalem(): void
    {
        $pani = $this->osoba('zgarnka', 1);

        $wpis = $this->wpis($pani, 'Przenoszę się tutaj. Mój stary profil: https://garnek.pl/kuchnia-zofii');
        $this->analizuj($wpis);

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Odnośnik do serwisu, Z KTÓREGO ludzie do nas przychodzą, jest w pierwszym wpisie oczekiwany, nie podejrzany.',
        );
    }

    public function test_uprzejmosc_pod_dwoma_wpisami_nie_jest_sygnalem(): void
    {
        $gospodyni = $this->osoba('gospodyni');
        $mila = $this->osoba('mila');

        $pierwszy = $this->wpis($gospodyni, 'Dziś rosół.');
        $drugi = $this->wpis($gospodyni, 'A na kolację naleśniki.');

        // Ten sam krótki komplement pod dwoma wpisami z rzędu. To jest ruch,
        // który trzyma ten serwis przy życiu — a bez progu długości wyglądałby
        // dla automatu identycznie jak spam.
        $this->analizuj($this->komentarz($mila, $pierwszy, 'Wygląda pysznie!'));
        $this->analizuj($this->komentarz($mila, $drugi, 'Wygląda pysznie!'));

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Automat oznaczył osobę, która pod dwoma wpisami napisała to samo miłe zdanie.',
        );
    }

    public function test_przepis_z_temperaturami_nie_jest_numerem_telefonu(): void
    {
        $autor = $this->osoba('piekarz');

        // Trzy grupy po trzy cyfry rozdzielone spacjami — czyli dokładnie
        // kształt, który wyłapuje `GRUPA_DZIEWIECIU_CYFR`. Zapala sygnał
        // dopiero wtedy, gdy obok stoi słowo kontaktowe; w przepisie go nie ma.
        $wpis = $this->wpis($autor, 'Trzy etapy pieczenia: 220 200 180 stopni, po dwadzieścia minut każdy.');
        $this->analizuj($wpis);

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Temperatury z przepisu zostały wzięte za numer telefonu.',
        );
    }

    public function test_odnosnik_u_konta_z_dluzszym_stazem_nie_jest_sygnalem(): void
    {
        $stala = $this->osoba('bywalczyni', 60);

        $this->analizuj($this->wpis($stala, 'Przepis znalazłam tutaj: https://blogkulinarny.example/sernik i wyszedł świetnie.'));

        $this->assertCount(0, $this->oznaczenia(), 'Podanie źródła przepisu przez osobę z dłuższym stażem nie jest sygnałem.');
    }

    public function test_odnosnik_w_dalszej_tresci_swiezego_konta_nie_jest_sygnalem(): void
    {
        $nowa = $this->osoba('nowaalaczynna', 2);

        // Cztery wcześniejsze treści — próg to pierwsze trzy.
        foreach (range(1, 4) as $i) {
            $this->wpis($nowa, 'Wpis numer '.$i.' o zwykłym gotowaniu w tygodniu.', now()->subMinutes(200 - $i));
        }

        $this->analizuj($this->wpis($nowa, 'A przepis mam stąd: https://blogkulinarny.example/zupa'));

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Sygnał odnośnika ma dotyczyć PIERWSZYCH treści konta, nie każdej treści młodego konta.',
        );
    }

    // ---------------------------------------------------------------
    // GRANICA: OZNACZENIE JEST NIEWIDZIALNE POZA KOLEJKĄ (poz. 3.6)
    // ---------------------------------------------------------------

    public function test_oznaczona_tresc_jest_dalej_widoczna_dla_autora_i_dla_obcego(): void
    {
        $autor = $this->osoba('oznaczony');
        $obcy = $this->osoba('przechodzien');

        $wpis = $this->wpis($autor, 'Ciasta na zamówienie, tel. 600 100 200, odbiór osobisty w Rzeszowie.');
        $this->analizuj($wpis);

        $this->assertCount(1, $this->oznaczenia(), 'Test sprawdzałby widoczność treści, której automat wcale nie oznaczył.');

        // 1. Stan treści w bazie nie ruszony.
        $this->assertSame(Post::STATUS_PUBLISHED, $wpis->refresh()->status);
        $this->assertNull($wpis->deleted_at);
        $this->assertSame(Post::VISIBILITY_PUBLIC, $wpis->visibility);

        // 2. Autor widzi swój wpis.
        $this->actingAs($autor)->get($wpis->url())
            ->assertOk()
            ->assertSee('Ciasta na zamówienie');

        // 3. Obcy widzi ten sam wpis. To jest sedno poz. 3.16: żadnego cichego
        //    ograniczania zasięgu.
        $this->actingAs($obcy)->get($wpis->url())
            ->assertOk()
            ->assertSee('Ciasta na zamówienie');

        // 4. Gość niezalogowany też.
        $this->get($wpis->url())->assertOk()->assertSee('Ciasta na zamówienie');
    }

    public function test_autor_nie_dostaje_zadnego_powiadomienia_o_oznaczeniu(): void
    {
        $autor = $this->osoba('cichy');

        $this->analizuj($this->wpis($autor, 'Zarabiaj z domu, szczegóły na WhatsApp 600 100 200.'));

        $this->assertCount(1, $this->oznaczenia());
        $this->assertSame(
            0,
            Notification::query()->where('user_id', $autor->getKey())->count(),
            'Autor dostał powiadomienie o oznaczeniu — a z jego treścią nic się nie stało.',
        );
    }

    public function test_prywatny_wpis_nie_jest_analizowany(): void
    {
        $autor = $this->osoba('prywatna');

        $wpis = $this->wpis(
            $autor,
            'Zarabiaj z domu, szczegóły na WhatsApp 600 100 200.',
            null,
            Post::VISIBILITY_PRIVATE,
        );

        $this->analizuj($wpis);

        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Automat położył przed moderatorem treść, której autor świadomie nie pokazał nikomu.',
        );
    }

    // ---------------------------------------------------------------
    // KOLEJKA I DECYZJE
    // ---------------------------------------------------------------

    public function test_publikacja_nie_czeka_na_analize(): void
    {
        Queue::fake();

        $autor = $this->osoba('publikujaca');

        $this->actingAs($autor)
            ->post(route('posts.store'), [
                'body' => 'Zarabiaj z domu, tel. 600 100 200.',
                'visibility' => Post::VISIBILITY_PUBLIC,
            ])
            ->assertSessionHasNoErrors();

        // Zadanie POSZŁO DO KOLEJKI — i to do PRAWDZIWEJ, nie na połączenie
        // `sync`. Bez tego warunku test przechodziłby także dla
        // `dispatch_sync()`, które przy `Queue::fake()` też zapisuje się jako
        // „wysłane", a na produkcji liczyłoby sygnały w żądaniu HTTP.
        Queue::assertPushed(
            PrzeanalizujTresc::class,
            static fn (PrzeanalizujTresc $zadanie): bool => $zadanie->connection !== 'sync',
        );

        // …i NIE zostało wykonane w żądaniu. Gdyby kontroler liczył sygnały
        // synchronicznie, wiersz w `reports` już by tu stał — a treść, która
        // pasuje do wzorca, została użyta właśnie po to, żeby ta asercja nie
        // przechodziła przypadkiem.
        $this->assertCount(
            0,
            $this->oznaczenia(),
            'Sygnały policzyły się w żądaniu HTTP zamiast w kolejce.',
        );
    }

    public function test_odrzucenie_przez_moderatora_nie_wraca(): void
    {
        $moderator = $this->moderator();
        $autor = $this->osoba('podejrzany');

        $wpis = $this->wpis($autor, 'Ciasta na zamówienie, tel. 600 100 200.');
        $this->analizuj($wpis);

        $this->assertCount(1, $this->oznaczenia());

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey(), 'oznaczenia' => $this->oznaczeniaNaEkranie()])
            ->assertSessionHasNoErrors();

        $oznaczenie = $this->oznaczenia()->first();
        $this->assertSame(Report::STATUS_REJECTED, $oznaczenie->status);

        // Automat ogląda tę samą treść jeszcze raz — na przykład po ponownym
        // uruchomieniu zadania z kolejki albo po ponowieniu po awarii.
        $this->analizuj($wpis->refresh());

        $this->assertCount(
            1,
            $this->oznaczenia(),
            'Automat postawił drugie oznaczenie po tym, jak moderator powiedział „to nic takiego".',
        );
        $this->assertSame(Report::STATUS_REJECTED, $this->oznaczenia()->first()->status);
    }

    public function test_jedno_klikniecie_zamyka_cala_grupe_jednego_konta(): void
    {
        $moderator = $this->moderator();
        $spamer = $this->osoba('hurtownik');

        foreach (range(1, 3) as $i) {
            $this->analizuj($this->wpis($spamer, 'Ciasta na zamówienie numer '.$i.', tel. 600 100 20'.$i.'.'));
        }

        $this->assertCount(3, $this->oznaczenia());

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $spamer->getKey(), 'oznaczenia' => $this->oznaczeniaNaEkranie()])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('status', Report::STATUS_OPEN)
            ->count());

        // Log ma odpowiadać na pytanie „co się stało z TĄ treścią", więc
        // każde oznaczenie dostaje własny wiersz, a nie jeden na grupę.
        $this->assertSame(3, ModerationAction::query()
            ->where('action', ModerationAction::ACTION_NONE)
            ->count());

        // Autor nadal o niczym nie wie — `no_action` znaczy, że nic mu się
        // nie stało.
        $this->assertSame(0, Notification::query()->where('user_id', $spamer->getKey())->count());
    }

    public function test_oznaczenia_automatu_nie_zasypuja_kolejki_zgloszen_od_ludzi(): void
    {
        $moderator = $this->moderator();
        $spamer = $this->osoba('zasypujacy');

        foreach (range(1, 3) as $i) {
            $this->analizuj($this->wpis($spamer, 'Zarabiaj z domu, oferta numer '.$i.'.'));
        }

        $this->actingAs($moderator)->get(route('admin.reports'))
            ->assertOk()
            ->assertDontSee('Automat: znany wzorzec spamu');

        $this->actingAs($moderator)->get(route('admin.reports', ['zrodlo' => Report::SOURCE_AUTOMAT]))
            ->assertOk()
            ->assertSee('Automat: znany wzorzec spamu');

        $this->actingAs($moderator)->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee('Sygnały automatu')
            ->assertSee('3 oznaczeń');
    }

    public function test_kolejka_stawia_najciezszy_sygnal_na_gorze(): void
    {
        $moderator = $this->moderator();

        $lzejszy = $this->osoba('powtarzajacy');
        $this->wpis($lzejszy, self::DLUGI, now()->subMinutes(5));
        $this->analizuj($this->wpis($lzejszy, self::DLUGI, now()->subMinutes(1)));

        $ciezszy = $this->osoba('ogloszeniowy');
        $this->analizuj($this->wpis($ciezszy, 'Zarabiaj z domu, szczegóły na priv.'));

        $odpowiedz = $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $tresc = $odpowiedz->getContent();

        $this->assertLessThan(
            strpos($tresc, 'Automat: powtórzona treść'),
            strpos($tresc, 'Automat: znany wzorzec spamu'),
            'Cięższy sygnał ma stać wyżej — przy tysiącu kont kolejność decyduje o tym, czego moderator nie zdąży przejrzeć.',
        );
    }

    // ---------------------------------------------------------------
    // WYŁĄCZNIK I BAZA
    // ---------------------------------------------------------------

    public function test_jedna_zmienna_wylacza_caly_wykrywacz(): void
    {
        config(['kuking.moderation.sygnaly.wlaczone' => false]);

        $autor = $this->osoba('nieanalizowany');
        $this->analizuj($this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 200.'));

        $this->assertCount(0, $this->oznaczenia(), 'KUKING_SYGNALY_AUTOMATU=false nie zatrzymało analizy.');
    }

    public function test_baza_nie_pozwala_na_dwa_oznaczenia_tej_samej_tresci(): void
    {
        $autor = $this->osoba('jedyny');
        $wpis = $this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 200.');

        $this->analizuj($wpis);
        $this->assertCount(1, $this->oznaczenia());

        // Obietnica „jedno oznaczenie na treść" ma pokrycie w INDEKSIE, a nie
        // tylko w `SELECT`-cie przed `INSERT`-em: dwa równoległe zadania
        // z kolejki widziałyby „nie ma oznaczenia" jednocześnie.
        $this->expectException(QueryException::class);

        Report::create([
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => WykrywaczSygnalow::KOD_WZORZEC,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_uzasadnienie_nie_mowi_ze_ktos_zglosil_gdy_wskazal_automat(): void
    {
        $moderator = $this->moderator();
        $autor = $this->osoba('ukrywany');

        $wpis = $this->wpis($autor, 'Zarabiaj z domu, tel. 600 100 200.');
        $this->analizuj($wpis);

        $oznaczenie = $this->oznaczenia()->first();

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $oznaczenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam-reklama',
            ])
            ->assertSessionHasNoErrors();

        $powiadomienie = Notification::query()->where('user_id', $autor->getKey())->firstOrFail();
        $zdania = implode(' ', UzasadnienieDecyzji::zdania(
            ModerationAction::query()->where('report_id', $oznaczenie->getKey())->firstOrFail(),
        ));

        $this->assertStringNotContainsString(
            'od innej osoby',
            $zdania,
            'Uzasadnienie każe autorowi szukać zgłaszającego, którego nigdy nie było (DSA art. 17 ust. 3 lit. b).',
        );
        $this->assertStringContainsString('Nikt tego nie zgłosił', $zdania);
        $this->assertStringContainsString('narzędzie do wychwytywania spamu', $zdania);

        // KONTROLA: powiadomienie naprawdę powstało, więc test nie sprawdza pustki.
        $this->assertSame(Notification::TYPE_MODERATION, $powiadomienie->type);
    }

    /**
     * Identyfikatory otwartych oznaczeń automatu — to, co formularz grupy
     * niesie z ekranu (#1059). Przysłana lista tylko ogranicza zakres, więc
     * oznaczenia innych grup w niej nie szkodzą.
     *
     * @return list<string>
     */
    private function oznaczeniaNaEkranie(): array
    {
        return Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
