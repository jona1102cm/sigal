<?php

use App\Domain\HumanResources\Services\EmployeeInitialPasswordGenerator;

test('it combines the complete identity card with every normalized name initial', function () {
    $password = (new EmployeeInitialPasswordGenerator)->generate(
        '8123456-1A',
        'María Elena',
        'Vargas Suárez',
    );

    expect($password)->toBe('8123456-1AMEVS');
});

test('it includes initials from compound names and surnames without accents', function () {
    $password = (new EmployeeInitialPasswordGenerator)->generate(
        '7654321',
        'José Luis',
        'Núñez de la Barra',
    );

    expect($password)->toBe('7654321JLNDLB');
});
