<?php

use App\Models\User;

it('logs in with valid credentials (FR-1)', function () {
    $user = User::factory()->create([
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $this->post('/login', [
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
    ])->assertRedirect('/uploads');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials without saying which field was wrong (FR-1)', function () {
    User::factory()->create([
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $this->post('/login', [
        'email' => 'rony@example.com',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('throttles login after five failed attempts (FR-1, NFR-2)', function () {
    User::factory()->create([
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
    ]);

    // The limiter is keyed on email plus IP, so a password-spray against one account stops here
    // rather than at the guess that happens to be right.
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'email' => 'rony@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // The sixth attempt is refused by the throttle middleware before authentication runs, so even
    // the correct password does not get in. 429 rather than a validation error: the request was
    // never evaluated.
    $this->post('/login', [
        'email' => 'rony@example.com',
        'password' => 'correct-horse-battery',
    ])->assertStatus(429);

    $this->assertGuest();
});

it('logs out and abandons the session (FR-1)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});
