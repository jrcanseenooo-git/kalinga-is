/**
 * KaVAT scoring in the browser, built from the definition the server sends
 * (App\Services\Kavat::definition). The server recomputes the score on save
 * and on finalize; this copy gives the case manager the live result, also
 * offline in field mode.
 */
export type KavatAnswer = 'yes' | 'maybe' | 'no';
export type KavatOption = { code: string; label: string; points: number; detail: string | null };
export type KavatQuestion = { key: string; text: string; max: number; options: KavatOption[] };
export type KavatSection = { number: number; title: string; sensitive: boolean; questions: KavatQuestion[] };
export type KavatStatement = { number: number; text: string; reversed: boolean };
export type KavatDomain = { code: string; title: string; items: number[]; bands: [number, number]; interpretations: [string, string, string]; statements: KavatStatement[] };
export type KavatDefinition = {
  version: string; answers: Record<KavatAnswer, string>; bandLabels: [string, string, string]; domains: KavatDomain[]; sections: KavatSection[];
  part1Max: number; part2Max: number; threshold: number; occasions: Record<string, string>;
  /** Occasions offered when starting a KaVAT in Kalinga (the baseline is done in the separate tool). */
  startOccasions?: string[];
};
/** Result of the baseline KaVAT done before intake in the project's screening tool. */
export type KavatBaseline = {
  administered_at: string; empowerment_rate: number; safety_rate: number; final_score: number; priority: 'high' | 'standard';
  notes: string | null; document_id: number | null; recorded_by: string | null; updated_at: string | null;
};
export type KavatAnswers = { part1: Record<string, KavatAnswer>; part2: Record<string, string>; details?: Record<string, string> };
export type DomainScore = { code: string; title: string; score: number; max: number; complete: boolean; band: string | null; level: number | null; interpretation: string | null };
export type KavatScore = {
  complete: boolean; answered: number; questions: number; part1: number; part2: number; empowerment_rate: number; objective_risk_rate: number; safety_rate: number;
  final_score: number | null; priority: 'high' | 'standard' | null; domains: DomainScore[]; sections: { number: number; title: string; score: number; max: number }[];
  red_flags: { section: number; title: string; question: string; sensitive: boolean }[];
};
export type KavatRecord = {
  id: number; occasion: string; administered_at: string; administered_by: string | null; status: 'draft' | 'final'; revision: number;
  answers: KavatAnswers; notes: string | null; finalized_at: string | null; score: KavatScore;
  /** A finalized KaVAT reopened for correction (the previous answers are kept until it is finalized or cancelled). */
  correcting?: boolean; previous_final_score?: number | null; correction_reason?: string | null; corrected_at?: string | null;
};

const round2 = (value: number) => Math.round(value * 100) / 100;

export function statementPoints(statement: { reversed: boolean }, answer: KavatAnswer | undefined): number | null {
  if (answer === 'maybe') return 3;
  if (answer === 'yes') return statement.reversed ? 1 : 5;
  if (answer === 'no') return statement.reversed ? 5 : 1;
  return null;
}

export function scoreKavat(definition: KavatDefinition, answers: KavatAnswers): KavatScore {
  let part1 = 0;
  let answered1 = 0;
  const domains: DomainScore[] = definition.domains.map((domain) => {
    let sum = 0;
    let complete = true;
    for (const statement of domain.statements) {
      const value = statementPoints(statement, answers.part1?.[String(statement.number)]);
      if (value === null) { complete = false; continue; }
      sum += value;
      answered1++;
    }
    part1 += sum;
    const level = complete ? (sum <= domain.bands[0] ? 0 : sum <= domain.bands[1] ? 1 : 2) : null;
    return { code: domain.code, title: domain.title, score: sum, max: domain.statements.length * 5, complete, level,
      band: level === null ? null : definition.bandLabels[level], interpretation: level === null ? null : domain.interpretations[level] };
  });

  let part2 = 0;
  let answered2 = 0;
  let questions2 = 0;
  const red_flags: KavatScore['red_flags'] = [];
  const sections = definition.sections.map((section) => {
    let sum = 0;
    for (const question of section.questions) {
      questions2++;
      const option = question.options.find((item) => item.code === answers.part2?.[question.key]);
      if (!option) continue;
      answered2++;
      sum += option.points;
      if (option.code === 'rta') red_flags.push({ section: section.number, title: section.title, question: question.key, sensitive: section.sensitive });
    }
    part2 += sum;
    return { number: section.number, title: section.title, score: sum, max: section.questions.reduce((total, question) => total + question.max, 0) };
  });

  const statements = definition.domains.reduce((total, domain) => total + domain.statements.length, 0);
  const complete = answered1 === statements && answered2 === questions2;
  const empowerment = round2((part1 / definition.part1Max) * 100);
  const risk = round2((part2 / definition.part2Max) * 100);
  const safety = round2(100 - risk);
  const final = round2(empowerment * 0.4 + safety * 0.6);
  return {
    complete, answered: answered1 + answered2, questions: statements + questions2, part1, part2, empowerment_rate: empowerment, objective_risk_rate: risk, safety_rate: safety,
    final_score: complete ? final : null, priority: complete ? (final < definition.threshold ? 'high' : 'standard') : null, domains, sections, red_flags,
  };
}

export const priorityLabel = (priority: string | null | undefined) =>
  priority === 'high' ? 'High Priority (Eligible)' : priority === 'standard' ? 'Standard / Low Priority' : 'Not yet scored';

export const emptyKavatAnswers = (): KavatAnswers => ({ part1: {}, part2: {}, details: {} });
