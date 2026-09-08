/**
 * Plakietka autozapisu — „Szkic zapisany.” w formularzu przepisu.
 * @dsAdherence aria-live="polite", nigdy assertive. Nie pisz „Autosave aktywny”. Nie migaj co dziesięć sekund.
 */
export interface AutosaveBadgeProps {
  /** Domyślnie „Szkic zapisany.” — z kropką, bez wykrzyknika. */
  tekst?: string;
  className?: string;
}

export declare function AutosaveBadge(props: AutosaveBadgeProps): JSX.Element;
