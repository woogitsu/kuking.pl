# AI-assisted development

## Bezpieczny model

```text
prompt
→ AGENTS.md
→ branch
→ implementation
→ tests
→ PR
→ CI
→ review
→ merge
→ Railway
```

## Przykład promptu

> Dodaj funkcję Ugotowałem zgodnie z AGENTS.md, PRODUCT.md, UX_50_PLUS.md i DATABASE.md. Dodaj policy, Livewire UI, testy Feature, telemetrykę i docs. Nie dodawaj Redis ani nowych usług.

## Agent przed kodem odpowiada

1. Czy funkcja jest MVP?
2. Jakie encje już istnieją?
3. Kto ma uprawnienia?
4. Co może pójść źle?
5. Jaki test potwierdzi sukces?
6. Czy UX jest czytelny dla 50+?

## Nie automerge

Bez review:
- auth;
- billing;
- permissions;
- migrations;
- moderacja;
- production infrastructure.

Możliwe automerge później:
- docs;
- copy;
- test-only;
- drobne style przy green CI.
