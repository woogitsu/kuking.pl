<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\TrescDigestu;
use App\Mail\PodsumowanieTygodnia;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DigestNieKolejkujeSekretowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pelny_payload_kolejki_nie_zawiera_sekretow_a_list_zachowuje_tresc_bez_odczytow_bazy(): void
    {
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
        $odbiorca = $this->user('odbiorca583', ['display_name' => 'Odbiorca migawki']);
        $kucharz = $this->user('kucharz583', ['display_name' => 'Kucharz migawki']);
        $obserwujacy = $this->user('obserwujacy583', ['display_name' => 'Obserwujący migawki']);
        $autor = $this->user('autor583', ['display_name' => 'Autor migawki']);
        $sekrety = [];

        foreach ([$odbiorca, $kucharz, $obserwujacy, $autor] as $i => $osoba) {
            $osoba->load('profile');
            // W pamięci, bez zapisu do users: rozpoznawalne, fałszywe wartości.
            // Nowa nieznana kolumna i relacja też nie mogą trafić do payloadu.
            foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_backup_codes', 'przyszly_sekret'] as $pole) {
                $marker = 'FAKE_SECRET_583_'.$i.'_'.$pole;
                $sekrety[] = $marker;
                $osoba->setRawAttributes([...$osoba->getAttributes(), $pole => $marker]);
            }
            $osoba->setRelation('niepotrzebnaRelacja', (new User)->setRawAttributes(['password' => 'FAKE_NESTED_SECRET_583']));
        }
        $sekrety[] = 'FAKE_NESTED_SECRET_583';
        $przepis = (new Recipe)->setRawAttributes(['title' => 'Rosół migawki', 'private_note' => 'FAKE_RECIPE_SECRET_583']);
        $przepis->setRelation('author', $odbiorca);
        $wykonanie = (new CookedEvent)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000583', 'note' => 'Notatka migawki']);
        $wykonanie->setRelation('user', $kucharz)->setRelation('recipe', $przepis);
        $wpis = (new Post)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000584', 'body' => 'Treść migawki']);
        $wpis->setRelation('author', $autor)->setRelation('recipe', $przepis);
        $sekrety[] = 'FAKE_RECIPE_SECRET_583';

        $tresc = new TrescDigestu($odbiorca, [$wykonanie], [$obserwujacy], 3, [$wpis], 'Pytanie migawki?');
        $list = new PodsumowanieTygodnia($tresc);
        $html = $list->render();
        $tekst = view('mail.podsumowanie-tygodnia-tekst', $list->content()->with)->render();
        $temat = $list->envelope()->subject;
        $naglowki = $list->headers()->text;

        // Prawdziwe Mail::queue -> DatabaseQueue -> jobs.payload, bez Mail::fake.
        // Osobny egzemplarz: render() przygotowuje callbacki Symfony dla HTML.
        Mail::to($odbiorca->email)->queue(new PodsumowanieTygodnia($tresc));
        $this->assertDatabaseCount('jobs', 1);
        $payload = (string) DB::table('jobs')->value('payload');
        $dane = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $command = $dane['data']['command'];
        foreach ($sekrety as $sekret) {
            $this->assertStringNotContainsString($sekret, $payload);
            $this->assertStringNotContainsString($sekret, $command);
        }
        foreach ([$kucharz, $obserwujacy, $autor] as $osoba) {
            $this->assertStringNotContainsString($osoba->email, $command);
        }
        $this->assertStringContainsString('Notatka migawki', $command);
        $this->assertStringContainsString('Pytanie migawki?', $command);

        // Zmiany po zakolejkowaniu nie mogą zmienić wcześniej dobranego listu.
        DB::table('profiles')->where('user_id', $kucharz->getKey())->update(['display_name' => 'Późniejsza nazwa']);
        $zapytania = [];
        DB::listen(static function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });
        $odczytany = unserialize($command);
        $this->assertInstanceOf(SendQueuedMailable::class, $odczytany);
        $mail = $odczytany->mailable;
        $this->assertInstanceOf(PodsumowanieTygodnia::class, $mail);
        foreach ([$mail->tresc->odbiorca, $mail->tresc->wykonania[0]->user,
            $mail->tresc->nowiObserwujacy[0], $mail->tresc->wpisyObserwowanych[0]->author] as $osoba) {
            $this->assertSame(['id'], array_keys($osoba->getAttributes()));
            $this->assertSame(['profile'], array_keys($osoba->getRelations()));
            $this->assertSame(['display_name'], array_keys($osoba->profile->getAttributes()));
            $this->assertSame([], $osoba->profile->getRelations());
        }
        $this->assertSame(['id', 'note'], array_keys($mail->tresc->wykonania[0]->getAttributes()));
        $this->assertSame(['user', 'recipe'], array_keys($mail->tresc->wykonania[0]->getRelations()));
        $this->assertSame(['id', 'body'], array_keys($mail->tresc->wpisyObserwowanych[0]->getAttributes()));
        $this->assertSame(['author', 'recipe'], array_keys($mail->tresc->wpisyObserwowanych[0]->getRelations()));
        foreach ([$mail->tresc->wykonania[0]->recipe, $mail->tresc->wpisyObserwowanych[0]->recipe] as $przepis) {
            $this->assertSame(['title'], array_keys($przepis->getAttributes()));
            $this->assertSame([], $przepis->getRelations());
        }
        $this->assertTrue($mail->hasTo($odbiorca->email));
        $this->assertSame($html, $mail->render());
        $this->assertSame($tekst, view('mail.podsumowanie-tygodnia-tekst', $mail->content()->with)->render());
        $this->assertSame($temat, $mail->envelope()->subject);
        $this->assertSame($naglowki, $mail->headers()->text);
        $this->assertSame([], $zapytania);
        $this->assertSame(3, $mail->tresc->ileNowychObserwujacych);
        // Bez mutowania oryginalnych modeli dla pozostałych odbiorców paczki.
        $this->assertSame('FAKE_SECRET_583_1_password', $kucharz->getAttributes()['password']);

        // Producent jest tym procesem PHPUnit. Każdy czytnik to osobny PHP,
        // bez załadowanej nowej klasy DTO w przypadku starego workera.
        foreach (['old', 'current'] as $reader) {
            $odpowiedz = $this->odczytajWOsobnymProcesie($command, $reader);
            $this->assertSame($html, $odpowiedz['html']);
            $this->assertSame($tekst, $odpowiedz['text']);
            $this->assertSame($temat, $odpowiedz['subject']);
            $this->assertSame($naglowki, $odpowiedz['headers']);
            $this->assertSame(0, $odpowiedz['queries']);
        }
    }

    /** @return array{html: string, text: string, subject: string, headers: array<string, string>, queries: int} */
    private function odczytajWOsobnymProcesie(string $command, string $reader): array
    {
        $fixture = base_path('tests/Fixtures/digest583/TrescDigestu-ac5ff9d.php.fixture');
        $this->assertSame('d6b8412d72f92d8219d37001391099ae3932b914e763d0eb1edfefa01a858745', hash_file('sha256', $fixture));
        $script = <<<'PHP'
        $root = $argv[1];
        require $root.'/vendor/autoload.php';
        if ($argv[2] === 'old') {
            require $root.'/tests/Fixtures/digest583/TrescDigestu-ac5ff9d.php.fixture';
        }
        $app = require $root.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $queries = 0;
        Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });
        $job = unserialize(stream_get_contents(STDIN));
        $mail = $job->mailable;
        echo json_encode([
            'html' => $mail->render(),
            'text' => view('mail.podsumowanie-tygodnia-tekst', $mail->content()->with)->render(),
            'subject' => $mail->envelope()->subject,
            'headers' => $mail->headers()->text,
            'queries' => $queries,
        ], JSON_THROW_ON_ERROR);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, base_path(), $reader], base_path(), [
            'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true', 'AWS_BUCKET' => 'kuking-local-test', 'AWS_DEFAULT_REGION' => 'auto',
            'APP_KEY' => config('app.key'), 'APP_URL' => config('app.url'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
            'DB_DATABASE' => 'forbidden583', 'DB_USERNAME' => 'forbidden583', 'DB_PASSWORD' => '', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        ]);
        $process->setInput($command)->setTimeout(30)->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_stary_worker_odczytuje_nowy_payload_bez_pomocy_nowego_dto(): void
    {
        $osoba = $this->user('rolling583', ['display_name' => 'Osoba rolling deploy']);
        $osoba->load('profile');
        $tresc = new TrescDigestu($osoba, [], [$osoba], 1, [], 'Pytanie rolling deploy?');
        $list = new PodsumowanieTygodnia($tresc);
        // Osobny test: nie ma wcześniejszej asercji typów/allowlisty, która
        // mogłaby ukryć TypeError rzeczywistego starego czytnika.
        $command = serialize(new SendQueuedMailable($list));
        $wynik = $this->odczytajWOsobnymProcesie($command, 'old');
        $this->assertSame($list->render(), $wynik['html']);
        $this->assertSame($list->envelope()->subject, $wynik['subject']);
        $this->assertSame($list->headers()->text, $wynik['headers']);
        $this->assertSame(0, $wynik['queries']);
    }

    public function test_odczytuje_stary_format_a_kolejny_zapis_usuwa_modele_i_sekrety(): void
    {
        $osoba = $this->user('stary583', ['display_name' => 'Stara migawka']);
        $osoba->load('profile');
        $osoba->setRawAttributes([...$osoba->getAttributes(), 'remember_token' => 'FAKE_LEGACY_SECRET_583']);
        $pola = ['odbiorca' => $osoba, 'wykonania' => [], 'nowiObserwujacy' => [$osoba],
            'ileNowychObserwujacych' => 1, 'wpisyObserwowanych' => [], 'pytanieGospodarza' => null];
        // Format PHP dawnego DTO, zanim klasa dostała __serialize().
        $klasa = TrescDigestu::class;
        $stary = 'O:'.strlen($klasa).':"'.$klasa.'":'.substr(serialize($pola), 2);
        $this->assertStringContainsString('FAKE_LEGACY_SECRET_583', $stary);
        $tresc = unserialize($stary);
        $this->assertInstanceOf(TrescDigestu::class, $tresc);
        $this->assertSame('Stara migawka', $tresc->odbiorca->displayName());
        $this->assertFalse($tresc->jestPusty());
        $this->assertStringNotContainsString('FAKE_LEGACY_SECRET_583', serialize($tresc));
    }

    public function test_migawka_zachowuje_brak_relacji_i_wpis_z_samym_przepisem(): void
    {
        $osoba = (new User)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000585']);
        $osoba->setRelation('profile', null);
        $przepis = (new Recipe)->setRawAttributes(['title' => 'Sam przepis']);
        $wpis = (new Post)->setRawAttributes(['id' => '00000000-0000-4000-8000-000000000586', 'body' => null]);
        $wpis->setRelation('author', null)->setRelation('recipe', $przepis);
        $tresc = new TrescDigestu($osoba, [], [], 0, [$wpis], null);
        $list = new PodsumowanieTygodnia($tresc);
        $odczytany = unserialize(serialize($list));
        $this->assertInstanceOf(PodsumowanieTygodnia::class, $odczytany);
        $this->assertSame($list->render(), $odczytany->render());
        $this->assertSame('Użytkownik Kuking', $odczytany->tresc->odbiorca->displayName());
        $this->assertNull($odczytany->tresc->wpisyObserwowanych[0]->author);
        $this->assertSame('Sam przepis', $odczytany->tresc->wpisyObserwowanych[0]->recipe->title);
        $this->assertFalse($odczytany->tresc->jestPusty());
        $this->assertTrue(unserialize(serialize(TrescDigestu::pusta($osoba)))->jestPusty());
    }
}
