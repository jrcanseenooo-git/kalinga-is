export type PlanningValue = string | number | boolean | null;
export type PlanningData = Record<string, PlanningValue>;
export interface PlanningField {
 key:string; label:string; kind?:'text'|'textarea'|'date'|'datetime-local'|'select'|'choice'|'checkbox'|'number';
 required?:boolean; max?:number; hint?:string; options?:{value:PlanningValue;label:string}[]; choices?:{value:string;label:string}[]; wide?:boolean;
}
export interface CasePlan {
 id:number; version:number; status:string; is_current:boolean; authored_by:number;
 overall_objective:string|null; summary:string; plan_date:string|null; review_due_at:string|null;
 previous_plan_id:number|null; reviewed_at:string|null; returned_at:string|null; review_comments:string|null;
}
export interface PlanGoal {id:number;domain:string;need_problem:string;goal_statement:string;priority:string;target_date:string|null;status:string}
export interface PlanIntervention {
 id:number;case_plan_goal_id:number|null;activity:string|null;service_category:string;responsible_office:string|null;
 need?:string;goal?:string;
 target_date:string|null;priority:string;progress:string;outcome_status:string|null;actual_outcome:string|null;
 expected_outcome:string|null;barriers:string|null;next_action:string|null;
 operational_record_type:string|null;operational_record_id:number|null;operational_status?:string|null;
 service_provider_id?:number|null;provider_name?:string|null;start_date?:string|null;
}
export interface PlanEntry {plan:CasePlan;goals:PlanGoal[];interventions:PlanIntervention[]}
export interface ContinuityRecord {id:number;case_plan_id:number|null;status:string;responsible_office:string|null;created_at:string;initial_follow_up_at?:string|null;target_date?:string|null;review_at?:string|null;required?:boolean;applicable?:boolean}
export interface HandoverRecord {id:number;status:string;originating_office:string;receiving_office:string;reason:string;receiving_worker_name:string|null;requested_at:string|null;endorsed_at:string|null;accepted_at:string|null;completed_at:string|null}
export interface PlanningResponse {plans:PlanEntry[];aftercare:ContinuityRecord[];reintegration:ContinuityRecord[];transfers:HandoverRecord[]}
