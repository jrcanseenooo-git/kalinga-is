<?php

/*
 * Option sets the administrator can edit (Administration > Form options).
 * 'text' sets store the label itself in the record, so renaming or retiring an
 * option never changes saved records; 'coded' sets store a code and only the
 * label, order and availability change. 'usage' lists where saved records use
 * the option, for the warning shown before a change is saved.
 *
 * The defaults are the starting lists (they match
 * resources/js/utils/caseOptions.ts, which remains the fallback when the
 * server lists are not loaded). OWNER REVIEW: confirm every list with the
 * Kalinga team.
 */

return [
    'relationships' => ['title' => 'Relationship to the child', 'kind' => 'text', 'description' => 'Family composition, caregivers and support persons.', 'client' => 'RELATIONSHIPS',
        'usage' => [['relationships', 'relationship_type']],
        'defaults' => ['Mother', 'Father', 'Stepmother', 'Stepfather', 'Grandmother', 'Grandfather', 'Sister', 'Brother', 'Half-sibling', 'Aunt', 'Uncle', 'Cousin', 'Legal guardian', 'Foster parent', 'Spouse / partner', 'Own child', 'In-law', 'Other relative', 'Trusted adult (non-relative)']],
    'civil_statuses' => ['title' => 'Civil status', 'kind' => 'text', 'description' => 'Child and family members.', 'client' => 'CIVIL_STATUSES',
        'usage' => [['clients', 'civil_status'], ['relationships', 'civil_status']],
        'defaults' => ['Single', 'Married', 'Living in (common-law union)', 'Separated', 'Widowed', 'Annulled']],
    'religions' => ['title' => 'Religion', 'kind' => 'text', 'description' => 'Child (intake).', 'client' => 'RELIGIONS',
        'usage' => [['clients', 'religion']],
        'defaults' => ['Roman Catholic', 'Islam', 'Iglesia ni Cristo', 'Evangelical / Born Again Christian', 'Aglipayan', 'Seventh-day Adventist', 'Other Christian', 'Indigenous belief', 'No religion']],
    'ip_groups' => ['title' => 'Indigenous Peoples group', 'kind' => 'text', 'description' => 'Child (intake). Add the IP groups of your area.', 'client' => 'IP_MEMBERSHIP',
        'usage' => [['clients', 'ip_category']],
        'defaults' => ['Not a member of an Indigenous Peoples group']],
    'education_levels' => ['title' => 'Education', 'kind' => 'text', 'description' => 'Child and family members.', 'client' => 'EDUCATION_LEVELS',
        'usage' => [['clients', 'education_attainment'], ['relationships', 'education']],
        'defaults' => ['No formal education', 'Kindergarten', 'Elementary level', 'Elementary graduate', 'Junior high school level', 'Junior high school graduate', 'Senior high school level', 'Senior high school graduate', 'Alternative Learning System (ALS)', 'Vocational / technical', 'College level', 'College graduate']],
    'occupations' => ['title' => 'Occupation', 'kind' => 'text', 'description' => 'Family members.', 'client' => 'OCCUPATIONS',
        'usage' => [['relationships', 'occupation']],
        'defaults' => ['Student', 'Farmer', 'Fisherfolk', 'Laborer / construction worker', 'Vendor / small business', 'Driver', 'Household helper', 'Government employee', 'Private employee', 'Overseas Filipino worker', 'Homemaker', 'Unemployed']],
    'referral_sources' => ['title' => 'Referred by (source of the case)', 'kind' => 'text', 'description' => 'Intake, case details.', 'client' => 'REFERRAL_SOURCES',
        'usage' => [['cases', 'referred_by']],
        'defaults' => ['Self / walk-in', 'Parent or relative', 'Barangay official / BCPC', 'School / teacher / guidance office', 'Health facility / RHU', 'Women and Children Protection Unit (WCPU)', 'PNP Women and Children Protection Desk', 'DSWD Field Office', 'Other LGU social welfare office', 'NGO / civil society organization', 'Faith-based organization', 'NCIP / IP leader', 'NCMF / Muslim religious leader', 'Hotline / online report', 'Community member', 'Prosecutor / court']],
    'visit_locations' => ['title' => 'Place of visit or activity', 'kind' => 'text', 'description' => 'Visits, calendar activities and field mode.', 'client' => 'VISIT_LOCATIONS',
        'usage' => [['monitoring_visits', 'location'], ['tasks', 'location']],
        'defaults' => ['Child\'s home', 'Relative\'s home', 'School', 'Barangay hall', 'LGU social welfare office', 'Health facility / RHU', 'Women and Children Protection Unit (WCPU)', 'Residential care facility', 'Temporary shelter', 'Police station (WCPD)', 'Prosecutor / court', 'Phone or video call']],
    'service_categories' => ['title' => 'Service category', 'kind' => 'text', 'description' => 'Referrals and plans.', 'client' => 'SERVICE_CATEGORIES',
        'usage' => [['referrals', 'service_category']],
        'defaults' => ['Medical / medico-legal examination', 'Mental health and psychosocial support (MHPSS)', 'Counseling', 'Legal assistance', 'Temporary shelter / residential care', 'Alternative care (kinship / foster)', 'Educational assistance', 'Return-to-school support', 'Reproductive, maternal or newborn health care', 'Family counseling / parenting sessions', 'Livelihood or financial assistance (family)', 'Birth registration / civil documents', 'Reintegration support', 'Faith-based / spiritual support', 'Child-friendly interview / joint MDT interview']],
    'partner_agencies' => ['title' => 'Partner agencies', 'kind' => 'text', 'description' => 'MDT members, conferences and office profile.', 'client' => 'PARTNER_AGENCIES',
        'usage' => [['referrals', 'referred_to']],
        'defaults' => ['LGU social welfare office (C/MSWDO)', 'Barangay / BCPC', 'Municipal / City Health Office', 'Rural Health Unit', 'Women and Children Protection Unit (WCPU)', 'PNP Women and Children Protection Desk', 'Prosecutor (DOJ)', 'Public Attorney\'s Office (PAO)', 'Department of Education (school)', 'DSWD Field Office', 'NCIP', 'NCMF', 'NGO / civil society organization', 'Residential care facility']],
    'disability_types' => ['title' => 'Disability or support need', 'kind' => 'coded', 'description' => 'Child (intake).', 'client' => 'DISABILITY_TYPES',
        'usage' => [['cases', 'disability_evidence']],
        'defaults' => ['no_support_need_declared' => 'No disability support need declared', 'deaf_hard_of_hearing' => 'Deaf / hard of hearing', 'intellectual_disability' => 'Intellectual disability',
            'learning_disability' => 'Learning disability', 'mental_disability' => 'Mental disability', 'physical_disability' => 'Physical disability', 'psychosocial_disability' => 'Psychosocial disability',
            'speech_language_impairment' => 'Speech and language impairment', 'visual_disability' => 'Visual disability', 'cancer' => 'Cancer', 'rare_disease' => 'Rare disease']],
    'grant_types' => ['title' => 'Grant types', 'kind' => 'coded', 'description' => 'Grants log of each case.', 'client' => null,
        'usage' => [['case_grants', 'grant_type']],
        'defaults' => ['educational' => 'Educational assistance', 'financial' => 'Financial assistance (AICS)', 'medical' => 'Medical assistance', 'livelihood' => 'Livelihood / seed capital',
            'transportation' => 'Transportation assistance', 'food' => 'Food / material assistance', 'other' => 'Other']],
    'intake_risk_factors' => ['title' => 'Risk factors (intake safety triage)', 'kind' => 'coded', 'weighted' => true, 'description' => 'Intake step 4. The points add up to the suggested risk level.', 'client' => null,
        'usage' => [['cases', 'risk_indicators', 'json']],
        'defaults' => null],
];
