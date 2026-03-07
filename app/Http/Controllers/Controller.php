<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="API Dog",
 *     version="1.0.0",
 *     description="Documentação da API Dog"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="Servidor local"
 * )
 */
abstract class Controller
{
    //
}
