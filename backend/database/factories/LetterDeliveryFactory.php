<?php

namespace Database\Factories;

use App\Models\LetterDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LetterDelivery>
 */
class LetterDeliveryFactory extends Factory
{
    protected $model = LetterDelivery::class;

    private static int $counter = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::$counter++;
        $year = now()->format('Y');
        $randomPart = Str::upper(Str::random(6));
        $trackingNumber = "REF-{$year}-{$randomPart}-" . self::$counter;

        return [
            'tracking_number' => $trackingNumber,
            'delivery_person_name' => $this->faker->name(),
            'delivery_person_phone' => $this->faker->optional()->phoneNumber(),
            'delivery_person_email' => $this->faker->optional()->safeEmail(),
            'recipient_title' => $this->faker->randomElement([
                'HR Officer',
                'IT Officer',
                'Finance Manager',
                'Human Resource Manager',
                'Chief Executive Officer',
                'Chief Technology Officer',
                'Managing Director',
                'Administrative Manager',
                'Accountant',
                'Procurement Officer',
                'Internal Auditor',
                'Risk Manager',
                'Security Manager',
                'Receptionist',
                'Driver',
            ]),
            'status' => $this->faker->randomElement(['DELIVERED', 'RECEIVED']),
            'delivered_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'received_at' => null,
            'received_by_user_id' => null,
            'version' => 0,
        ];
    }

    /**
     * Configure the model as a delivered letter.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'DELIVERED',
            'received_at' => null,
            'received_by_user_id' => null,
        ]);
    }

    /**
     * Configure the model as a received letter.
     */
    public function received(User $receivedBy = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'RECEIVED',
            'received_at' => $this->faker->dateTimeBetween($attributes['delivered_at'] ?? '-30 days', 'now'),
            'received_by_user_id' => $receivedBy?->id ?? User::factory(),
        ]);
    }
}