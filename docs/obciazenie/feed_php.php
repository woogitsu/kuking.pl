<?php

declare(strict_types=1);
// Zestaw zapytań jednej strony feedu odkrywania dla ZALOGOWANEGO widza,
// w kształcie, jaki generuje Eloquent (feed + 7 doładowań relacji).
// Mierzy osobno czas w bazie i czas w kliencie.

require __DIR__.'/connection.php';
[$dsn, $user, $password] = benchmarkConnection();
$db = getenv('BENCH_DB');
$iter = (int) (getenv('BENCH_ITER') ?: 200);
$viewer = getenv('BENCH_VIEWER') ?: '00000000-0000-4000-8000-000000000003';

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$sqlDir = getenv('BENCH_SQL_DIR') ?: __DIR__;
$FEED = file_get_contents($sqlDir.'/'.(getenv('BENCH_FIX') === '1' ? 'feed_fix_php.sql' : 'feed_orig_php.sql'));
if ($FEED === false) {
    fwrite(STDERR, "brak pliku SQL\n");
    exit(1);
}
$LICZBA_V = substr_count($FEED, '?');

$STMT = [];
$prep = function (string $sql) use ($pdo, &$STMT) {
    if (getenv('BENCH_CACHE') !== '1') {
        return $pdo->prepare($sql);
    }
    $k = crc32($sql);

    return $STMT[$k] ??= $pdo->prepare($sql);
};

function inList(array $ids): string
{
    return implode(',', array_fill(0, max(count($ids), 1), '?'));
}

$dbTime = 0.0;
$rendered = 0;
$queries = 0;
$t0 = hrtime(true);

for ($n = 0; $n < $iter; $n++) {
    // 1. FEED
    $q0 = hrtime(true);
    $st = $prep($FEED);
    $st->execute(array_fill(0, $LICZBA_V, $viewer));
    $posts = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9;
    $queries++;

    if ($posts === []) {
        continue;
    }

    if (getenv('BENCH_VERIFY') === '1' && $n === 0) {
        fwrite(STDERR, "KONTROLA PHP+PDO:\n");
        foreach ($posts as $p) {
            fwrite(STDERR, sprintf("  %s c=%d z=%d\n", $p['id'], (int) $p['comments_count'], (int) $p['zapisow_count']));
        }
    }

    $postIds = array_column($posts, 'id');
    $authorIds = array_values(array_unique(array_column($posts, 'author_id')));
    $recipeIds = array_values(array_filter(array_unique(array_column($posts, 'recipe_id'))));

    // 2. autorzy
    $q0 = hrtime(true);
    $st = $prep('select * from users where id in ('.inList($authorIds).')');
    $st->execute($authorIds);
    $authors = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9;
    $queries++;

    // 3. profile
    $q0 = hrtime(true);
    $st = $prep('select * from profiles where user_id in ('.inList($authorIds).')');
    $st->execute($authorIds);
    $profiles = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9;
    $queries++;

    // 4. awatary
    $avatarIds = array_values(array_filter(array_unique(array_column($profiles, 'avatar_media_id'))));
    if ($avatarIds !== []) {
        $q0 = hrtime(true);
        $st = $prep('select * from media where id in ('.inList($avatarIds).')');
        $st->execute($avatarIds);
        $st->fetchAll(PDO::FETCH_ASSOC);
        $dbTime += (hrtime(true) - $q0) / 1e9;
        $queries++;
    }

    // 5. zdjęcia wpisów
    $q0 = hrtime(true);
    $st = $prep('select "media".*, "post_media"."post_id" as "pivot_post_id" from "media"
        inner join "post_media" on "media"."id" = "post_media"."media_id"
        where "post_media"."post_id" in ('.inList($postIds).') order by "post_media"."position"');
    $st->execute($postIds);
    $media = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9;
    $queries++;

    // 6. przepisy
    if ($recipeIds !== []) {
        $q0 = hrtime(true);
        $st = $prep('select id, title, slug, visibility, hero_media_id from recipes where id in ('.inList($recipeIds).')');
        $st->execute($recipeIds);
        $recipes = $st->fetchAll(PDO::FETCH_ASSOC);
        $dbTime += (hrtime(true) - $q0) / 1e9;
        $queries++;

        $heroIds = array_values(array_filter(array_unique(array_column($recipes, 'hero_media_id'))));
        if ($heroIds !== []) {
            $q0 = hrtime(true);
            $st = $prep('select * from media where id in ('.inList($heroIds).')');
            $st->execute($heroIds);
            $st->fetchAll(PDO::FETCH_ASSOC);
            $dbTime += (hrtime(true) - $q0) / 1e9;
            $queries++;
        }
    }

    // 7. tagi
    $q0 = hrtime(true);
    $st = $prep('select "tags"."id", "tags"."slug", "tags"."name", "post_tags"."post_id" as "pivot_post_id"
        from "tags" inner join "post_tags" on "tags"."id" = "post_tags"."tag_id"
        where "post_tags"."post_id" in ('.inList($postIds).')');
    $st->execute($postIds);
    $tags = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9;
    $queries++;

    // RENDER: sklejenie relacji + HTML (to jest praca klienta, nie bazy)
    $byAuthor = [];
    foreach ($authors as $a) {
        $byAuthor[$a['id']] = $a;
    }
    $profByUser = [];
    foreach ($profiles as $p) {
        $profByUser[$p['user_id']] = $p;
    }
    $mediaByPost = [];
    foreach ($media as $m) {
        $mediaByPost[$m['pivot_post_id']][] = $m;
    }
    $tagsByPost = [];
    foreach ($tags as $t) {
        $tagsByPost[$t['pivot_post_id']][] = $t;
    }

    $html = '<main class="feed">';
    foreach ($posts as $p) {
        $prof = $profByUser[$p['author_id']] ?? ['display_name' => '', 'username' => ''];
        $html .= '<article class="post"><header><img src="/zdjecia/'
            .htmlspecialchars((string) ($prof['avatar_media_id'] ?? '')).'/male" alt=""><a href="/@'
            .htmlspecialchars((string) $prof['username']).'">'
            .htmlspecialchars((string) $prof['display_name']).'</a></header><p>'
            .nl2br(htmlspecialchars((string) $p['body'])).'</p>';
        foreach ($mediaByPost[$p['id']] ?? [] as $m) {
            $html .= '<img src="/zdjecia/'.htmlspecialchars($m['id']).'/duze" width="'
                .(int) $m['width'].'" height="'.(int) $m['height'].'" alt="" loading="lazy">';
        }
        $html .= '<ul class="tagi">';
        foreach ($tagsByPost[$p['id']] ?? [] as $t) {
            $html .= '<li><a href="/tag/'.htmlspecialchars($t['slug']).'">#'
                .htmlspecialchars($t['name']).'</a></li>';
        }
        $html .= '</ul><footer>'.(int) $p['comments_count'].' komentarzy · '
            .(int) $p['zapisow_count'].' zapisów</footer></article>';
    }
    $html .= '</main>';
    $rendered += strlen($html);
}

$total = (hrtime(true) - $t0) / 1e9;
printf("%s\tPHP+PDO\titer=%d\ttotal=%.3fs\tavg=%.2fms\tdb=%.2fms\tklient=%.2fms\tzapytan/str=%.1f\thtml=%dB\trps=%.1f\n",
    $db, $iter, $total, $total / $iter * 1000, $dbTime / $iter * 1000,
    ($total - $dbTime) / $iter * 1000, $queries / $iter, intdiv($rendered, max($iter, 1)), $iter / $total);
