# Notatka do rozwiązania

## Czas pracy

2,5 godziny na synchronizację i 0,5 godziny na testy.

## Kompromisy i ograniczenia

Dla prostoty zapisuję plan i postęp w JSON. Po przerwaniu procesu żądanie może zostać ponowione, ale dostarczone API nie zduplikuje tagów.

Zrobiłem podstawowe testy logiki tagów. Produkcyjnie dodałbym bazę danych, blokadę równoległych uruchomień, automatyczny restart i monitoring. Dodałbym też rosnące opóźnienie ponowień - obecnie brak `Retry-After` lub wartość zero oznacza ponowienie bez czekania.

## Założenia

- Działa jeden proces, a dane wejściowe i tagi w B nie zmieniają się podczas synchronizacji.
- Po zabiciu program zostanie uruchomiony ponownie, a API i proces pozwolą ostatecznie wykonać pracę.
- Zmiana eksportu, mapowania lub reset B wymaga usunięcia `progress.json`.
- Zarządzam tylko tagami z mapowania. Zamknięte rekrutacje też się liczą, a tagi do zdjęcia jedynie raportuję.
