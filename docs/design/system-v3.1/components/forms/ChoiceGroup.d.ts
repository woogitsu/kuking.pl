/**
 * Grupa wyboru „Kto to widzi” — trzy duże karty, zawsze widoczne.
 * Dodaj zdjęcie, dodaj przepis.
 * @dsAdherence Nigdy lista rozwijana. Maksimum trzy kolumny. Nie skracaj opisów do jednego słowa.
 */
export interface ChoiceOption {
  value: string;
  /** Nazwa możliwości, np. „Tylko ja”. */
  label: string;
  /** Zdanie wyjaśniające, np. „Twój prywatny zeszyt.” Osobny blok, nie ciąg dalszy etykiety. */
  help: string;
}

export interface ChoiceGroupProps {
  /** Wspólna nazwa przycisków radiowych — strzałkami przechodzi się między nimi. */
  name?: string;
  /** Pytanie w legendzie. Czytnik przeczyta je przy każdej możliwości. */
  legenda?: string;
  /** Trzy możliwości. Domyślnie: Wszyscy, Tylko obserwujący, Tylko ja. */
  opcje?: ChoiceOption[];
  /** Wybrana wartość. Z `onZmiana` steruje nią strona; bez `onZmiana` jest tylko wartością początkową, a grupa trzyma wybór u siebie. */
  wybrana?: string;
  onZmiana?: (value: string) => void;
  className?: string;
}

export declare function ChoiceGroup(props: ChoiceGroupProps): JSX.Element;
export declare const WIDOCZNOSC_DOMYSLNA: ChoiceOption[];
