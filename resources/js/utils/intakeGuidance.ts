/**
 * Rule-based intake guidance (no external AI). Each rule reads the intake form
 * and returns a short prompt for the worker. Wording points to the protection
 * pathway; it does not decide anything for the worker.
 * OWNER REVIEW: confirm rules and wording with the CEFMU project team.
 */
export type IntakeHint = { tone: 'urgent' | 'action' | 'info'; title: string; text: string };
type IntakeLike = {
  birthdate?: string; initial_risk_level?: string; immediate_safety_concern?: boolean; immediate_actions?: string;
  union_type?: string; partner_age_band?: string; pregnancy_parenting_status?: string; education_status_at_intake?: string;
  cefmu_classifications?: string[]; risk_indicators?: string[]; ip_category?: string; religion?: string; safety_plan?: string;
};

const ageOf = (birthdate?: string) => {
  if (!birthdate) return null;
  const birth = new Date(`${birthdate}T00:00:00`); const now = new Date();
  let age = now.getFullYear() - birth.getFullYear();
  if (now.getMonth() < birth.getMonth() || (now.getMonth() === birth.getMonth() && now.getDate() < birth.getDate())) age--;
  return age;
};

export function intakeGuidance(form: IntakeLike): IntakeHint[] {
  const hints: IntakeHint[] = [];
  const age = ageOf(form.birthdate);
  const risk = form.initial_risk_level ?? '';
  if (risk === 'critical' || form.immediate_safety_concern) hints.push({ tone: 'urgent', title: 'Immediate protection first', text: 'Act through the emergency protection pathway now (safe placement, PNP-WCPD, WCPU). Record only the actions taken.' });
  if (['high', 'critical'].includes(risk) && !form.immediate_actions?.trim()) hints.push({ tone: 'action', title: 'Record the immediate action', text: 'High or critical risk needs the protective action you took before the case can be created.' });
  if (['high', 'critical'].includes(risk)) hints.push({ tone: 'action', title: 'Consider a WCPU / medico-legal referral', text: 'After the case is created, refer from Progress notes so the response is tracked.' });
  if (form.partner_age_band === '18_or_older' || form.partner_age_band === 'age_or_power_imbalance_reported') hints.push({ tone: 'action', title: 'Coordinate with PNP-WCPD and the prosecutor', text: 'RA 11596 treats child marriage, its facilitation, and an adult living with a child outside marriage as public crimes. Follow your referral protocol; barangay settlement is not an option.' });
  if (['civil_marriage', 'religious_marriage', 'customary_traditional_marriage'].includes(form.union_type ?? '') && age !== null && age < 18) hints.push({ tone: 'info', title: 'Note who officiated or facilitated', text: 'Record the officiant or facilitator in the case notes for the legal referral.' });
  if (form.pregnancy_parenting_status === 'pregnant' || form.pregnancy_parenting_status === 'postpartum') hints.push({ tone: 'action', title: 'Maternal and adolescent health', text: 'Plan a referral to the health office for prenatal or postpartum care.' });
  if (['interrupted', 'out_of_school'].includes(form.education_status_at_intake ?? '')) hints.push({ tone: 'info', title: 'Return to school', text: 'Consider a school re-entry or Alternative Learning System referral in the care plan.' });
  if (form.ip_category && !/not a member/i.test(form.ip_category)) hints.push({ tone: 'info', title: 'Indigenous Peoples community', text: 'Coordinate with NCIP or the IP mandatory representative for a culturally appropriate response.' });
  if (form.religion === 'Islam') hints.push({ tone: 'info', title: 'Muslim community', text: 'Coordinate with NCMF or local Muslim religious leaders when appropriate.' });
  if (age !== null && age >= 18) hints.push({ tone: 'info', title: 'Check the age', text: 'The child is 18 or older by the date of birth entered. CEFMU cases concern children; confirm the date.' });
  if (risk && !form.safety_plan?.trim() && risk !== 'low') hints.push({ tone: 'info', title: 'Add a short safety plan', text: 'Who will do what next and when the next review is.' });
  return hints;
}
