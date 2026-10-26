# Data model

`clients` is the person registry; `reports` records reported concerns; `cases` is the electronic case folder linked to a client and optional report. Supporting child tables capture notes, plans/interventions, referrals/providers, monitoring visits, tasks, and document metadata. `audit_logs` records business events; `offline_sync_items` reserves durable server-side synchronization status.

Foreign keys enforce parent relationships, case numbers and report references are unique, soft deletion is used for personally sensitive primary records, and common caseload/status lookup columns are indexed.

`coordination_direct_messages` stores private Connect conversations separately
from `lgu_coordination_messages`. Each record belongs to one circle and an exact
sender/recipient pair, with an optional parent in the same pair. Circle, user
and parent foreign keys restrict deletion; two directional compound indexes
support bounded history. The server enforces current eligibility for both
participants and same-conversation replies. These records carry no case IDs,
case references, mentions or formal-capture links. See
[Connect messaging delivery](../implementation/CONNECT-MESSAGING-2026-10-23.md).
