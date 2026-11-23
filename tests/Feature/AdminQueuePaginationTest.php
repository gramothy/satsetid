<?php

namespace Tests\Feature;

use App\Models\QueueTicket;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminQueuePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tickets_are_paginated_in_sequence_order(): void
    {
        $restaurant = $this->restaurant();
        $this->tickets($restaurant, 30);
        $owner = User::factory()->create(['role' => 'owner', 'active' => true]);

        $first = $this->actingAs($owner)->get(route('admin.restaurants.queue', $restaurant));
        $first->assertOk();
        $first->assertSee('A-001');
        $first->assertSee('A-025');
        $first->assertDontSee('A-026');
        $first->assertSee('?page=2', false);

        $second = $this->actingAs($owner)->get(route('admin.restaurants.queue', [$restaurant, 'page' => 2]));
        $second->assertOk();
        $second->assertSee('A-026');
        $second->assertSee('A-030');
        $second->assertDontSee('A-025');
    }

    public function test_short_queue_shows_no_pagination(): void
    {
        $restaurant = $this->restaurant();
        $this->tickets($restaurant, 3);

        $this->actingAs(User::factory()->create(['role' => 'owner', 'active' => true]))
            ->get(route('admin.restaurants.queue', $restaurant))
            ->assertOk()
            ->assertDontSee('admin-pagination', false);
    }

    private function tickets(Restaurant $restaurant, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            QueueTicket::query()->create([
                'public_token' => (string) \Illuminate\Support\Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'customer_name' => 'Tamu '.$i,
                'phone_number' => '62812345'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'queue_number' => sprintf('%s-%03d', $restaurant->prefix, $i),
                'sequence_number' => $i,
                'party_size' => 1,
                'status' => QueueTicket::STATUS_WAITING,
                'joined_at' => now()->subMinutes($count - $i),
            ]);
        }
    }

    private function restaurant(): Restaurant
    {
        return Restaurant::query()->create([
            'name' => 'Ayam Bakar', 'slug' => 'ayam-bakar-a', 'category' => 'Makanan',
            'prefix' => 'A', 'average_service_minutes' => 5, 'max_wait_minutes' => 30,
            'max_party_size' => 6, 'active' => true, 'queue_open' => true,
        ]);
    }
}
