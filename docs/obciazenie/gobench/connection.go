package main

import (
	"net"
	"net/url"
	"os"
	"regexp"
	"strconv"
)

// Ta sama jawna granica co w PHP i build_scale.sh; brak domyślnego serwera/bazy.
func dsn(db string) string {
	host, port, user := os.Getenv("PGHOST"), os.Getenv("PGPORT"), os.Getenv("PGUSER")
	n, err := strconv.Atoi(port)
	if !regexp.MustCompile(`^kuking_bench_[a-z0-9_]{1,40}$`).MatchString(db) ||
		(host != "127.0.0.1" && host != "localhost") ||
		!regexp.MustCompile(`^[0-9]{4,5}$`).MatchString(port) || err != nil || n < 1024 || n > 65535 || n == 5432 ||
		!regexp.MustCompile(`^[a-z_][a-z0-9_]{0,62}$`).MatchString(user) ||
		os.Getenv("PGHOSTADDR") != "" || os.Getenv("PGSERVICE") != "" || os.Getenv("PGSERVICEFILE") != "" {
		panic("Wskaż BENCH_DB kuking_bench_*, lokalny PGHOST, izolowany PGPORT (nie 5432) i PGUSER.")
	}
	u := url.URL{Scheme: "postgres", Host: net.JoinHostPort(host, port), Path: "/" + db, User: url.User(user)}
	if password, ok := os.LookupEnv("PGPASSWORD"); ok {
		u.User = url.UserPassword(user, password)
	}
	return u.String()
}
