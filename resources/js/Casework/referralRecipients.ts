/**
 * Who a referral goes to, and the service category suggested from its
 * purpose. A referral goes either to a CPU member (LGU Child Protection Unit)
 * or to an external agency; the two are tracked separately.
 */
export type CpuOption = { id: number; name: string; cpu_role: string; position: string | null; has_account: boolean };
export type ReferralKind = 'cpu' | 'external';

/** Recipient values: "cpu:<id>" for a listed CPU member, otherwise the agency or office name. */
export function recipientAgency(recipient: string, cpuMembers: CpuOption[]): string {
  if (!recipient.startsWith('cpu:')) return recipient;
  const role = cpuMembers.find((item) => `cpu:${item.id}` === recipient)?.cpu_role ?? '';
  return role.includes('Health') ? 'Municipal / City Health Office'
    : role.includes('Law') ? 'PNP Women and Children Protection Desk'
      : role.includes('Legal') ? "Public Attorney's Office (PAO)"
        : role.includes('Education') ? 'Department of Education (school)'
          : role.includes('Mental') ? 'Mental health / psychosocial officer'
            : 'LGU social welfare office (C/MSWDO)';
}

const CATEGORY_HINTS: [RegExp, string][] = [
  [/medico|medical|check-up|treatment/i, 'Medical / medico-legal examination'],
  [/legal|complaint|court|inquest|prosecut|case build/i, 'Legal assistance'],
  [/psych|counsel|mental|therapy/i, 'Mental health and psychosocial support (MHPSS)'],
  [/shelter|residential|custody|placement/i, 'Temporary shelter / residential care'],
  [/school|educat|als|learning/i, 'Educational assistance'],
  [/pregnan|maternal|reproductive/i, 'Reproductive, maternal or newborn health care'],
  [/financ|aics/i, 'Livelihood or financial assistance (family)'],
  [/livelihood|skills/i, 'Livelihood or financial assistance (family)'],
  [/interview/i, 'Child-friendly interview / joint MDT interview'],
  [/family|home visit|parent/i, 'Family counseling / parenting sessions'],
  [/reintegrat|aftercare/i, 'Reintegration support'],
];

/** The service category that fits a purpose (or the plan row text), if any. */
export const categoryHint = (text: string): string | null => CATEGORY_HINTS.find(([pattern]) => pattern.test(text))?.[1] ?? null;
/** True when the category was set by a hint (so a new hint may replace it). */
export const isHintedCategory = (category: string) => CATEGORY_HINTS.some(([, value]) => value === category);
