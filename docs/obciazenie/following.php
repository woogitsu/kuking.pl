<?php

declare(strict_types=1);
require __DIR__.'/connection.php';
[$dsn, $user, $password] = benchmarkConnection();
$pdo = new PDO($dsn, $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

$probes = [
    20 => '00000000-0000-4000-8000-000000000001',
    200 => '00000000-0000-4000-8000-000000000002',
    2000 => '00000000-0000-4000-8000-000000000003',
    10000 => '00000000-0000-4000-8000-000000000004',
];
function med(array $a): float
{
    sort($a);

    return $a[intdiv(count($a), 2)];
}

printf("%14s %11s %11s %12s %11s\n", 'obserwowanych', 'pluck[ms]', 'feed[ms]', 'razem[ms]', 'SQL[KB]');
foreach ($probes as $label => $uid) {
    $tp = [];
    for ($i = 0; $i < 7; $i++) {
        $s = hrtime(true);
        $st = $pdo->prepare('select users.id from users inner join follows on users.id = follows.followed_id where follows.follower_id = ?');
        $st->execute([$uid]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        $tp[] = (hrtime(true) - $s) / 1e6;
    }
    $n = count($ids);
    $ph = implode(',', array_fill(0, max($n, 1), '?'));
    $sql = 'select "posts".*,
      (select count(*) from "comments" where "comments"."post_id" = "posts"."id"
         and "comments"."status" = \'published\' and "comments"."deleted_at" is null) as comments_count
      from "posts"
      where "posts"."status" = \'published\' and "posts"."published_at" is not null
        and "posts"."author_id" in ('.$ph.')
        and "posts"."visibility" in (\'public\',\'followers\')
        and exists (select 1 from "users" where "users"."id" = "posts"."author_id" and "users"."status" = \'active\')
        and "posts"."deleted_at" is null
      order by "posts"."published_at" desc, "posts"."id" desc limit 10';
    $tf = [];
    for ($i = 0; $i < 7; $i++) {
        $s = hrtime(true);
        $st = $pdo->prepare($sql);
        $st->execute($ids ?: ['00000000-0000-4000-8000-000000000000']);
        $st->fetchAll(PDO::FETCH_ASSOC);
        $tf[] = (hrtime(true) - $s) / 1e6;
    }
    printf("%14d %11.2f %11.2f %12.2f %11.1f\n", $n, med($tp), med($tf), med($tp) + med($tf), strlen($sql) / 1024);
}
