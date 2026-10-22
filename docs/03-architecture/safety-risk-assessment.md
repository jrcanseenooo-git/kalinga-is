# Safety and Risk Assessment

## Workflow and safeguards

`risk_assessments` is a repeatable professional record, separate from the generic case assessment. Each record is an initial, reassessment, emergency, pre-closure, or other assessment in `draft` or `final` status. Final records are append-only: there is no update endpoint, so a correction must be captured in a later assessment rather than silently overwriting history.

The case header's current risk is derived only from the latest finalized assessment by `assessed_at` (then record ID), never from a draft or insertion order. High or critical current risk shows **Review required**. This is an explainable prompt only; it does not select an intervention, disposition, or closure decision.

## Data dictionary

| Table | Key fields | Purpose |
| --- | --- | --- |
| `risk_assessments` | case, type, assessed time/by, risk level, immediate protection, safety-plan status, required action, responsible person, target/reassessment dates, rationale, draft/final metadata | Historical professional safety assessment. |
| `risk_assessment_factors` | assessment, domain, finding level, `is_protective_factor`, notes | Separates risk concerns from protective factors without a numerical score. |
| `cases` | current risk level, current risk assessed time | Cached display of the latest finalized professional assessment. |

`risk.assess` is limited to authorized casework roles and still requires normal assigned/municipal/provincial case scope. MDT/partner roles have no automatic case or risk-assessment access. Narrative content is not placed in notifications, dashboards, analytics, or audit change metadata.

## Reassessment and offline boundary

The Case Workspace displays scheduled or overdue reassessment as **Review required**. It does not create a professional decision automatically. The next Field Mode increment should support encrypted offline drafts for risk assessments, factors, and reassessment dates, with version-aware sync and human review for conflicts; it is intentionally not enabled in the current offline queue.
