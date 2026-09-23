CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE OR REPLACE FUNCTION public.kuking_normalize(text)
RETURNS text
AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, lower($1)) $$
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE;

CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(255) NOT NULL UNIQUE,
    password varchar(255) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','suspended','banned','pending_delete','erased')),
    role varchar(20) NOT NULL DEFAULT 'user' CHECK (role IN ('user','moderator','admin')),
    locale varchar(10) NOT NULL DEFAULT 'pl',
    text_scale smallint NOT NULL DEFAULT 100,
    wants_weekly_digest boolean NOT NULL DEFAULT false,
    email_verified_at timestamptz,
    remember_token varchar(100),
    ostatnio_widziany_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE media (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    disk varchar(40) NOT NULL DEFAULT 'public',
    variants_disk varchar(40),
    object_key varchar(700) NOT NULL UNIQUE,
    mime_type varchar(120),
    bytes bigint,
    width integer,
    height integer,
    status varchar(20) NOT NULL DEFAULT 'ready'
        CHECK (status IN ('pending','processing','ready','rejected','deleted')),
    alt_text varchar(500),
    checksum_sha256 char(64),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX media_owner_created_idx ON media(owner_id, created_at);
CREATE INDEX media_checksum_idx ON media(checksum_sha256);

CREATE TABLE profiles (
    user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    username varchar(40) NOT NULL UNIQUE,
    display_name varchar(100) NOT NULL,
    bio varchar(500),
    avatar_media_id uuid REFERENCES media(id) ON DELETE SET NULL,
    region varchar(80),
    speciality varchar(120),
    display_name_search text GENERATED ALWAYS AS (public.kuking_normalize(display_name)) STORED,
    username_search text GENERATED ALWAYS AS (public.kuking_normalize(username)) STORED,
    speciality_search text GENERATED ALWAYS AS (public.kuking_normalize(coalesce(speciality,''))) STORED,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX profiles_username_trgm_idx ON profiles USING gin (username_search gin_trgm_ops);
CREATE INDEX profiles_display_name_trgm_idx ON profiles USING gin (display_name_search gin_trgm_ops);
CREATE INDEX profiles_speciality_trgm_idx ON profiles USING gin (speciality_search gin_trgm_ops);

CREATE TABLE follows (
    follower_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (follower_id, followed_id),
    CHECK (follower_id <> followed_id)
);
CREATE INDEX follows_followed_idx ON follows(followed_id, created_at);

CREATE TABLE blocks (
    blocker_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    blocked_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (blocker_id, blocked_id),
    CHECK (blocker_id <> blocked_id)
);
CREATE INDEX blocks_blocked_idx ON blocks(blocked_id);

CREATE TABLE recipes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title varchar(180) NOT NULL,
    slug varchar(220) NOT NULL UNIQUE,
    summary varchar(2000),
    servings numeric(6,2),
    prep_minutes integer,
    cook_minutes integer,
    difficulty varchar(12),
    visibility varchar(20) NOT NULL DEFAULT 'public'
        CHECK (visibility IN ('public','followers','private')),
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','published','hidden','removed')),
    hero_media_id uuid REFERENCES media(id) ON DELETE SET NULL,
    source_type varchar(20) NOT NULL DEFAULT 'own',
    published_at timestamptz,
    title_search text GENERATED ALWAYS AS (public.kuking_normalize(title)) STORED,
    summary_search text GENERATED ALWAYS AS (public.kuking_normalize(coalesce(summary,''))) STORED,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE INDEX recipes_author_published_idx ON recipes (author_id, published_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX recipes_title_trgm_idx ON recipes USING gin (title_search gin_trgm_ops);
CREATE INDEX recipes_summary_trgm_idx ON recipes USING gin (summary_search gin_trgm_ops);

CREATE TABLE posts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body varchar(4000),
    visibility varchar(20) NOT NULL DEFAULT 'public'
        CHECK (visibility IN ('public','followers','private')),
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','published','hidden','removed')),
    recipe_id uuid REFERENCES recipes(id) ON DELETE SET NULL,
    display_mode varchar(20) NOT NULL DEFAULT 'standard',
    published_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE INDEX posts_author_published_idx ON posts (author_id, published_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX posts_published_idx ON posts (published_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND visibility = 'public';

CREATE TABLE post_media (
    post_id uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    media_id uuid NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    position smallint NOT NULL DEFAULT 0 CHECK (position >= 0),
    PRIMARY KEY (post_id, media_id),
    UNIQUE (post_id, position)
);

CREATE TABLE comments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    post_id uuid REFERENCES posts(id) ON DELETE CASCADE,
    recipe_id uuid REFERENCES recipes(id) ON DELETE CASCADE,
    cooked_event_id uuid,
    parent_id uuid REFERENCES comments(id) ON DELETE CASCADE,
    body varchar(4000) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'published'
        CHECK (status IN ('published','hidden','removed')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE INDEX comments_post_idx ON comments (post_id, created_at);
CREATE INDEX comments_recipe_idx ON comments (recipe_id, created_at);
CREATE INDEX comments_author_idx ON comments (author_id, created_at DESC);
CREATE INDEX comments_parent_idx ON comments (parent_id);

CREATE TABLE tags (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name varchar(30) NOT NULL,
    normalized_name varchar(30) NOT NULL UNIQUE,
    slug varchar(40) NOT NULL UNIQUE,
    status varchar(10) NOT NULL DEFAULT 'active',
    is_seeded boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tags_name_trgm_idx ON tags USING gin (public.kuking_normalize(name) gin_trgm_ops);

CREATE TABLE post_tags (
    post_id uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag_id uuid NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    position smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (post_id, tag_id),
    UNIQUE (post_id, position)
);
CREATE INDEX post_tags_tag_idx ON post_tags(tag_id);

CREATE TABLE collections (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name varchar(120) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX collections_owner_idx ON collections(owner_id);

CREATE TABLE collection_items (
    collection_id uuid NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
    recipe_id uuid REFERENCES recipes(id) ON DELETE CASCADE,
    post_id uuid REFERENCES posts(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(recipe_id, post_id) = 1)
);
CREATE UNIQUE INDEX collection_items_recipe_uniq ON collection_items(collection_id, recipe_id) WHERE recipe_id IS NOT NULL;
CREATE UNIQUE INDEX collection_items_post_uniq ON collection_items(collection_id, post_id) WHERE post_id IS NOT NULL;
CREATE INDEX collection_items_post_idx ON collection_items(post_id);

CREATE TABLE cooked_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    note varchar(2000),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX cooked_recipe_idx ON cooked_events(recipe_id, created_at DESC);
CREATE INDEX cooked_user_idx ON cooked_events(user_id, created_at DESC);
