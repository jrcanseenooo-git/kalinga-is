# Data model

`clients` is the person registry; `reports` records reported concerns; `cases` is the electronic case folder linked to a client and optional report. Supporting child tables capture notes, plans/interventions, referrals/providers, monitoring visits, tasks, and document metadata. `audit_logs` records business events; `offline_sync_items` reserves durable server-side synchronization status.

Foreign keys enforce parent relationships, case numbers and report references are unique, soft deletion is used for personally sensitive primary records, and common caseload/status lookup columns are indexed.
