<?php

declare(strict_types=1);

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\DailyPick;
use App\Models\HeroPick;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Dwie fazy: pusty → odbiór GET → pelny → odbiór GET. Bez DemoSeeder,
// kasowania danych i tworzenia sesji z pominięciem logowania/2FA.
// php scripts/fixtures/panel-marki.php pusty /zewnetrzny/katalog/panel581.json
// php scripts/fixtures/panel-marki.php pelny /zewnetrzny/katalog/panel581.json
// Plik zawiera WYŁĄCZNIE losowe poświadczenia lokalnej fixture. Nie jest dowodem
// do publikacji. Caller loguje się formularzem, oblicza TOTP z totpSecret,
// zamienia alias sesja na storageState i przekazuje scenariusze do miernika.
umask(0077);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    // Nie wypisujemy SQL, DSN ani argumentów wyjątku z poświadczeniami.
    fwrite(STDERR, 'PANEL581: fixture przerwana ('.get_class($error).'). Sprawdź warunki izolacji i stan fazy.'.PHP_EOL);
    exit(1);
});
$connection = DB::connection();
$port = (string) $connection->getConfig('port');
$database = $connection->getDatabaseName();
$ci = getenv('GITHUB_ACTIONS') === 'true';
if (PHP_SAPI !== 'cli' || ! $app->environment(['local', 'testing'])
    || realpath((string) getenv('APP_BASE_PATH')) !== realpath(__DIR__.'/../..')
    || $connection->getDriverName() !== 'pgsql'
    || $connection->getConfig('host') !== '127.0.0.1'
    || ! getenv('DB_PORT') || $port !== (string) getenv('DB_PORT')
    || (! $ci && ($port !== '55439' || ! in_array($database, ['kuking_581_browser', 'kuking_581_acceptance', 'kuking_581_menu', 'kuking_581_menu_extra'], true)))
    || ($ci && $database !== 'kuking_port_panel')
    || config('mail.default') !== 'array'
    || config('filesystems.disks.public.driver') !== 'local') {
    throw new RuntimeException('Wymagana izolowana lokalna baza panelu, jawny APP_BASE_PATH i port, mailer array.');
}
Http::preventStrayRequests();
config(['logging.default' => 'single', 'queue.default' => 'sync']);
$phase = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
$parent = realpath(dirname($statePath));
$repo = realpath(__DIR__.'/../..');
if (! in_array($phase, ['pusty', 'pelny'], true) || $statePath === '' || $parent === false
    || ! is_writable($parent) || is_link($statePath)
    || str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR), strtolower($repo.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Podaj fazę i plik w istniejącym katalogu poza repo.');
}
$statePath = $parent.DIRECTORY_SEPARATOR.basename($statePath);
for ($directory = $parent; dirname($directory) !== $directory; $directory = dirname($directory)) {
    if (file_exists($directory.DIRECTORY_SEPARATOR.'.git')) {
        throw new RuntimeException('Poświadczenia fixture nie mogą trafić do żadnego repozytorium.');
    }
}

// Wszystkie te ekrany mają globalne zapytania. Sesja innego moderatora nie
// tworzy pustego stanu. Odmowa chroni dane i uczciwość odbioru, nie czyścimy ich.
$assertEmpty = static function (): void {
    foreach (['reports', 'appeals', 'moderation_actions', 'contact_messages', 'contact_message_replies',
        'posts', 'recipes', 'cooked_events', 'media', 'hero_picks', 'daily_picks', 'tag_promotions'] as $table) {
        if (DB::table($table)->exists()) {
            throw new RuntimeException('Zastane dane panelu. Użyj świeżej wydzielonej bazy.');
        }
    }
};
$assertEmpty();
$scenario = static function (string $id, string $family, string $path, array $selectors, string $session = 'konto') use ($phase): array {
    return ['id' => $id, 'rodzina' => $family, 'stan' => $session === 'bramka' ? 'bramka' : $phase,
        'path' => $path, 'sesja' => $session, 'oczekiwaneSelektory' => $selectors];
};
$one = static fn (string $selector, int $min = 1, ?int $max = null): array => array_filter(['selector' => $selector, 'min' => $min, 'max' => $max], static fn ($v) => $v !== null);
$scenarios = [];

if ($phase === 'pusty') {
    if (file_exists($statePath) || User::query()->exists()) {
        throw new RuntimeException('Faza pusta wymaga nowej bazy i nowego pliku stanu.');
    }
    $namespace = 'panel581-'.bin2hex(random_bytes(6));
    $state = DB::transaction(function () use ($namespace): array {
        $accounts = [];
        foreach (['konto' => User::ROLE_ADMIN, 'bramka' => User::ROLE_MODERATOR] as $alias => $role) {
            $password = bin2hex(random_bytes(24));
            $user = User::factory()->create(['email' => $namespace.'-'.$alias.'@example.test',
                'password' => Hash::make($password), 'role' => $role, 'wants_weekly_digest' => false]);
            $user->refresh()->profile->update(['username' => str_replace('-', '_', $namespace).'_'.$alias,
                'display_name' => $alias === 'konto' ? 'Gospodarz odbioru panelu' : 'Moderator bez drugiego składnika']);
            $accounts[$alias] = ['id' => (string) $user->id, 'email' => $user->email, 'password' => $password];
            if ($alias === 'konto') {
                $secret = app(TwoFactorAuthenticator::class)->generateSecret();
                $user->beginTwoFactorSetup($secret);
                $user->confirmTwoFactor([]);
                $accounts[$alias]['totpSecret'] = $secret;
            }
        }

        return ['namespace' => $namespace, 'database' => DB::connection()->getDatabaseName(), 'phase' => 'pusty', ...$accounts];
    });
    $emptyRoutes = [
        'zgloszenia' => ['/admin/zgloszenia', '.empty-state-title'],
        'sygnaly' => ['/admin/sygnaly', '.empty-state-title'],
        'odwolania' => ['/admin/odwolania', '.empty-state-title'],
        'wiadomosci' => ['/admin/wiadomosci', '.empty-state-title'],
        'kolaz-powitalny' => ['/admin/kolaz-powitalny', 'p.meta:has-text("Nie ma jeszcze ani jednego publicznego zdjęcia do wyboru.")'],
        'kuking-na-dzis' => ['/admin/kuking-na-dzis', 'p.meta:has-text("W ostatnich 7 dniach nikt nic nie opublikował.")'],
        'tagi-promowane' => ['/admin/tagi-promowane', '.empty-state-title'],
        'uzytkownicy' => ['/admin/uzytkownicy?szukaj='.$namespace.'-brak-konta', '.empty-state-title'],
    ];
    foreach ($emptyRoutes as $family => [$path, $selector]) {
        $scenarios[] = $scenario($family.'-pusty', $family, $path, [$one('main '.$selector, 1, 1)]);
    }
    foreach (['wpisy', 'przepisy', 'ugotowane'] as $type) {
        $scenarios[] = $scenario('bez-odpowiedzi-'.$type.'-pusty', 'bez-odpowiedzi', '/admin/bez-odpowiedzi?typ='.$type,
            [$one('main .empty-state-title', 1, 1), $one('main article.card', 0, 0)]);
    }
    $scenarios[] = $scenario('bramka-2fa', 'bramka-2fa', '/admin/zgloszenia',
        [$one('main .marka-panel-bramka[aria-labelledby="panel-wymaga-2fa"] #panel-wymaga-2fa', 1, 1),
            $one('main a[href$="/ustawienia/2fa/wlacz"]', 1, 1)], 'bramka');
} else {
    if (! is_file($statePath)) {
        throw new RuntimeException('Najpierw faza pusta i jej odbiór.');
    }
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    if ($state['phase'] !== 'pusty' || $state['database'] !== $database
        || ! preg_match('/\Apanel581-[a-f0-9]{12}\z/', $state['namespace']) || User::query()->count() !== 2) {
        throw new RuntimeException('Stan nie odpowiada bazie albo fazę pełną już wykonano.');
    }
    $namespace = $state['namespace'];
    $host = User::whereKey($state['konto']['id'])->where('email', $state['konto']['email'])->firstOrFail();
    $gate = User::whereKey($state['bramka']['id'])->where('email', $state['bramka']['email'])->firstOrFail();
    if (! $host->hasTwoFactorConfirmed() || $host->role !== User::ROLE_ADMIN || $gate->hasTwoFactorConfirmed()) {
        throw new RuntimeException('Konta fixture nie zachowały wymaganych uprawnień.');
    }
    // Obraz z już używanego w repo źródła, bez sieci i bez oryginałów użytkowników.
    $zip = new ZipArchive;
    if ($zip->open(base_path('docs/design/references/KuKing-styl-wizualizacja-konstytucja.zip')) !== true) {
        throw new RuntimeException('Brak archiwum fotografii fixture.');
    }
    $bytes = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_ends_with($zip->getNameIndex($i), 'placki-ziemniaczane.webp')) {
            $bytes = $zip->getFromIndex($i);
            break;
        }
    }
    $zip->close();
    $size = $bytes === false ? false : getimagesizefromstring($bytes);
    if ($size === false) {
        throw new RuntimeException('Brak poprawnej fotografii fixture.');
    }
    $data = DB::transaction(function () use ($namespace, $host, $gate, $bytes, $size): array {
        $author = User::factory()->create(['email' => $namespace.'-autor@example.test', 'wants_weekly_digest' => false]);
        $author->refresh()->profile->update(['username' => str_replace('-', '_', $namespace).'_autor', 'display_name' => 'Małgorzata — rodzinne przepisy i domowe wypieki']);
        $cook = User::factory()->create(['email' => $namespace.'-kucharz@example.test', 'wants_weekly_digest' => false]);
        $cook->refresh()->profile->update(['username' => str_replace('-', '_', $namespace).'_kucharz', 'display_name' => 'Stanisław — gotowanie dla rodziny']);
        $posts = [];
        $photos = [];
        for ($i = 0; $i < 4; $i++) {
            $post = Post::factory()->create(['author_id' => $author->id,
                'body' => 'Dane odbioru panelu: placki ziemniaczane ze wspólnego niedzielnego gotowania. '.$namespace.'-'.$i,
                'published_at' => now()->subHours(30 - $i)]);
            $key = 'media/'.$namespace.'-'.$i.'.webp';
            $variants = [];
            foreach (['thumb', 'feed', 'large'] as $variant) {
                $variantKey = Media::kluczPublicznegoWariantu($key, $variant);
                if (Storage::disk('public')->exists($variantKey) || ! Storage::disk('public')->put($variantKey, $bytes)) {
                    throw new RuntimeException('Odmowa nadpisania lub błąd zapisu fotografii fixture.');
                }
                $variants[$variant] = ['key' => $variantKey, 'width' => $size[0], 'height' => $size[1]];
            }
            $photo = Media::create(['owner_id' => $author->id, 'disk' => 'public', 'object_key' => $key,
                'mime_type' => 'image/webp', 'bytes' => strlen($bytes), 'width' => $size[0], 'height' => $size[1],
                'status' => Media::STATUS_READY, 'alt_text' => 'Fotografia poglądowa placków — odbiór panelu',
                'metadata' => ['variants' => $variants]]);
            $post->media()->attach($photo->id, ['position' => 0]);
            HeroPick::create(['media_id' => $photo->id, 'post_id' => $post->id, 'position' => $i, 'curator_id' => $host->id]);
            $posts[] = $post;
            $photos[] = $photo;
        }
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'slug' => $namespace.'-placki',
            'title' => 'Placki ziemniaczane z sosem grzybowym — dane odbioru panelu',
            'summary' => 'Przepis pomiarowy z jawnym autorem, fotografią i instrukcją.',
            'hero_media_id' => $photos[0]->id, 'servings' => 4, 'published_at' => now()->subHours(28)]);
        $recipe->steps()->create(['position' => 1, 'instruction' => 'Przygotuj ziemniaki i usmaż placki. Dane odbioru panelu.']);
        $event = app(RecordCookedEvent::class)->handle($cook, $recipe,
            note: 'Dane odbioru: podałem z sosem grzybowym; rodzina prosi o powtórkę.', actualMinutes: 45);
        $report = Report::create(['reporter_id' => $cook->id, 'target_type' => 'post', 'target_id' => $posts[0]->id,
            'source' => Report::SOURCE_COMMUNITY, 'reason' => 'other', 'details' => 'Dane odbioru: proszę sprawdzić podpis i autorstwo zdjęcia.', 'status' => Report::STATUS_OPEN]);
        Report::create(['autor_tresci_id' => $author->id, 'target_type' => 'post', 'target_id' => $posts[1]->id,
            'source' => Report::SOURCE_AUTOMAT, 'reason' => 'automat_powtorzenie',
            'details' => 'Dane odbioru: podobny podpis dwóch zdjęć, do oceny przez człowieka.', 'status' => Report::STATUS_OPEN]);
        $action = ModerationAction::create(['moderator_id' => $gate->id, 'target_type' => 'post',
            'target_id' => $posts[2]->id, 'subject_user_id' => $author->id, 'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'other', 'note' => 'Dane odbioru historii decyzji.', 'user_message' => 'Dane odbioru: prosimy wyjaśnić autorstwo podpisu.']);
        $appeal = Appeal::create(['moderation_action_id' => $action->id, 'user_id' => $author->id,
            'appellant' => Appeal::APPELLANT_AUTHOR, 'body' => 'Dane odbioru: zdjęcie i podpis przygotowałam samodzielnie. Proszę ponownie sprawdzić decyzję.', 'status' => Appeal::STATUS_OPEN]);
        $message = ContactMessage::factory()->create(['user_id' => $author->id, 'contact_email' => $author->email,
            'message' => 'Dane odbioru: po powiększeniu tekstu nie mogę odnaleźć podpisu zdjęcia. Proszę o wskazówkę.', 'wydanie' => 'panel581']);
        $reply = ContactMessageReply::create(['contact_message_id' => $message->id, 'author_id' => $host->id,
            'body' => 'Dane odbioru historii odpowiedzi; ta wiadomość nie została wysłana.']);
        $reply->oznaczNieudana('Lokalna fixture: wysyłka nie była podejmowana.');
        $tag = Tag::create(['name' => $namespace, 'normalized_name' => $namespace, 'slug' => $namespace]);
        TagPromotion::create(['tag_id' => $tag->id, 'position' => 0, 'note' => 'Dane odbioru: rodzinne dania na wspólny obiad.']);
        foreach ([[DailyPick::TYPE_POST, $posts[0]->id], [DailyPick::TYPE_USER, $author->id]] as [$type, $id]) {
            DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => $type, 'subject_id' => $id,
                'position' => 0, 'curator_id' => $host->id, 'note' => 'Dane odbioru redakcyjnego wyboru.']);
        }

        return ['author' => (string) $author->id, 'report' => (string) $report->id, 'appeal' => (string) $appeal->id,
            'message' => (string) $message->id, 'recipe' => $recipe->slug, 'cooked' => (string) $event->id];
    });
    $fullRoutes = [
        'zgloszenia' => ['/admin/zgloszenia', 'form[action$="/admin/zgloszenia/'.$data['report'].'"]'],
        'sygnaly' => ['/admin/sygnaly', '.sygnal-podglad img'],
        'odwolania' => ['/admin/odwolania', 'article.odwolanie'],
        'wiadomosci' => ['/admin/wiadomosci', 'a[href$="/admin/wiadomosci/'.$data['message'].'"]'],
        'wiadomosc' => ['/admin/wiadomosci/'.$data['message'], 'article.wizard-row'],
        'kolaz-powitalny' => ['/admin/kolaz-powitalny', 'input[name="zdjecia[]"]:checked'],
        'kuking-na-dzis' => ['/admin/kuking-na-dzis', 'input[name="wpisy[]"]:checked'],
        'tagi-promowane' => ['/admin/tagi-promowane', 'form[action$="/admin/tagi-promowane/'.$namespace.'"]'],
        'uzytkownicy' => ['/admin/uzytkownicy?szukaj='.$namespace, '.tabela-kont tbody tr'],
        'uzytkownik' => ['/admin/uzytkownicy/'.$data['author'], 'main article'],
    ];
    foreach ($fullRoutes as $family => [$path, $selector]) {
        $scenarios[] = $scenario($family.'-pelny', $family, $path, [$one($selector, $family === 'kolaz-powitalny' ? 4 : 1)]);
    }
    foreach (['wpisy', 'przepisy', 'ugotowane'] as $type) {
        $scenarios[] = $scenario('bez-odpowiedzi-'.$type.'-pelny', 'bez-odpowiedzi', '/admin/bez-odpowiedzi?typ='.$type,
            [$one('main article.card'), $one('main .empty-state-title', 0, 0)]);
    }
    $state['phase'] = 'pelny';
    $state['dane'] = $data;
}
$state['scenariusze'] = $scenarios;
$encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$handle = fopen($statePath, $phase === 'pusty' ? 'x' : 'wb');
if ($handle === false || fwrite($handle, $encoded) !== strlen($encoded)) {
    throw new RuntimeException('Nie zapisano pliku stanu. Nie ponawiaj bez sprawdzenia bazy.');
}
fclose($handle);
chmod($statePath, 0600);
echo 'PANEL581: przygotowano fazę '.$phase.'; scenariuszy '.count($scenarios).'. Poświadczenia tylko w zewnętrznym pliku.'.PHP_EOL;
