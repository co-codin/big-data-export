<?php

namespace Database\Factories;

use App\Enums\ProcessStatusId;
use App\Models\ReportProcess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ReportProcess>
 */
class ReportProcessFactory extends Factory
{
    protected $model = ReportProcess::class;

    public function definition(): array
    {
        return [
            'rp_pid' => fake()->numberBetween(1, 99_999),
            'rp_start_datetime' => Carbon::now(),
            'rp_exec_time' => null,
            'ps_id' => ProcessStatusId::Started,
            'rp_file_save_path' => null,
        ];
    }

    public function started(): static
    {
        return $this->state(fn () => ['ps_id' => ProcessStatusId::Started]);
    }

    public function completed(?string $path = null): static
    {
        return $this->state(fn () => [
            'ps_id' => ProcessStatusId::Completed,
            'rp_exec_time' => fake()->numberBetween(1, 5_000),
            'rp_file_save_path' => $path,
        ]);
    }

    public function errored(): static
    {
        return $this->state(fn () => [
            'ps_id' => ProcessStatusId::Error,
            'rp_exec_time' => fake()->numberBetween(1, 5_000),
            'rp_file_save_path' => null,
        ]);
    }
}
