/**
 * Pole zaznaczenia — włącza albo wyłącza jedną rzecz, zawsze niezależną od
 * pozostałych. Rejestracja (regulamin), dodaj przepis, czytelność.
 * @dsAdherence Cały wiersz jest klikalny, bo input siedzi w label. Nie dokładaj aria-checked. Nie używaj jako przełącznika działającego natychmiast bez „Zapisz”.
 */
export interface CheckboxProps {
  id: string;
  disabled?: boolean;
  /** Powód wyłączenia, w nawiasie obok etykiety. */
  powodWylaczenia?: string;
  className?: string;
  /** Pełne zdanie, nie hasło. */
  children: React.ReactNode;
  name?: string;
  checked?: boolean;
  defaultChecked?: boolean;
  value?: string;
  onChange?: (e: React.ChangeEvent) => void;
}

export declare function Checkbox(props: CheckboxProps): JSX.Element;
