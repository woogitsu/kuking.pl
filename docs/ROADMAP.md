# Roadmap

## 0. Fundament
- Laravel start;
- Postgres;
- CI;
- Railway staging;
- `/health`;
- auth;
- layout;
- design tokens;
- error monitoring.

**DoD:** green CI + staging.

## 1. User
- register/login;
- profile;
- avatar;
- text size;
- privacy.

**Test:** użytkownik tworzy konto i zwiększa tekst.

## 2. Social graph
- follow;
- unfollow;
- block;
- lists;
- policies.

**Test:** blocked user nie może follow.

## 3. Post
- upload;
- photo + text;
- edit;
- delete;
- profile archive;
- permalink.

**Test UX:** osoba 50+ dodaje zdjęcie bez pomocy.

## 4. Feed
- following feed;
- cursor pagination;
- empty state;
- minimal Discover.

## 5. Recipes
- draft;
- 3-step wizard;
- ingredients;
- steps;
- versions;
- SEO page.

**Test:** przerwanie wizard nie traci danych.

## 6. Ugotowałem
- cooked event;
- result photo;
- would make again;
- actual time;
- notification.

**Test:** ten sam user może gotować ten sam recipe wiele razy.

## 7. Comments
- post;
- recipe;
- cooked event;
- replies;
- block aware.

## 8. Collections + Search
- save;
- folders;
- Postgres search;
- filters.

## 9. Moderation
- reports;
- roles;
- actions;
- audit.

Musi być przed public launch.

## 10. Data rights
- export;
- delete;
- retention.

## 11. PWA + hardening
- manifest;
- service worker;
- backups;
- restore drill;
- rate limits;
- headers.

## Closed alpha gate
- 20+ realnych userów;
- stabilny upload;
- brak blockerów UX;
- moderation działa;
- restore przetestowany.

## V1 gate
Planner/groups/forks dopiero gdy WAC i D30 pokazują powroty.
