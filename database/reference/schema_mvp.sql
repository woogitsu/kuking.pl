CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE users (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email varchar(255) NOT NULL UNIQUE,
    password varchar(255) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','suspended','banned','pending_delete')),
    locale varchar(10) NOT NULL DEFAULT 'pl',
    text_scale smallint NOT NULL DEFAULT 100
        CHECK (text_scale BETWEEN 90 AND 140),
    email_verified_at timestamptz,
    remember_token varchar(100),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE media (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    disk varchar(40) NOT NULL DEFAULT 'public',
    object_key varchar(700) NOT NULL UNIQUE,
    mime_type varchar(120),
    bytes bigint CHECK (bytes >= 0),
    width integer CHECK (width > 0),
    height integer CHECK (height > 0),
    status varchar(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending','processing','ready','rejected','deleted')),
    alt_text varchar(500),
    checksum_sha256 char(64),
    perceptual_hash varchar(128),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX media_owner_created_idx ON media(owner_id, created_at DESC);

CREATE TABLE profiles (
    user_id uuid PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    username varchar(40) NOT NULL UNIQUE,
    display_name varchar(100) NOT NULL,
    bio varchar(500),
    avatar_media_id uuid REFERENCES media(id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (username ~ '^[a-zA-Z0-9_]{3,40}$')
);

CREATE INDEX profiles_username_trgm_idx
    ON profiles USING gin (username gin_trgm_ops);

CREATE TABLE follows (
    follower_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (follower_id, followed_id),
    CHECK (follower_id <> followed_id)
);

CREATE INDEX follows_followed_idx ON follows(followed_id, created_at DESC);

CREATE TABLE blocks (
    blocker_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    blocked_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (blocker_id, blocked_id),
    CHECK (blocker_id <> blocked_id)
);

CREATE TABLE posts (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body varchar(4000),
    visibility varchar(20) NOT NULL DEFAULT 'public'
        CHECK (visibility IN ('public','followers','private')),
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','published','hidden','removed')),
    published_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);

CREATE INDEX posts_author_published_idx
    ON posts(author_id, published_at DESC, id DESC)
    WHERE deleted_at IS NULL;

CREATE TABLE post_media (
    post_id uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    media_id uuid NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    position smallint NOT NULL DEFAULT 0 CHECK (position >= 0),
    PRIMARY KEY (post_id, media_id),
    UNIQUE (post_id, position)
);

CREATE TABLE recipes (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title varchar(180) NOT NULL,
    slug varchar(220) NOT NULL UNIQUE,
    summary varchar(2000),
    servings numeric(6,2) CHECK (servings > 0),
    prep_minutes integer CHECK (prep_minutes >= 0),
    cook_minutes integer CHECK (cook_minutes >= 0),
    difficulty varchar(12)
        CHECK (difficulty IN ('easy','medium','hard')),
    visibility varchar(20) NOT NULL DEFAULT 'public'
        CHECK (visibility IN ('public','followers','private')),
    status varchar(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','published','hidden','removed')),
    hero_media_id uuid REFERENCES media(id) ON DELETE SET NULL,
    source_type varchar(20) NOT NULL DEFAULT 'own'
        CHECK (source_type IN ('own','family','adaptation','external')),
    source_url text,
    published_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);

CREATE INDEX recipes_author_published_idx
    ON recipes(author_id, published_at DESC, id DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX recipes_title_trgm_idx
    ON recipes USING gin (title gin_trgm_ops);

CREATE TABLE recipe_versions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    editor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    version_number integer NOT NULL CHECK (version_number > 0),
    snapshot jsonb NOT NULL,
    change_note varchar(500),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (recipe_id, version_number)
);

CREATE TABLE ingredients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    canonical_name varchar(160) NOT NULL,
    normalized_name varchar(160) NOT NULL UNIQUE,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX ingredients_name_trgm_idx
    ON ingredients USING gin (normalized_name gin_trgm_ops);

CREATE TABLE units (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code varchar(30) NOT NULL UNIQUE,
    name varchar(80) NOT NULL,
    unit_type varchar(30),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE recipe_ingredients (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    group_name varchar(120),
    ingredient_id uuid REFERENCES ingredients(id) ON DELETE SET NULL,
    ingredient_text varchar(240) NOT NULL,
    quantity numeric(12,4) CHECK (quantity >= 0),
    unit_id uuid REFERENCES units(id) ON DELETE SET NULL,
    note varchar(300),
    position smallint NOT NULL CHECK (position >= 0),
    UNIQUE (recipe_id, position)
);

CREATE TABLE recipe_steps (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    position smallint NOT NULL CHECK (position >= 0),
    instruction text NOT NULL,
    media_id uuid REFERENCES media(id) ON DELETE SET NULL,
    timer_seconds integer CHECK (timer_seconds >= 0),
    UNIQUE (recipe_id, position)
);

CREATE TABLE cooked_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    note varchar(2000),
    would_make_again boolean,
    perceived_difficulty varchar(12)
        CHECK (perceived_difficulty IN ('easy','medium','hard')),
    actual_minutes integer CHECK (actual_minutes >= 0),
    cooked_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX cooked_events_recipe_idx
    ON cooked_events(recipe_id, cooked_at DESC);

CREATE INDEX cooked_events_user_idx
    ON cooked_events(user_id, cooked_at DESC);

CREATE TABLE cooked_event_media (
    cooked_event_id uuid NOT NULL REFERENCES cooked_events(id) ON DELETE CASCADE,
    media_id uuid NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    position smallint NOT NULL DEFAULT 0 CHECK (position >= 0),
    PRIMARY KEY (cooked_event_id, media_id),
    UNIQUE (cooked_event_id, position)
);

CREATE TABLE comments (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    post_id uuid REFERENCES posts(id) ON DELETE CASCADE,
    recipe_id uuid REFERENCES recipes(id) ON DELETE CASCADE,
    cooked_event_id uuid REFERENCES cooked_events(id) ON DELETE CASCADE,
    parent_id uuid REFERENCES comments(id) ON DELETE CASCADE,
    body varchar(4000) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'published'
        CHECK (status IN ('published','hidden','removed')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz,
    CHECK (num_nonnulls(post_id, recipe_id, cooked_event_id) = 1)
);

CREATE INDEX comments_post_idx ON comments(post_id, created_at);
CREATE INDEX comments_recipe_idx ON comments(recipe_id, created_at);
CREATE INDEX comments_cooked_idx ON comments(cooked_event_id, created_at);

CREATE TABLE collections (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name varchar(120) NOT NULL,
    description varchar(500),
    visibility varchar(20) NOT NULL DEFAULT 'private'
        CHECK (visibility IN ('public','private')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE collection_items (
    collection_id uuid NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (collection_id, recipe_id)
);

CREATE TABLE notifications (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type varchar(80) NOT NULL,
    data jsonb NOT NULL DEFAULT '{}'::jsonb,
    read_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX notifications_user_unread_idx
    ON notifications(user_id, created_at DESC)
    WHERE read_at IS NULL;

CREATE TABLE reports (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    reporter_id uuid REFERENCES users(id) ON DELETE SET NULL,
    target_type varchar(30) NOT NULL
        CHECK (target_type IN ('user','post','recipe','comment','cooked_event')),
    target_id uuid NOT NULL,
    reason varchar(40) NOT NULL,
    details varchar(2000),
    status varchar(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open','triage','reviewing','resolved','rejected')),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX reports_status_created_idx ON reports(status, created_at);

CREATE TABLE moderation_actions (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    moderator_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    report_id uuid REFERENCES reports(id) ON DELETE SET NULL,
    target_type varchar(30) NOT NULL,
    target_id uuid NOT NULL,
    action varchar(40) NOT NULL,
    reason_code varchar(80) NOT NULL,
    note varchar(2000),
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE audit_log (
    id bigserial PRIMARY KEY,
    actor_id uuid REFERENCES users(id) ON DELETE SET NULL,
    action varchar(100) NOT NULL,
    subject_type varchar(80),
    subject_id uuid,
    ip_hash varchar(128),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now()
);
