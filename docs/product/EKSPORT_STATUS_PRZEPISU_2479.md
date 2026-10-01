# Stan przepisu w osobistym archiwum (#2479)

Karta HTML i spis używają jednego mapowania bieżącego statusu przepisu.
`hidden` jest podpisany jako „ukryty”, a rzeczywisty szkic jako „szkic”.
Zdanie „nigdy nie został opublikowany” pojawia się tylko przy szkicu bez
zapisanej daty publikacji. Data publikacji pozostaje osobnym faktem i jest
pokazywana, jeśli została zapisana. Ukryty przepis bez daty nie dostaje
wymyślonej historii.

Eksport nadal bierze wyłącznie własne przepisy nieusunięte miękko. Nie
zmienia statusów, widoczności, dat ani reguł moderacji. Test buduje rzeczywisty
ZIP z ukrytym przepisem po publikacji, szkicem, przepisem publicznym i
prywatnym, ukrytym bez daty, usuniętym i cudzym; sprawdza kartę, spis i JSON.
Cofnięcie dotyczy tylko etykiet HTML. Nie ma migracji danych.
