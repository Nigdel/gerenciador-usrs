<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SecretsRotateTest extends TestCase
{
    /** @test */
    public function it_rotates_app_key()
    {
        // Ensure a known key exists
        $original = env('APP_KEY');
        // Run the command
        Artisan::call('secrets:rotate');
        $output = Artisan::output();
        $this->assertStringContainsString('APP_KEY rotated', $output);
        $new = env('APP_KEY');
        $this->assertNotEquals($original, $new);
    }
}
