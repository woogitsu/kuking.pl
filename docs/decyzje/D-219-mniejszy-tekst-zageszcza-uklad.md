## D-219 — Mniejszy tekst zagęszcza układ (#589, 15 września 2026)

Na jawne polecenie właściciela wartości 70/80/90% zmniejszają również odstępy i zapas wewnątrz kontrolek, zamiast pozostawiać mały tekst w dużych powierzchniach. Osobny współczynnik min(1, user-text-scale) zachowuje domyślne odstępy przy 100% i 140%; duży tekst naturalnie zwiększa potrzebną wysokość. Ważne cele dotykowe mają nadal minimum 48 px. Nie zmieniamy szerokości kontenerów, breakpointów ani proporcji zdjęć i nie stosujemy globalnego zoomu/transform. Nie obiecujemy liniowej skali całej geometrii. To uzupełnienie D-217.
