-- Zapytania mierzone przez scripts/pomiar-pytan-372.py (#372).
--
-- Odtworzone ręcznie z tego, co buduje Laravel dla `/pytania`
-- (QuestionController::index → QuestionList::query), z flagą pytań WŁĄCZONĄ
-- (Post::scopeEnabledKinds nic wtedy nie dokłada). Kolejność warunków
-- i powtórzenia (`published()` dwa razy) jak w wygenerowanym SQL-u:
--   * `->count()` zdejmuje z zapytania kolumny, `withCount` i ORDER BY
--     (Query\Builder::aggregate/setAggregate), więc licznik to czysty COUNT(*);
--   * `whereDoesntHave('allComments', …)` = NOT EXISTS z warunkami
--     QuestionList::visibleAnswers + Comment::scopeWidoczneDla + SoftDeletes;
--   * `whereHas('tags', …)` = EXISTS na tags ⋈ post_tags.
-- Zmiana QuestionList / scopeWidoczneDla wymaga poprawienia tego pliku —
-- inaczej pomiar mierzy zapytanie, którego aplikacja już nie wysyła.
--
-- Od 25.09.2026 odpowiedź = komentarz INNEJ osoby (`OdpowiedzNaPytanie`),
-- stąd `comments.author_id != posts.author_id` w każdym podzapytaniu.
--
-- Zapytania `licznik_*` to pełny COUNT, który do 25.09.2026 szedł na każde
-- wejście na /pytania. Dziś `licznik_gosc` i `przeliczenie_tagi` liczy zadanie
-- w tle (`PytaniaBezOdpowiedzi::przelicz()`), a na żądanie idą tylko
-- `poprawka_*` dla zalogowanego (plus jeden odczyt cache).
--
-- Znaczniki: {widz} = uuid widza pomiaru, {tag} = slug tagu,
-- {w_blokadzie} = lista uuid osób w blokadzie z widzem (w obie strony).
-- Każdy blok zaczyna się od `-- nazwa:` i kończy średnikiem.

-- nazwa: licznik_gosc
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and status = 'published' and published_at is not null and visibility = 'public'
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.deleted_at is null;

-- nazwa: licznik_gosc_tag
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and status = 'published' and published_at is not null and visibility = 'public'
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and exists (select * from tags inner join post_tags on tags.id = post_tags.tag_id
      where posts.id = post_tags.post_id and slug = '{tag}' and status = 'active')
  and posts.deleted_at is null;

-- nazwa: licznik_konto
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = posts.author_id)
      or (blocks.blocker_id = posts.author_id and blocks.blocked_id = '{widz}'))
  and (posts.author_id = '{widz}' or (status = 'published' and published_at is not null
      and (visibility = 'public' or (visibility = 'followers'
          and exists (select 1 from follows where follows.follower_id = '{widz}' and follows.followed_id = posts.author_id)))))
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = comments.author_id)
          or (blocks.blocker_id = comments.author_id and blocks.blocked_id = '{widz}'))
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.deleted_at is null;

-- nazwa: licznik_konto_tag
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = posts.author_id)
      or (blocks.blocker_id = posts.author_id and blocks.blocked_id = '{widz}'))
  and (posts.author_id = '{widz}' or (status = 'published' and published_at is not null
      and (visibility = 'public' or (visibility = 'followers'
          and exists (select 1 from follows where follows.follower_id = '{widz}' and follows.followed_id = posts.author_id)))))
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = comments.author_id)
          or (blocks.blocker_id = comments.author_id and blocks.blocked_id = '{widz}'))
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and exists (select * from tags inner join post_tags on tags.id = post_tags.tag_id
      where posts.id = post_tags.post_id and slug = '{tag}' and status = 'active')
  and posts.deleted_at is null;

-- Pierwsza strona listy „Najnowsze” dla gościa: cursorPaginate(15) pobiera
-- 16 wierszy, `withCount` liczy odpowiedzi skorelowanym podzapytaniem.
-- nazwa: lista_gosc
select posts.*, (select count(*) from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null) as answer_count
from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and status = 'published' and published_at is not null and visibility = 'public'
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and posts.deleted_at is null
order by published_at desc, id desc limit 16;

-- nazwa: lista_gosc_bez_odpowiedzi
select posts.*, (select count(*) from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null) as answer_count
from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and status = 'published' and published_at is not null and visibility = 'public'
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.deleted_at is null
order by published_at desc, id desc limit 16;

-- Przeliczenie w tle, część per tag (`PytaniaBezOdpowiedzi::przelicz()`).
-- nazwa: przeliczenie_tagi
select tags.slug, count(*) as ile from post_tags
inner join tags on tags.id = post_tags.tag_id
where tags.status = 'active'
  and post_tags.post_id in (select posts.id from posts
      where posts.kind = 'question'
        and status = 'published' and published_at is not null
        and status = 'published' and published_at is not null and visibility = 'public'
        and exists (select * from users where posts.author_id = users.id and status = 'active')
        and not exists (select * from comments where posts.id = comments.post_id
            and comments.parent_id is null and comments.body_removed_at is null
            and comments.author_id != posts.author_id
            and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
            and comments.status = 'published' and comments.deleted_at is null)
        and posts.deleted_at is null)
group by tags.slug;

-- Poprawka widza na żądanie: kto jest z nim w blokadzie (dwa zapytania w kodzie).
-- nazwa: poprawka_blokady
select blocked_id from blocks where blocker_id = '{widz}'
union all
select blocker_id from blocks where blocked_id = '{widz}';

-- (1) pytania z liczby gościa, których autor jest w blokadzie z widzem.
-- nazwa: poprawka_1_ukryte
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and status = 'published' and published_at is not null and visibility = 'public'
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.author_id in ({w_blokadzie})
  and posts.deleted_at is null;

-- (2) niepubliczne pytania widoczne dla widza: własne i od obserwowanych.
-- nazwa: poprawka_2_niepubliczne
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = posts.author_id)
      or (blocks.blocker_id = posts.author_id and blocks.blocked_id = '{widz}'))
  and (posts.author_id = '{widz}' or (status = 'published' and published_at is not null
      and (visibility = 'public' or (visibility = 'followers'
          and exists (select 1 from follows where follows.follower_id = '{widz}' and follows.followed_id = posts.author_id)))))
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = comments.author_id)
          or (blocks.blocker_id = comments.author_id and blocks.blocked_id = '{widz}'))
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.visibility != 'public'
  and (posts.author_id = '{widz}' or posts.author_id in (select followed_id from follows where follower_id = '{widz}'))
  and posts.deleted_at is null;

-- (3) publiczne pytania odpowiedziane wyłącznie przez osoby w blokadzie z widzem.
-- nazwa: poprawka_3_odpowiedzi_z_blokady
select count(*) as aggregate from posts
where posts.kind = 'question'
  and status = 'published' and published_at is not null
  and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = posts.author_id)
      or (blocks.blocker_id = posts.author_id and blocks.blocked_id = '{widz}'))
  and (posts.author_id = '{widz}' or (status = 'published' and published_at is not null
      and (visibility = 'public' or (visibility = 'followers'
          and exists (select 1 from follows where follows.follower_id = '{widz}' and follows.followed_id = posts.author_id)))))
  and exists (select * from users where posts.author_id = users.id and status = 'active')
  and not exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and not exists (select 1 from blocks where (blocks.blocker_id = '{widz}' and blocks.blocked_id = comments.author_id)
          or (blocks.blocker_id = comments.author_id and blocks.blocked_id = '{widz}'))
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.deleted_at is null)
  and posts.visibility = 'public'
  and exists (select * from comments where posts.id = comments.post_id
      and comments.parent_id is null and comments.body_removed_at is null
      and comments.author_id != posts.author_id
      and exists (select * from users where comments.author_id = users.id and status not in ('banned', 'pending_delete'))
      and comments.status = 'published' and comments.author_id in ({w_blokadzie})
      and comments.deleted_at is null)
  and posts.deleted_at is null;

-- Odczyt z cache na każdą odsłonę (CACHE_STORE=database): jedno zapytanie
-- po kluczu głównym tabeli `cache`. Tabela pomiaru nie ma `cache`, więc
-- mierzymy równoważny odczyt po kluczu głównym `tags`.
-- nazwa: odczyt_cache_rownowaznik
select * from tags where id = md5('p372:tag:0')::uuid;
