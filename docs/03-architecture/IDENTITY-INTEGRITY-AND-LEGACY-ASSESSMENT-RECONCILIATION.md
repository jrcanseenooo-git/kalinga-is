# Identity Integrity, Controlled Merge, and Legacy Assessment Reconciliation

## Safety boundary

Identity similarity is decision support, never an identity determination. Kalinga uses bounded, deterministic candidate generation (shared normalized identity fields and approved identifier hashes) and records the matching and conflicting indicators. It does not use facial recognition, biometrics, AI identity inference, or opaque composite scores.

Generation blocks first on normalized surname or date of birth, limits the candidate set to 100 records, and requires at least two explainable matching indicators before creating a review prompt. `clients` has indexed identity-status/DOB blocking and a PostgreSQL/SQLite normalized-surname index for this path; it is not an all-to-all comparison.

The retired `/cases/duplicates/check` route returns `410 Gone`; it cannot expose match details. Intake creates a case normally and creates scoped review candidates afterwards where deterministic blocking indicates review is warranted.

## Identity workflow

`Potential duplicate → confirmed duplicate / related record / not duplicate / no action → merge proposed → merge approved → executed`

`related record` creates an explicit identity link only. Family/social relationships, household memberships, identity links, and same-person decisions are different records and must not be inferred from one another.

### Authorization and scope

| Permission | Roles | Scope |
|---|---|---|
| `identity.candidates.view` | casework roles | Existing case assignment, municipality, or province scope |
| `identity.review`, `identity.link` | CPO, LGU Supervisor, Field Office | Same server-side scope |
| `identity.merge.approve`, `identity.merge.execute`, `identity.merge.history.view` | CPO, LGU Supervisor, Field Office | Same server-side scope and same-jurisdiction candidate |
| `assessments.legacy.reconcile` | CPO, LGU Supervisor, Field Office | Same case scope |

Cross-jurisdiction candidates expose only a notice that a potential match exists elsewhere. They do not reveal the other identity's demographics, identifiers, cases, documents, narratives, or assessment content. Cross-LGU linking and merging remain disabled pending DPO/DSA and owner-approved inter-LGU governance.

## Controlled merge

A merge never merges cases. It preserves case numbers, status histories, assignments, plans, referrals, assessments, conferences, MDT data, documents, legal holds, disclosures, and retention governance. Only the case/report identity reference is reassigned in a single transaction; each transfer is written to `client_identity_merge_affected_records`.

The source client becomes `merged` with `canonical_client_id`; it is not deleted. Normal active search suppresses merged sources. Historic identifiers can be resolved only through the authorized, POST-only historical-identifier workflow. The supplied value is normalized and matched through the keyed `normalized_hash`; it is never put in a URL, queue, audit metadata, analytics, or notification. The response returns only the authorized canonical-client reference, not identifier values or merge rationale.

At proposal time, field decisions are frozen. Approval requires a different authorized user. Execution locks the merge and both client rows, rechecks active identity state and frozen signatures, and is idempotent. A changed identity makes approval stale instead of recalculating fields. A merged source cannot be used as a new active source, preventing cycles and ambiguous chains.

No one-click reversal is implemented. Downstream records can change after execution; an authorized administrative correction must use the recorded lineage and affected-record ledger. This is a deliberate safety limitation.

## Household and identifiers

This release adds minimal `households` and `household_memberships` structures: effective dates, membership state, source, and correction reason. It does not implement household case management or automatic household merges.

`client_identifiers` stores type, protected value, normalized matching hash, display hint, source, and verification state. Production key-management/encryption requirements for protected values require DPO/security-owner confirmation before identifier entry is enabled. If the source and survivor have different values of the same identifier type, the proposal records a `client_identity_merge_identifier_conflicts` record. Execution preserves both records and their provenance: it neither transfers, overwrites, nor deduplicates identifiers. A future controlled identifier-correction workflow is required before that evidence can change.

## Legacy assessment reconciliation

`assessments` remains untouched. `legacy_assessment_reconciliations` records classification (`equivalent_mappable`, `partial`, `unsupported`, `historical_only`, or `requires_manual_review`), validation state, unresolved fields, reviewer, migration version/date, and a proposed structured target.

Reconciliation is not run by an identity merge. A partial legacy assessment may only create a source-attributed draft/incomplete Comprehensive Assessment after a separate approved migration action. It is never represented as finalized merely because legacy JSON has values.

## Audit and privacy

Audits include candidate view/review, link, merge proposal/approval/execution, and metadata-only affected-record counts. They exclude identity values, case narratives, child participation, Health/SRH, document names, and assessment responses. Notifications, dashboards, analytics, and the review queue must carry operational metadata only.
