<?php
declare(strict_types=1);
// Zestaw zapytań jednej strony feedu odkrywania dla ZALOGOWANEGO widza,
// w kształcie, jaki generuje Eloquent (feed + 7 doładowań relacji).
// Mierzy osobno czas w bazie i czas w kliencie.

$db   = getenv('BENCH_DB') ?: 'kuking_bench';
$iter = (int) (getenv('BENCH_ITER') ?: 200);
$viewer = getenv('BENCH_VIEWER') ?: '00000000-0000-4000-8000-000000000003';

$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$db}", 'kuking', 'kuking', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$FEED = <<<SQL
select "posts".*,
  (select count(*) from "comments" where "comments"."post_id" = "posts"."id"
     and "comments"."status" = 'published' and "comments"."deleted_at" is null) as "comments_count",
  (select count(*) from "collection_items" where "collection_items"."post_id" = "posts"."id") as "zapisow_count",
  exists (select 1 from "collection_items"
     inner join "collections" on "collections"."id" = "collection_items"."collection_id"
     where "collection_items"."post_id" = "posts"."id" and "collections"."owner_id" = :v) as "czy_zapisany"
from "posts"
where "posts"."status" = 'published' and "posts"."published_at" is not null
  and "posts"."visibility" = 'public'
  and exists (select 1 from "users" where "users"."id" = "posts"."author_id" and "users"."status" = 'active')
  and not exists (select 1 from "blocks" where ("blocks"."blocker_id" = :v2 and "blocks"."blocked_id" = "posts"."author_id")
                    or ("blocks"."blocker_id" = "posts"."author_id" and "blocks"."blocked_id" = :v3))
  and ("posts"."recipe_id" is null or exists (
        select 1 from "recipes" where "recipes"."id" = "posts"."recipe_id"
          and "recipes"."status" = 'published' and "recipes"."published_at" is not null
          and "recipes"."visibility" = 'public' and "recipes"."deleted_at" is null))
  and "posts"."deleted_at" is null
order by "posts"."published_at" desc, "posts"."id" desc
limit 10
SQL;

$FEED_FIX = <<<SQL
select "posts".*,
  (select count(*) from "comments" where "comments"."post_id" = "posts"."id"
     and "comments"."status" = 'published' and "comments"."deleted_at" is null) as "comments_count",
  (select count(*) from "collection_items" where "collection_items"."post_id" = "posts"."id") as "zapisow_count",
  exists (select 1 from "collection_items"
     inner join "collections" on "collections"."id" = "collection_items"."collection_id"
     where "collection_items"."post_id" = "posts"."id" and "collections"."owner_id" = :v) as "czy_zapisany"
from "posts"
left join "recipes" on "recipes"."id" = "posts"."recipe_id"
  and "recipes"."status" = 'published' and "recipes"."published_at" is not null
  and "recipes"."visibility" = 'public' and "recipes"."deleted_at" is null
inner join "users" on "users"."id" = "posts"."author_id" and "users"."status" = 'active'
where "posts"."status" = 'published' and "posts"."published_at" is not null
  and "posts"."visibility" = 'public'
  and not exists (select 1 from "blocks" where ("blocks"."blocker_id" = :v2 and "blocks"."blocked_id" = "posts"."author_id")
                    or ("blocks"."blocker_id" = "posts"."author_id" and "blocks"."blocked_id" = :v3))
  and ("posts"."recipe_id" is null or "recipes"."id" is not null)
  and "posts"."deleted_at" is null
order by "posts"."published_at" desc, "posts"."id" desc
limit 10
SQL;

if (getenv('BENCH_FIX') === '1') { $FEED = $FEED_FIX; }

$STMT = [];
$prep = function (string $sql) use ($pdo, &$STMT) {
    if (getenv('BENCH_CACHE') !== '1') { return $pdo->prepare($sql); }
    $k = crc32($sql);
    return $STMT[$k] ??= $pdo->prepare($sql);
};

function inList(array $ids): string {
    return implode(',', array_fill(0, max(count($ids), 1), '?'));
}

$dbTime = 0.0; $rendered = 0; $queries = 0;
$t0 = hrtime(true);

for ($n = 0; $n < $iter; $n++) {
    // 1. FEED
    $q0 = hrtime(true);
    $st = $prep($FEED);
    $st->execute([':v' => $viewer, ':v2' => $viewer, ':v3' => $viewer]);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

    if ($posts === []) { continue; }

    $postIds   = array_column($posts, 'id');
    $authorIds = array_values(array_unique(array_column($posts, 'author_id')));
    $recipeIds = array_values(array_filter(array_unique(array_column($posts, 'recipe_id'))));

    // 2. autorzy
    $q0 = hrtime(true);
    $st = $prep('select * from users where id in ('.inList($authorIds).')');
    $st->execute($authorIds);
    $authors = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

    // 3. profile
    $q0 = hrtime(true);
    $st = $prep('select * from profiles where user_id in ('.inList($authorIds).')');
    $st->execute($authorIds);
    $profiles = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

    // 4. awatary
    $avatarIds = array_values(array_filter(array_unique(array_column($profiles, 'avatar_media_id'))));
    if ($avatarIds !== []) {
        $q0 = hrtime(true);
        $st = $prep('select * from media where id in ('.inList($avatarIds).')');
        $st->execute($avatarIds);
        $st->fetchAll(PDO::FETCH_ASSOC);
        $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;
    }

    // 5. zdjęcia wpisów
    $q0 = hrtime(true);
    $st = $prep('select "media".*, "post_media"."post_id" as "pivot_post_id" from "media"
        inner join "post_media" on "media"."id" = "post_media"."media_id"
        where "post_media"."post_id" in ('.inList($postIds).') order by "post_media"."position"');
    $st->execute($postIds);
    $media = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

    // 6. przepisy
    if ($recipeIds !== []) {
        $q0 = hrtime(true);
        $st = $prep('select id, title, slug, visibility, hero_media_id from recipes where id in ('.inList($recipeIds).')');
        $st->execute($recipeIds);
        $recipes = $st->fetchAll(PDO::FETCH_ASSOC);
        $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

        $heroIds = array_values(array_filter(array_unique(array_column($recipes, 'hero_media_id'))));
        if ($heroIds !== []) {
            $q0 = hrtime(true);
            $st = $prep('select * from media where id in ('.inList($heroIds).')');
            $st->execute($heroIds);
            $st->fetchAll(PDO::FETCH_ASSOC);
            $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;
        }
    }

    // 7. tagi
    $q0 = hrtime(true);
    $st = $prep('select "tags"."id", "tags"."slug", "tags"."name", "post_tags"."post_id" as "pivot_post_id"
        from "tags" inner join "post_tags" on "tags"."id" = "post_tags"."tag_id"
        where "post_tags"."post_id" in ('.inList($postIds).')');
    $st->execute($postIds);
    $tags = $st->fetchAll(PDO::FETCH_ASSOC);
    $dbTime += (hrtime(true) - $q0) / 1e9; $queries++;

    // RENDER: sklejenie relacji + HTML (to jest praca klienta, nie bazy)
    $byAuthor = [];
    foreach ($authors as $a) { $byAuthor[$a['id']] = $a; }
    $profByUser = [];
    foreach ($profiles as $p) { $profByUser[$p['user_id']] = $p; }
    $mediaByPost = [];
    foreach ($media as $m) { $mediaByPost[$m['pivot_post_id']][] = $m; }
    $tagsByPost = [];
    foreach ($tags as $t) { $tagsByPost[$t['pivot_post_id']][] = $t; }

    $html = '<main class="feed">';
    foreach ($posts as $p) {
        $prof = $profByUser[$p['author_id']] ?? ['display_name' => '', 'username' => ''];
        $html .= '<article class="post"><header><img src="/zdjecia/'
            . htmlspecialchars((string) ($prof['avatar_media_id'] ?? '')) . '/male" alt=""><a href="/@'
            . htmlspecialchars((string) $prof['username']) . '">'
            . htmlspecialchars((string) $prof['display_name']) . '</a></header><p>'
            . nl2br(htmlspecialchars((string) $p['body'])) . '</p>';
        foreach ($mediaByPost[$p['id']] ?? [] as $m) {
            $html .= '<img src="/zdjecia/' . htmlspecialchars($m['id']) . '/duze" width="'
                . (int) $m['width'] . '" height="' . (int) $m['height'] . '" alt="" loading="lazy">';
        }
        $html .= '<ul class="tagi">';
        foreach ($tagsByPost[$p['id']] ?? [] as $t) {
            $html .= '<li><a href="/tag/' . htmlspecialchars($t['slug']) . '">#'
                . htmlspecialchars($t['name']) . '</a></li>';
        }
        $html .= '</ul><footer>' . (int) $p['comments_count'] . ' komentarzy · '
            . (int) $p['zapisow_count'] . ' zapisów</footer></article>';
    }
    $html .= '</main>';
    $rendered += strlen($html);
}

$total = (hrtime(true) - $t0) / 1e9;
printf("%s\tPHP+PDO\titer=%d\ttotal=%.3fs\tavg=%.2fms\tdb=%.2fms\tklient=%.2fms\tzapytan/str=%.1f\thtml=%dB\trps=%.1f\n",
    $db, $iter, $total, $total / $iter * 1000, $dbTime / $iter * 1000,
    ($total - $dbTime) / $iter * 1000, $queries / $iter, intdiv($rendered, max($iter, 1)), $iter / $total);
