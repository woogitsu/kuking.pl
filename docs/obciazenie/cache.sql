\set k random(1, 50000)
select "value" from "cache" where "key" = 'klucz' || :k and "expiration" > 1700000000;
