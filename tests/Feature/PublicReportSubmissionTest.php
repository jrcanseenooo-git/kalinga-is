<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_report_is_confidentially_endorsed_to_the_selected_lgu(): void
    {
        $this->post(route('public.reports.store'), [
            'source' => 'Community member',
            'narrative' => 'A concise public concern that needs confidential screening.',
            'municipality' => 'Tabuk City',
            'priority' => 'normal',
        ])->assertRedirect(route('public.dashboard'));

        $this->assertDatabaseHas('reports', [
            'status' => 'FOR_SCREENING',
            'category' => 'CEFMU',
            'is_public_submission' => true,
            'endorsed_municipality' => 'Tabuk City',
            'created_by' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'public_report.submitted', 'auditable_type' => 'report']);
    }

    public function test_public_report_rejects_invalid_philippine_mobile_number(): void
    {
        $this->from(route('public.dashboard'))->post(route('public.reports.store'), [
            'source' => 'Community member',
            'reporter_contact' => '093400000000000',
            'narrative' => 'A concise public concern that needs confidential screening.',
            'municipality' => 'Tabuk City',
            'priority' => 'normal',
        ])->assertRedirect(route('public.dashboard'))->assertSessionHasErrors('reporter_contact');
    }

    public function test_public_report_endpoint_is_rate_limited(): void
    {
        $payload = [
            'source' => 'Community member',
            'narrative' => 'A concise public concern that needs confidential screening.',
            'municipality' => 'Tabuk City',
            'priority' => 'normal',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->post(route('public.reports.store'), $payload)->assertRedirect(route('public.dashboard'));
        }

        $this->post(route('public.reports.store'), $payload)->assertStatus(429);
    }

    public function test_unapproved_cross_origin_request_is_rejected(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')
            ->get(route('public.dashboard'))
            ->assertForbidden();
    }
}
