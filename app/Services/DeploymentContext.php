<?php

namespace App\Services;

/** Centraliza las propiedades técnicas del entorno que deben persistirse en el dominio. */
class DeploymentContext
{
    public function isUat(): bool
    {
        return config('sigal.uat.enabled') === true;
    }
}
