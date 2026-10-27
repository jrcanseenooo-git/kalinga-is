/**
 * Kalinga Assist tips for each page (by Inertia page component), with the
 * help guides most related to it. Plain written guidance; no external AI.
 */
export type PageTips = { title: string; tips: string[]; guides: string[] };

const DEFAULT: PageTips = { title: 'Getting around', tips: ['Use the search box at the top to find a case by number.', 'Your name at the top right has Send feedback, text size and Help.'], guides: ['first-day', 'dashboard'] };

export const ASSIST_TIPS: Record<string, PageTips> = {
    Dashboard: { title: 'Your to-do list', tips: ['Work from the top: red items are urgent.', 'Kalinga suggests lists the next step for each of your cases.', 'Coming up shows your next dated activities.'], guides: ['dashboard', 'calendar'] },
    'Cases/Index': { title: 'Cases', tips: ['Choose "Needs action" to see only cases with a next step.', 'Filters remember your choices; Clear resets them.'], guides: ['case-page', 'intake'] },
    'Cases/Show': { title: 'This case', tips: ['The Next step box at the top tells you what to do next.', 'Tabs with a dot need attention.', 'The consent given at intake is on Client & Intake; add the signed form photo there if it is missing.'], guides: ['case-page', 'kavat', 'kalinga-forms', 'referrals'] },
    'Cases/Create': { title: 'Kalinga Intake', tips: ['Answers save as you go; use "Save and continue later" if the child is not ready.', 'Use the A buttons at the top of the form to make the text bigger.', 'Take photo opens the camera; Kalinga checks that the form is clear and readable.', 'Mobile numbers and names are tidied for you when you leave the field.'], guides: ['intake', 'privacy'] },
    'Calendar/Index': { title: 'Calendar', tips: ['Tap a date to add an activity; invite colleagues under Invite people.', 'Moving or cancelling needs a reason; the people concerned are told.'], guides: ['calendar', 'phone-notifications'] },
    'Analytics/Index': { title: 'Analytics', tips: ['Click an area on the map to go down a level; the breadcrumb goes back up.', 'Every figure follows the place and period you choose.', 'Export PDF prints a report with your name and the time; it is recorded in the audit log.'], guides: ['analytics'] },
    'Offline/Workspace': { title: 'Field mode', tips: ['Download the case pack while you still have signal.', 'Work saves encrypted on this device and is sent when you sync.'], guides: ['field-setup', 'field-work', 'lost-device'] },
    'Connect/Network': { title: 'Project team chat', tips: ['Search a name, region or office to start a conversation.', 'Use referrals, not chat, for child-specific details.'], guides: ['team-chat'] },
    'Cpu/Coordination': { title: 'Kalinga Connect', tips: ['Keep messages to coordination; do not share child details in chat.'], guides: ['connect'] },
    'Lgu/Profile': { title: 'Office & signatories', tips: ['The office head named here signs reports as Noted by.', 'Keep the CPU member list current so referrals reach the right people.'], guides: ['lgu-profile'] },
    'Admin/FormOptions': { title: 'Form options', tips: ['Changing a choice in use shows how many cases use it; confirm only if intended.', 'Use Preview to see a new intake field before switching it on.'], guides: ['form-options'] },
    'Admin/Audits': { title: 'Audit logs', tips: ['The cards at the top filter to failed actions and security alerts.', 'Exports are recorded in the log too.'], guides: ['audit-logs'] },
    'Help/Guide': { title: 'Help & guides', tips: ['Type a question in your own words, for example "how do I refer a child".'], guides: ['first-day'] },
};

export const tipsFor = (component: string): PageTips => ASSIST_TIPS[component] ?? DEFAULT;
