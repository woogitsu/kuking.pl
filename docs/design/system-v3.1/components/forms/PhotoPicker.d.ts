/**
 * Wybór zdjęcia — duży obszar z etykietą na ukrytym polu pliku. Główna akcja
 * ekranów „Dodaj zdjęcie” i „Dodaj przepis”.
 * @dsAdherence Nigdy natywny przycisk pliku (rysuje angielski napis, D-107). Nigdy display:none na polu. Przeciąganie myszą jest dodatkiem, nigdy jedyną drogą.
 */
export interface PhotoPickerProps {
  /** Identyfikator pola pliku. */
  id?: string;
  /** Napis główny. Domyślnie „Dodaj zdjęcie”. */
  tytul?: string;
  /** Zdanie mówiące, co się stanie po kliknięciu. */
  opis?: string;
  /** Lista typów. Ogranicza listę plików, ale nie zastępuje walidacji po stronie serwera. */
  accept?: string;
  /** Konkretny powód odrzucenia pliku, po polsku. */
  blad?: React.ReactNode;
  className?: string;
  name?: string;
  onChange?: (e: React.ChangeEvent) => void;
}

export declare function PhotoPicker(props: PhotoPickerProps): JSX.Element;
