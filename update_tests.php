<?php

$files = glob('src/tests/Feature/*.php');
$files = array_merge($files, glob('src/tests/Feature/Subsystems/Accounts/*.php'));

foreach ($files as $file) {
    if (str_contains($file, 'Auth') || str_contains($file, 'HomeTest') || str_contains($file, 'UserTest')) continue;

    $content = file_get_contents($file);

    // Add User import if missing
    if (!str_contains($content, 'use App\Models\User;')) {
        $content = preg_replace('/use Tests\\TestCase;/', "use App\Models\User;\nuse Tests\TestCase;", $content);
    }

    // Add setup method if it doesn't exist
    if (!str_contains($content, 'protected function setUp(): void')) {
        $setup = "\n    protected function setUp(): void\n    {\n        parent::setUp();\n        \$this->actingAs(User::factory()->create());\n    }\n\n";
        $content = preg_replace('/class ([a-zA-Z0-9_]+) extends TestCase/', "class $1 extends TestCase" . $setup, $content);
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}