# Privacy data flow

## Collection and storage

Public users submit a report through the public dashboard. The application records the report, its endorsed LGU, and an audit event. It is not automatically a protected case folder. Authorized workers screen and decide the report; an accepted report may create a case, client record, and initial status history in a database transaction.

Protected intake, assessment, safety, planning, referral, monitoring, consent, closure, and document workflows are available only after authenticated server-side permission and case-scope checks. Case data is stored in the application database; uploaded documents use the configured private filesystem disk. Database access, backup encryption, key custody, and deployment secrets are operational controls that must be confirmed by the deployment owner.

Records lifecycle governance uses only allowlisted record aliases and carries lifecycle/retention metadata rather than duplicating case narratives. New files are private and quarantined pending an approved scan integration; the application creates no public URL and never assumes scan clearance. Restricted document metadata is redacted for a case-scoped user lacking the dedicated permission. Legal holds, retention review, disclosure, and disposition events are audited using minimum metadata only. Disclosures retain exact record/version references without copied sensitive content. No current workflow can physically delete a record or transition it to disposed.

## Access and disclosure flow

Case content is returned only through authenticated endpoints after a role permission plus record-level jurisdiction check. Referral creation records the selected lawful basis and blocks consent-based sharing unless an active sharing consent exists. The current provider roles do not receive a case portal; provider-scoped disclosure is a future controlled workflow.

Analytics endpoints return aggregates only. Public and aggregate-only role views suppress grouped values below five. CSV exports are aggregate-only and create audit events. Audit pages expose accountability metadata rather than raw request/change values.

## Offline processing

Connect direct messages are a separate online-only flow: an authenticated sender
selects another currently eligible member of the same LGU circle; the server
checks current permissions, accounts, memberships, assignments and circle/LGU
status for both participants before returning or accepting their pair's
messages. A third member cannot read the pair through Connect. Body text is
stored only in `coordination_direct_messages`, not group messages, audit
metadata, case records or browser persistent storage. POST search keeps search
terms out of URLs; responses are private/no-store. Drafts remain in tab memory,
separated by circle and recipient, and clear on access denial. Sending requires
connectivity and uses the existing explicit unknown-outcome recovery instead of
automatic retries. Revocation blocks subsequent reads/searches/sends and causes
the client to clear loaded conversation/draft data on the next denied request.
Previously read information cannot be remotely recalled from a disconnected
device. No group previews, mentions, formal capture, case tags, attachments,
automated notifications or offline caching are added for direct messages.
Retention and authorized operational review remain owner-policy decisions;
no automatic purge or extra reviewer access is inferred.

Offline workspaces are gated by `offline.use`, restricted to operationally necessary authorized cases, and use the application’s controlled sync queue. Offline packs, sync conflicts, revocation, retry, expiry, and device cleanup require continued deployment and field-device review. Sensitive data must not be stored in LocalStorage; browser storage, logs, URLs, and device loss remain privacy-sensitive surfaces.

## Audit and incident handling

The system records security-relevant activities such as report lifecycle changes, case creation, referrals, documents, exports, account changes, and offline synchronization. Audit viewers receive metadata only. The owner must define incident response contacts, audit retention, backup retention, breach handling, and periodic access-review procedures before production use.
