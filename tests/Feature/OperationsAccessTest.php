<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_open_or_create_sensitive_operations(): void
    {
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->get(route('reports.index'))->assertForbidden();
        // The referral list page is retired; referrals live in case progress notes.
        $this->actingAs($viewer)->get(route('referrals.index'))->assertRedirect('/dashboard');
        $this->actingAs($viewer)->get(route('tasks.index'))->assertForbidden();
        $this->actingAs($viewer)->getJson(route('search', ['q' => 'test']))->assertForbidden();
        $this->assertNull(app('router')->getRoutes()->getByName('reports.store'));
    }
}
