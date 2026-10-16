<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/', name: 'project', methods: ['GET'])]
class ProjectController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['message' => 'Hello, World!']);
    }
}
