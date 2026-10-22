# Plan and continuity permission matrix

| Capability | Case Manager / Social Worker | CPO / LGU Supervisor / FO User | MDT member | Aggregate-only roles |
|---|---:|---:|---:|---:|
| View plan/continuity workspace | Assigned case scope only | Municipal/provincial case scope only | No standing access | No |
| Create/edit draft plan, goals, interventions | Yes, own draft | Yes, within scope | No | No |
| Submit plan for review | Yes, own draft | Yes, own draft | No | No |
| Return or activate plan | No | Yes, different author, within scope | No | No |
| Complete plan / record immutable review | No | Yes, different author, within scope | No | No |
| Record aftercare/reintegration | Yes, within scope | Yes, within scope | No | No |
| Start/advance same-jurisdiction handover | Yes, within scope | Yes, within scope | No | No |
| Cross-LGU handover | Disabled pending owner/DPO policy | Disabled pending owner/DPO policy | No | No |
| View Overview metadata | Within case scope | Within case scope | No standing access | Aggregate only, no case plan data |

`plans.manage`, `plans.review`, and `transfers.manage` are enforced by Laravel Gates and by `CaseAccessScope`; Vue visibility is not an authorization control. A plan reviewer must be a different user from the author. Case assignment changes only on a completed approved handover transaction.

## Policy validation required

The LGU/FO owner and DPO must validate: closure blocker versus warning thresholds; required aftercare conditions/frequency; formal cross-LGU handoff and retained/suspended source access; applicable reintegration safeguards; record retention; and any future offline draft access and encryption design.
