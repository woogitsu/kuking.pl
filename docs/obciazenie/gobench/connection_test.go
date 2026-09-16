package main

import (
	"net/url"
	"testing"
)

func TestExplicitConnection(t *testing.T) {
	t.Setenv("PGHOST", "127.0.0.1")
	t.Setenv("PGPORT", "55439")
	t.Setenv("PGUSER", "benchmark_user")
	t.Setenv("PGPASSWORD", "test:@/quote'")
	u, err := url.Parse(dsn("kuking_bench_test"))
	if err != nil || u.Host != "127.0.0.1:55439" || u.Path != "/kuking_bench_test" || u.User.Username() != "benchmark_user" {
		t.Fatal("Parametry połączenia nie zostały zachowane")
	}
	password, _ := u.User.Password()
	if password != "test:@/quote'" {
		t.Fatal("Kodowanie hasła")
	}
}

func TestUnsafeConnectionRejected(t *testing.T) {
	for _, db := range []string{"kuking", "postgres", "", "kuking_bench_x;DROP DATABASE kuking", "kuking_bench_"} {
		t.Run(db, func(t *testing.T) {
			t.Setenv("PGHOST", "127.0.0.1")
			t.Setenv("PGPORT", "55439")
			t.Setenv("PGUSER", "benchmark_user")
			defer func() {
				if recover() == nil {
					t.Fatal("Niebezpieczna nazwa przyjęta")
				}
			}()
			dsn(db)
		})
	}
	for _, port := range []string{"", "5432", "0", "65536", "55439;"} {
		t.Run("port"+port, func(t *testing.T) {
			t.Setenv("PGHOST", "localhost")
			t.Setenv("PGPORT", port)
			t.Setenv("PGUSER", "benchmark_user")
			defer func() {
				if recover() == nil {
					t.Fatal("Niebezpieczny port przyjęty")
				}
			}()
			dsn("kuking_bench_test")
		})
	}
}
