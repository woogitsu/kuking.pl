-- V1/V2 placeholders. Nie uruchamiać automatycznie.

CREATE TABLE groups (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id),
    slug varchar(120) NOT NULL UNIQUE,
    name varchar(120) NOT NULL,
    description text,
    visibility varchar(20) NOT NULL DEFAULT 'public',
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE group_members (
    group_id uuid NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    user_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role varchar(20) NOT NULL DEFAULT 'member',
    joined_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (group_id, user_id)
);

CREATE TABLE recipe_forks (
    parent_recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE RESTRICT,
    child_recipe_id uuid NOT NULL UNIQUE REFERENCES recipes(id) ON DELETE CASCADE,
    created_by uuid NOT NULL REFERENCES users(id),
    note text,
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_recipe_id <> child_recipe_id)
);

CREATE TABLE meal_plans (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    week_start date NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE pantry_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ingredient_id uuid REFERENCES ingredients(id) ON DELETE SET NULL,
    label varchar(160) NOT NULL,
    quantity numeric(12,4),
    unit_id uuid REFERENCES units(id) ON DELETE SET NULL,
    expires_on date
);
