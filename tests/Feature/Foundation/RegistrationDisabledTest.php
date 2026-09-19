<?php

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'x', 'email' => 'x@y.z', 'password' => 'secretsecret', 'password_confirmation' => 'secretsecret'])
        ->assertNotFound();
});
