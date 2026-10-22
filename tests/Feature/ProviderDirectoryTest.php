<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_add_provider(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $this->actingAs($admin)->post(route('providers.store'), ['name' => 'Kalinga Health Center', 'service_type' => 'Health', 'contact_person' => 'Dr. Cruz', 'phone' => '09170000000', 'municipality' => 'Tabuk'])->assertRedirect();
        $this->assertDatabaseHas('service_providers', ['name' => 'Kalinga Health Center', 'service_type' => 'Health', 'active' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'provider.created']);
    }

    public function test_case_worker_cannot_add_provider(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($worker)->post(route('providers.store'), ['name' => 'Unauthorized', 'service_type' => 'Health'])->assertForbidden();
    }

    public function test_case_worker_cannot_view_provider_contact_directory(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);

        $this->actingAs($worker)->get(route('providers.index'))->assertForbidden();
    }
}
