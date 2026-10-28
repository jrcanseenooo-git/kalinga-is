import type { ReportProps } from '@/types/reports';
import { NOTE_TYPES } from '@/utils/caseOptions';
import { counted, human, longDate, peso } from '@/utils/reportFormat';

const noteLabel = (type: string | null) => NOTE_TYPES.find((item) => item.value === type)?.label ?? human(type);

/** Actions taken, oldest first: progress-note actions, referrals, services and grants. */
export function reportActions(r: ReportProps): { at: string; action: string; who: string }[] {
  const rows: { at: string; action: string; who: string }[] = [];
  for (const note of r.notes ?? []) if (note.at && note.type !== 'referral' && note.type !== 'service_delivery') rows.push({ at: note.at, action: note.action || noteLabel(note.type), who: note.author ?? '' });
  for (const ref of r.referrals ?? []) rows.push({ at: ref.referred_at || ref.created_at, action: `Referral for ${(ref.purpose || ref.service_category).toLowerCase()} - ${human(ref.status).toLowerCase()}`, who: ref.referred_to ?? '' });
  for (const svc of r.services ?? []) rows.push({ at: svc.date_provided, action: `${svc.service_type}${svc.specific_detail ? `: ${svc.specific_detail}` : ''}${svc.amount ? ` (${peso(svc.amount)})` : ''}`, who: svc.provided_by ?? '' });
  for (const grant of r.grants?.items ?? []) if (!grant.voided_at) rows.push({ at: grant.released_at, action: `${grant.type_label} (${peso(grant.amount)})${grant.purpose ? ` - ${grant.purpose}` : ''}`, who: grant.received_by ?? '' });
  return rows.sort((a, b) => a.at.localeCompare(b.at));
}

export function reportStatusText(r: ReportProps): string {
  const info = r.caseInfo;
  const open = (r.referrals ?? []).filter((item) => ['SENT', 'ACCEPTED', 'IN_PROGRESS'].includes(item.status)).length;
  const stage = info.stage && human(info.stage) !== human(info.status) ? ` at the ${human(info.stage).toLowerCase()} stage` : '';
  const parts = [`As of ${longDate(r.generatedAt)}, the case is ${human(info.status).toLowerCase()}${stage}.`];
  parts.push(open ? `There ${open === 1 ? 'is' : 'are'} ${counted(open, 'referral')} awaiting completion.` : 'There are no pending referrals.');
  if (r.kavat) parts.push(`The latest KaVAT (${longDate(r.kavat.administered_at)}) gave a final protection score of ${r.kavat.final_score} (${r.kavat.priority_label}).`);
  return parts.join(' ');
}
