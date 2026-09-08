/**
 * Belka górna — znak, szukanie i dwie akcje konta w tym samym miejscu na
 * każdej z piętnastu podstron.
 * @dsAdherence Nie przywracaj pola szukania na telefonie. Nie zdejmuj napisów z akcji. Nie licz belce szerokości osobno od siatki treści.
 */
export interface TopBarProps {
  /** Wariant gościa: znak plus „Zaloguj” i „Zostań kuKINGiem”, bez pola szukania. */
  gosc?: boolean;
  /** Imię osoby zalogowanej — do awatara w akcji „Konto”. */
  imie?: string;
  avatarSrc?: string;
  /** Tekst podpowiedzi w polu szukania. Prawdziwa etykieta jest schowana dla oka, nie zastąpiona. */
  placeholder?: string;
  onSzukaj?: (e: React.FormEvent) => void;
  className?: string;
}

export declare function TopBar(props: TopBarProps): JSX.Element;
