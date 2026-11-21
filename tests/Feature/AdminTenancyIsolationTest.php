<?php

use App\Models\Admin;
use App\Models\Family;
use App\Models\User;

test('admin console login page is accessible at /console/login', function () {
    $response = $this->get('/console/login');

    $response->assertSuccessful();
});

test('admin model user accessing console root is redirected to families resource', function () {
    $admin = Admin::create([
        'name' => 'System Admin',
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $this->actingAs($admin, 'admin')
        ->get('/console')
        ->assertRedirect('/console/families');
});

test('normal user cannot access console and is redirected to console login', function () {
    $family = Family::create(['name' => 'Smiths']);
    $user = User::factory()->create([
        'family_id' => $family->id,
        'email' => 'regular@example.com',
    ]);

    // Normal user logged into web guard cannot access console
    $this->actingAs($user, 'web')
        ->get('/console')
        ->assertRedirect('/console/login');
});

test('admin can view families and users lists in central context', function () {
    $familyA = Family::create(['name' => 'Family Alpha']);
    $familyB = Family::create(['name' => 'Family Beta']);

    User::factory()->create(['family_id' => $familyA->id, 'email' => 'alpha@test.com']);
    User::factory()->create(['family_id' => $familyB->id, 'email' => 'beta@test.com']);

    $admin = Admin::create([
        'name' => 'Super Admin',
        'email' => 'super@admin.com',
        'password' => 'password',
    ]);

    // Admin accesses families list
    $this->actingAs($admin, 'admin')
        ->get('/console/families')
        ->assertSuccessful()
        ->assertSee('Family Alpha')
        ->assertSee('Family Beta');

    // Admin accesses users list
    $this->actingAs($admin, 'admin')
        ->get('/console/users')
        ->assertSuccessful()
        ->assertSee('alpha@test.com')
        ->assertSee('beta@test.com');

    // Verify tenancy is NOT initialized in central admin context
    expect(tenancy()->initialized)->toBeFalse();

    // Verify all families and users are visible
    expect(Family::count())->toBe(2)
        ->and(User::count())->toBe(2);
});

test('welcome page does not display login or register buttons', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('Log in')
        ->assertDontSee('Register');
});
