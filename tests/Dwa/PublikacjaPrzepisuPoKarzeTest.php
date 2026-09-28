<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Posts\KontoNieMozePublikowac;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * #2189: zapis przepisu rozstrzyga stan autora na ŚWIEŻYM wierszu, pod blokadą.
 *
 * Kara zatwierdzona, gdy zapis czekał na blokadę konta, ma dać odmowę bez
 * skutku — tak jak w `PublishPost` (`PublikacjaWpisuPoKarzeTest`). Proces
 * wczytuje AKTYWNEGO autora przed przeplotem (jak żądanie po middleware
 * i Policy), a bariera zatwierdza karę dopiero potem.
 *
 * Kontrola ujemna: zastąpienie w `PublishRecipe` świeżego pobrania autora
 * z kontrolą `isActive()` z powrotem samym `FOR KEY SHARE` oblewa testy
 * `kara_zatwierdzona_przed_blokada_autora_cofa_caly_zapis` (zapis przechodzi
 * mimo kary), a `wygasla_kara...` przestaje zdejmować zawieszenie.
 */
#[Group('dwa-polaczenia')]
final class PublikacjaPrzepisuPoKarzeTest extends TestDwochPolaczen
{
    /** Przepisy tego testu — przed kontami, bo `recipe_versions.editor_id` jest RESTRICT. */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        try {
            $autorzy = $this->konta;
            if ($autorzy !== []) {
                DB::table('posts')->whereIn('author_id', $autorzy)->delete();
                $this->przepisy = array_merge(
                    $this->przepisy,
                    DB::table('recipes')->whereIn('author_id', $autorzy)->pluck('id')->all(),
                );
            }
            if ($this->przepisy !== []) {
                DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nNie udało się posprzątać przepisów #2189: ".$e->getMessage()."\n");
        }
        $this->przepisy = [];

        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function karyIZapisy(): array
    {
        $kary = [
            'zawieszenie' => User::STATUS_SUSPENDED,
            'ban' => User::STATUS_BANNED,
            'oczekiwanie na usunięcie' => User::STATUS_PENDING_DELETE,
        ];
        $tryby = ['nowy_szkic', 'nowa_publikacja', 'edycja_szkicu', 'edycja_publicznego'];

        $zestawy = [];
        foreach ($kary as $nazwaKary => $status) {
            foreach ($tryby as $tryb) {
                $zestawy["$nazwaKary: $tryb"] = [$status, $tryb];
            }
        }

        return $zestawy;
    }

    private function istniejacyPrzepis(User $autor, string $status): Recipe
    {
        $znacznik = bin2hex(random_bytes(5));
        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół sprzed kary '.$znacznik,
            'slug' => 'rosol-sprzed-kary-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => $status,
            'published_at' => $status === Recipe::STATUS_PUBLISHED ? now() : null,
        ]);
        $this->przepisy[] = (string) $przepis->getKey();

        if ($status === Recipe::STATUS_PUBLISHED) {
            RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $autor->getKey(),
                'version_number' => 1,
                'change_note' => 'Pierwsza publikacja',
                'snapshot' => ['title' => $przepis->title],
            ]);
        }

        return $przepis->refresh();
    }

    /** @return array<string, int> Stan wszystkiego, co zapis przepisu potrafi dopisać albo zmienić. */
    private function stan(User $autor): array
    {
        $przepisy = DB::table('recipes')->where('author_id', $autor->getKey())->pluck('id')->all();

        return [
            'przepisy' => count($przepisy),
            'wersje' => DB::table('recipe_versions')->whereIn('recipe_id', $przepisy)->count(),
            'skladniki' => DB::table('recipe_ingredients')->whereIn('recipe_id', $przepisy)->count(),
            'kroki' => DB::table('recipe_steps')->whereIn('recipe_id', $przepisy)->count(),
            'audyt' => DB::table('audit_log')->where('actor_id', $autor->getKey())
                ->whereIn('action', ['recipe.published', 'recipe.updated'])->count(),
            'wpisy_w_strumieniu' => Post::query()->where('author_id', $autor->getKey())->count(),
            'powiadomienia' => Notification::query()->where('actor_id', $autor->getKey())->count(),
            'zdjecia_w_przepisach' => DB::table('recipes')->where('author_id', $autor->getKey())
                ->whereNotNull('hero_media_id')->count(),
        ];
    }

    #[Test]
    #[DataProvider('karyIZapisy')]
    public function kara_zatwierdzona_przed_blokada_autora_cofa_caly_zapis(string $status, string $tryb): void
    {
        $autor = $this->konto();
        $zdjecie = $tryb === 'nowa_publikacja' ? Media::factory()->create(['owner_id' => $autor->getKey()]) : null;
        $przepis = match ($tryb) {
            'edycja_szkicu' => $this->istniejacyPrzepis($autor, Recipe::STATUS_DRAFT),
            'edycja_publicznego' => $this->istniejacyPrzepis($autor, Recipe::STATUS_PUBLISHED),
            default => null,
        };
        $tytulPrzed = $przepis?->title;
        $rewizjaPrzed = $przepis?->content_revision;
        $przedZapisem = $this->stan($autor);

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [$autor->getKey()]);
        try {
            // Proces ładuje aktywnego autora PRZED zatwierdzeniem kary, po czym
            // staje w kolejce po jego wiersz — tak jak żądanie zaczęte przed karą.
            $proces = $this->wTle('zapisz-przepis-2189', array_filter([
                'autor' => (string) $autor->getKey(),
                'tryb' => $tryb,
                'tytul' => 'Rosół po karze '.bin2hex(random_bytes(3)),
                'przepis' => $przepis === null ? null : (string) $przepis->getKey(),
                'zdjecie' => $zdjecie === null ? null : (string) $zdjecie->getKey(),
            ]));
            $this->czekajNaZablokowane(1);

            $usuniete = $status === User::STATUS_PENDING_DELETE;
            $zmiana = $bariera->prepare('UPDATE users SET status = ?, delete_scope = ?, delete_requested_at = ? WHERE id = ?');
            $zmiana->execute([
                $status,
                $usuniete ? User::DELETE_SCOPE_MINIMUM : null,
                $usuniete ? now()->toDateTimeString() : null,
                $autor->getKey(),
            ]);
            $this->assertSame(1, $zmiana->rowCount());
            $bariera->commit();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $proces->wynik();
        $this->assertBezZakleszczenia($wynik, 'zapis przepisu po zatwierdzonej karze');
        $this->assertFalse($wynik['ok'], 'Zapis przepisu po zatwierdzonej karze nie może się udać.');
        $this->assertSame(KontoNieMozePublikowac::class, $wynik['wyjatek'], (string) $wynik['komunikat']);
        $this->assertNull($wynik['sqlstate'], 'Odmowa powinna być regułą domenową, nie timeoutem bazy.');
        $this->assertSame($status, $autor->fresh()->status);

        // Brak skutku ubocznego: ani treści, ani wersji, audytu, wpisu w strumieniu, powiadomień.
        $this->assertSame($przedZapisem, $this->stan($autor));
        if ($przepis !== null) {
            $po = Recipe::query()->findOrFail($przepis->getKey());
            $this->assertSame($tytulPrzed, $po->title);
            $this->assertSame($rewizjaPrzed, $po->content_revision);
            $this->assertSame($przepis->status, $po->status);
        }
        if ($zdjecie !== null) {
            $this->assertSame(0, Recipe::query()->where('hero_media_id', $zdjecie->getKey())->count());
        }
    }

    #[Test]
    public function wygasla_kara_zostaje_zdjeta_i_zapis_przechodzi(): void
    {
        $autor = $this->konto();
        $przedZapisem = $this->stan($autor);

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [$autor->getKey()]);
        try {
            $proces = $this->wTle('zapisz-przepis-2189', [
                'autor' => (string) $autor->getKey(),
                'tryb' => 'nowa_publikacja',
                'tytul' => 'Rosół po wygasłej karze',
            ]);
            $this->czekajNaZablokowane(1);

            $zmiana = $bariera->prepare('UPDATE users SET status = ?, status_expires_at = ? WHERE id = ?');
            $zmiana->execute([User::STATUS_SUSPENDED, now()->subHour()->toDateTimeString(), $autor->getKey()]);
            $this->assertSame(1, $zmiana->rowCount());
            $bariera->commit();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $proces->wynik();
        $this->assertTrue($wynik['ok'], (string) $wynik['komunikat']);
        $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()->status);
        $this->assertNull($autor->fresh()->status_expires_at);
        $po = $this->stan($autor);
        $this->assertSame($przedZapisem['przepisy'] + 1, $po['przepisy']);
        $this->assertSame(Recipe::STATUS_PUBLISHED, Recipe::query()->findOrFail($wynik['wartosc'])->status);
    }

    /** @return array<string, array{string}> */
    public static function tryby(): array
    {
        return ['nowy szkic' => ['nowy_szkic'], 'edycja publicznego przepisu' => ['edycja_publicznego']];
    }

    #[Test]
    #[DataProvider('tryby')]
    public function zapis_pierwszy_konczy_sie_przed_kara(string $tryb): void
    {
        $autor = $this->konto();
        $przepis = $tryb === 'edycja_publicznego' ? $this->istniejacyPrzepis($autor, Recipe::STATUS_PUBLISHED) : null;
        $przedZapisem = $this->stan($autor);

        $barieraId = random_int(1, 2_000_000_000);
        $bariera = $this->nowePolaczenie();
        $bariera->beginTransaction();
        $bariera->prepare('SELECT pg_advisory_xact_lock(2189, ?)')->execute([$barieraId]);

        try {
            $zapis = $this->wTle('zapisz-przepis-2189', array_filter([
                'autor' => (string) $autor->getKey(),
                'tryb' => $tryb,
                'tytul' => 'Rosół przed karą',
                'przepis' => $przepis === null ? null : (string) $przepis->getKey(),
                'bariera' => (string) $barieraId,
            ]));
            // Zapis trzyma już świeżego autora pod FOR NO KEY UPDATE i czeka
            // na naszą barierę. Dopiero teraz kara staje w kolejce po ten wiersz.
            $this->czekajNaZablokowane(1);
            $kara = $this->wTle('status-konta', ['konto' => (string) $autor->getKey(), 'przejscie' => 'zawies']);
            $this->czekajNaZablokowane(2);
        } finally {
            $bariera->rollBack();
        }

        $wynikZapisu = $zapis->wynik();
        $wynikKary = $kara->wynik();
        $this->assertBezZakleszczenia($wynikZapisu, 'zapis przepisu przed karą');
        $this->assertBezZakleszczenia($wynikKary, 'kara po zapisie przepisu');
        $this->assertTrue($wynikZapisu['ok'], (string) $wynikZapisu['komunikat']);
        $this->assertTrue($wynikKary['ok'], (string) $wynikKary['komunikat']);
        $this->assertSame(User::STATUS_SUSPENDED, $wynikKary['wartosc']);
        $this->assertSame(User::STATUS_SUSPENDED, $autor->fresh()->status);

        $po = $this->stan($autor);
        $this->assertSame($przedZapisem['przepisy'] + ($przepis === null ? 1 : 0), $po['przepisy']);
        if ($przepis !== null) {
            $this->assertSame($przedZapisem['wersje'] + 1, $po['wersje']);
            $this->assertSame(1, $po['audyt']);
        }
    }
}
