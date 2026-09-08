/**
 * Kroki kreatora — na którym kroku formularza przepisu stoi człowiek i ile
 * zostało. Ekran „Dodaj przepis” (trzy kroki, trzy adresy, D-108).
 * @dsAdherence Postęp zawsze napisany słowami. Kropki nie są klikalne. Nagłówek strony musi mówić to samo co krok.
 */
export interface WizardStepsProps {
  /** Numer bieżącego kroku, liczony od 1. */
  krok: number;
  /** Ile kroków razem. Domyślnie 3. */
  ile?: number;
  /** Nazwa kroku: „składniki”, „kroki”. */
  nazwa?: string;
  className?: string;
}

export declare function WizardSteps(props: WizardStepsProps): JSX.Element;
