<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\DziennikWymazan;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Próba odtworzenia kopii z rejestrem wykonanych usunięć (#2708, pytanie 8
 * analizy prawnej z 2.10.2026: „po odtworzeniu kopii najpierw stosujemy
 * rejestr usunięć, a dopiero potem uruchamiamy serwis”).
 *
 * `DziennikWymazanPozaBazaTest` sprawdza, że konto wraca do `erased`.
 * Ten plik pyta o to, co widzi człowiek i co zostaje w bazie:
 *
 *  1. „kopia” zawiera KOMPLET danych konta (konto, profil, wpis, przepis,
 *     komentarz w cudzym wątku, wykonanie, sesję, gotową paczkę danych,
 *     token resetu hasła);
 *  2. konto zostaje wymazane (stan docelowy — odpowiedzi serwisu i wiersze
 *     są zapamiętane);
 *  3. „odtworzenie” wstawia wiersze z kopii — kontrola dodatnia: serwis
 *     znów pokazuje prawdziwe nazwisko i treści, więc bez następnego kroku
 *     test by oblał;
 *  4. `kuking:wymaz-ponownie` — odpowiedzi serwisu i wiersze wracają do
 *     stanu docelowego, a konto kontrolne i konto założone PO kopii nie są
 *     ruszane.
 */
class OdtworzenieKopiiZRejestremUsunietychKontTest extends TestCase
{
    use RefreshDatabase;

    private const NAZWISKO = 'Barbara Wisniewska';

    private const EMAIL = 'basia.odtworzona@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.dziennik_wymazan.dysk' => 'dziennik_test']);
        config(['filesystems.disks.dziennik_test' => ['driver' => 'local', 'root' => storage_path('framework/testing/dziennik')]]);
        Storage::fake('dziennik_test');
        config(['session.driver' => 'database']);
    }

    /**
     * @return array{basia: User, halina: User, wpis: Post, przepis: Recipe, komentarz: Comment, wykonanie: CookedEvent, wpisKontrolny: Post, cudzyWpis: Post, kopia: array<string, list<array<string, mixed>>>}
     */
    private function scenaZKopia(): array
    {
        $basia = $this->user('basia', ['display_name' => self::NAZWISKO, 'email' => self::EMAIL]);
        $halina = $this->user('halina', ['display_name' => 'Halina Kontrolna', 'email' => 'halina.kontrolna@example.com']);
        $ktos = $this->user('ktos', ['display_name' => 'Ktos Trzeci']);

        $wpis = Post::factory()->for($basia, 'author')->create(['body' => 'Wpis Basi o rosole babci Zofii.']);
        $wpisKontrolny = Post::factory()->for($halina, 'author')->create(['body' => 'Kontrolny wpis Haliny o bigosie.']);
        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosol babci Zofii']);
        $cudzyWpis = Post::factory()->for($ktos, 'author')->create(['body' => 'Wpis osoby trzeciej.']);
        $komentarz = Comment::create([
            'author_id' => $basia->getKey(),
            'post_id' => $cudzyWpis->getKey(),
            'body' => 'Komentarz Basi w cudzym watku.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $wykonanie = CookedEvent::factory()->create(['user_id' => $halina->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Halina ugotowala rosol.']);

        DB::table('sessions')->insert([
            'id' => 'sesja-basi-z-kopii',
            'user_id' => $basia->getKey(),
            'ip_address' => '203.0.113.0',
            'user_agent' => 'test',
            'payload' => 'x',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert(['email' => self::EMAIL, 'token' => 'token-z-kopii', 'created_at' => now()]);
        DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/basia.zip',
            'bytes' => 10,
            'completed_at' => now(),
            'expires_at' => now()->addDays(5),
        ]);

        $id = $basia->getKey();
        $kopia = [
            'users' => $this->zrzut('users', 'id', [$id]),
            'profiles' => $this->zrzut('profiles', 'user_id', [$id]),
            'posts' => $this->zrzut('posts', 'author_id', [$id]),
            'recipes' => $this->zrzut('recipes', 'author_id', [$id]),
            'comments' => $this->zrzut('comments', 'author_id', [$id]),
            'cooked_events' => $this->zrzut('cooked_events', 'user_id', [$id, $halina->getKey()]),
            'sessions' => $this->zrzut('sessions', 'user_id', [$id]),
            'password_reset_tokens' => $this->zrzut('password_reset_tokens', 'email', [self::EMAIL]),
            'data_exports' => $this->zrzut('data_exports', 'user_id', [$id]),
        ];

        return compact('basia', 'halina', 'wpis', 'przepis', 'komentarz', 'wykonanie', 'wpisKontrolny', 'cudzyWpis', 'kopia');
    }

    /**
     * Wiersze tak, jak zapisałby je zrzut bazy. Kolumn generowanych (`*_search`)
     * zrzut nie wstawia — liczy je baza.
     *
     * @param  list<string>  $wartosci
     * @return list<array<string, mixed>>
     */
    private function zrzut(string $tabela, string $kolumna, array $wartosci): array
    {
        $generowane = DB::table('information_schema.columns')
            ->where('table_name', $tabela)
            ->where('is_generated', 'ALWAYS')
            ->pluck('column_name')
            ->all();

        return DB::table($tabela)->whereIn($kolumna, $wartosci)->get()
            ->map(fn (object $w): array => array_diff_key((array) $w, array_flip($generowane)))
            ->all();
    }

    /**
     * „Odtworzenie bazy z kopii”: wiersze wracają do stanu z kopii, a to, co
     * w międzyczasie powstało w tych tabelach dla tego konta (np. potwierdzenie
     * RODO z wymazania), cofa się razem z bazą.
     *
     * @param  array<string, list<array<string, mixed>>>  $kopia
     */
    private function odtworz(array $kopia): void
    {
        $klucz = ['password_reset_tokens' => 'email', 'profiles' => 'user_id'];

        // Kolejność: rodzice przed dziećmi (klucze obce).
        foreach (['users', 'profiles', 'posts', 'recipes', 'comments', 'cooked_events', 'sessions', 'password_reset_tokens', 'data_exports'] as $tabela) {
            $k = $klucz[$tabela] ?? 'id';
            foreach ($kopia[$tabela] as $wiersz) {
                DB::table($tabela)->updateOrInsert([$k => $wiersz[$k]], $wiersz);
            }
        }

        DB::table('potwierdzenia_zadan_rodo')->delete();
        DB::table('audit_log')->whereIn('action', ['account.delete_requested', 'account.data_erased'])->delete();
    }

    /**
     * Co widzi gość: kody odpowiedzi i to, czy w treści jest nazwisko osoby.
     *
     * @return array<string, array{int, bool}>
     */
    private function odpowiedziSerwisu(User $basia, Post $wpis, Recipe $przepis, Post $cudzyWpis): array
    {
        $adresy = [
            'profil' => '/@'.$basia->profile->username,
            'wpis' => route('posts.show', $wpis),
            'przepis' => route('recipes.show', $przepis->slug),
            'komentarz' => route('posts.show', $cudzyWpis),
        ];

        $wynik = [];
        foreach ($adresy as $nazwa => $adres) {
            $odpowiedz = $this->get($adres);
            $wynik[$nazwa] = [$odpowiedz->getStatusCode(), str_contains($odpowiedz->getContent(), self::NAZWISKO) || str_contains($odpowiedz->getContent(), 'Komentarz Basi')];
        }

        return $wynik;
    }

    public function test_po_odtworzeniu_kopii_serwis_nie_pokazuje_wymazanego_konta_ani_jego_tresci(): void
    {
        $s = $this->scenaZKopia();
        // Profil w kopii ma jeszcze pierwotną nazwę użytkownika.
        $nazwaZKopii = $s['basia']->profile->username;

        // 1. Kontrola kopii: serwis pokazuje wszystko.
        $przedWymazaniem = $this->odpowiedziSerwisu($s['basia'], $s['wpis'], $s['przepis'], $s['cudzyWpis']);
        $this->assertSame(200, $przedWymazaniem['profil'][0]);
        $this->assertTrue($przedWymazaniem['profil'][1], 'Kontrola kopii: profil powinien pokazywać nazwisko.');
        $this->assertSame(200, $przedWymazaniem['wpis'][0]);

        // 2. Wymazanie „wszystko” na prośbę osoby.
        $s['basia']->fresh()->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue(app(EraseAccountData::class)->handle($s['basia']->fresh()));
        $stanDocelowy = $this->odpowiedziSerwisu($s['basia'], $s['wpis'], $s['przepis'], $s['cudzyWpis']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $s['basia']->getKey())->count());

        // 3. „Odtworzenie”: wiersze z kopii wracają. KONTROLA DODATNIA —
        // bez kroku 4 serwis znów pokazuje to, co człowiek kazał usunąć.
        $this->odtworz($s['kopia']);
        $poOdtworzeniu = $this->odpowiedziSerwisu($s['basia'], $s['wpis'], $s['przepis'], $s['cudzyWpis']);
        $this->assertSame(200, $poOdtworzeniu['profil'][0]);
        $this->assertTrue($poOdtworzeniu['profil'][1], 'Po odtworzeniu serwis pokazuje prawdziwe nazwisko — to jest ryzyko, przed którym chroni rejestr.');
        $this->assertSame(200, $poOdtworzeniu['wpis'][0]);
        $this->assertSame(200, $poOdtworzeniu['przepis'][0]);
        $this->assertTrue($poOdtworzeniu['komentarz'][1], 'Po odtworzeniu komentarz Basi jest w cudzym wątku.');
        $this->assertSame(self::EMAIL, $s['basia']->fresh()->email);

        // 4. Rejestr usunięć stosowany PRZED uruchomieniem serwisu.
        $this->artisan('kuking:wymaz-ponownie', ['--od' => now()->subDay()->toDateTimeString()])->assertSuccessful();

        $poRejestrze = $this->odpowiedziSerwisu($s['basia'], $s['wpis'], $s['przepis'], $s['cudzyWpis']);
        $this->assertSame($stanDocelowy, $poRejestrze, 'Po zastosowaniu rejestru serwis ma odpowiadać tak jak po pierwszym wymazaniu.');
        $this->assertFalse($poRejestrze['profil'][1], 'Profil nadal pokazuje nazwisko.');
        $this->assertFalse($poRejestrze['komentarz'][1], 'Wątek nadal pokazuje komentarz wymazanej osoby.');

        $konto = $s['basia']->fresh();
        $this->assertSame(User::STATUS_ERASED, $konto->status);
        $this->assertNotSame(self::EMAIL, $konto->email);
        $this->assertNotSame($nazwaZKopii, $konto->profile->username);
        $this->assertSame('Użytkownik usunięty', $konto->profile->display_name);

        // Treści (zakres „wszystko”), sesja, token resetu i paczka danych.
        $this->assertDatabaseMissing('posts', ['id' => $s['wpis']->getKey()]);
        $this->assertDatabaseMissing('recipes', ['id' => $s['przepis']->getKey()]);
        $this->assertDatabaseMissing('comments', ['id' => $s['komentarz']->getKey(), 'deleted_at' => null]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $konto->getKey())->count(), 'Sesja z kopii przeżyła ponowne wymazanie.');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', self::EMAIL)->count(), 'Token resetu hasła z kopii przeżył ponowne wymazanie.');
        $paczka = DataExport::query()->where('user_id', $konto->getKey())->firstOrFail();
        $this->assertFalse($paczka->isDownloadable(), 'Gotowa paczka z kopii nadal da się pobrać.');

        // Ślad wymazania wrócił do bazy (potwierdzenie RODO).
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['konto_id' => $konto->getKey(), 'wynik' => 'wykonane', 'zakres' => User::DELETE_SCOPE_EVERYTHING]);
    }

    public function test_rejestr_nie_rusza_kont_kontrolnych_ani_zalozonych_po_kopii(): void
    {
        $s = $this->scenaZKopia();
        $s['basia']->fresh()->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue(app(EraseAccountData::class)->handle($s['basia']->fresh()));

        // Konto założone PO dacie kopii i wymazane po niej: w odtworzonej
        // bazie go nie ma, a w rejestrze jest wpis.
        $this->assertTrue(app(DziennikWymazan::class)->zapisz((string) Str::uuid(), User::DELETE_SCOPE_MINIMUM, now()));

        $this->odtworz($s['kopia']);

        $this->artisan('kuking:wymaz-ponownie')
            ->expectsOutputToContain('Wymazano ponownie: 1. Już wymazane w kopii: 0. Brak konta w kopii: 1. Błędy: 0.')
            ->assertSuccessful();

        // Konto kontrolne (nigdy nie prosiło o usunięcie) jest nietknięte.
        $halina = $s['halina']->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $halina->status);
        $this->assertSame('halina.kontrolna@example.com', $halina->email);
        $this->assertSame('Halina Kontrolna', $halina->profile->display_name);
        $this->get(route('posts.show', $s['wpisKontrolny']))->assertOk()->assertSee('Kontrolny wpis Haliny');
    }

    public function test_zakres_minimum_po_odtworzeniu_zostawia_tekst_pod_anonimowym_podpisem(): void
    {
        $s = $this->scenaZKopia();
        $s['basia']->fresh()->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        $this->assertTrue(app(EraseAccountData::class)->handle($s['basia']->fresh()));
        $this->odtworz($s['kopia']);

        $this->artisan('kuking:wymaz-ponownie')->assertSuccessful();

        $odpowiedz = $this->get(route('posts.show', $s['wpis']))->assertOk();
        $odpowiedz->assertSee('Wpis Basi o rosole babci Zofii');
        $odpowiedz->assertSee('Użytkownik usunięty');
        $odpowiedz->assertDontSee(self::NAZWISKO);
        $this->get('/@'.$s['kopia']['profiles'][0]['username'])->assertNotFound();
    }

    /**
     * Kolejność z analizy (pytanie 8): rejestr usunięć PRZED podpięciem bazy
     * do serwisu. Runbook 3(b) jest jedyną instrukcją, którą właściciel
     * wykona w awarii, więc to, w jakiej kolejności ją czyta, jest częścią
     * zachowania.
     */
    public function test_runbook_kaze_zastosowac_rejestr_usuniec_przed_podpieciem_bazy_do_serwisu(): void
    {
        $dokument = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));
        $poczatek = strpos($dokument, '### 3(b) Utracona cała baza');
        $koniec = strpos($dokument, '### 3.1 Po KAŻDYM odtworzeniu');
        $this->assertNotFalse($poczatek);
        $this->assertNotFalse($koniec);
        $this->assertGreaterThan($poczatek, $koniec, 'Nie znaleziono sekcji 3(b) w runbooku.');
        $sekcja = substr($dokument, $poczatek, $koniec - $poczatek);

        $pierwszaPara = strpos($sekcja, '# 5. Dopiero po potwierdzeniu kroku 4 zastosuj rejestr usunięć');
        $drugaPara = strpos($sekcja, '# 6. Wstrzymaj ruch usług web, worker i scheduler na starej bazie');
        $koniecDrugiejPary = strpos($sekcja, '# 7. Dopiero teraz podepnij nowy DB_URL');
        $podpiecie = strpos($sekcja, 'railway variables --set "DB_URL=');
        $this->assertNotFalse($pierwszaPara);
        $this->assertNotFalse($drugaPara);
        $this->assertNotFalse($koniecDrugiejPary);
        $this->assertNotFalse($podpiecie, 'Runbook 3(b) nie ma kroku podpięcia DB_URL do serwisu.');

        $this->assertTrue($pierwszaPara < $drugaPara, 'RUNBOOK_REJESTR_PO_PODPIECIU: pierwszy podgląd i wymazanie muszą poprzedzać drugą kontrolę.');
        $this->assertTrue($drugaPara < $koniecDrugiejPary, 'RUNBOOK_DRUGI_WYMAZ_PRZED_PODPIECIEM: druga kontrola musi poprzedzać podpięcie bazy.');
        $this->assertTrue($pierwszaPara < $podpiecie, 'RUNBOOK_REJESTR_PO_PODPIECIU: nie podpinaj bazy przed pierwszym podglądem i wymazaniem.');
        $this->assertTrue($koniecDrugiejPary < $podpiecie, 'RUNBOOK_DRUGI_WYMAZ_PRZED_PODPIECIEM: nie podpinaj bazy przed drugim podglądem i wymazaniem.');

        $this->assertParaWymazaniaWRunbooku(
            substr($sekcja, $pierwszaPara, $drugaPara - $pierwszaPara),
            'RUNBOOK_REJESTR_PO_PODPIECIU',
        );
        $this->assertParaWymazaniaWRunbooku(
            substr($sekcja, $drugaPara, $koniecDrugiejPary - $drugaPara),
            'RUNBOOK_DRUGI_WYMAZ_PRZED_PODPIECIEM',
        );
    }

    private function assertParaWymazaniaWRunbooku(string $fragment, string $marker): void
    {
        $linie = preg_split('/\R/', $fragment);
        $this->assertIsArray($linie);
        $komendy = [];

        foreach ($linie as $numer => $linia) {
            $komenda = trim($linia);
            if (! str_starts_with($komenda, 'php artisan kuking:wymaz-ponownie')) {
                continue;
            }

            $this->assertSame(
                'railway run --service kuking.pl --environment production '.chr(92),
                trim($linie[$numer - 2] ?? ''),
                $marker.': wymazanie musi działać przez odizolowany serwis.',
            );
            $this->assertSame(
                'env DB_URL="$DB_URL_NOWEJ_BAZY" '.chr(92),
                trim($linie[$numer - 1] ?? ''),
                $marker.': wymazanie musi dotyczyć nowej bazy.',
            );
            $komendy[] = $komenda;
        }

        $this->assertCount(2, $komendy, $marker.': wymagane są podgląd i wykonanie przed podpięciem bazy.');
        $this->assertMatchesRegularExpression('/^php artisan kuking:wymaz-ponownie --od="[^"]+" --na-sucho$/', $komendy[0], $marker.': najpierw podgląd.');
        $this->assertMatchesRegularExpression('/^php artisan kuking:wymaz-ponownie --od="[^"]+"$/', $komendy[1], $marker.': potem wykonanie.');
    }
}
