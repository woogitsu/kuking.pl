package main

import (
	"context"
	"fmt"
	"html"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
)

const feedSQL = `
select "posts".*,
  (select count(*) from "comments" where "comments"."post_id" = "posts"."id"
     and "comments"."status" = 'published' and "comments"."deleted_at" is null) as "comments_count",
  (select count(*) from "collection_items" where "collection_items"."post_id" = "posts"."id") as "zapisow_count",
  exists (select 1 from "collection_items"
     inner join "collections" on "collections"."id" = "collection_items"."collection_id"
     where "collection_items"."post_id" = "posts"."id" and "collections"."owner_id" = $1) as "czy_zapisany"
from "posts"
where "posts"."status" = 'published' and "posts"."published_at" is not null
  and "posts"."visibility" = 'public'
  and exists (select 1 from "users" where "users"."id" = "posts"."author_id" and "users"."status" = 'active')
  and not exists (select 1 from "blocks" where ("blocks"."blocker_id" = $1 and "blocks"."blocked_id" = "posts"."author_id")
                    or ("blocks"."blocker_id" = "posts"."author_id" and "blocks"."blocked_id" = $1))
  and ("posts"."recipe_id" is null or exists (
        select 1 from "recipes" where "recipes"."id" = "posts"."recipe_id"
          and "recipes"."status" = 'published' and "recipes"."published_at" is not null
          and "recipes"."visibility" = 'public' and "recipes"."deleted_at" is null))
  and "posts"."deleted_at" is null
order by "posts"."published_at" desc, "posts"."id" desc
limit 10`

const feedFixSQL = `
select "posts".*,
  (select count(*) from "comments" where "comments"."post_id" = "posts"."id"
     and "comments"."status" = 'published' and "comments"."deleted_at" is null) as "comments_count",
  (select count(*) from "collection_items" where "collection_items"."post_id" = "posts"."id") as "zapisow_count",
  exists (select 1 from "collection_items"
     inner join "collections" on "collections"."id" = "collection_items"."collection_id"
     where "collection_items"."post_id" = "posts"."id" and "collections"."owner_id" = $1) as "czy_zapisany"
from "posts"
left join "recipes" on "recipes"."id" = "posts"."recipe_id"
  and "recipes"."status" = 'published' and "recipes"."published_at" is not null
  and "recipes"."visibility" = 'public' and "recipes"."deleted_at" is null
inner join "users" on "users"."id" = "posts"."author_id" and "users"."status" = 'active'
where "posts"."status" = 'published' and "posts"."published_at" is not null
  and "posts"."visibility" = 'public'
  and not exists (select 1 from "blocks" where ("blocks"."blocker_id" = $1 and "blocks"."blocked_id" = "posts"."author_id")
                    or ("blocks"."blocker_id" = "posts"."author_id" and "blocks"."blocked_id" = $1))
  and ("posts"."recipe_id" is null or "recipes"."id" is not null)
  and "posts"."deleted_at" is null
order by "posts"."published_at" desc, "posts"."id" desc
limit 10`

func activeFeedSQL() string {
	dir := os.Getenv("BENCH_SQL_DIR")
	if dir == "" {
		dir = ".."
	}
	name := "feed_orig_go.sql"
	if os.Getenv("BENCH_FIX") == "1" {
		name = "feed_fix_go.sql"
	}
	b, err := os.ReadFile(dir + "/" + name)
	must(err)
	return string(b)
}

func labelSuffix() string {
	if os.Getenv("BENCH_FIX") == "1" {
		return "/fix"
	}
	return ""
}

type Post struct {
	ID            string     `gorm:"column:id;primaryKey"`
	AuthorID      string     `gorm:"column:author_id"`
	Body          *string    `gorm:"column:body"`
	Visibility    string     `gorm:"column:visibility"`
	Status        string     `gorm:"column:status"`
	RecipeID      *string    `gorm:"column:recipe_id"`
	DisplayMode   string     `gorm:"column:display_mode"`
	PublishedAt   *time.Time `gorm:"column:published_at"`
	CreatedAt     time.Time  `gorm:"column:created_at"`
	UpdatedAt     time.Time  `gorm:"column:updated_at"`
	DeletedAt     *time.Time `gorm:"column:deleted_at"`
	CommentsCount int        `gorm:"column:comments_count"`
	ZapisowCount  int        `gorm:"column:zapisow_count"`
	CzyZapisany   bool       `gorm:"column:czy_zapisany"`

	Author *User    `gorm:"foreignKey:AuthorID"`
	Recipe *Recipe  `gorm:"foreignKey:RecipeID"`
	Media  []Media  `gorm:"many2many:post_media;joinForeignKey:post_id;joinReferences:media_id"`
	Tags   []Tag    `gorm:"many2many:post_tags;joinForeignKey:post_id;joinReferences:tag_id"`
}

func (Post) TableName() string { return "posts" }

type User struct {
	ID      string `gorm:"column:id;primaryKey"`
	Email   string `gorm:"column:email"`
	Status  string `gorm:"column:status"`
	Role    string `gorm:"column:role"`
	Profile *Profile `gorm:"foreignKey:UserID"`
}

func (User) TableName() string { return "users" }

type Profile struct {
	UserID        string  `gorm:"column:user_id;primaryKey"`
	Username      string  `gorm:"column:username"`
	DisplayName   string  `gorm:"column:display_name"`
	Bio           *string `gorm:"column:bio"`
	AvatarMediaID *string `gorm:"column:avatar_media_id"`
	Region        *string `gorm:"column:region"`
	Avatar        *Media  `gorm:"foreignKey:AvatarMediaID"`
}

func (Profile) TableName() string { return "profiles" }

type Media struct {
	ID        string  `gorm:"column:id;primaryKey"`
	OwnerID   string  `gorm:"column:owner_id"`
	ObjectKey string  `gorm:"column:object_key"`
	MimeType  *string `gorm:"column:mime_type"`
	Width     *int    `gorm:"column:width"`
	Height    *int    `gorm:"column:height"`
	Status    string  `gorm:"column:status"`
}

func (Media) TableName() string { return "media" }

type Recipe struct {
	ID          string  `gorm:"column:id;primaryKey"`
	Title       string  `gorm:"column:title"`
	Slug        string  `gorm:"column:slug"`
	Visibility  string  `gorm:"column:visibility"`
	HeroMediaID *string `gorm:"column:hero_media_id"`
	HeroMedia   *Media  `gorm:"foreignKey:HeroMediaID"`
}

func (Recipe) TableName() string { return "recipes" }

type Tag struct {
	ID   string `gorm:"column:id;primaryKey"`
	Slug string `gorm:"column:slug"`
	Name string `gorm:"column:name"`
}

func (Tag) TableName() string { return "tags" }

type row struct {
	id, authorID string
	body         string
	recipeID     *string
	comments     int
	zapisow      int
}

func main() {
	mode := os.Getenv("BENCH_MODE")
	db := os.Getenv("BENCH_DB")
	iter, _ := strconv.Atoi(os.Getenv("BENCH_ITER"))
	if iter == 0 {
		iter = 200
	}
	viewer := os.Getenv("BENCH_VIEWER")
	if viewer == "" {
		viewer = "00000000-0000-4000-8000-000000000003"
	}

	if mode == "gorm" {
		runGorm(db, iter, viewer)
		return
	}
	runPgx(db, iter, viewer)
}

func report(db, label string, iter int, total, dbTime time.Duration, queries, htmlLen int) {
	fmt.Printf("%s\t%s\titer=%d\ttotal=%.3fs\tavg=%.2fms\tdb=%.2fms\tklient=%.2fms\tzapytan/str=%.1f\thtml=%dB\trps=%.1f\n",
		db, label, iter, total.Seconds(),
		float64(total.Microseconds())/float64(iter)/1000,
		float64(dbTime.Microseconds())/float64(iter)/1000,
		float64((total-dbTime).Microseconds())/float64(iter)/1000,
		float64(queries)/float64(iter), htmlLen/iter, float64(iter)/total.Seconds())
}

func runPgx(db string, iter int, viewer string) {
	ctx := context.Background()
	pool, err := pgxpool.New(ctx, dsn(db))
	must(err)
	defer pool.Close()

	sqlText := activeFeedSQL()
	var dbTime time.Duration
	queries, rendered := 0, 0
	t0 := time.Now()

	for n := 0; n < iter; n++ {
		q0 := time.Now()
		rows, err := pool.Query(ctx, sqlText, viewer)
		must(err)
		var posts []row
		var postIDs, authorIDs, recipeIDs []string
		for rows.Next() {
			vals, err := rows.Values()
			must(err)
			fds := rows.FieldDescriptions()
			var r row
			for i, fd := range fds {
				switch fd.Name {
				case "id":
					r.id = asUUID(vals[i])
				case "author_id":
					r.authorID = asUUID(vals[i])
				case "body":
					if vals[i] != nil {
						r.body = vals[i].(string)
					}
				case "recipe_id":
					if vals[i] != nil {
						s := asUUID(vals[i])
						r.recipeID = &s
					}
				case "comments_count":
					r.comments = int(vals[i].(int64))
				case "zapisow_count":
					r.zapisow = int(vals[i].(int64))
				}
			}
			posts = append(posts, r)
		}
		rows.Close()
		must(rows.Err())
		dbTime += time.Since(q0)
		queries++

		if len(posts) == 0 {
			continue
		}
		if os.Getenv("BENCH_VERIFY") == "1" && n == 0 {
			fmt.Fprintf(os.Stderr, "KONTROLA Go+pgx:\n")
			for _, p := range posts {
				fmt.Fprintf(os.Stderr, "  %s c=%d z=%d\n", p.id, p.comments, p.zapisow)
			}
		}
		seenA := map[string]bool{}
		seenR := map[string]bool{}
		for _, p := range posts {
			postIDs = append(postIDs, p.id)
			if !seenA[p.authorID] {
				seenA[p.authorID] = true
				authorIDs = append(authorIDs, p.authorID)
			}
			if p.recipeID != nil && !seenR[*p.recipeID] {
				seenR[*p.recipeID] = true
				recipeIDs = append(recipeIDs, *p.recipeID)
			}
		}

		// 2. autorzy
		q0 = time.Now()
		r2, err := pool.Query(ctx, `select id, email, status, role from users where id = any($1)`, authorIDs)
		must(err)
		for r2.Next() {
			var id, email, status, role string
			must(r2.Scan(&id, &email, &status, &role))
		}
		r2.Close()
		must(r2.Err())
		dbTime += time.Since(q0)
		queries++

		// 3. profile
		q0 = time.Now()
		prof, err := pool.Query(ctx, `select user_id, username, display_name, bio, avatar_media_id, region from profiles where user_id = any($1)`, authorIDs)
		must(err)
		type pr struct{ user, uname, dname, avatar string }
		profs := map[string]pr{}
		var avatarIDs []string
		for prof.Next() {
			var uid, uname, dname string
			var bio, avatar, region *string
			must(prof.Scan(&uid, &uname, &dname, &bio, &avatar, &region))
			av := ""
			if avatar != nil {
				av = *avatar
				avatarIDs = append(avatarIDs, av)
			}
			profs[uid] = pr{uid, uname, dname, av}
		}
		prof.Close()
		dbTime += time.Since(q0)
		queries++

		// 4. awatary
		if len(avatarIDs) > 0 {
			q0 = time.Now()
			r4, err := pool.Query(ctx, `select id, object_key, width, height from media where id = any($1)`, avatarIDs)
			must(err)
			for r4.Next() {
			}
			r4.Close()
			dbTime += time.Since(q0)
			queries++
		}

		// 5. zdjęcia wpisów
		q0 = time.Now()
		r5, err := pool.Query(ctx, `select "media"."id", "media"."width", "media"."height", "post_media"."post_id"
			from "media" inner join "post_media" on "media"."id" = "post_media"."media_id"
			where "post_media"."post_id" = any($1) order by "post_media"."position"`, postIDs)
		must(err)
		type mrec struct {
			id            string
			width, height int
		}
		mediaByPost := map[string][]mrec{}
		for r5.Next() {
			var id, pid string
			var w, h *int
			must(r5.Scan(&id, &w, &h, &pid))
			mm := mrec{id: id}
			if w != nil {
				mm.width = *w
			}
			if h != nil {
				mm.height = *h
			}
			mediaByPost[pid] = append(mediaByPost[pid], mm)
		}
		r5.Close()
		dbTime += time.Since(q0)
		queries++

		// 6. przepisy + zdjęcia główne
		if len(recipeIDs) > 0 {
			q0 = time.Now()
			r6, err := pool.Query(ctx, `select id, title, slug, visibility, hero_media_id from recipes where id = any($1)`, recipeIDs)
			must(err)
			var heroIDs []string
			for r6.Next() {
				var id, title, slug, vis string
				var hero *string
				must(r6.Scan(&id, &title, &slug, &vis, &hero))
				if hero != nil {
					heroIDs = append(heroIDs, *hero)
				}
			}
			r6.Close()
			dbTime += time.Since(q0)
			queries++

			if len(heroIDs) > 0 {
				q0 = time.Now()
				r7, err := pool.Query(ctx, `select id, object_key, width, height from media where id = any($1)`, heroIDs)
				must(err)
				for r7.Next() {
				}
				r7.Close()
				dbTime += time.Since(q0)
				queries++
			}
		}

		// 7. tagi
		q0 = time.Now()
		r8, err := pool.Query(ctx, `select "tags"."id", "tags"."slug", "tags"."name", "post_tags"."post_id"
			from "tags" inner join "post_tags" on "tags"."id" = "post_tags"."tag_id"
			where "post_tags"."post_id" = any($1)`, postIDs)
		must(err)
		type trec struct{ slug, name string }
		tagsByPost := map[string][]trec{}
		for r8.Next() {
			var id, slug, name, pid string
			must(r8.Scan(&id, &slug, &name, &pid))
			tagsByPost[pid] = append(tagsByPost[pid], trec{slug, name})
		}
		r8.Close()
		dbTime += time.Since(q0)
		queries++

		// RENDER
		var sb strings.Builder
		sb.WriteString(`<main class="feed">`)
		for _, p := range posts {
			pf := profs[p.authorID]
			sb.WriteString(`<article class="post"><header><img src="/zdjecia/`)
			sb.WriteString(html.EscapeString(pf.avatar))
			sb.WriteString(`/male" alt=""><a href="/@`)
			sb.WriteString(html.EscapeString(pf.uname))
			sb.WriteString(`">`)
			sb.WriteString(html.EscapeString(pf.dname))
			sb.WriteString(`</a></header><p>`)
			sb.WriteString(strings.ReplaceAll(html.EscapeString(p.body), "\n", "<br />\n"))
			sb.WriteString(`</p>`)
			for _, m := range mediaByPost[p.id] {
				sb.WriteString(`<img src="/zdjecia/`)
				sb.WriteString(html.EscapeString(m.id))
				sb.WriteString(`/duze" width="`)
				sb.WriteString(strconv.Itoa(m.width))
				sb.WriteString(`" height="`)
				sb.WriteString(strconv.Itoa(m.height))
				sb.WriteString(`" alt="" loading="lazy">`)
			}
			sb.WriteString(`<ul class="tagi">`)
			for _, t := range tagsByPost[p.id] {
				sb.WriteString(`<li><a href="/tag/`)
				sb.WriteString(html.EscapeString(t.slug))
				sb.WriteString(`">#`)
				sb.WriteString(html.EscapeString(t.name))
				sb.WriteString(`</a></li>`)
			}
			sb.WriteString(`</ul><footer>`)
			sb.WriteString(strconv.Itoa(p.comments))
			sb.WriteString(` komentarzy · `)
			sb.WriteString(strconv.Itoa(p.zapisow))
			sb.WriteString(` zapisów</footer></article>`)
		}
		sb.WriteString(`</main>`)
		rendered += sb.Len()
	}

	report(db, "Go+pgx"+labelSuffix(), iter, time.Since(t0), dbTime, queries, rendered)
}

func runGorm(dbName string, iter int, viewer string) {
	licznik := &liczacyLogger{}
	gdb, err := gorm.Open(postgres.Open(dsn(dbName)), &gorm.Config{
		Logger:                 licznik,
		SkipDefaultTransaction: true,
		PrepareStmt:            true,
	})
	must(err)

	sqlText := activeFeedSQL()
	var dbTime time.Duration
	rendered := 0
	t0 := time.Now()

	for n := 0; n < iter; n++ {
		var posts []Post
		q0 := time.Now()

		// JEDNO pobranie feedu: zapytanie wchodzi jako podzapytanie do Find,
		// więc Preload dokłada relacje do TYCH SAMYCH wierszy. Wcześniejsza
		// wersja tego harnessu robiła Raw().Scan() i zaraz potem drugi Find()
		// po identyfikatorach — czyli liczyła GORM-owi jedno zapytanie za dużo.
		sub := gdb.Raw(sqlText, viewer)
		must(gdb.Table("(?) as posts", sub).
			Preload("Author").
			Preload("Author.Profile").
			Preload("Author.Profile.Avatar").
			Preload("Media").
			Preload("Tags").
			Preload("Recipe").
			Preload("Recipe.HeroMedia").
			Order("published_at desc, id desc").
			Find(&posts).Error)
		dbTime += time.Since(q0)

		if len(posts) == 0 {
			continue
		}
		if os.Getenv("BENCH_VERIFY") == "1" && n == 0 {
			wypiszKontrole("Go+GORM", posts)
		}

		var sb strings.Builder
		sb.WriteString(`<main class="feed">`)
		for _, p := range posts {
			uname, dname, avatar := "", "", ""
			if p.Author != nil && p.Author.Profile != nil {
				uname = p.Author.Profile.Username
				dname = p.Author.Profile.DisplayName
				if p.Author.Profile.AvatarMediaID != nil {
					avatar = *p.Author.Profile.AvatarMediaID
				}
			}
			sb.WriteString(`<article class="post"><header><img src="/zdjecia/`)
			sb.WriteString(html.EscapeString(avatar))
			sb.WriteString(`/male" alt=""><a href="/@`)
			sb.WriteString(html.EscapeString(uname))
			sb.WriteString(`">`)
			sb.WriteString(html.EscapeString(dname))
			sb.WriteString(`</a></header><p>`)
			body := ""
			if p.Body != nil {
				body = *p.Body
			}
			sb.WriteString(strings.ReplaceAll(html.EscapeString(body), "\n", "<br />\n"))
			sb.WriteString(`</p>`)
			for _, m := range p.Media {
				w, h := 0, 0
				if m.Width != nil {
					w = *m.Width
				}
				if m.Height != nil {
					h = *m.Height
				}
				sb.WriteString(`<img src="/zdjecia/`)
				sb.WriteString(html.EscapeString(m.ID))
				sb.WriteString(`/duze" width="`)
				sb.WriteString(strconv.Itoa(w))
				sb.WriteString(`" height="`)
				sb.WriteString(strconv.Itoa(h))
				sb.WriteString(`" alt="" loading="lazy">`)
			}
			sb.WriteString(`<ul class="tagi">`)
			for _, t := range p.Tags {
				sb.WriteString(`<li><a href="/tag/`)
				sb.WriteString(html.EscapeString(t.Slug))
				sb.WriteString(`">#`)
				sb.WriteString(html.EscapeString(t.Name))
				sb.WriteString(`</a></li>`)
			}
			sb.WriteString(`</ul><footer>`)
			sb.WriteString(strconv.Itoa(p.CommentsCount))
			sb.WriteString(` komentarzy · `)
			sb.WriteString(strconv.Itoa(p.ZapisowCount))
			sb.WriteString(` zapisów</footer></article>`)
		}
		sb.WriteString(`</main>`)
		rendered += sb.Len()
	}

	report(dbName, "Go+GORM"+labelSuffix(), iter, time.Since(t0), dbTime, int(licznik.n), rendered)
}

// liczacyLogger liczy KAŻDĄ instrukcję SQL, którą GORM naprawdę wysyła.
// Poprzednia wersja harnessu dopisywała stałą `queries += 7` — to było
// założenie, nie pomiar.
type liczacyLogger struct{ n int64 }

func (l *liczacyLogger) LogMode(logger.LogLevel) logger.Interface { return l }
func (l *liczacyLogger) Info(context.Context, string, ...any)     {}
func (l *liczacyLogger) Warn(context.Context, string, ...any)     {}
func (l *liczacyLogger) Error(context.Context, string, ...any)    {}
func (l *liczacyLogger) Trace(_ context.Context, _ time.Time, fc func() (string, int64), _ error) {
	atomic.AddInt64(&l.n, 1)
}

func wypiszKontrole(label string, posts []Post) {
	fmt.Fprintf(os.Stderr, "KONTROLA %s:\n", label)
	for _, p := range posts {
		fmt.Fprintf(os.Stderr, "  %s c=%d z=%d\n", p.ID, p.CommentsCount, p.ZapisowCount)
	}
}

func ids(posts []Post) []string {
	out := make([]string, 0, len(posts))
	for _, p := range posts {
		out = append(out, p.ID)
	}
	return out
}

func asUUID(v any) string {
	switch t := v.(type) {
	case string:
		return t
	case [16]byte:
		return fmt.Sprintf("%x-%x-%x-%x-%x", t[0:4], t[4:6], t[6:8], t[8:10], t[10:16])
	default:
		return fmt.Sprint(v)
	}
}

func must(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, "BŁĄD:", err)
		os.Exit(1)
	}
}
