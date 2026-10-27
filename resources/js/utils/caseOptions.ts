/**
 * Shared dropdown catalogue for CEFMU case management.
 *
 * These lists replace free-text entry where the answer is one of a known set.
 * Values are stored as their label text (the database columns stay free text),
 * so older records keep their meaning and any answer outside a list is kept
 * through "Other (specify)".
 *
 * OWNER REVIEW: lists follow common CANE+D / RA 11596 case management practice
 * and the agencies named in the CSPC protocol. Confirm them with the CEFMU
 * project team before go-live; editing a list here changes every form.
 */

export type Option = { value: string; label: string; hint?: string };

const list = (...labels: string[]): Option[] => labels.map(label => ({ value: label, label }));

/** Relationship of a person to the child (family composition, caregivers, supports). */
export const RELATIONSHIPS = list(
  'Mother', 'Father', 'Stepmother', 'Stepfather', 'Grandmother', 'Grandfather',
  'Sister', 'Brother', 'Half-sibling', 'Aunt', 'Uncle', 'Cousin',
  'Legal guardian', 'Foster parent', 'Spouse / partner', 'Own child', 'In-law',
  'Other relative', 'Trusted adult (non-relative)',
);

export const CIVIL_STATUSES = list('Single', 'Married', 'Living in (common-law union)', 'Separated', 'Widowed', 'Annulled');
export const RELIGIONS = list('Roman Catholic', 'Islam', 'Iglesia ni Cristo', 'Evangelical / Born Again Christian', 'Aglipayan', 'Seventh-day Adventist', 'Other Christian', 'Indigenous belief', 'No religion');
export const IP_MEMBERSHIP = list('Not a member of an Indigenous Peoples group');
export const EDUCATION_LEVELS = list(
  'No formal education', 'Kindergarten', 'Elementary level', 'Elementary graduate', 'Junior high school level',
  'Junior high school graduate', 'Senior high school level', 'Senior high school graduate',
  'Alternative Learning System (ALS)', 'Vocational / technical', 'College level', 'College graduate',
);
export const OCCUPATIONS = list(
  'Student', 'Farmer', 'Fisherfolk', 'Laborer / construction worker', 'Vendor / small business', 'Driver',
  'Household helper', 'Government employee', 'Private employee', 'Overseas Filipino worker', 'Homemaker', 'Unemployed',
);

/** Who referred or reported the case. */
export const REFERRAL_SOURCES = list(
  'Self / walk-in', 'Parent or relative', 'Barangay official / BCPC', 'School / teacher / guidance office',
  'Health facility / RHU', 'Women and Children Protection Unit (WCPU)', 'PNP Women and Children Protection Desk',
  'DSWD Field Office', 'Other LGU social welfare office', 'NGO / civil society organization',
  'Faith-based organization', 'NCIP / IP leader', 'NCMF / Muslim religious leader', 'Hotline / online report',
  'Community member', 'Prosecutor / court',
);

/** Where a visit or interview took place. */
export const VISIT_LOCATIONS = list(
  "Child's home", "Relative's home", 'School', 'Barangay hall', 'LGU social welfare office',
  'Health facility / RHU', 'Women and Children Protection Unit (WCPU)', 'Residential care facility',
  'Temporary shelter', 'Police station (WCPD)', 'Prosecutor / court', 'Phone or video call',
);

/** Reasons for an addendum to a finalized record. */
export const ADDENDUM_REASONS = list(
  'Correction of an error', 'Additional information received', 'Late entry', 'Clarification requested by reviewer',
  'Update after case conference',
);

/** Decisions the child is consulted on (child participation record). */
export const PARTICIPATION_DECISIONS = list(
  'Safety plan', 'Care plan and goals', 'Referral to a service', 'Placement / living arrangement',
  'Return to family (reintegration)', 'Education plan', 'Health or psychosocial treatment',
  'Filing of a complaint / legal action', 'Disclosure of information', 'Case closure',
);

/** Who is responsible for a safety action. */
export const SAFETY_RESPONSIBLE = list(
  'Case manager', 'Parent / guardian', 'Other relative / trusted adult', 'CPU member', 'Barangay official / BCPC',
  'PNP Women and Children Protection Desk', 'Health officer', 'School personnel', 'The child (age-appropriate)',
);

/** Services and interventions given to the child or family. */
export const SERVICE_CATEGORIES = list(
  'Medical / medico-legal examination', 'Mental health and psychosocial support (MHPSS)', 'Counseling',
  'Legal assistance', 'Temporary shelter / residential care', 'Alternative care (kinship / foster)',
  'Educational assistance', 'Return-to-school support', 'Reproductive, maternal or newborn health care',
  'Family counseling / parenting sessions', 'Livelihood or financial assistance (family)',
  'Birth registration / civil documents', 'Reintegration support', 'Faith-based / spiritual support',
  'Child-friendly interview / joint MDT interview',
);

/** Agencies and organizations in the CPU / MDT. */
export const PARTNER_AGENCIES = list(
  'LGU social welfare office (C/MSWDO)', 'Barangay / BCPC', 'Municipal / City Health Office', 'Rural Health Unit',
  'Women and Children Protection Unit (WCPU)', 'PNP Women and Children Protection Desk', 'Prosecutor (DOJ)',
  'Public Attorney\'s Office (PAO)', 'Department of Education (school)', 'DSWD Field Office', 'NCIP', 'NCMF',
  'NGO / civil society organization', 'Residential care facility',
);

/** Professional roles of MDT / CPU members. */
export const PROFESSIONAL_ROLES = list(
  'Social worker / case manager', 'Child protection officer', 'Medical doctor', 'Nurse / midwife', 'Psychologist',
  'Psychiatrist', 'Guidance counselor', 'Police officer (WCPD)', 'Prosecutor', 'Lawyer', 'Barangay official',
  'Teacher / school head', 'IP mandatory representative', 'Religious / community leader',
);

/** CPU role of a member in the LGU profile. */
export const CPU_ROLES = list(
  'Case manager / secretariat', 'Social welfare representative',
  'Health representative', 'Law enforcement representative', 'Legal representative', 'Education representative',
  'Barangay / BCPC representative', 'Mental health representative', 'IP / NCIP representative', 'Member',
);

/** Purposes for granting case access or disclosing information. */
export const SHARING_PURPOSES = list(
  'Provision of a referred service', 'Joint assessment / MDT interview', 'Case conference', 'Legal proceedings',
  'Medical or psychosocial treatment', 'Safety planning', 'Follow-up and monitoring', 'Official report',
);

/** Lawful basis recorded in the consent register (RA 10173). */
export const CONSENT_BASES = list(
  'Consent of the child and parent / guardian', 'Consent of the parent / guardian', 'Consent of the child (age-appropriate)',
  'Required by law (legal obligation)', 'Protection of life or health (vital interests)', 'Public function of a government agency',
);

export const DELIVERY_METHODS = list('Hand-delivered sealed copy', 'Encrypted email', 'Secure system access', 'Registered mail', 'Shown, no copy given');
export const LAWFUL_AUTHORITIES = list(
  'Court order / subpoena', 'Written consent of the child and parent / guardian', 'RA 11596 (child marriage)',
  'RA 7610 (special protection of children)', 'RA 10173 section 13 (sensitive personal information)',
  'RA 9344 (juvenile justice)', 'RA 11930 (online sexual abuse)',
);
export const HOLD_REASONS = list('Pending legal case', 'Court order', 'Investigation', 'Data subject request', 'Audit');
export const HOLD_AUTHORITIES = list('Court', 'Prosecutor', 'DSWD Legal Service', 'Data Protection Officer', 'Head of office');
export const RECORD_TYPES: Option[] = [
  { value: 'document', label: 'Document' }, { value: 'case_note', label: 'Progress note' },
  { value: 'assessment', label: 'Assessment' }, { value: 'case_plan', label: 'Case plan' },
];

/** MDT professional note categories. */
export const MDT_NOTE_CATEGORIES = list(
  'Medical findings', 'Psychological assessment', 'Legal update', 'Police / investigation update', 'School update',
  'Service delivered', 'Recommendation', 'Coordination',
);

/** Calendar event types, with the colour shown on the calendar. */
export const EVENT_TYPES: (Option & { tone: string })[] = [
  { value: 'home_visit', label: 'Home visit', tone: 'blue' },
  { value: 'monitoring_visit', label: 'Monitoring visit', tone: 'teal' },
  { value: 'case_conference', label: 'Case conference', tone: 'amber' },
  { value: 'assessment', label: 'Assessment / interview', tone: 'rose' },
  { value: 'medical', label: 'Medical / medico-legal', tone: 'red' },
  { value: 'court', label: 'Court / prosecutor hearing', tone: 'slate' },
  { value: 'referral_follow_up', label: 'Referral follow-up', tone: 'violet' },
  { value: 'family_meeting', label: 'Family meeting', tone: 'green' },
  { value: 'deadline', label: 'Report / deadline', tone: 'purple' },
  { value: 'other', label: 'Other activity', tone: 'gray' },
];
export const eventTypeLabel = (value?: string | null) => EVENT_TYPES.find(type => type.value === value)?.label ?? 'Activity';
export const eventTone = (value?: string | null) => EVENT_TYPES.find(type => type.value === value)?.tone ?? 'gray';

/** Case client category: Kalinga handles CEFMU cases only. */
export const CLIENT_CATEGORIES = list('CEFMU');

/**
 * Referral recipients and the purposes that fit each one (from the earlier
 * Kalinga progress-note design). OWNER REVIEW with the CPU.
 */
export const REFERRAL_RECIPIENTS: { group: string; options: string[] }[] = [
  { group: 'Within the CPU / LGU', options: ['LGU social welfare office (C/MSWDO)', 'Municipal / City Health Office', 'Barangay / BCPC', 'Mental health / psychosocial officer'] },
  { group: 'Partner agencies', options: ['Women and Children Protection Unit (WCPU)', 'PNP Women and Children Protection Desk', 'Prosecutor (DOJ)', "Public Attorney's Office (PAO)", 'Department of Education (school)', 'DSWD Field Office / residential care', 'NCIP', 'NCMF', 'NGO / civil society organization'] },
];
const DEFAULT_PURPOSES = ['Psychosocial support', 'Medical assistance', 'Legal assistance', 'Educational assistance', 'Shelter / temporary placement', 'Financial assistance', 'Law enforcement', 'Case conference'];
export const REFERRAL_PURPOSES: Record<string, string[]> = {
  'LGU social welfare office (C/MSWDO)': ['Family assessment / home visit', 'Safety planning', 'Shelter / temporary placement', 'Financial assistance', 'Aftercare follow-up', 'Case conference'],
  'Municipal / City Health Office': ['Medical check-up', 'Medico-legal examination', 'Pregnancy / maternal care', 'Adolescent reproductive health care', 'Mental health referral'],
  'Barangay / BCPC': ['Community monitoring', 'Safety intervention', 'Return-to-school support'],
  'Mental health / psychosocial officer': ['Psychosocial support', 'Counseling / crisis intervention', 'Psychological evaluation', 'Family counseling'],
  'Women and Children Protection Unit (WCPU)': ['Medico-legal examination', 'Medical treatment', 'Joint MDT interview', 'Psychosocial support'],
  'PNP Women and Children Protection Desk': ['Filing of complaint', 'Investigation support', 'Rescue / safety intervention', 'Blotter / incident recording'],
  'Prosecutor (DOJ)': ['Filing of complaint', 'Inquest / preliminary investigation', 'Case build-up'],
  "Public Attorney's Office (PAO)": ['Legal counseling', 'Legal assistance', 'Court representation'],
  'Department of Education (school)': ['School re-entry', 'Alternative Learning System enrollment', 'Guidance counseling', 'Educational assistance'],
  'DSWD Field Office / residential care': ['Temporary shelter / residential care', 'Protective custody coordination', 'Psychosocial support', 'Financial assistance'],
  NCIP: ['Culturally appropriate intervention', 'IP community coordination', 'Legal assistance'],
  NCMF: ['Coordination with Muslim religious leaders', 'Shari\'a court coordination', 'Community intervention'],
  'NGO / civil society organization': ['Psychosocial support', 'Educational assistance', 'Livelihood / skills training', 'Shelter / temporary placement'],
};
/** CPU (LGU Child Protection Unit) recipients; everything else is an external agency. */
export const CPU_RECIPIENTS = REFERRAL_RECIPIENTS[0].options;
export const EXTERNAL_RECIPIENTS = REFERRAL_RECIPIENTS.slice(1).flatMap((group) => group.options);
export const isCpuReferral = (referral: { lgu_cpu_member_id?: number | null; referred_to?: string | null }) =>
  Boolean(referral.lgu_cpu_member_id) || CPU_RECIPIENTS.includes(referral.referred_to ?? '');
export const purposesFor = (recipient?: string | null): string[] => (recipient && REFERRAL_PURPOSES[recipient]) || DEFAULT_PURPOSES;

/** Services given to the child or family (earlier Kalinga "Services provided"). */
export const SERVICE_TYPES: (Option & { amount: boolean; detail: string })[] = [
  { value: 'Medical', label: 'Medical', amount: true, detail: 'What medical service? e.g. check-up, medicines' },
  { value: 'Financial', label: 'Financial assistance', amount: true, detail: 'Purpose, e.g. AICS, transport for hearing' },
  { value: 'Transportation', label: 'Transportation', amount: true, detail: 'Trip, e.g. to WCPU, to court' },
  { value: 'Legal', label: 'Legal', amount: false, detail: 'e.g. legal counseling, filing assistance' },
  { value: 'Psychosocial', label: 'Psychosocial', amount: false, detail: 'e.g. counseling session, group therapy' },
  { value: 'Educational', label: 'Educational', amount: true, detail: 'e.g. school supplies, ALS enrollment' },
  { value: 'Shelter / temporary placement', label: 'Shelter / temporary placement', amount: false, detail: 'Facility or family placement' },
  { value: 'Food / relief goods', label: 'Food / relief goods', amount: false, detail: 'e.g. food pack, hygiene kit' },
  { value: 'Livelihood', label: 'Livelihood (family)', amount: true, detail: 'e.g. skills training, starter kit' },
  { value: 'Other', label: 'Other service', amount: false, detail: 'Describe the service' },
];

/** Disability or support need (intake). Codes are stored; labels can be edited in Form options. */
export const DISABILITY_TYPES: Option[] = [
  { value: 'no_support_need_declared', label: 'No disability support need declared' }, { value: 'deaf_hard_of_hearing', label: 'Deaf / hard of hearing' },
  { value: 'intellectual_disability', label: 'Intellectual disability' }, { value: 'learning_disability', label: 'Learning disability' },
  { value: 'mental_disability', label: 'Mental disability' }, { value: 'physical_disability', label: 'Physical disability' },
  { value: 'psychosocial_disability', label: 'Psychosocial disability' }, { value: 'speech_language_impairment', label: 'Speech and language impairment' },
  { value: 'visual_disability', label: 'Visual disability' }, { value: 'cancer', label: 'Cancer' }, { value: 'rare_disease', label: 'Rare disease' },
];

/**
 * Lists edited by the administrator (Administration > Form options) arrive
 * with every page as `optionSets`. They replace the built-in lists above in
 * place, so every form uses them; without them (or offline before the first
 * load) the built-in lists stay.
 */
const OVERRIDABLE: Record<string, Option[]> = {} as Record<string, Option[]>;
export function applyOptionSets(sets: Record<string, Option[]> | null | undefined): void {
  if (!sets) return;
  for (const [name, options] of Object.entries(sets)) {
    const target = OVERRIDABLE[name];
    if (target && Array.isArray(options) && options.length) target.splice(0, target.length, ...options.map(option => ({ value: String(option.value), label: String(option.label), ...(option.hint ? { hint: String(option.hint) } : {}) })));
  }
}

/** Progress note types; "referral" and "service" open their own fields. */
export const NOTE_TYPES: Option[] = [
  { value: 'contact', label: 'Contact / home visit' },
  { value: 'monitoring', label: 'Monitoring' },
  { value: 'referral', label: 'Referral' },
  { value: 'service_delivery', label: 'Service provided' },
  { value: 'coordination', label: 'Coordination' },
  { value: 'case_review', label: 'Case review' },
  { value: 'other', label: 'Other' },
];

Object.assign(OVERRIDABLE, { RELATIONSHIPS, CIVIL_STATUSES, RELIGIONS, IP_MEMBERSHIP, EDUCATION_LEVELS, OCCUPATIONS, REFERRAL_SOURCES, VISIT_LOCATIONS, SERVICE_CATEGORIES, PARTNER_AGENCIES, DISABILITY_TYPES });
