select "posts".*,
  (select count(*) from "comments"
     where "comments"."post_id" = "posts"."id"
       and not exists (select 1 from "blocks"
             where ("blocks"."blocker_id" = '00000000-0000-4000-8000-000000000003' and "blocks"."blocked_id" = "comments"."author_id")
                or ("blocks"."blocker_id" = "comments"."author_id" and "blocks"."blocked_id" = '00000000-0000-4000-8000-000000000003'))
       and exists (select 1 from "users" as "ca" where "ca"."id" = "comments"."author_id"
             and "ca"."status" not in ('banned','pending_delete'))
       and "comments"."status" = 'published'
       and "comments"."deleted_at" is null) as "comments_count",
  (select count(distinct "zu"."id") from "users" as "zu"
     inner join "collections" on "collections"."owner_id" = "zu"."id"
     inner join "collection_items" on "collection_items"."collection_id" = "collections"."id"
     where "collection_items"."post_id" = "posts"."id"
       and "zu"."id" <> "posts"."author_id"
       and "zu"."status" = 'active'
       and not exists (select 1 from "blocks"
             where ("blocks"."blocker_id" = '00000000-0000-4000-8000-000000000003' and "blocks"."blocked_id" = "zu"."id")
                or ("blocks"."blocker_id" = "zu"."id" and "blocks"."blocked_id" = '00000000-0000-4000-8000-000000000003'))) as "zapisow_count",
  exists (select 1 from "collection_items"
     inner join "collections" on "collections"."id" = "collection_items"."collection_id"
     where "collection_items"."post_id" = "posts"."id" and "collections"."owner_id" = '00000000-0000-4000-8000-000000000003') as "czy_zapisany"
from "posts"
left join "recipes" on "recipes"."id" = "posts"."recipe_id"
  and "recipes"."status" = 'published' and "recipes"."published_at" is not null
  and "recipes"."visibility" = 'public' and "recipes"."deleted_at" is null
inner join "users" on "users"."id" = "posts"."author_id" and "users"."status" = 'active'
where "posts"."status" = 'published' and "posts"."published_at" is not null
  and "posts"."visibility" = 'public'
  and true
  and not exists (select 1 from "blocks" where ("blocks"."blocker_id" = '00000000-0000-4000-8000-000000000003' and "blocks"."blocked_id" = "posts"."author_id")
                    or ("blocks"."blocker_id" = "posts"."author_id" and "blocks"."blocked_id" = '00000000-0000-4000-8000-000000000003'))
  and ("posts"."recipe_id" is null or "recipes"."id" is not null)
  and "posts"."deleted_at" is null
order by "posts"."published_at" desc, "posts"."id" desc
limit 10