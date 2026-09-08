/**
 * Plakietka — jedno słowo o stanie albo pochodzeniu elementu.
 * @dsAdherence Głośną plakietkę (cooked, sukces, blad) wolno użyć raz na ekran. Co powtarza się w każdym elemencie listy, jest ciche. Plakietka nigdy nie jest klikalna.
 */
export interface BadgeProps {
  /**
   * Waga plakietki.
   * - cichy: bez tła i ramki, 15 px — „konto przykładowe” w strumieniu
   * - spokojny: tło wgłębione — „Szkic”, „Tylko ja”, „nowe”
   * - cooked / sukces / blad: tło akcentu, sukcesu, błędu — RAZ na ekran
   */
  waga?: "cichy" | "spokojny" | "cooked" | "sukces" | "blad";
  /** Nazwa ikony przed napisem. */
  ikona?: string;
  className?: string;
  /** Treść musi być zrozumiała bez koloru — „Do poprawy” ma znaczyć to samo w druku czarno-białym. */
  children: React.ReactNode;
}

export declare function Badge(props: BadgeProps): JSX.Element;
