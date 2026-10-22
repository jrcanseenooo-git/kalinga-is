#!/usr/bin/env python3
"""
Builds the Kalinga data dictionary from the live PostgreSQL schema.

Usage (from the project root, database settings as in .env):
    PGPASSWORD=... psql -h HOST -U USER DB -Atc "$(python3 scripts/generate_data_dictionary.py --sql)" > schema.json
    python3 scripts/generate_data_dictionary.py schema.json docs/04-database/DATA-DICTIONARY.md [out.html] [out.csv]

The CSV (one row per table, then its columns; id and timestamps omitted)
is the source of the Google Sheets copy of the data dictionary.

Table purposes are maintained below; column descriptions come from naming
rules and foreign keys. Update TABLES when a migration adds a table.
"""
import html
import json
import re
import sys

SQL = """select json_agg(t) from (
 select c.table_name, c.column_name, c.ordinal_position,
  case when c.data_type='character varying' then 'varchar('||coalesce(c.character_maximum_length::text,'')||')'
       when c.data_type like 'timestamp%' then 'timestamp' else c.data_type end as type,
  c.is_nullable, c.column_default,
  (select ccu.table_name from information_schema.key_column_usage k
     join information_schema.table_constraints tc on tc.constraint_name=k.constraint_name and tc.constraint_type='FOREIGN KEY'
     join information_schema.constraint_column_usage ccu on ccu.constraint_name=tc.constraint_name
    where k.table_name=c.table_name and k.column_name=c.column_name limit 1) as fk,
  exists(select 1 from information_schema.key_column_usage k join information_schema.table_constraints tc
          on tc.constraint_name=k.constraint_name and tc.constraint_type='PRIMARY KEY'
          where k.table_name=c.table_name and k.column_name=c.column_name) as pk
 from information_schema.columns c
 join information_schema.tables t on t.table_name=c.table_name and t.table_schema='public' and t.table_type='BASE TABLE'
 where c.table_schema='public' order by c.table_name, c.ordinal_position) t"""

GROUPS = [
    ('Clients and identity', ['clients', 'client_identifiers', 'client_identity_links', 'client_identity_merges', 'client_identity_merge_affected_records',
        'client_identity_merge_field_decisions', 'client_identity_merge_identifier_conflicts', 'identity_duplicate_candidates', 'identity_review_decisions',
        'relationships', 'households', 'household_memberships']),
    ('Reports and intake', ['reports', 'reference_sequences']),
    ('Case folder', ['cases', 'case_status_history', 'case_lifecycle_policies', 'case_notes', 'monitoring_visits', 'tasks', 'consents', 'documents']),
    ('Assessment', ['risk_assessments', 'risk_assessment_factors', 'case_assessments', 'case_assessment_sections', 'case_assessment_workflow_events', 'assessments',
        'legacy_assessment_reconciliations', 'child_participation_records', 'child_participation_workflow_events']),
    ('Planning and services', ['case_plans', 'case_plan_goals', 'case_plan_history', 'case_plan_reviews', 'interventions', 'services_provided', 'service_providers',
        'referrals', 'referral_events', 'case_conferences', 'case_conference_participants', 'case_conference_decisions', 'case_conference_actions', 'case_conference_history']),
    ('Closure, transfer and aftercare', ['case_closure_records', 'case_aftercare_plans', 'case_reintegrations', 'case_transfers', 'case_transfer_history']),
    ('Multidisciplinary team and coordination', ['mdt_case_access', 'mdt_case_notes', 'mdt_actions', 'mdt_attachments', 'mdt_discussions', 'mdt_discussion_reads',
        'lgu_coordination_circles', 'lgu_coordination_members', 'lgu_coordination_messages', 'lgu_coordination_message_mentions', 'lgu_coordination_message_reads']),
    ('Records governance', ['record_governance', 'record_classifications', 'retention_rules', 'legal_holds', 'legal_hold_records', 'record_dispositions',
        'record_disposition_history', 'record_disclosures', 'record_disclosure_items']),
    ('LGU and organisation', ['lgu_units', 'lgu_profiles', 'lgu_cpu_members', 'lgu_reconciliation_queue', 'user_lgu_assignments']),
    ('Users and security', ['users', 'roles', 'permissions', 'permission_role', 'password_reset_tokens', 'sessions', 'audit_logs', 'notifications']),
    ('Offline field mode', ['offline_devices', 'offline_sync_items']),
    ('Configuration', ['form_fields', 'reference_data']),
    ('Framework (Laravel)', ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs']),
]

TABLES = {
    'clients': 'The child (client) of a case: identifying information, address, safe contact and primary caregiver.',
    'client_identifiers': 'Official identifiers recorded for a client, used for duplicate checks.',
    'client_identity_links': 'Links between client records confirmed as the same person.',
    'client_identity_merges': 'Approved merges of duplicate client records.',
    'client_identity_merge_affected_records': 'Records moved from one client to another during a merge.',
    'client_identity_merge_field_decisions': 'Which value was kept for each field during a merge.',
    'client_identity_merge_identifier_conflicts': 'Identifiers that disagreed between merged clients.',
    'identity_duplicate_candidates': 'Possible duplicate clients found by the system, for review.',
    'identity_review_decisions': 'Decisions taken on possible duplicates.',
    'relationships': 'Family members and support persons of a client (family composition).',
    'households': 'Households a client belongs to.',
    'household_memberships': 'Membership of clients in households.',
    'reports': 'Reports of possible CEFMU cases received from the public or partners, before a case is opened.',
    'reference_sequences': 'Counters used to issue report and case numbers.',
    'cases': 'The case folder: status, stage, CEFMU classification, intake details, risk level, consent, assignment and LGU.',
    'case_status_history': 'Every change of case status, with who and when.',
    'case_lifecycle_policies': 'Rules for allowed case status changes.',
    'case_notes': 'Progress notes and their addenda (corrections), with purpose, action taken and next steps.',
    'monitoring_visits': 'Home and monitoring visits.',
    'tasks': 'Calendar activities and follow-ups (visits, conferences, deadlines).',
    'consents': 'Consent and lawful basis for processing and sharing case information.',
    'documents': 'Uploaded case documents (stored privately; quarantined until scanned).',
    'risk_assessments': 'Safety and risk assessments.',
    'risk_assessment_factors': 'Risk factors rated in a safety and risk assessment.',
    'case_assessments': 'Comprehensive assessments (versioned).',
    'case_assessment_sections': 'Sections of a comprehensive assessment (facts, observations, analysis).',
    'case_assessment_workflow_events': 'Review and approval steps of a comprehensive assessment.',
    'assessments': 'Assessments from the earlier system, kept for reference.',
    'legacy_assessment_reconciliations': 'How earlier-system assessments were matched to current records.',
    'child_participation_records': "Records of the child's views and participation in decisions.",
    'child_participation_workflow_events': 'Review steps of child participation records.',
    'case_plans': 'Intervention plans (versioned): objective, status, review date.',
    'case_plan_goals': 'Goals of an intervention plan by domain, with target dates.',
    'case_plan_history': 'Changes to an intervention plan.',
    'case_plan_reviews': 'Periodic reviews of an intervention plan.',
    'interventions': 'Activities and services planned under each goal, with responsible person and progress.',
    'services_provided': 'Services and assistance actually given to the client.',
    'service_providers': 'Directory of agencies and offices that receive referrals.',
    'referrals': 'Referrals to CPU members and agencies, with status, outcome and dates.',
    'referral_events': 'Each status change of a referral.',
    'case_conferences': 'Case conferences.',
    'case_conference_participants': 'People invited to or attending a case conference.',
    'case_conference_decisions': 'Decisions taken at a case conference.',
    'case_conference_actions': 'Actions agreed at a case conference.',
    'case_conference_history': 'Changes to case conference records.',
    'case_closure_records': 'Closure summaries and their review (versioned).',
    'case_aftercare_plans': 'Aftercare plans after closure.',
    'case_reintegrations': 'Reintegration plans.',
    'case_transfers': 'Formal handover of a case to another worker or office.',
    'case_transfer_history': 'Steps of a case handover.',
    'mdt_case_access': 'Time-limited access of a CPU/MDT member to one case, with purpose and lawful basis.',
    'mdt_case_notes': 'Notes written by MDT members on a case they were given access to.',
    'mdt_actions': 'Actions assigned to MDT members.',
    'mdt_attachments': 'Files attached in MDT collaboration.',
    'mdt_discussions': 'MDT discussion messages on a case.',
    'mdt_discussion_reads': 'Which discussion messages each member has read.',
    'lgu_coordination_circles': 'Kalinga Connect coordination groups per LGU.',
    'lgu_coordination_members': 'Members of a coordination group.',
    'lgu_coordination_messages': 'Kalinga Connect messages.',
    'lgu_coordination_message_mentions': 'People mentioned in a message.',
    'lgu_coordination_message_reads': 'Read receipts of messages.',
    'record_governance': 'Classification and retention status of a record.',
    'record_classifications': 'Record classification categories and sensitivity.',
    'retention_rules': 'How long each kind of record is kept.',
    'legal_holds': 'Legal holds that stop disposal of records.',
    'legal_hold_records': 'Records covered by a legal hold.',
    'record_dispositions': 'Proposals to dispose of records at the end of retention.',
    'record_disposition_history': 'Review and approval steps of a disposition.',
    'record_disclosures': 'Records released to an outside party, with purpose and authority.',
    'record_disclosure_items': 'Items included in a disclosure.',
    'lgu_units': 'Cities and municipalities (PSGC) using Kalinga.',
    'lgu_profiles': 'Office details, logo and office head (signatory) of each LGU.',
    'lgu_cpu_members': 'Composition of the LGU Child Protection Unit.',
    'lgu_reconciliation_queue': 'Records whose LGU must be confirmed.',
    'user_lgu_assignments': 'Which LGUs each user works in, with effective dates.',
    'users': 'User accounts: role, position, location, 2FA settings and status.',
    'roles': 'Roles available in the system.',
    'permissions': 'Permissions that can be granted.',
    'permission_role': 'Permissions granted to each role.',
    'password_reset_tokens': 'One-time password reset tokens.',
    'sessions': 'Active sign-in sessions (encrypted).',
    'audit_logs': 'Append-only audit trail of sign-ins, views and every change (ids and codes, no narratives).',
    'notifications': 'In-app notifications to users.',
    'offline_devices': 'Devices registered for Field mode, with their case pack expiry and revocation.',
    'offline_sync_items': 'Records sent from Field mode and their sync status.',
    'form_fields': 'Extra form fields configured by the administrator.',
    'reference_data': 'Lists used in forms (reference values).',
    'migrations': 'Database migrations applied (Laravel).',
    'cache': 'Application cache (Laravel).', 'cache_locks': 'Cache locks (Laravel).',
    'jobs': 'Queued background jobs (Laravel).', 'job_batches': 'Batches of background jobs (Laravel).', 'failed_jobs': 'Background jobs that failed (Laravel).',
}

COMMON = {
    'id': 'Unique record number.', 'created_at': 'When the record was created.', 'updated_at': 'When the record was last changed.',
    'deleted_at': 'When the record was archived (soft delete); empty if active.', 'status': 'Current status of the record.',
    'version': 'Version number; a new version is created instead of overwriting.', 'is_current': 'Whether this is the current version.',
    'active': 'Whether the record is in use.', 'name': 'Name.', 'remarks': 'Remarks.', 'notes': 'Notes.', 'description': 'Description.',
    'priority': 'Priority (low, normal, high, urgent).', 'category': 'Category.', 'code': 'Short code.', 'label': 'Display label.',
    'region': 'Region.', 'province': 'Province.', 'municipality': 'City or municipality.', 'barangay': 'Barangay.',
    'reason': 'Reason given.', 'comments': 'Comments.', 'outcome': 'Outcome.', 'event': 'Event name.', 'changes': 'Details of the change (ids and codes only).',
    'record_type': 'Kind of record.', 'record_id': 'Number of the related record.', 'source_type': 'Kind of record this came from.', 'source_id': 'Number of the record this came from.',
    'target_date': 'Target completion date.', 'review_due_at': 'Date the next review is due.', 'finalized_at': 'When the record was finalized (locked).',
    'body': 'Text of the note or message.', 'purpose': 'Purpose.', 'lawful_basis': 'Lawful basis under the Data Privacy Act.', 'ip_address': 'Client network address at the time of the action.',
}


def describe(table: str, col: dict) -> str:
    name, fk = col['column_name'], col['fk']
    if name in COMMON:
        return COMMON[name]
    if fk:
        noun = fk.replace('_', ' ').rstrip('s') if fk != 'case_plans' else 'intervention plan'
        if name.endswith('_by'):
            return f"User who {name[:-3].replace('_', ' ')} the record."
        return f"Link to the {noun} record ({fk})."
    words = name.replace('_', ' ')
    if name.endswith('_at'):
        return f"Date and time: {words[:-3]}."
    if name.endswith('_date'):
        return f"Date: {words[:-5]}."
    if name.startswith('is_') or name.startswith('has_') or col['type'] == 'boolean':
        return f"Yes/no: {words}."
    if name.endswith('_count'):
        return f"Number of {words[:-6]}."
    if name.endswith('_id'):
        return f"Number of the related {words[:-3]}."
    return words[0].upper() + words[1:] + '.'


def load(path: str):
    rows = json.load(open(path))
    tables = {}
    for r in rows:
        tables.setdefault(r['table_name'], []).append(r)
    return tables


def to_markdown(tables) -> str:
    out = ['# Data dictionary - Kalinga CEFMU Case Management Information System', '',
           'Generated from the PostgreSQL schema by scripts/generate_data_dictionary.py. '
           f'{len(tables)} tables. PK = primary key; FK = references another table.', '']
    for group, names in GROUPS:
        present = [n for n in names if n in tables]
        if not present:
            continue
        out += [f'## {group}', '']
        for t in present:
            out += [f'### {t}', '', TABLES.get(t, ''), '', '| Column | Type | Null | Key | Description |', '|---|---|---|---|---|']
            for c in tables[t]:
                key = 'PK' if c['pk'] else (f"FK {c['fk']}" if c['fk'] else '')
                out.append(f"| {c['column_name']} | {c['type']} | {'Yes' if c['is_nullable'] == 'YES' else 'No'} | {key} | {describe(t, c)} |")
            out.append('')
    missing = sorted(set(tables) - {n for _, ns in GROUPS for n in ns})
    if missing:
        out += ['## Other tables', ''] + [f'- {m}' for m in missing]
    return '\n'.join(out) + '\n'


def to_html(tables) -> str:
    e = html.escape
    parts = []
    for group, names in GROUPS:
        present = [n for n in names if n in tables]
        if not present:
            continue
        parts.append(f'<h2>{e(group)}</h2>')
        for t in present:
            parts.append(f'<h3>{e(t)}</h3><p>{e(TABLES.get(t, ""))}</p>')
            rows = ''.join(
                f"<tr><td>{e(c['column_name'])}</td><td>{e(c['type'])}</td><td>{'Yes' if c['is_nullable'] == 'YES' else 'No'}</td>"
                f"<td>{'PK' if c['pk'] else (e('FK ' + c['fk']) if c['fk'] else '')}</td><td>{e(describe(t, c))}</td></tr>" for c in tables[t])
            parts.append('<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:9pt">'
                         '<tr style="background:#f4f1fa"><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Description</th></tr>' + rows + '</table>')
    return '\n'.join(parts)


def to_csv(tables) -> str:
    import csv
    import io
    out = io.StringIO()
    writer = csv.writer(out, lineterminator='\n')
    writer.writerow(['Group', 'Table', 'Column', 'Type', 'Required', 'Notes'])
    for group, names in GROUPS:
        if group.startswith('Framework'):
            continue
        for t in [n for n in names if n in tables]:
            writer.writerow([group, t, '', '', '', TABLES.get(t, '')])
            for c in tables[t]:
                if c['column_name'] in ('id', 'created_at', 'updated_at'):
                    continue
                d = describe(t, c)
                if d.lower().rstrip('.') == c['column_name'].replace('_', ' ').lower():
                    d = ''
                if c['fk']:
                    d = ('Links to ' + c['fk'] + ('. ' + d if d and not d.startswith('Link to') else '')).rstrip()
                kind = c['type'].replace('timestamp without time zone', 'timestamp')
                writer.writerow(['', t, c['column_name'], 'text' if kind == 'varchar(255)' else kind, '' if c['is_nullable'] == 'YES' else 'Yes', d])
    return out.getvalue()


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--sql':
        print(SQL)
        sys.exit(0)
    tables = load(sys.argv[1])
    open(sys.argv[2], 'w').write(to_markdown(tables))
    for extra in sys.argv[3:]:
        open(extra, 'w').write(to_csv(tables) if extra.endswith('.csv') else to_html(tables))
    print(f'{len(tables)} tables written')
