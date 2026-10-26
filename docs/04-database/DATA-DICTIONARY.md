# Data dictionary - Kalinga CEFMU Case Management Information System

Generated from the PostgreSQL schema by scripts/generate_data_dictionary.py. 95 tables. PK = primary key; FK = references another table.

## Clients and identity

### clients

The child (client) of a case: identifying information, address, safe contact and primary caregiver.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| first_name | varchar(255) | No |  | First name. |
| last_name | varchar(255) | No |  | Last name. |
| birthdate | date | Yes |  | Birthdate. |
| sex | varchar(24) | Yes |  | Sex. |
| phone | varchar(255) | Yes |  | Phone. |
| barangay | varchar(255) | Yes |  | Barangay. |
| municipality | varchar(255) | Yes |  | City or municipality. |
| province | varchar(255) | Yes |  | Province. |
| identifiers | json | Yes |  | Identifiers. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| deleted_at | timestamp | Yes |  | When the record was archived (soft delete); empty if active. |
| region | varchar(255) | Yes |  | Region. |
| middle_name | varchar(255) | Yes |  | Middle name. |
| suffix | varchar(255) | Yes |  | Suffix. |
| civil_status | varchar(255) | Yes |  | Civil status. |
| religion | varchar(255) | Yes |  | Religion. |
| ip_category | varchar(255) | Yes |  | Ip category. |
| education_attainment | varchar(255) | Yes |  | Education attainment. |
| house_no_street | varchar(255) | Yes |  | House no street. |
| permanent_house_no_street | varchar(255) | Yes |  | Permanent house no street. |
| permanent_region | varchar(255) | Yes |  | Permanent region. |
| permanent_province | varchar(255) | Yes |  | Permanent province. |
| permanent_municipality | varchar(255) | Yes |  | Permanent municipality. |
| permanent_barangay | varchar(255) | Yes |  | Permanent barangay. |
| safe_contact_method | varchar(100) | Yes |  | Safe contact method. |
| safe_contact_notes | text | Yes |  | Safe contact notes. |
| primary_caregiver_name | varchar(160) | Yes |  | Primary caregiver name. |
| primary_caregiver_relationship | varchar(100) | Yes |  | Primary caregiver relationship. |
| primary_caregiver_contact | varchar(80) | Yes |  | Primary caregiver contact. |
| identity_status | varchar(32) | No |  | Identity status. |
| canonical_client_id | bigint | Yes | FK clients | Link to the client record (clients). |
| identity_updated_at | timestamp | Yes |  | Date and time: identity updated. |
| alias | varchar(80) | Yes |  | Alias. |
| permanent_same_as_present | boolean | Yes |  | Yes/no: permanent same as present. |
| caregiver_same_address | boolean | Yes |  | Yes/no: caregiver same address. |
| caregiver_house_no_street | varchar(255) | Yes |  | Caregiver house no street. |
| caregiver_region | varchar(120) | Yes |  | Caregiver region. |
| caregiver_province | varchar(120) | Yes |  | Caregiver province. |
| caregiver_municipality | varchar(120) | Yes |  | Caregiver municipality. |
| caregiver_barangay | varchar(120) | Yes |  | Caregiver barangay. |

### client_identifiers

Official identifiers recorded for a client, used for duplicate checks.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| client_id | bigint | No | FK clients | Link to the client record (clients). |
| identifier_type | varchar(80) | No |  | Identifier type. |
| protected_value | text | Yes |  | Protected value. |
| normalized_hash | character | Yes |  | Normalized hash. |
| display_hint | varchar(64) | Yes |  | Display hint. |
| verification_status | varchar(32) | No |  | Verification status. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| recorded_by | bigint | Yes | FK users | User who recorded the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### client_identity_links

Links between client records confirmed as the same person.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| left_client_id | bigint | No | FK clients | Link to the client record (clients). |
| right_client_id | bigint | No | FK clients | Link to the client record (clients). |
| link_type | varchar(50) | No |  | Link type. |
| status | varchar(32) | No |  | Current status of the record. |
| rationale | text | Yes |  | Rationale. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### client_identity_merges

Approved merges of duplicate client records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| candidate_id | bigint | Yes | FK identity_duplicate_candidates | Link to the identity duplicate candidate record (identity_duplicate_candidates). |
| source_client_id | bigint | No | FK clients | Link to the client record (clients). |
| survivor_client_id | bigint | No | FK clients | Link to the client record (clients). |
| status | varchar(32) | No |  | Current status of the record. |
| reason | text | No |  | Reason given. |
| source_signature | varchar(64) | No |  | Source signature. |
| survivor_signature | varchar(64) | No |  | Survivor signature. |
| proposed_by | bigint | No | FK users | User who proposed the record. |
| proposed_at | timestamp | No |  | Date and time: proposed. |
| approved_by | bigint | Yes | FK users | User who approved the record. |
| approved_at | timestamp | Yes |  | Date and time: approved. |
| executed_by | bigint | Yes | FK users | User who executed the record. |
| executed_at | timestamp | Yes |  | Date and time: executed. |
| correction_notes | text | Yes |  | Correction notes. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### client_identity_merge_affected_records

Records moved from one client to another during a merge.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| merge_id | bigint | No | FK client_identity_merges | Link to the client identity merge record (client_identity_merges). |
| record_type | varchar(80) | No |  | Kind of record. |
| record_id | bigint | No |  | Number of the related record. |
| from_client_id | bigint | No |  | Number of the related from client. |
| to_client_id | bigint | No |  | Number of the related to client. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### client_identity_merge_field_decisions

Which value was kept for each field during a merge.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| merge_id | bigint | No | FK client_identity_merges | Link to the client identity merge record (client_identity_merges). |
| field_name | varchar(80) | No |  | Field name. |
| resolution | varchar(40) | No |  | Resolution. |
| frozen_value | text | Yes |  | Frozen value. |
| rationale | text | Yes |  | Rationale. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### client_identity_merge_identifier_conflicts

Identifiers that disagreed between merged clients.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| merge_id | bigint | No | FK client_identity_merges | Link to the client identity merge record (client_identity_merges). |
| source_identifier_id | bigint | No | FK client_identifiers | Link to the client identifier record (client_identifiers). |
| survivor_identifier_id | bigint | Yes | FK client_identifiers | Link to the client identifier record (client_identifiers). |
| identifier_type | varchar(80) | No |  | Identifier type. |
| resolution | varchar(48) | No |  | Resolution. |
| review_note | text | Yes |  | Review note. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### identity_duplicate_candidates

Possible duplicate clients found by the system, for review.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| client_a_id | bigint | No | FK clients | Link to the client record (clients). |
| client_b_id | bigint | No | FK clients | Link to the client record (clients). |
| candidate_key | varchar(80) | No |  | Candidate key. |
| status | varchar(40) | No |  | Current status of the record. |
| matching_signals | json | No |  | Matching signals. |
| jurisdiction_context | varchar(32) | No |  | Jurisdiction context. |
| created_by | bigint | Yes | FK users | User who created the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### identity_review_decisions

Decisions taken on possible duplicates.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| candidate_id | bigint | No | FK identity_duplicate_candidates | Link to the identity duplicate candidate record (identity_duplicate_candidates). |
| decision | varchar(40) | No |  | Decision. |
| rationale | text | Yes |  | Rationale. |
| reviewed_by | bigint | No | FK users | User who reviewed the record. |
| reviewed_at | timestamp | No |  | Date and time: reviewed. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### relationships

Family members and support persons of a client (family composition).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| client_id | bigint | No | FK clients | Link to the client record (clients). |
| related_client_id | bigint | Yes | FK clients | Link to the client record (clients). |
| name | varchar(255) | Yes |  | Name. |
| relationship_type | varchar(255) | No |  | Relationship type. |
| phone | varchar(255) | Yes |  | Phone. |
| is_guardian | boolean | No |  | Yes/no: is guardian. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| sex | varchar(24) | Yes |  | Sex. |
| birthdate | date | Yes |  | Birthdate. |
| education | varchar(255) | Yes |  | Education. |
| occupation | varchar(255) | Yes |  | Occupation. |
| civil_status | varchar(80) | Yes |  | Civil status. |
| same_address_as_client | boolean | Yes |  | Yes/no: same address as client. |
| house_no_street | varchar(255) | Yes |  | House no street. |
| region | varchar(120) | Yes |  | Region. |
| province | varchar(120) | Yes |  | Province. |
| municipality | varchar(120) | Yes |  | City or municipality. |
| barangay | varchar(120) | Yes |  | Barangay. |

### households

Households a client belongs to.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| status | varchar(32) | No |  | Current status of the record. |
| locality_hint | varchar(255) | Yes |  | Locality hint. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| created_by | bigint | Yes | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### household_memberships

Membership of clients in households.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| household_id | bigint | No | FK households | Link to the household record (households). |
| client_id | bigint | No | FK clients | Link to the client record (clients). |
| effective_from | date | Yes |  | Effective from. |
| effective_to | date | Yes |  | Effective to. |
| membership_status | varchar(32) | No |  | Membership status. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| correction_reason | text | Yes |  | Correction reason. |
| recorded_by | bigint | Yes | FK users | User who recorded the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Reports and intake

### reports

Reports of possible CEFMU cases received from the public or partners, before a case is opened.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| reference_no | varchar(255) | No |  | Reference no. |
| client_id | bigint | Yes | FK clients | Link to the client record (clients). |
| status | varchar(255) | No |  | Current status of the record. |
| source | varchar(255) | Yes |  | Source. |
| narrative | text | No |  | Narrative. |
| priority | varchar(255) | No |  | Priority (low, normal, high, urgent). |
| submitted_at | timestamp | Yes |  | Date and time: submitted. |
| created_by | bigint | Yes | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| deleted_at | timestamp | Yes |  | When the record was archived (soft delete); empty if active. |
| source_other | varchar(255) | Yes |  | Source other. |
| reporter_name | varchar(255) | Yes |  | Reporter name. |
| reporter_contact | varchar(255) | Yes |  | Reporter contact. |
| subject_first | varchar(255) | Yes |  | Subject first. |
| subject_last | varchar(255) | Yes |  | Subject last. |
| subject_middle | varchar(255) | Yes |  | Subject middle. |
| subject_birthdate | date | Yes |  | Subject birthdate. |
| subject_age | smallint | Yes |  | Subject age. |
| subject_sex | varchar(24) | Yes |  | Subject sex. |
| category | varchar(255) | Yes |  | Category. |
| region | varchar(255) | Yes |  | Region. |
| province | varchar(255) | Yes |  | Province. |
| municipality | varchar(255) | Yes |  | City or municipality. |
| barangay | varchar(255) | Yes |  | Barangay. |
| latitude | numeric | Yes |  | Latitude. |
| longitude | numeric | Yes |  | Longitude. |
| geo_accuracy_m | numeric | Yes |  | Geo accuracy m. |
| geo_source | varchar(255) | Yes |  | Geo source. |
| screened_at | timestamp | Yes |  | Date and time: screened. |
| screened_by | bigint | Yes | FK users | User who screened the record. |
| screening_remarks | text | Yes |  | Screening remarks. |
| validated_at | timestamp | Yes |  | Date and time: validated. |
| validated_by | bigint | Yes | FK users | User who validated the record. |
| validation_remarks | text | Yes |  | Validation remarks. |
| decided_at | timestamp | Yes |  | Date and time: decided. |
| decided_by | bigint | Yes | FK users | User who decided the record. |
| decision_reason | text | Yes |  | Decision reason. |
| duplicate_of_report_id | bigint | Yes | FK reports | Link to the report record (reports). |
| referred_out_to | varchar(255) | Yes |  | Referred out to. |
| is_public_submission | boolean | No |  | Yes/no: is public submission. |
| endorsed_municipality | varchar(255) | Yes |  | Endorsed municipality. |
| lgu_id | bigint | Yes | FK lgu_units | Link to the lgu unit record (lgu_units). |

### reference_sequences

Counters used to issue report and case numbers.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| prefix | varchar(16) | No |  | Prefix. |
| year | smallint | No |  | Year. |
| last_number | bigint | No |  | Last number. |
| initialized_at | timestamp | Yes |  | Date and time: initialized. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### intake_drafts

Saved intakes: a paused Kalinga Intake, visible only to the case manager who started it. Answers are encrypted with the application key; deleted when the case folder is created, when discarded, or 30 days after the last change.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | uuid | No | PK | Unique record number. |
| user_id | bigint | No | FK users | Link to the user record (users). |
| lgu_id | bigint | Yes | FK lgu_units | Link to the lgu unit record (lgu_units). |
| label | varchar(80) | Yes |  | Display label. |
| step | smallint | No |  | Step. |
| payload | text | No |  | Payload. |
| expires_at | timestamp | No |  | Date and time: expires. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Case folder

### cases

The case folder: status, stage, CEFMU classification, intake details, risk level, consent, assignment and LGU.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_number | varchar(255) | No |  | Case number. |
| client_id | bigint | No | FK clients | Link to the client record (clients). |
| report_id | bigint | Yes | FK reports | Link to the report record (reports). |
| assigned_to | bigint | Yes | FK users | Link to the user record (users). |
| status | varchar(255) | No |  | Current status of the record. |
| stage | varchar(255) | No |  | Stage. |
| priority | varchar(255) | No |  | Priority (low, normal, high, urgent). |
| category | varchar(255) | Yes |  | Category. |
| opened_at | timestamp | No |  | Date and time: opened. |
| last_activity_at | timestamp | Yes |  | Date and time: last activity. |
| retention_review_at | timestamp | Yes |  | Date and time: retention review. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| deleted_at | timestamp | Yes |  | When the record was archived (soft delete); empty if active. |
| offline_version | integer | No |  | Offline version. |
| closure_summary | text | Yes |  | Closure summary. |
| closed_at | timestamp | Yes |  | Date and time: closed. |
| case_type | varchar(255) | Yes |  | Case type. |
| circumstance | varchar(255) | Yes |  | Circumstance. |
| pregnancy_status | varchar(255) | Yes |  | Pregnancy status. |
| disability_evidence | varchar(255) | Yes |  | Disability evidence. |
| functional_difficulty | varchar(255) | Yes |  | Functional difficulty. |
| immediate_safety_concern | boolean | No |  | Yes/no: immediate safety concern. |
| mode_of_admission | varchar(255) | Yes |  | Mode of admission. |
| referred_by | varchar(255) | Yes |  | Referred by. |
| referral_date | date | Yes |  | Date: referral. |
| problem_presented | text | Yes |  | Problem presented. |
| initial_assessment | text | Yes |  | Initial assessment. |
| plan_recommendations | text | Yes |  | Plan recommendations. |
| intake_remarks | text | Yes |  | Intake remarks. |
| initial_risk_level | varchar(20) | Yes |  | Initial risk level. |
| risk_indicators | json | Yes |  | Risk indicators. |
| immediate_actions | text | Yes |  | Immediate actions. |
| safety_plan | text | Yes |  | Safety plan. |
| next_review_at | timestamp | Yes |  | Date and time: next review. |
| intake_completed_at | timestamp | Yes |  | Date and time: intake completed. |
| cefmu_situation | varchar(60) | Yes |  | Cefmu situation. |
| union_type | varchar(60) | Yes |  | Union type. |
| partner_age_band | varchar(60) | Yes |  | Partner age band. |
| education_status_at_intake | varchar(100) | Yes |  | Education status at intake. |
| pregnancy_parenting_status | varchar(100) | Yes |  | Pregnancy parenting status. |
| cefmu_context | text | Yes |  | Cefmu context. |
| privacy_notice_provided_at | timestamp | Yes |  | Date and time: privacy notice provided. |
| privacy_notice_method | varchar(80) | Yes |  | Privacy notice method. |
| data_sharing_basis | varchar(80) | Yes |  | Data sharing basis. |
| consent_status | varchar(80) | Yes |  | Consent status. |
| cefmu_classifications | json | Yes |  | Cefmu classifications. |
| closure_reviewed_by | bigint | Yes | FK users | User who closure reviewed the record. |
| closure_reviewed_at | timestamp | Yes |  | Date and time: closure reviewed. |
| current_risk_level | varchar(20) | Yes |  | Current risk level. |
| current_risk_assessed_at | timestamp | Yes |  | Date and time: current risk assessed. |
| lgu_id | bigint | Yes | FK lgu_units | Link to the lgu unit record (lgu_units). |
| union_forms | json | Yes |  | Union forms. |
| union_status | varchar(20) | Yes |  | Union status. |
| partner_current_age | smallint | Yes |  | Partner current age. |
| union_duration_months | smallint | Yes |  | Union duration months. |
| current_residence | varchar(40) | Yes |  | Current residence. |
| risk_points | numeric | Yes |  | Risk points. |
| suggested_risk_level | varchar(20) | Yes |  | Suggested risk level. |
| risk_level_reason | varchar(1000) | Yes |  | Risk level reason. |
| consent_form_type | varchar(30) | Yes |  | Consent form type. |
| consent_document_id | bigint | Yes | FK documents | Link to the document record (documents). |

### case_status_history

Every change of case status, with who and when.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| from_status | varchar(255) | Yes |  | From status. |
| to_status | varchar(255) | No |  | To status. |
| reason | text | Yes |  | Reason given. |
| changed_by | bigint | No | FK users | User who changed the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_lifecycle_policies

Rules for allowed case status changes.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| scope | varchar(60) | No |  | Scope. |
| key | varchar(100) | No |  | Key. |
| value | varchar(40) | No |  | Value. |
| description | text | Yes |  | Description. |
| requires_owner_validation | boolean | No |  | Yes/no: requires owner validation. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_notes

Progress notes and their addenda (corrections), with purpose, action taken and next steps.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| body | text | No |  | Text of the note or message. |
| visibility | varchar(255) | No |  | Visibility. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| source_id | bigint | Yes |  | Number of the record this came from. |
| provenance_summary | text | Yes |  | Provenance summary. |
| captured_at | timestamp | Yes |  | Date and time: captured. |
| record_type | varchar(30) | No |  | Kind of record. |
| note_type | varchar(80) | No |  | Note type. |
| occurred_at | timestamp | Yes |  | Date and time: occurred. |
| previous_note_id | bigint | Yes | FK case_notes | Link to the case note record (case_notes). |
| addendum_reason | text | Yes |  | Addendum reason. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| finalized_by | bigint | Yes | FK users | User who finalized the record. |
| is_current | boolean | No |  | Whether this is the current version. |
| action_taken | text | Yes |  | Action taken. |
| next_steps | text | Yes |  | Next steps. |

### monitoring_visits

Home and monitoring visits.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| visited_at | date | No |  | Date and time: visited. |
| location | varchar(255) | Yes |  | Location. |
| observations | text | No |  | Observations. |
| risks | text | Yes |  | Risks. |
| next_steps | text | Yes |  | Next steps. |
| latitude | numeric | Yes |  | Latitude. |
| longitude | numeric | Yes |  | Longitude. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### tasks

Calendar activities and follow-ups (visits, conferences, deadlines).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | Yes | FK cases | Link to the case record (cases). |
| assigned_to | bigint | No | FK users | Link to the user record (users). |
| title | varchar(255) | No |  | Title. |
| due_at | timestamp | Yes |  | Date and time: due. |
| status | varchar(255) | No |  | Current status of the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| source_id | bigint | Yes |  | Number of the record this came from. |
| event_type | varchar(40) | Yes |  | Event type. |
| location | varchar(255) | Yes |  | Location. |
| details | text | Yes |  | Details. |

### consents

Consent and lawful basis for processing and sharing case information.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| lawful_basis | varchar(255) | No |  | Lawful basis under the Data Privacy Act. |
| purposes | json | No |  | Purposes. |
| sharing_allowed | boolean | No |  | Yes/no: sharing allowed. |
| granted_at | timestamp | No |  | Date and time: granted. |
| withdrawn_at | timestamp | Yes |  | Date and time: withdrawn. |
| recorded_by | bigint | No | FK users | User who recorded the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### documents

Uploaded case documents (stored privately; quarantined until scanned).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| name | varchar(255) | No |  | Name. |
| disk | varchar(255) | No |  | Disk. |
| path | varchar(255) | No |  | Path. |
| mime_type | varchar(255) | No |  | Mime type. |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| uploaded_by | bigint | No | FK users | User who uploaded the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| deleted_at | timestamp | Yes |  | When the record was archived (soft delete); empty if active. |
| lineage_id | uuid | Yes |  | Number of the related lineage. |
| previous_document_id | bigint | Yes | FK documents | Link to the document record (documents). |
| source_module | varchar(64) | Yes |  | Source module. |
| uploader_notes | text | Yes |  | Uploader notes. |

## Assessment

### risk_assessments

Safety and risk assessments.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| assessment_type | varchar(30) | No |  | Assessment type. |
| assessed_at | timestamp | No |  | Date and time: assessed. |
| overall_risk_level | varchar(20) | No |  | Overall risk level. |
| immediate_protection_required | boolean | No |  | Yes/no: immediate protection required. |
| safety_plan_status | varchar(30) | No |  | Safety plan status. |
| required_action | text | Yes |  | Required action. |
| responsible_person | varchar(255) | Yes |  | Responsible person. |
| target_date | date | Yes |  | Target completion date. |
| next_reassessment_at | timestamp | Yes |  | Date and time: next reassessment. |
| professional_rationale | text | Yes |  | Professional rationale. |
| status | varchar(20) | No |  | Current status of the record. |
| assessed_by | bigint | No | FK users | User who assessed the record. |
| finalized_by | bigint | Yes | FK users | User who finalized the record. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### risk_assessment_factors

Risk factors rated in a safety and risk assessment.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| risk_assessment_id | bigint | No | FK risk_assessments | Link to the risk assessment record (risk_assessments). |
| domain | varchar(80) | No |  | Domain. |
| finding_level | varchar(30) | No |  | Finding level. |
| is_protective_factor | boolean | No |  | Yes/no: is protective factor. |
| notes | text | Yes |  | Notes. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_assessments

Comprehensive assessments (versioned).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| previous_assessment_id | bigint | Yes | FK case_assessments | Link to the case assessment record (case_assessments). |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| assessment_date | date | No |  | Date: assessment. |
| status | varchar(30) | No |  | Current status of the record. |
| authored_by | bigint | No | FK users | User who authored the record. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| finalized_by | bigint | Yes | FK users | User who finalized the record. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| is_current | boolean | No |  | Whether this is the current version. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| review_comments | text | Yes |  | Review comments. |
| returned_by | bigint | Yes | FK users | User who returned the record. |
| returned_at | timestamp | Yes |  | Date and time: returned. |
| amendment_reason | varchar(500) | Yes |  | Amendment reason. |
| record_type | varchar(20) | No |  | Kind of record. |

### case_assessment_sections

Sections of a comprehensive assessment (facts, observations, analysis).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_assessment_id | bigint | No | FK case_assessments | Link to the case assessment record (case_assessments). |
| section_key | varchar(80) | No |  | Section key. |
| completion_state | varchar(30) | No |  | Completion state. |
| source_type | varchar(50) | Yes |  | Kind of record this came from. |
| facts | text | Yes |  | Facts. |
| reported_information | text | Yes |  | Reported information. |
| observations | text | Yes |  | Observations. |
| analysis | text | Yes |  | Analysis. |
| conflicting_information | text | Yes |  | Conflicting information. |
| restricted | boolean | No |  | Yes/no: restricted. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_assessment_workflow_events

Review and approval steps of a comprehensive assessment.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_assessment_id | bigint | No | FK case_assessments | Link to the case assessment record (case_assessments). |
| event | varchar(30) | No |  | Event name. |
| actor_id | bigint | No | FK users | Link to the user record (users). |
| comments | text | Yes |  | Comments. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### assessments

Assessments from the earlier system, kept for reference.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| status | varchar(255) | No |  | Current status of the record. |
| responses | json | Yes |  | Responses. |
| summary | text | Yes |  | Summary. |
| completed_by | bigint | Yes | FK users | User who completed the record. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### legacy_assessment_reconciliations

How earlier-system assessments were matched to current records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| legacy_assessment_id | bigint | No | FK assessments | Link to the assessment record (assessments). |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| proposed_case_assessment_id | bigint | Yes | FK case_assessments | Link to the case assessment record (case_assessments). |
| classification | varchar(40) | No |  | Classification. |
| validation_status | varchar(40) | No |  | Validation status. |
| unresolved_fields | json | Yes |  | Unresolved fields. |
| migration_version | varchar(40) | Yes |  | Migration version. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| migrated_at | timestamp | Yes |  | Date and time: migrated. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### child_participation_records

Records of the child's views and participation in decisions.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| previous_record_id | bigint | Yes | FK child_participation_records | Link to the child participation record record (child_participation_records). |
| lineage_key | uuid | No |  | Lineage key. |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| record_type | varchar(20) | No |  | Kind of record. |
| occasion_type | varchar(40) | No |  | Occasion type. |
| decision_title | varchar(255) | No |  | Decision title. |
| participation_date | date | No |  | Date: participation. |
| participation_status | varchar(40) | No |  | Participation status. |
| non_participation_reason | text | Yes |  | Non participation reason. |
| amendment_reason | text | Yes |  | Amendment reason. |
| status | varchar(30) | No |  | Current status of the record. |
| authored_by | bigint | No | FK users | User who authored the record. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| returned_by | bigint | Yes | FK users | User who returned the record. |
| returned_at | timestamp | Yes |  | Date and time: returned. |
| review_comments | text | Yes |  | Review comments. |
| finalized_by | bigint | Yes | FK users | User who finalized the record. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| is_current | boolean | No |  | Whether this is the current version. |
| developmental_considerations | text | Yes |  | Developmental considerations. |
| preferred_language | varchar(120) | Yes |  | Preferred language. |
| communication_method | varchar(255) | Yes |  | Communication method. |
| accessibility_accommodation | text | Yes |  | Accessibility accommodation. |
| interpreter_or_support | varchar(255) | Yes |  | Interpreter or support. |
| trusted_adult_or_support_person | varchar(255) | Yes |  | Trusted adult or support person. |
| location_environment | varchar(255) | Yes |  | Location environment. |
| privacy_reasonably_provided | boolean | Yes |  | Yes/no: privacy reasonably provided. |
| comfortable_to_continue | boolean | Yes |  | Yes/no: comfortable to continue. |
| child_views | text | Yes |  | Child views. |
| direct_quote | text | Yes |  | Direct quote. |
| worker_observation | text | Yes |  | Worker observation. |
| reported_information | text | Yes |  | Reported information. |
| professional_consideration | text | Yes |  | Professional consideration. |
| child_preference | text | Yes |  | Child preference. |
| factors_considered | text | Yes |  | Factors considered. |
| view_influence | text | Yes |  | View influence. |
| decision_alignment | varchar(30) | Yes |  | Decision alignment. |
| best_interest_rationale | text | Yes |  | Best interest rationale. |
| safeguards_mitigating_actions | text | Yes |  | Safeguards mitigating actions. |
| outcome_explained_to_child | boolean | Yes |  | Yes/no: outcome explained to child. |
| feedback_date | date | Yes |  | Date: feedback. |
| feedback_by | varchar(255) | Yes |  | Feedback by. |
| feedback_method_language | varchar(255) | Yes |  | Feedback method language. |
| child_response_questions | text | Yes |  | Child response questions. |
| follow_up_needed | text | Yes |  | Follow up needed. |
| sharing_preference | text | Yes |  | Sharing preference. |
| sharing_professional_consideration | text | Yes |  | Sharing professional consideration. |
| necessary_disclosure_basis | text | Yes |  | Necessary disclosure basis. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### child_participation_workflow_events

Review steps of child participation records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| child_participation_record_id | bigint | No | FK child_participation_records | Link to the child participation record record (child_participation_records). |
| event | varchar(30) | No |  | Event name. |
| actor_id | bigint | No | FK users | Link to the user record (users). |
| comments | text | Yes |  | Comments. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Planning and services

### case_plans

Intervention plans (versioned): objective, status, review date.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| summary | text | Yes |  | Summary. |
| review_due_at | date | Yes |  | Date the next review is due. |
| status | varchar(255) | No |  | Current status of the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| previous_plan_id | bigint | Yes | FK case_plans | Link to the intervention plan record (case_plans). |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| plan_date | date | Yes |  | Date: plan. |
| authored_by | bigint | Yes | FK users | User who authored the record. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| returned_by | bigint | Yes | FK users | User who returned the record. |
| returned_at | timestamp | Yes |  | Date and time: returned. |
| review_comments | text | Yes |  | Review comments. |
| overall_objective | text | Yes |  | Overall objective. |
| comprehensive_assessment_id | bigint | Yes | FK case_assessments | Link to the case assessment record (case_assessments). |
| risk_assessment_id | bigint | Yes | FK risk_assessments | Link to the risk assessment record (risk_assessments). |
| child_participation_record_id | bigint | Yes | FK child_participation_records | Link to the child participation record record (child_participation_records). |
| is_current | boolean | No |  | Whether this is the current version. |

### case_plan_goals

Goals of an intervention plan by domain, with target dates.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_plan_id | bigint | No | FK case_plans | Link to the intervention plan record (case_plans). |
| domain | varchar(80) | No |  | Domain. |
| need_problem | varchar(500) | No |  | Need problem. |
| goal_statement | text | No |  | Goal statement. |
| priority | varchar(20) | No |  | Priority (low, normal, high, urgent). |
| target_date | date | Yes |  | Target completion date. |
| status | varchar(30) | No |  | Current status of the record. |
| outcome | varchar(40) | Yes |  | Outcome. |
| outcome_observations | text | Yes |  | Outcome observations. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_plan_history

Changes to an intervention plan.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_plan_id | bigint | No | FK case_plans | Link to the intervention plan record (case_plans). |
| event | varchar(80) | No |  | Event name. |
| actor_id | bigint | Yes | FK users | Link to the user record (users). |
| comments | text | Yes |  | Comments. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_plan_reviews

Periodic reviews of an intervention plan.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_plan_id | bigint | No | FK case_plans | Link to the intervention plan record (case_plans). |
| reviewed_by | bigint | No | FK users | User who reviewed the record. |
| review_date | date | No |  | Date: review. |
| overall_progress | varchar(40) | No |  | Overall progress. |
| changed_needs | text | Yes |  | Changed needs. |
| barriers | text | Yes |  | Barriers. |
| continuing_concerns | text | Yes |  | Continuing concerns. |
| outcome_observations | text | Yes |  | Outcome observations. |
| recommendation | text | Yes |  | Recommendation. |
| revision_required | boolean | No |  | Yes/no: revision required. |
| next_review_at | date | Yes |  | Date and time: next review. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### interventions

Activities and services planned under each goal, with responsible person and progress.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_plan_id | bigint | No | FK case_plans | Link to the intervention plan record (case_plans). |
| need | varchar(255) | No |  | Need. |
| goal | varchar(255) | No |  | Goal. |
| service_category | varchar(255) | No |  | Service category. |
| target_date | date | Yes |  | Target completion date. |
| progress | varchar(255) | No |  | Progress. |
| outcome | text | Yes |  | Outcome. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| case_plan_goal_id | bigint | Yes | FK case_plan_goals | Link to the case plan goal record (case_plan_goals). |
| activity | text | Yes |  | Activity. |
| responsible_user_id | bigint | Yes | FK users | Link to the user record (users). |
| responsible_office | varchar(180) | Yes |  | Responsible office. |
| service_provider_id | bigint | Yes | FK service_providers | Link to the service provider record (service_providers). |
| start_date | date | Yes |  | Date: start. |
| priority | varchar(20) | No |  | Priority (low, normal, high, urgent). |
| expected_outcome | text | Yes |  | Expected outcome. |
| actual_outcome | text | Yes |  | Actual outcome. |
| outcome_status | varchar(40) | Yes |  | Outcome status. |
| barriers | text | Yes |  | Barriers. |
| next_action | text | Yes |  | Next action. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| origin_type | varchar(80) | Yes |  | Origin type. |
| origin_id | bigint | Yes |  | Number of the related origin. |
| operational_record_type | varchar(80) | Yes |  | Operational record type. |
| operational_record_id | bigint | Yes |  | Number of the related operational record. |

### services_provided

Services and assistance actually given to the client.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| service_type | varchar(60) | No |  | Service type. |
| specific_detail | varchar(255) | Yes |  | Specific detail. |
| amount | numeric | Yes |  | Amount. |
| date_provided | date | No |  | Date provided. |
| provided_by | varchar(160) | Yes |  | Provided by. |
| referral_id | bigint | Yes | FK referrals | Link to the referral record (referrals). |
| case_note_id | bigint | Yes | FK case_notes | Link to the case note record (case_notes). |
| recorded_by | bigint | No | FK users | User who recorded the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### service_providers

Directory of agencies and offices that receive referrals.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| name | varchar(255) | No |  | Name. |
| service_type | varchar(255) | No |  | Service type. |
| contact_person | varchar(255) | Yes |  | Contact person. |
| phone | varchar(255) | Yes |  | Phone. |
| municipality | varchar(255) | Yes |  | City or municipality. |
| active | boolean | No |  | Whether the record is in use. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| region | varchar(255) | Yes |  | Region. |
| province | varchar(255) | Yes |  | Province. |
| barangay | varchar(255) | Yes |  | Barangay. |

### referrals

Referrals to CPU members and agencies, with status, outcome and dates.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| service_provider_id | bigint | Yes | FK service_providers | Link to the service provider record (service_providers). |
| status | varchar(255) | No |  | Current status of the record. |
| service_category | varchar(255) | No |  | Service category. |
| reason | text | Yes |  | Reason given. |
| referred_at | timestamp | Yes |  | Date and time: referred. |
| accepted_at | timestamp | Yes |  | Date and time: accepted. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| lawful_basis | varchar(40) | Yes |  | Lawful basis under the Data Privacy Act. |
| sharing_authorized_by | bigint | Yes | FK users | User who sharing authorized the record. |
| sharing_authorized_at | timestamp | Yes |  | Date and time: sharing authorized. |
| outcome | text | Yes |  | Outcome. |
| status_reason | text | Yes |  | Status reason. |
| referred_to | varchar(160) | Yes |  | Referred to. |
| purpose | varchar(160) | Yes |  | Purpose. |
| case_note_id | bigint | Yes | FK case_notes | Link to the case note record (case_notes). |
| lgu_cpu_member_id | bigint | Yes | FK lgu_cpu_members | Link to the lgu cpu member record (lgu_cpu_members). |
| recipient_user_id | bigint | Yes | FK users | Link to the user record (users). |
| mdt_case_access_id | bigint | Yes | FK mdt_case_access | Link to the mdt case acce record (mdt_case_access). |
| target_date | date | Yes |  | Target completion date. |

### referral_events

Each status change of a referral.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| referral_id | bigint | No | FK referrals | Link to the referral record (referrals). |
| from_status | varchar(40) | Yes |  | From status. |
| to_status | varchar(40) | No |  | To status. |
| remarks | text | Yes |  | Remarks. |
| outcome | text | Yes |  | Outcome. |
| reason | text | Yes |  | Reason given. |
| actor_id | bigint | Yes | FK users | Link to the user record (users). |
| created_at | timestamp | Yes |  | When the record was created. |

### case_conferences

Case conferences.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| conference_type | varchar(80) | No |  | Conference type. |
| other_type_reason | varchar(500) | Yes |  | Other type reason. |
| status | varchar(40) | No |  | Current status of the record. |
| scheduled_at | timestamp | Yes |  | Date and time: scheduled. |
| started_at | timestamp | Yes |  | Date and time: started. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| venue_mode | varchar(100) | Yes |  | Venue mode. |
| facilitator_name | varchar(180) | Yes |  | Facilitator name. |
| recorder_name | varchar(180) | Yes |  | Recorder name. |
| purpose | text | Yes |  | Purpose. |
| case_updates | text | Yes |  | Case updates. |
| key_issues | text | Yes |  | Key issues. |
| child_participation_status | varchar(60) | Yes |  | Child participation status. |
| child_participation_record_id | bigint | Yes | FK child_participation_records | Link to the child participation record record (child_participation_records). |
| risk_assessment_id | bigint | Yes | FK risk_assessments | Link to the risk assessment record (risk_assessments). |
| family_perspective | text | Yes |  | Family perspective. |
| professional_observations | text | Yes |  | Professional observations. |
| next_review_at | timestamp | Yes |  | Date and time: next review. |
| confidentiality | varchar(40) | No |  | Confidentiality. |
| created_by | bigint | No | FK users | User who created the record. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| finalized_by | bigint | Yes | FK users | User who finalized the record. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| returned_by | bigint | Yes | FK users | User who returned the record. |
| returned_at | timestamp | Yes |  | Date and time: returned. |
| review_comments | text | Yes |  | Review comments. |
| previous_conference_id | bigint | Yes | FK case_conferences | Link to the case conference record (case_conferences). |
| record_type | varchar(30) | No |  | Kind of record. |
| addendum_reason | text | Yes |  | Addendum reason. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_conference_participants

People invited to or attending a case conference.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_conference_id | bigint | No | FK case_conferences | Link to the case conference record (case_conferences). |
| user_id | bigint | Yes | FK users | Link to the user record (users). |
| name | varchar(180) | No |  | Name. |
| professional_role | varchar(140) | Yes |  | Professional role. |
| organization | varchar(180) | Yes |  | Organization. |
| attendance_status | varchar(40) | No |  | Attendance status. |
| participation_mode | varchar(60) | Yes |  | Participation mode. |
| conference_role | varchar(80) | Yes |  | Conference role. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_conference_decisions

Decisions taken at a case conference.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_conference_id | bigint | No | FK case_conferences | Link to the case conference record (case_conferences). |
| decision_type | varchar(20) | No |  | Decision type. |
| statement | text | No |  | Statement. |
| status | varchar(40) | No |  | Current status of the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_conference_actions

Actions agreed at a case conference.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_conference_id | bigint | No | FK case_conferences | Link to the case conference record (case_conferences). |
| action | text | No |  | Action. |
| category | varchar(100) | Yes |  | Category. |
| responsible_user_id | bigint | Yes | FK users | Link to the user record (users). |
| responsible_office | varchar(180) | Yes |  | Responsible office. |
| target_at | timestamp | Yes |  | Date and time: target. |
| priority | varchar(30) | No |  | Priority (low, normal, high, urgent). |
| local_status | varchar(40) | No |  | Local status. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| source_id | bigint | Yes |  | Number of the record this came from. |
| outcome_remarks | text | Yes |  | Outcome remarks. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_conference_history

Changes to case conference records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_conference_id | bigint | No | FK case_conferences | Link to the case conference record (case_conferences). |
| event | varchar(80) | No |  | Event name. |
| actor_id | bigint | Yes | FK users | Link to the user record (users). |
| comments | text | Yes |  | Comments. |
| prior_scheduled_at | timestamp | Yes |  | Date and time: prior scheduled. |
| new_scheduled_at | timestamp | Yes |  | Date and time: new scheduled. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Closure, transfer and aftercare

### case_closure_records

Closure summaries and their review (versioned).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| version | integer | No |  | Version number; a new version is created instead of overwriting. |
| current_slot | smallint | Yes |  | Current slot. |
| summary | text | Yes |  | Summary. |
| source | varchar(40) | No |  | Source. |
| recorded_by | bigint | Yes | FK users | User who recorded the record. |
| recorded_at | timestamp | Yes |  | Date and time: recorded. |
| captured_by | bigint | Yes | FK users | User who captured the record. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| closed_at | timestamp | Yes |  | Date and time: closed. |
| superseded_at | timestamp | Yes |  | Date and time: superseded. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_aftercare_plans

Aftercare plans after closure.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| case_plan_id | bigint | Yes | FK case_plans | Link to the intervention plan record (case_plans). |
| required | boolean | No |  | Yes/no: required. |
| basis | text | Yes |  | Basis. |
| responsible_user_id | bigint | Yes | FK users | Link to the user record (users). |
| responsible_office | varchar(180) | Yes |  | Responsible office. |
| initial_follow_up_at | date | Yes |  | Date and time: initial follow up. |
| status | varchar(30) | No |  | Current status of the record. |
| continuity_notes | text | Yes |  | Continuity notes. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_reintegrations

Reintegration plans.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| case_plan_id | bigint | Yes | FK case_plans | Link to the intervention plan record (case_plans). |
| applicable | boolean | No |  | Yes/no: applicable. |
| basis | text | Yes |  | Basis. |
| status | varchar(30) | No |  | Current status of the record. |
| child_participation_record_id | bigint | Yes | FK child_participation_records | Link to the child participation record record (child_participation_records). |
| risk_assessment_id | bigint | Yes | FK risk_assessments | Link to the risk assessment record (risk_assessments). |
| continuity_plan | text | Yes |  | Continuity plan. |
| safeguards | text | Yes |  | Safeguards. |
| responsible_office | varchar(180) | Yes |  | Responsible office. |
| target_date | date | Yes |  | Target completion date. |
| review_at | date | Yes |  | Date and time: review. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_transfers

Formal handover of a case to another worker or office.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| status | varchar(40) | No |  | Current status of the record. |
| originating_office | varchar(180) | No |  | Originating office. |
| receiving_office | varchar(180) | No |  | Receiving office. |
| originating_worker_id | bigint | Yes | FK users | Link to the user record (users). |
| receiving_worker_id | bigint | Yes | FK users | Link to the user record (users). |
| reason | text | No |  | Reason given. |
| new_locality | varchar(255) | Yes |  | New locality. |
| requested_at | timestamp | Yes |  | Date and time: requested. |
| endorsed_at | timestamp | Yes |  | Date and time: endorsed. |
| accepted_at | timestamp | Yes |  | Date and time: accepted. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| continuity_notes | text | Yes |  | Continuity notes. |
| clarification_comments | text | Yes |  | Clarification comments. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### case_transfer_history

Steps of a case handover.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_transfer_id | bigint | No | FK case_transfers | Link to the case transfer record (case_transfers). |
| event | varchar(80) | No |  | Event name. |
| actor_id | bigint | Yes | FK users | Link to the user record (users). |
| comments | text | Yes |  | Comments. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Multidisciplinary team and coordination

### mdt_case_access

Time-limited access of a CPU/MDT member to one case, with purpose and lawful basis.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| user_id | bigint | No | FK users | Link to the user record (users). |
| professional_role | varchar(120) | No |  | Professional role. |
| organization | varchar(180) | No |  | Organization. |
| purpose | varchar(255) | No |  | Purpose. |
| status | varchar(32) | No |  | Current status of the record. |
| allowed_categories | json | No |  | Allowed categories. |
| remarks | text | Yes |  | Remarks. |
| lawful_basis | varchar(180) | Yes |  | Lawful basis under the Data Privacy Act. |
| assigned_by | bigint | No | FK users | User who assigned the record. |
| assigned_at | timestamp | No |  | Date and time: assigned. |
| acknowledged_at | timestamp | Yes |  | Date and time: acknowledged. |
| access_starts_at | timestamp | Yes |  | Date and time: access starts. |
| access_expires_at | timestamp | Yes |  | Date and time: access expires. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| revoked_at | timestamp | Yes |  | Date and time: revoked. |
| revoked_by | bigint | Yes | FK users | User who revoked the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| expire_on_completion | boolean | No |  | Yes/no: expire on completion. |
| aftercare_allowed | boolean | No |  | Yes/no: aftercare allowed. |

### mdt_case_notes

Notes written by MDT members on a case they were given access to.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| mdt_access_id | bigint | No | FK mdt_case_access | Link to the mdt case acce record (mdt_case_access). |
| previous_note_id | bigint | Yes | FK mdt_case_notes | Link to the mdt case note record (mdt_case_notes). |
| category | varchar(100) | No |  | Category. |
| action_performed | text | Yes |  | Action performed. |
| findings | text | Yes |  | Findings. |
| recommendation | text | Yes |  | Recommendation. |
| follow_up_needed | text | Yes |  | Follow up needed. |
| status | varchar(32) | No |  | Current status of the record. |
| finalized_at | timestamp | Yes |  | When the record was finalized (locked). |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| record_type | varchar(24) | No |  | Kind of record. |
| addendum_reason | text | Yes |  | Addendum reason. |
| source_type | varchar(80) | Yes |  | Kind of record this came from. |
| source_id | bigint | Yes |  | Number of the record this came from. |
| provenance_summary | text | Yes |  | Provenance summary. |
| captured_at | timestamp | Yes |  | Date and time: captured. |

### mdt_actions

Actions assigned to MDT members.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| mdt_access_id | bigint | No | FK mdt_case_access | Link to the mdt case acce record (mdt_case_access). |
| title | varchar(255) | No |  | Title. |
| instructions | text | Yes |  | Instructions. |
| status | varchar(32) | No |  | Current status of the record. |
| due_at | timestamp | Yes |  | Date and time: due. |
| responded_at | timestamp | Yes |  | Date and time: responded. |
| completed_at | timestamp | Yes |  | Date and time: completed. |
| created_by | bigint | No | FK users | User who created the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### mdt_attachments

Files attached in MDT collaboration.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| mdt_access_id | bigint | No | FK mdt_case_access | Link to the mdt case acce record (mdt_case_access). |
| mdt_action_id | bigint | Yes | FK mdt_actions | Link to the mdt action record (mdt_actions). |
| mdt_note_id | bigint | Yes | FK mdt_case_notes | Link to the mdt case note record (mdt_case_notes). |
| purpose_category | varchar(100) | No |  | Purpose category. |
| name | varchar(255) | No |  | Name. |
| disk | varchar(255) | No |  | Disk. |
| path | varchar(255) | No |  | Path. |
| mime_type | varchar(255) | No |  | Mime type. |
| size_bytes | integer | No |  | Size bytes. |
| uploaded_by | bigint | No | FK users | User who uploaded the record. |
| revoked_at | timestamp | Yes |  | Date and time: revoked. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| scan_state | varchar(24) | No |  | Scan state. |

### mdt_discussions

MDT discussion messages on a case.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| mdt_access_id | bigint | Yes | FK mdt_case_access | Link to the mdt case acce record (mdt_case_access). |
| parent_id | bigint | Yes | FK mdt_discussions | Link to the mdt discussion record (mdt_discussions). |
| author_id | bigint | No | FK users | Link to the user record (users). |
| body | text | No |  | Text of the note or message. |
| is_system_event | boolean | No |  | Yes/no: is system event. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### mdt_discussion_reads

Which discussion messages each member has read.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| discussion_id | bigint | No | FK mdt_discussions | Link to the mdt discussion record (mdt_discussions). |
| user_id | bigint | No | FK users | Link to the user record (users). |
| read_at | timestamp | No |  | Date and time: read. |

### lgu_coordination_circles

Kalinga Connect coordination groups per LGU.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| lgu_id | bigint | No | FK lgu_units | Link to the lgu unit record (lgu_units). |
| name | varchar(180) | No |  | Name. |
| active | boolean | No |  | Whether the record is in use. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_coordination_members

Members of a coordination group.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| circle_id | bigint | No | FK lgu_coordination_circles | Link to the lgu coordination circle record (lgu_coordination_circles). |
| user_id | bigint | No | FK users | Link to the user record (users). |
| membership_role | varchar(80) | No |  | Membership role. |
| joined_at | timestamp | No |  | Date and time: joined. |
| left_at | timestamp | Yes |  | Date and time: left. |
| configured_by | bigint | Yes | FK users | User who configured the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_coordination_messages

Kalinga Connect messages.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| circle_id | bigint | No | FK lgu_coordination_circles | Link to the lgu coordination circle record (lgu_coordination_circles). |
| author_id | bigint | No | FK users | Link to the user record (users). |
| parent_id | bigint | Yes | FK lgu_coordination_messages | Link to the lgu coordination message record (lgu_coordination_messages). |
| case_id | bigint | Yes | FK cases | Link to the case record (cases). |
| case_reference | varchar(80) | Yes |  | Case reference. |
| body | text | No |  | Text of the note or message. |
| is_urgent | boolean | No |  | Yes/no: is urgent. |
| follow_up | boolean | No |  | Yes/no: follow up. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_coordination_message_mentions

People mentioned in a message.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| message_id | bigint | No | FK lgu_coordination_messages | Link to the lgu coordination message record (lgu_coordination_messages). |
| user_id | bigint | No | FK users | Link to the user record (users). |
| created_at | timestamp | No |  | When the record was created. |

### lgu_coordination_message_reads

Read receipts of messages.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| message_id | bigint | No | FK lgu_coordination_messages | Link to the lgu coordination message record (lgu_coordination_messages). |
| user_id | bigint | No | FK users | Link to the user record (users). |
| read_at | timestamp | No |  | Date and time: read. |

### coordination_direct_messages

Participant-only online coordination messages between two currently eligible members of the same LGU circle. Separate from group chat and formal case records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| circle_id | bigint | No | FK lgu_coordination_circles | Link to the lgu coordination circle record (lgu_coordination_circles). |
| sender_id | bigint | No | FK users | Link to the user record (users). |
| recipient_id | bigint | No | FK users | Link to the user record (users). |
| parent_id | bigint | Yes | FK coordination_direct_messages | Link to the coordination direct message record (coordination_direct_messages). |
| body | text | No |  | Text of the note or message. |
| is_urgent | boolean | No |  | Yes/no: is urgent. |
| follow_up | boolean | No |  | Yes/no: follow up. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Records governance

### record_governance

Classification and retention status of a record.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| record_type | varchar(64) | No |  | Kind of record. |
| record_id | bigint | No |  | Number of the related record. |
| record_classification_id | bigint | Yes | FK record_classifications | Link to the record classification record (record_classifications). |
| retention_rule_id | bigint | Yes | FK retention_rules | Link to the retention rule record (retention_rules). |
| lifecycle_state | varchar(40) | No |  | Lifecycle state. |
| retention_review_at | timestamp | Yes |  | Date and time: retention review. |
| scan_state | varchar(32) | No |  | Scan state. |
| legacy_record | boolean | No |  | Yes/no: legacy record. |
| classified_by | bigint | Yes | FK users | User who classified the record. |
| classified_at | timestamp | Yes |  | Date and time: classified. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### record_classifications

Record classification categories and sensitivity.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| code | varchar(255) | No |  | Short code. |
| label | varchar(255) | No |  | Display label. |
| sensitivity_level | varchar(32) | No |  | Sensitivity level. |
| restricted | boolean | No |  | Yes/no: restricted. |
| policy_validated | boolean | No |  | Yes/no: policy validated. |
| active | boolean | No |  | Whether the record is in use. |
| owner_notes | text | Yes |  | Owner notes. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### retention_rules

How long each kind of record is kept.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| code | varchar(255) | No |  | Short code. |
| label | varchar(255) | No |  | Display label. |
| record_type | varchar(64) | No |  | Kind of record. |
| record_classification_id | bigint | Yes | FK record_classifications | Link to the record classification record (record_classifications). |
| case_status | varchar(255) | Yes |  | Case status. |
| review_after_days | integer | Yes |  | Review after days. |
| policy_validated | boolean | No |  | Yes/no: policy validated. |
| approved_at | timestamp | Yes |  | Date and time: approved. |
| approved_by | bigint | Yes | FK users | User who approved the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### legal_holds

Legal holds that stop disposal of records.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| scope | varchar(24) | No |  | Scope. |
| reason_code | varchar(120) | No |  | Reason code. |
| authority | varchar(255) | No |  | Authority. |
| placed_by | bigint | No | FK users | User who placed the record. |
| placed_at | timestamp | No |  | Date and time: placed. |
| review_at | timestamp | Yes |  | Date and time: review. |
| released_by | bigint | Yes | FK users | User who released the record. |
| released_at | timestamp | Yes |  | Date and time: released. |
| release_reason | varchar(1000) | Yes |  | Release reason. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### legal_hold_records

Records covered by a legal hold.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| legal_hold_id | bigint | No | FK legal_holds | Link to the legal hold record (legal_holds). |
| record_type | varchar(64) | No |  | Kind of record. |
| record_id | bigint | No |  | Number of the related record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### record_dispositions

Proposals to dispose of records at the end of retention.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| record_governance_id | bigint | No | FK record_governance | Link to the record governance record (record_governance). |
| status | varchar(40) | No |  | Current status of the record. |
| proposal_reason | varchar(1000) | Yes |  | Proposal reason. |
| proposed_by | bigint | Yes | FK users | User who proposed the record. |
| proposed_at | timestamp | Yes |  | Date and time: proposed. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| approved_by | bigint | Yes | FK users | User who approved the record. |
| approved_at | timestamp | Yes |  | Date and time: approved. |
| executed_at | timestamp | Yes |  | Date and time: executed. |
| executed_by | bigint | Yes | FK users | User who executed the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### record_disposition_history

Review and approval steps of a disposition.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| record_disposition_id | bigint | No | FK record_dispositions | Link to the record disposition record (record_dispositions). |
| event | varchar(48) | No |  | Event name. |
| actor_id | bigint | Yes | FK users | Link to the user record (users). |
| reason_code | varchar(120) | Yes |  | Reason code. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### record_disclosures

Records released to an outside party, with purpose and authority.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| case_id | bigint | No | FK cases | Link to the case record (cases). |
| recipient_organization | varchar(255) | No |  | Recipient organization. |
| recipient_name | varchar(255) | Yes |  | Recipient name. |
| purpose | varchar(500) | No |  | Purpose. |
| lawful_authority | varchar(255) | No |  | Lawful authority. |
| delivery_method | varchar(100) | No |  | Delivery method. |
| authorized_by | bigint | No | FK users | User who authorized the record. |
| authorized_at | timestamp | No |  | Date and time: authorized. |
| acknowledged_at | timestamp | Yes |  | Date and time: acknowledged. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### record_disclosure_items

Items included in a disclosure.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| record_disclosure_id | bigint | No | FK record_disclosures | Link to the record disclosure record (record_disclosures). |
| record_type | varchar(64) | No |  | Kind of record. |
| record_id | bigint | No |  | Number of the related record. |
| category | varchar(120) | No |  | Category. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## LGU and organisation

### lgu_units

Cities and municipalities (PSGC) using Kalinga.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| psgc_code | varchar(20) | No |  | Psgc code. |
| name | varchar(160) | No |  | Name. |
| province | varchar(120) | Yes |  | Province. |
| region | varchar(120) | Yes |  | Region. |
| active | boolean | No |  | Whether the record is in use. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_profiles

Office details, logo and office head (signatory) of each LGU.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| lgu_id | bigint | No | FK lgu_units | Link to the lgu unit record (lgu_units). |
| office_name | varchar(180) | Yes |  | Office name. |
| office_short_name | varchar(40) | Yes |  | Office short name. |
| address | varchar(255) | Yes |  | Address. |
| contact_number | varchar(60) | Yes |  | Contact number. |
| email | varchar(160) | Yes |  | Email. |
| logo_path | varchar(255) | Yes |  | Logo path. |
| head_name | varchar(160) | Yes |  | Head name. |
| head_position | varchar(160) | Yes |  | Head position. |
| head_designation | varchar(160) | Yes |  | Head designation. |
| updated_by | bigint | Yes | FK users | User who updated the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_cpu_members

Composition of the LGU Child Protection Unit.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| lgu_id | bigint | No | FK lgu_units | Link to the lgu unit record (lgu_units). |
| name | varchar(160) | No |  | Name. |
| position | varchar(160) | Yes |  | Position. |
| office | varchar(160) | Yes |  | Office. |
| cpu_role | varchar(80) | No |  | Cpu role. |
| user_id | bigint | Yes | FK users | Link to the user record (users). |
| sort_order | smallint | No |  | Sort order. |
| active | boolean | No |  | Whether the record is in use. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### lgu_reconciliation_queue

Records whose LGU must be confirmed.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| record_type | varchar(80) | No |  | Kind of record. |
| record_id | bigint | No |  | Number of the related record. |
| legacy_municipality | varchar(120) | Yes |  | Legacy municipality. |
| legacy_province | varchar(120) | Yes |  | Legacy province. |
| proposed_lgu_id | bigint | Yes | FK lgu_units | Link to the lgu unit record (lgu_units). |
| match_status | varchar(40) | No |  | Match status. |
| reviewed_by | bigint | Yes | FK users | User who reviewed the record. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### user_lgu_assignments

Which LGUs each user works in, with effective dates.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| user_id | bigint | No | FK users | Link to the user record (users). |
| lgu_id | bigint | No | FK lgu_units | Link to the lgu unit record (lgu_units). |
| effective_from | timestamp | No |  | Effective from. |
| effective_to | timestamp | Yes |  | Effective to. |
| active | boolean | No |  | Whether the record is in use. |
| assigned_by | bigint | Yes | FK users | User who assigned the record. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Users and security

### users

User accounts: role, position, location, 2FA settings and status.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| name | varchar(255) | No |  | Name. |
| email | varchar(255) | No |  | Email. |
| role | varchar(255) | No |  | Role. |
| province | varchar(255) | Yes |  | Province. |
| municipality | varchar(255) | Yes |  | City or municipality. |
| email_verified_at | timestamp | Yes |  | Date and time: email verified. |
| password | varchar(255) | No |  | Password. |
| remember_token | varchar(100) | Yes |  | Remember token. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| active | boolean | No |  | Whether the record is in use. |
| access_revoked_at | timestamp | Yes |  | Date and time: access revoked. |
| google_id | varchar(255) | Yes |  | Number of the related google. |
| region | varchar(120) | Yes |  | Region. |
| account_status | varchar(20) | No |  | Account status. |
| requested_role | varchar(60) | Yes |  | Requested role. |
| registration_note | text | Yes |  | Registration note. |
| reviewed_at | timestamp | Yes |  | Date and time: reviewed. |
| reviewed_by | bigint | Yes |  | Reviewed by. |
| cpu_discipline | varchar(40) | Yes |  | Cpu discipline. |
| two_factor_secret | text | Yes |  | Two factor secret. |
| two_factor_confirmed_at | timestamp | Yes |  | Date and time: two factor confirmed. |
| two_factor_recovery_codes | text | Yes |  | Two factor recovery codes. |
| two_factor_method | varchar(10) | No |  | Two factor method. |
| two_factor_email_code | text | Yes |  | Two factor email code. |
| two_factor_email_code_expires_at | timestamp | Yes |  | Date and time: two factor email code expires. |
| position | varchar(160) | Yes |  | Position. |

### roles

Roles available in the system.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| code | varchar(255) | No |  | Short code. |
| name | varchar(255) | No |  | Name. |
| description | text | Yes |  | Description. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### permissions

Permissions that can be granted.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| code | varchar(255) | No |  | Short code. |
| name | varchar(255) | No |  | Name. |
| module | varchar(255) | No |  | Module. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### permission_role

Permissions granted to each role.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| role_id | bigint | No | PK | Link to the role record (roles). |
| permission_id | bigint | No | PK | Link to the permission record (permissions). |

### password_reset_tokens

One-time password reset tokens.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| email | varchar(255) | No | PK | Email. |
| token | varchar(255) | No |  | Token. |
| created_at | timestamp | Yes |  | When the record was created. |

### sessions

Active sign-in sessions (encrypted).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | varchar(255) | No | PK | Unique record number. |
| user_id | bigint | Yes |  | Number of the related user. |
| ip_address | varchar(45) | Yes |  | Client network address at the time of the action. |
| user_agent | text | Yes |  | User agent. |
| payload | text | No |  | Payload. |
| last_activity | integer | No |  | Last activity. |

### audit_logs

Append-only audit trail of sign-ins, views and every change (ids and codes, no narratives).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| user_id | bigint | Yes | FK users | Link to the user record (users). |
| event | varchar(255) | No |  | Event name. |
| auditable_type | varchar(255) | No |  | Auditable type. |
| auditable_id | bigint | Yes |  | Number of the related auditable. |
| changes | json | Yes |  | Details of the change (ids and codes only). |
| outcome | varchar(255) | No |  | Outcome. |
| ip_address | varchar(45) | Yes |  | Client network address at the time of the action. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| log_type | varchar(40) | Yes |  | Log type. |
| module | varchar(60) | Yes |  | Module. |
| action | varchar(20) | Yes |  | Action. |
| record_ref | varchar(80) | Yes |  | Record ref. |
| description | varchar(255) | Yes |  | Description. |
| old_value | varchar(255) | Yes |  | Old value. |
| new_value | varchar(255) | Yes |  | New value. |
| user_role | varchar(40) | Yes |  | User role. |

### notifications

In-app notifications to users.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| user_id | bigint | No | FK users | Link to the user record (users). |
| type | varchar(255) | No |  | Type. |
| title | varchar(255) | No |  | Title. |
| body | text | No |  | Text of the note or message. |
| action_url | varchar(255) | Yes |  | Action url. |
| read_at | timestamp | Yes |  | Date and time: read. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Offline field mode

### offline_devices

Devices registered for Field mode, with their case pack expiry and revocation.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| user_id | bigint | No | FK users | Link to the user record (users). |
| device_id | uuid | No |  | Number of the related device. |
| last_seen_at | timestamp | No |  | Date and time: last seen. |
| pack_expires_at | timestamp | Yes |  | Date and time: pack expires. |
| revoked_at | timestamp | Yes |  | Date and time: revoked. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| device_name | varchar(80) | Yes |  | Device name. |

### offline_sync_items

Records sent from Field mode and their sync status.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| offline_id | uuid | No |  | Number of the related offline. |
| user_id | bigint | No | FK users | Link to the user record (users). |
| resource | varchar(255) | No |  | Resource. |
| payload | json | No |  | Payload. |
| status | varchar(255) | No |  | Current status of the record. |
| conflict_reason | text | Yes |  | Conflict reason. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

## Configuration

### form_fields

Extra form fields configured by the administrator.

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| form | varchar(255) | No |  | Form. |
| key | varchar(255) | No |  | Key. |
| label | varchar(255) | No |  | Display label. |
| type | varchar(255) | No |  | Type. |
| required | boolean | No |  | Yes/no: required. |
| options | json | Yes |  | Options. |
| sort_order | integer | No |  | Sort order. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |

### reference_data

Lists used in forms (reference values).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| set_name | varchar(255) | No |  | Set name. |
| code | varchar(255) | No |  | Short code. |
| label | varchar(255) | No |  | Display label. |
| definition | text | Yes |  | Definition. |
| source | text | Yes |  | Source. |
| active | boolean | No |  | Whether the record is in use. |
| created_at | timestamp | Yes |  | When the record was created. |
| updated_at | timestamp | Yes |  | When the record was last changed. |
| sort_order | integer | No |  | Sort order. |
| weight | numeric | Yes |  | Weight. |
| meta | json | Yes |  | Meta. |

## Framework (Laravel)

### migrations

Database migrations applied (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | integer | No | PK | Unique record number. |
| migration | varchar(255) | No |  | Migration. |
| batch | integer | No |  | Batch. |

### cache

Application cache (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| key | varchar(255) | No | PK | Key. |
| value | text | No |  | Value. |
| expiration | integer | No |  | Expiration. |

### cache_locks

Cache locks (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| key | varchar(255) | No | PK | Key. |
| owner | varchar(255) | No |  | Owner. |
| expiration | integer | No |  | Expiration. |

### jobs

Queued background jobs (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| queue | varchar(255) | No |  | Queue. |
| payload | text | No |  | Payload. |
| attempts | smallint | No |  | Attempts. |
| reserved_at | integer | Yes |  | Date and time: reserved. |
| available_at | integer | No |  | Date and time: available. |
| created_at | integer | No |  | When the record was created. |

### job_batches

Batches of background jobs (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | varchar(255) | No | PK | Unique record number. |
| name | varchar(255) | No |  | Name. |
| total_jobs | integer | No |  | Total jobs. |
| pending_jobs | integer | No |  | Pending jobs. |
| failed_jobs | integer | No |  | Failed jobs. |
| failed_job_ids | text | No |  | Failed job ids. |
| options | text | Yes |  | Options. |
| cancelled_at | integer | Yes |  | Date and time: cancelled. |
| created_at | integer | No |  | When the record was created. |
| finished_at | integer | Yes |  | Date and time: finished. |

### failed_jobs

Background jobs that failed (Laravel).

| Column | Type | Null | Key | Description |
|---|---|---|---|---|
| id | bigint | No | PK | Unique record number. |
| uuid | varchar(255) | No |  | Uuid. |
| connection | text | No |  | Connection. |
| queue | text | No |  | Queue. |
| payload | text | No |  | Payload. |
| exception | text | No |  | Exception. |
| failed_at | timestamp | No |  | Date and time: failed. |

