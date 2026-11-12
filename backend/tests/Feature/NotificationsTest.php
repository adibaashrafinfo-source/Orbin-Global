<?php

namespace Tests\Feature;

use App\Models\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    public function test_user_sees_own_and_role_notifications_and_can_mark_read(): void
    {
        $cashier = $this->userWithRole('cashier');
        $manager = $this->userWithRole('manager');
        Notification::query()->create(['user_id' => $cashier->id, 'type' => 'shift_open', 'title' => 'Shift still open', 'severity' => 'warning']);
        Notification::query()->create(['role' => 'cashier', 'type' => 'low_stock', 'title' => 'Low stock']);
        Notification::query()->create(['user_id' => $manager->id, 'type' => 'approval_pending', 'title' => 'Approve me']);

        $this->actingAsUser($cashier);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread', 2);
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 0);

        $this->actingAsUser($manager);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Approve me');
    }
}
