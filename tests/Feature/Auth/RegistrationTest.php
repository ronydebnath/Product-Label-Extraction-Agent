<?php

use App\Models\User;

it('registers a user and starts a session (FR-1)', function () {
    $response = $this->post('/register', [
        'name' => 'Rony Debnath',
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertRedirect('/uploads');
    $this->assertAuthenticated();

    expect(User::where('email', 'rony@example.com')->exists())->toBeTrue();
});

it('rejects an email that is already registered (FR-1)', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'name' => 'Impostor',
        'email' => 'taken@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
});

it('rejects a password below the minimum length (FR-1)', function () {
    $this->post('/register', [
        'name' => 'Rony Debnath',
        'email' => 'rony@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});
