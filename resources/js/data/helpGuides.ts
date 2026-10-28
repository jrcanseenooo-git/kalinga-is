/**
 * Help & guides content, written against the actual Kalinga IS screens.
 * Keep wording in plain language for field workers; update when screens change.
 */
export type HelpArticle = {
  id: string;
  category: 'Start here' | 'Case work' | 'Coordination' | 'Field mode' | 'Reports' | 'Privacy & security' | 'Settings';
  title: string;
  summary: string;
  /** Show only to users who have this permission (none = everyone). */
  permission?: string;
  steps: string[];
  tips?: string[];
  link?: { label: string; route: string };
  keywords?: string;
};

export const HELP_ARTICLES: HelpArticle[] = [
  {
    id: 'first-day', category: 'Start here', title: 'Your first day in Kalinga',
    summary: 'Sign in, set your position, and find your way around.',
    steps: [
      'Sign in with your account and the six-digit code from your authenticator app or email.',
      'Open your name at the top right, then Profile and security. Add your position (for example Social Welfare Officer II); it prints under your name on reports.',
      'Use the sidebar: Case work (Cases, Case intake, Calendar), Coordination (Kalinga Connect), Insights (Analytics) and Settings. On a phone, use the bar at the bottom.',
      'Badges in the sidebar show your active cases and calendar items due today or overdue.',
    ],
    tips: ['Sign out when you leave a shared computer. Kalinga also signs you out at midnight.'],
    keywords: 'login sign in two factor otp code navigation sidebar menu profile position',
  },
  {
    id: 'intake', category: 'Case work', title: 'Record a new CEFMU case (Kalinga Intake)', permission: 'cases.create',
    summary: 'Four steps: privacy notice and consent form, child and safe contact, case details (CEFMU incidence), safety triage.',
    steps: [
      'Open Case intake and choose Start new intake. A paused intake is listed under Saved intakes; choose Continue to pick it up.',
      'Step 1: record how the privacy notice was given. Take a photo of the signed consent or assent form; Kalinga checks that it is clear and readable. This is the case consent record (there is no separate consent register).',
      'Step 2: enter the child\'s details, an alias or nickname if any, the present and permanent address, safe contact, the primary caregiver and the family composition. Tick "Same address as the child" where it applies.',
      'Step 3: choose the CEFMU classification (early marriage, early union, forced marriage, forced union), the marriage or union type, whether the child is married or living with a partner now or before, the partner\'s age, how long, and where the child lives now.',
      'Step 4: tick the risks you observed. Kalinga suggests a risk level from their points; if you choose a lower level, write why. Then create the case folder; the case opens next.',
    ],
    tips: ['Answers save automatically after step 1. Choose "Save and continue later" if the child is not ready; saved intakes are deleted after 30 days without changes.', 'Forms show the masked name (for example J**S***D**UZ) unless you choose the alias or the full name.', 'No signal? Use Field mode: the same intake works offline and is sent when you reconnect.', 'Barangay settlement or mediation is not a CEFMU response; refer through the protection pathway.'],
    link: { label: 'Open Case intake', route: 'cases.create' },
    keywords: 'new case register intake create child privacy notice consent assent photo alias nickname address risk points family composition classification early marriage early union forced marriage forced union cohabitation saved intake resume',
  },
  {
    id: 'case-page', category: 'Case work', title: 'Find your way around a case', permission: 'cases.view',
    summary: 'Overview, Client & Intake, KaVAT, Kalinga forms, Progress notes & referrals, Closure & aftercare, Documents.',
    steps: [
      'Overview shows remarks from your supervisor, the client profile, case information, the case conference card, referrals and tasks.',
      'The Next step box at the top follows the Kalinga forms: baseline KaVAT result, Case Summary Report, Intervention Plan, progress notes, the Endline KaVAT at closure review, then the Social Case Study Report.',
      'KaVAT is the Kalinga Vulnerability Assessment Tool. Kalinga forms is where you write the summary, plan and case study.',
      'Progress notes & referrals holds notes, visits, referrals to CPU members and to external agencies, services provided and the grants log.',
      'Case conferences can be held at any time; open them from the case conference card on the Overview.',
    ],
    keywords: 'case details workspace tabs overview next step kavat forms referrals grants closure conference',
  },
  {
    id: 'kavat', category: 'Case work', title: 'Administer the KaVAT', permission: 'cases.update',
    summary: 'The Kalinga Vulnerability Assessment Tool measures empowerment (Part 1) and objective risk (Part 2).',
    steps: [
      'Baseline: the first KaVAT is done before intake in the project\'s screening tool. On the KaVAT tab, enter its date, empowerment rate and safety rate (Kalinga computes the final score and priority; check it matches the sheet) and, if you like, a photo of the sheet.',
      'The KaVAT is done three times: Baseline (before intake), Midline (during case management) and Endline (when the case is ready for closure). On the KaVAT tab, Kalinga picks the next one not yet done. Choose Start.',
      'Read each of the 25 statements to the child and tap Oo, Hindi Sigurado or Hindi. Then answer the 9 Part 2 sections. "Go to the next unanswered" takes you to anything you skipped.',
      'The score updates as you answer: empowerment rate, safety rate and the final protection score. Below 70 is High Priority (Eligible).',
      'Answers save as you go. When every item is answered, choose Finalize. KaVAT results shows the Baseline, Midline and Endline side by side with the change since the baseline.',
      'To fix answers recorded by mistake, choose Correct on the result. Change the answers, write the reason and choose Finalize the correction. The earlier result is kept until then; Cancel keeps it.',
    ],
    tips: ['"Refused to Answer" is a red flag. In the sensitive sections (partner age, decision to enter the union, violence, safety) it scores the highest risk.', 'Domains rated low appear as suggested rows in the Intervention Plan.', 'No signal? The KaVAT can be answered in Field mode and is sent when you sync; finalize it online.'],
    keywords: 'kavat vulnerability assessment tool risk empowerment safety score priority eligible domains red flag refused baseline midline endline correct correction screening sheet',
  },
  {
    id: 'kalinga-forms', category: 'Case work', title: 'Write the Kalinga forms', permission: 'cases.update',
    summary: 'Case Summary Report and Social Case Study Report in paragraphs; the Intervention Plan as a table.',
    steps: [
      'Open the case, then Kalinga forms. The forms are listed in order: Intake Sheet, Case Summary Report, Progress Notes, Intervention Plan, Social Case Study Report.',
      'Choose Write (or Continue) on a form. Each section has a short guide. Leave a blank line between paragraphs.',
      'Identifying information, family composition, interventions and status are filled in from the record when printed.',
      'Your writing saves as you type. Choose Finalize to keep a numbered copy (version 1, 2, ...); you can keep improving the form after.',
      'Choose Print to preview the report with the office header and signatories.',
    ],
    tips: ['If the same form is open on two devices, the second save asks which copy to keep, so nothing is overwritten silently.', 'In Field mode you can keep writing with no signal; finalize when you are back online.'],
    keywords: 'case summary report social case study report scsr intervention plan matrix write narrative autosave finalize version print',
  },
  {
    id: 'grants', category: 'Case work', title: 'Record grants released', permission: 'cases.update',
    summary: 'Each case has a grants log with the total released.',
    steps: [
      'Open the case, then Progress notes & referrals, then Grants log.',
      'Choose Record a grant. Enter the type, amount, date released, fund source and who received it.',
      'A wrong entry is voided with a reason. It stays in the log, crossed out, and is not counted in the total.',
    ],
    keywords: 'grant grants assistance amount financial educational released disbursed total void',
  },
  {
    id: 'progress-notes', category: 'Case work', title: 'Write a progress note', permission: 'cases.note',
    summary: 'Notes keep the factual record of each contact, visit and decision.',
    steps: [
      'Open the case, then Progress notes, then Add progress note.',
      'Choose the note type, the date and time it happened, and write what was seen, said and agreed.',
      'Add Action taken and Next steps. Tick "Create a linked follow-up task" to put the follow-up on your calendar.',
      'Finalize. A finalized note cannot be edited; use Add addendum to correct or add to it.',
    ],
    tips: ['Refer to the child by alias or case number in notes where you can, so names do not spread in narratives.'],
    keywords: 'note notes progress narrative addendum correct follow up',
  },
  {
    id: 'referrals', category: 'Coordination', title: 'Refer a child to a CPU member or an external agency', permission: 'referrals.create',
    summary: 'Referrals are written as progress notes and tracked separately for CPU members and external agencies.',
    steps: [
      'Open the case, then Progress notes & referrals. Choose Refer to CPU member or Refer to external agency (or Refer at the top).',
      'A referral to a CPU member goes to a member or office of the LGU Child Protection Unit. A referral to an external agency goes to a partner such as WCPU, PNP-WCPD, PAO, DepEd or an NGO. The two are listed separately.',
      'Pick the purpose; Kalinga suggests the service category. Choose the basis for sharing information.',
      'Write the reason for the referral and save. The referral is sent, and the response due date appears on your calendar.',
      'Use Track / update on the note to record Accepted, In progress, Completed (with the outcome) or why it did not proceed.',
    ],
    tips: ['A CPU member with a Kalinga account gets access to this case for the referral only. Access ends when the referral is completed or closed.', 'Consent can be the basis for a referral only when the photo of the signed consent / assent form is in the case. Add it in Client & Intake if it was not taken at intake.'],
    keywords: 'refer referral mdt cpu agency wcpu pnp pao track status accepted completed declined consent sharing',
  },
  {
    id: 'services', category: 'Case work', title: 'Record a service provided', permission: 'cases.note',
    summary: 'Every service the child or family actually received, with amounts for financial help.',
    steps: [
      'Open the case and choose Record service (in Progress notes & referrals).',
      'Choose the service type and date provided, and describe the specific service.',
      'For financial assistance, enter the amount. Link it to a referral if it came from one.',
      'Services show in green on the Overview, in Progress notes & referrals, and in the reports, with the total assistance.',
    ],
    keywords: 'service services provided assistance financial amount medical transport educational aics',
  },
  {
    id: 'calendar', category: 'Case work', title: 'Plan visits and deadlines in the Calendar', permission: 'activities.manage',
    summary: 'Tap a date to schedule; case dates appear on their own.',
    steps: [
      'Open Calendar and tap a date (or Add activity).',
      'Choose the type (home visit, case conference, medical, court hearing and others), the case and the time. The title fills itself.',
      'Under Invite people, pick colleagues to add. They get a notification and see the activity in their own calendar, where they answer Going or Not going.',
      'Tap an activity to edit it, mark it done or cancel it. Moving the date or cancelling needs a short reason. The people invited, the case manager, your supervisor, the Field Office and the Project Managers covering the area are told, with a Rescheduled or Cancelled label.',
      'Open History on an activity to see every change and the reason given.',
      'Dashed items come from case records: conferences, safety reviews, care plan reviews and referral due dates.',
    ],
    tips: ['On a phone the Agenda view opens first; switch to Month anytime.'],
    link: { label: 'Open Calendar', route: 'calendar.index' },
    keywords: 'calendar schedule task activity visit appointment deadline due overdue reminder',
  },
  {
    id: 'reports', category: 'Reports', title: 'Print a case report', permission: 'reports.social.generate',
    summary: 'Five ready-to-sign reports built from the case record: Intake Sheet, Progress Notes, Intervention Plan, Case Summary and Social Case Study.',
    steps: [
      'Open the case and choose Reports at the top, or use Case reports on the Overview tab.',
      'Choose the report. For Progress Notes you can set a period (from and to dates).',
      'Check the draft. You can correct wording on screen; these edits are not saved to the case.',
      'Names print as initials unless you choose Show full name. Contact numbers are masked.',
      'Choose Print / Save as PDF. You sign as Prepared by; the office head in Office & signatories signs as Noted by.',
    ],
    tips: ['Health and reproductive health details, restricted assessment sections and MDT notes are left out of the reports.', 'Each printed report gets a reference number (for example GIS, PN, IP, CSR or SCSR) and is recorded in the audit trail.'],
    keywords: 'report reports print pdf intake sheet gis progress notes intervention plan case summary social case study scsr sign signature noted by prepared by',
  },
  {
    id: 'lgu-profile', category: 'Settings', title: 'Set up your office profile and signatories', permission: 'lgu.profile.view',
    summary: 'Office name, logo, office head and CPU members used on reports and referrals.',
    steps: [
      'Open Office & signatories in the menu (under Management).',
      'Enter the office name and short name, address and contact, and upload the LGU logo.',
      'Enter the office head or supervisor with position and designation. They sign reports as Noted by.',
      'Add CPU members with their position, office and role. Link a member to their Kalinga account so referrals reach them.',
    ],
    link: { label: 'Open office profile', route: 'lgu-profile.show' },
    keywords: 'lgu profile logo signatory head supervisor cswdo mswdo cpu members composition settings',
  },
  {
    id: 'cpu-assignment', category: 'Coordination', title: 'Work on a case referred to you', permission: 'mdt.access',
    summary: 'Read the referral purpose and requested actions, then record your professional response.',
    steps: [
      'Open My referred cases and choose Open assignment on the referral you need to work on.',
      'Read Why this case was referred to you, including the referring worker, referral note and access expiry.',
      'Review What you need to do for action instructions and due dates. If the case has several active assignments for you, use the assignment selector; their actions and notes stay separate.',
      'Review only the case context shared with you. Record findings and a recommendation, save the draft and finalize when ready. Corrections to final notes use a linked addendum.',
      'Save notes and supporting files before marking the action complete. Under the current workflow completion ends active access to that assignment.',
    ],
    tips: ['Case coordination discussion is separate from the LGU group chat. It is visible to authorized participants for that case.', 'Unsent entries stay in the open tab only. After an unconfirmed save, refresh and review the saved records before allowing another write.'],
    link: { label: 'My referred cases', route: 'mdt.collaboration.index' },
    keywords: 'cpu mdt referral assignment purpose instructions actions findings recommendation',
  },
  {
    id: 'connect', category: 'Coordination', title: 'Coordinate in Kalinga Connect', permission: 'cpu.chat.view',
    summary: 'The CPU group chat for your LGU.',
    steps: [
      'Open Kalinga Connect to message your CPU coordination group.',
      'Choose Message beside an eligible member to open a direct conversation. Only you and that member can read it through Connect while both remain eligible in the same LGU circle.',
      'Choose a case reference, urgency or mentions in Message details. Your selections appear as removable chips in the composer; click the message box to close the selection panel.',
      'Keep chat to coordination. Record case facts in the case itself, not in chat.',
      'Use Record as note on a group message to record a reviewed professional summary when allowed. Direct messages do not support case tags, mentions or formal capture.',
    ],
    keywords: 'chat message group cpu connect coordination',
  },
  {
    id: 'field-setup', category: 'Field mode', title: 'Get ready before going to a no-signal area', permission: 'offline.use',
    summary: 'Three things to do while you still have signal.',
    steps: [
      'Open Field mode (bottom of the sidebar) while online.',
      'Set up the device with an offline passphrase of at least 12 characters. Remember it; nobody can recover it.',
      'Wait for "Field app ready" so Kalinga opens without signal.',
      'Choose Download case pack to bring your assigned cases. You need it only for updates to existing cases.',
    ],
    tips: ['The case pack lasts 72 hours. New intakes work even after it expires.'],
    link: { label: 'Open Field mode', route: 'offline.workspace' },
    keywords: 'offline field mode gida no signal internet wifi passphrase download pack prepare',
  },
  {
    id: 'field-work', category: 'Field mode', title: 'Work with no signal', permission: 'offline.use',
    summary: 'Do intakes and case updates offline; Kalinga sends them later.',
    steps: [
      'Open Field mode and unlock with your offline passphrase.',
      'Fill in a new intake. It saves itself as you type, encrypted on the device.',
      'At step 4 choose Save intake on this device. It appears under "Intakes saved in field mode" as Waiting to sync.',
      'For an existing case, choose it under Update a case and save a note or visit.',
      'When signal returns, Kalinga syncs by itself. If you are in the middle of an intake, it waits until you save, then tells you how many records were sent.',
    ],
    tips: ['Lock the workspace before handing the device to someone else.', 'If something needs review after sync, open the Conflicts tab.'],
    keywords: 'offline intake save sync send waiting conflict lock unlock signal returns',
  },
  {
    id: 'lost-device', category: 'Privacy & security', title: 'If a device is lost or replaced',
    summary: 'Act fast so case data stays protected.',
    steps: [
      'Tell your system administrator right away.',
      'In Field mode, My devices, revoke the lost device (or ask the administrator to).',
      'A revoked device cannot sync, and its encrypted data is cleared the next time it connects.',
    ],
    keywords: 'lost stolen device phone tablet revoke security breach',
  },
  {
    id: 'privacy', category: 'Privacy & security', title: 'Protect the child\'s information',
    summary: 'Need-to-know, minimum necessary, and the Data Privacy Act.',
    steps: [
      'Open only the cases you are assigned to or invited into.',
      'Share information only through referrals with a recorded basis, and only what the receiving office needs.',
      'Do not copy case details into chat apps, personal notes or photos on your phone.',
      'Report any suspected breach to your Data Protection Officer immediately; breaches must be assessed quickly.',
    ],
    tips: ['Analytics hide small groups (fewer than five) so a child cannot be identified.'],
    keywords: 'privacy data privacy act ra 10173 confidential breach share consent need to know',
  },
  {
    id: 'analytics', category: 'Reports', title: 'Read the analytics and the map', permission: 'analytics.view',
    summary: 'Drill down from the country to a barangay; every figure follows the place and period you pick.',
    steps: [
      'Open Analytics. Click an area on the map (or a row in the list beside it) to go down a level: country, region, province, city or municipality, then barangay. Use the breadcrumb at the top to go back up.',
      'Every card, chart and trend line on the page follows the place you picked. Choose a year and a quarter (Q1 to Q4) to narrow the period.',
      'Trend lines show the last 12 months and the last 8 quarters, so you can see whether cases are rising or falling.',
      'Suppressed or withheld values protect privacy; they do not mean zero.',
      'Export PDF prints the page with a watermark showing your name and the time. Each export is recorded in the audit log.',
    ],
    tips: ['You only see the areas your account covers.', 'The map works without outside map services; boundaries are stored in Kalinga.'],
    keywords: 'analytics charts map drill down region province municipality barangay quarter trend pdf export watermark suppressed',
  },
  {
    id: 'dashboard', category: 'Start here', title: 'Use your dashboard to-do list',
    summary: 'The dashboard lists only what needs you; charts are in Analytics.',
    steps: [
      'Open Dashboard. The count at the top tells you how many things need your attention.',
      'Work down the list: case tasks due, calendar invitations to answer, closures to review, replies waiting, cases on your watch list and accounts to approve, depending on your role.',
      'Coming up shows your next dated activities. Shortcuts take you to case intake, the calendar and Analytics.',
      'When the list is empty you will see All caught up.',
    ],
    link: { label: 'Open Analytics', route: 'analytics.index' },
    keywords: 'dashboard home to do todo list tasks pending attention caught up',
  },
  {
    id: 'phone-notifications', category: 'Settings', title: 'Get notifications on your phone',
    summary: 'Turn on phone alerts so you hear about invitations and changes even when Kalinga is closed.',
    steps: [
      'On your phone, open Kalinga in the browser. For best results add it to your home screen (Share, then Add to Home Screen, or the browser menu, then Install app).',
      'Open the bell (Notifications) and choose Turn on phone notifications. Allow notifications when the phone asks.',
      'Alerts show the same short text as the bell (no child names). If the phone is signed out, the alert only says something new is waiting; open Kalinga to read it.',
      'If the phone is offline, the alert arrives when it is back online (alerts are kept for one day).',
    ],
    tips: ['Choose Turn off on this device before you hand the phone to someone else.'],
    keywords: 'push notification phone alert lock screen mobile install home screen offline',
  },
  {
    id: 'form-options', category: 'Settings', title: 'Edit dropdown lists and intake fields', permission: 'options.manage',
    summary: 'Change the choices in dropdowns, the risk factor points and the extra intake fields.',
    steps: [
      'Open Administration, then Form options.',
      'Pick a list (for example Disability types or Risk factors), then add, rename, reorder or switch off choices. Saving shows how many open cases and saved intakes use a choice you changed; confirm only if that is intended.',
      'Risk factor points and the score levels for moderate, high and critical are set by the owner team; change them only when they decide.',
      'Under Intake fields, add a field (text, number, date, yes or no, or a list), with help text. Use Preview to see how it looks in the intake form before switching it on.',
    ],
    tips: ['Switching a choice off keeps it on old records; it only stops new use.'],
    link: { label: 'Open Form options', route: 'admin.options' },
    keywords: 'dropdown options list choices risk factors disability types intake fields custom field preview admin',
  },
  {
    id: 'feedback', category: 'Settings', title: 'Send feedback or report a problem',
    summary: 'Tell the team what works, what does not, and what you need.',
    steps: [
      'Open your name at the top right, then Send feedback.',
      'Choose the kind (suggestion, something is not working, question, or something works well), write a short subject and what happened, and give a rating if you like. The page you were on is added for you.',
      'You will get a notification when the team replies. Your past feedback and replies are listed in the same window.',
    ],
    tips: ['Do not include a child\'s name or case details in feedback.'],
    keywords: 'feedback suggestion bug problem report rating reply help desk',
  },
  {
    id: 'team-chat', category: 'Coordination', title: 'Message case managers in other regions',
    summary: 'Project team chat: Project Managers reach case managers, supervisors and Field Offices in the regions they cover.',
    steps: [
      'Open Project team chat in the sidebar.',
      'Project Managers: search a name, region or office, then choose Message to start a direct conversation, or create a named group (for example Region VIII) and add members.',
      'Case managers, supervisors and Field Offices: conversations you are added to appear here; reply in them. You can leave a group at any time.',
      'New messages show a badge on the conversation until you read it.',
    ],
    tips: ['Use the case page and referrals, not chat, for child-specific details.'],
    link: { label: 'Open Project team chat', route: 'connect.network' },
    keywords: 'chat message team project manager case manager region group direct conversation',
  },
  {
    id: 'assist', category: 'Start here', title: 'Get help from Kalinga Assist',
    summary: 'The Assist button at the top of every page: what needs you, tips for the page, and answers to your questions.',
    steps: [
      'Choose Assist (the sparkle button) at the top of any page.',
      'Needs your attention lists the most urgent things first; tap one to go straight there.',
      'Tips shows advice for the page you are on, with links to the related guides.',
      'Type a question in your own words, in English or Filipino (for example "paano mag refer ng bata"), to see the matching guide steps.',
    ],
    tips: ['Assist follows written rules and the guides; nothing you type is sent outside Kalinga.'],
    keywords: 'assist assistant help smart suggestions ai tips question ask paano',
  },
  {
    id: 'text-size', category: 'Settings', title: 'Make the text bigger',
    summary: 'Three sizes for the forms (Kalinga Intake, KaVAT and the Kalinga forms) on this device.',
    steps: [
      'Use the A buttons at the top of the Kalinga Intake or the KaVAT. The bigger A makes the form text, fields and buttons bigger.',
      'Only the forms change; the menu and the other pages keep their size.',
      'The size is kept on this device until you change it.',
    ],
    keywords: 'text size bigger font large small eyes read zoom older',
  },
  {
    id: 'audit-logs', category: 'Privacy & security', title: 'Review the system audit logs', permission: 'audits.view',
    summary: 'Who did what and when, in 14 log types, with filters, search and export.',
    steps: [
      'Open Administration, then Audit logs. Each entry has a Log ID (for example AUD-000125), time, user, role, log type, module, action, record ID, description, old and new value, IP address and status.',
      'Use Filters for the date range, user, role, log type, module, action, status or record ID (a case number or a Log ID). Search looks in the description, user, record and IP address.',
      'The cards at the top show entries today, failed or denied actions, security alerts and system errors in the last 7 days; choose a card to filter.',
      'Export Excel or CSV downloads exactly what the filters show. Every export is itself recorded in the log.',
    ],
    tips: ['The log is read-only for everyone, including administrators.', 'Passwords, sign-in codes, tokens and case narratives are never recorded.'],
    link: { label: 'Open the audit logs', route: 'admin.audits' },
    keywords: 'audit log logs trail security access denied failed login export excel csv who did what record id',
  },
];

/** Simple, explainable search: scores articles by matching words; no external AI. */
/** Everyday words (including common Filipino ones) mapped to the words the guides use. */
const SYNONYMS: Record<string, string[]> = {
  refer: ['referral'], referral: ['refer'], picture: ['photo', 'camera'], camera: ['photo'], photo: ['camera'], print: ['pdf', 'report'], pdf: ['print', 'report'],
  delete: ['remove', 'discard'], remove: ['delete', 'discard'], phone: ['mobile', 'notification'], mobile: ['phone'], schedule: ['calendar', 'activity'], meeting: ['calendar', 'activity', 'conference'],
  money: ['grant', 'assistance'], cash: ['grant'], assistance: ['grant'], signal: ['offline', 'field'], offline: ['signal', 'field'], internet: ['offline', 'signal'],
  password: ['sign', 'login'], login: ['sign'], bigger: ['text', 'size'], font: ['text', 'size'], chat: ['message', 'connect'], message: ['chat', 'connect'],
  paano: ['how'], bata: ['child'], larawan: ['photo'], tawag: ['contact'], pirma: ['signed', 'consent'],
};

export function searchHelp(query: string, articles: HelpArticle[]): HelpArticle[] {
  const typed = query.toLowerCase().split(/[^a-z0-9]+/).filter(word => word.length > 2);
  const words = [...new Set(typed.flatMap(word => [word, ...(SYNONYMS[word] ?? []), ...(word.endsWith('s') ? [word.slice(0, -1)] : [])]))];
  if (!words.length) return [];
  return articles
    .map(article => {
      const title = article.title.toLowerCase();
      const body = [article.summary, ...article.steps, ...(article.tips ?? []), article.keywords ?? ''].join(' ').toLowerCase();
      const score = words.reduce((sum, word) => sum + (title.includes(word) ? 3 : 0) + (body.includes(word) ? 1 : 0), 0);
      return { article, score };
    })
    .filter(item => item.score > 0)
    .sort((a, b) => b.score - a.score)
    .map(item => item.article);
}
