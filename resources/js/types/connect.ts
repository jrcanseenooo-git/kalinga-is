export interface CoordinationCircle { id:number; lgu_name:string; name:string; member_count?:number; last_author?:string|null; last_excerpt?:string|null; last_at?:string|null; unread?:number }
export interface CoordinationMember { id:number; name:string; role:string; membership_role:string; cpu_discipline?:string|null }
export interface CoordinationMessage { id:number; parent_id:number|null; case_id:number|null; case_reference:string|null; body:string; author:string; author_role:string; created_at:string; is_urgent:boolean; follow_up:boolean; can_open_case:boolean; is_own?:boolean; mentions?:{id:number;name:string}[] }
export interface CoordinationPagination { before:number|null; has_more:boolean }
export interface CoordinationProps { circles:CoordinationCircle[]; members:CoordinationMember[]; messages:CoordinationMessage[]; pagination?:CoordinationPagination; unread_before_opening?:number }
export interface CoordinationDraft {body:string;parent_id:number|null;case_id:string;is_urgent:boolean;follow_up:boolean;mention_user_ids:number[]}
export interface CoordinationPage {messages:CoordinationMessage[];members?:CoordinationMember[];pagination:CoordinationPagination}
