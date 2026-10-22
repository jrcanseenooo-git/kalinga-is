# UI-06 safety and risk assessment QA - 2026-10-18

Status: VERIFIED bounded UI/functional increment. Final checkpoint below supersedes intermediate results; whole-module acceptance remains open.

Reference: design-references/05_Safety_and_Risk.png, inspected individually. Preserve professional manual judgment and existing domains; mockup risk ratings and sample safety narratives are not authoritative. New flat composition places risk domains beside protective strengths/rationale on desktop, stacks them on phones, separates actions/review and assessment history. No automatic scoring, fabricated advice or new workflow policy.

Confirmed regressions before repair:
- High/critical risk with no immediate protection/action returned generic 422 rather than field validation (1 failed / 2 passed). Replaced abort with required_action ValidationException, preserving the same existing requirement. Existing HTML test now expects redirect/session error; JSON still 422.
- Existing workspace omitted persisted current_risk_level from its selected case fields. New actual Inertia regression failed; select now includes current risk and assessment timestamp under the existing case scope.
- Corrected browser locator to actual Safety tab, then the new named-control assertion failed: Assessment type was not labeled. Earlier incorrect Safety & Risk locator timeout was test setup, not product evidence.

Implemented: focused typed CaseSafetyPanel using shared field/status components, all existing form/domain options, field/error summary with focus and retained input, processing guard, explicit finalization review/cancel, separate draft/final feedback, sorted history, assessor provenance and progressive disclosure of recorded findings/rationale. Old history comparison arrow incorrectly mixed drafts/previous ordering; now show source records without implying a calculated trend. Existing review alert, existing server permissions and latest-finalized current-risk rule retained. No schema change. Assessment input/time display explicitly labels the existing UTC convention; Philippine time unification remains P2-C2, no legacy backfill.

Verification so far: risk/assignment target20/161 passed; expanded risk/assignment/dashboard target21/215 passed after current-risk repair. Production vue-tsc/Vite build21.62s passes. Browser workflow, whole-main axe, six-width screenshots and actual comparison are pending. No new offline store, localStorage, console, notification, URL narrative or service-worker cache writes introduced. Online draft is a server-saved assessment, not encrypted field autosave; connection loss before save remains a documented wider continuity requirement.

Remaining: collect browser results, repair confirmed issues, inspect actual six-width screenshots against reference, run final applicable backend/frontend regressions, then update matrices with actual verified scope. Official policy, PostgreSQL, real-device and field UAT blockers remain unchanged.

## UI-06 final checkpoint

VERIFIED bounded UI/functional increment. Final build28.96s; full backend309/2109 (113.99s); frontend20; final browser9/9 (1.7m). Six actual form and history viewport captures at360/390/768/1024/1440/1920 were individually inspected and compared with05_Safety_and_Risk.png. Refined full-width desktop composition, side-by-side risk/protective panels at1024+, stacked phone/tablet layout and navigation scroll to form below fixed header. Form top64-160px is asserted. Whole-main axe has no serious/critical findings and no horizontal overflow. Final draft/error/finalization/cancel/reload/history/current-risk workflow passes; recipient transfer also passes12.5s. All screenshots contain guarded synthetic records only.

Initial finalization workflow reached saved records but failed on the exact Safety tab locator after its real count badge changed the accessible name to Safety 1. Selector now accepts that existing count; no product timeout or access change. Prior incorrect tab name and wrapped-select label failures are test corrections, not additional product defects.

Reference comparison: retained flat bordered panels and adjacent risk/protective strengths, current professional rating and safety action area. Editable approved domains replace mockup fabricated per-domain ratings; no automated score, invented signs or fictional safety plan. All existing fields/options and recorded history remain. No whole module acceptance: approved domain/UAT, secure online unsaved-form continuity, PH time unification and offline risk applicability remain outstanding.
