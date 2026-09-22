<?php

namespace App\Domain\HumanResources\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Construye de forma única la contraseña inicial de una cuenta de funcionario. */
class EmployeeInitialPasswordGenerator
{
    /**
     * Concatena el CI completo con la inicial ASCII de cada nombre y apellido.
     *
     * El CI conserva su complemento y representación registrada. Las iniciales
     * se normalizan para que acentos, diéresis o eñes no dificulten el ingreso.
     */
    public function generate(string $identityCard, string $firstNames, string $lastNames): string
    {
        $identityCard = trim($identityCard);
        $asciiFullName = Str::ascii(trim("{$firstNames} {$lastNames}"));
        preg_match_all('/[A-Za-z]+/', $asciiFullName, $matches);
        $initials = implode('', array_map(
            static fn (string $word): string => strtoupper($word[0]),
            $matches[0],
        ));

        if ($identityCard === '' || $initials === '') {
            throw new InvalidArgumentException('El CI, los nombres y los apellidos son obligatorios para generar la contraseña inicial.');
        }

        return $identityCard.$initials;
    }
}
