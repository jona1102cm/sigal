<?php

test('the legislature interface can be rendered without exposing data', function () {
    $this->get('/legislatures')
        ->assertOk()
        ->assertSee('id="legislatures-app"', false);
});

test('the legislature API requires Sanctum authentication', function () {
    $this->getJson('/api/legislatures')
        ->assertUnauthorized();
});
