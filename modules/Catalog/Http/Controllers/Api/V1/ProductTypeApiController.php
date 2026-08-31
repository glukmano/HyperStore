<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;

class ProductTypeApiController extends Controller
{
    public function __construct(
        private readonly ProductTypeRegistryInterface $typeRegistry,
    ) {}

    public function index(): JsonResponse
    {
        $types = [];
        foreach ($this->typeRegistry->all() as $type) {
            $types[] = [
                'id' => $type->getId(),
                'name' => $type->getName(),
                'description' => $type->getDescription(),
                'capabilities' => $type->getCapabilities(),
            ];
        }

        return response()->json(['data' => $types]);
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->typeRegistry->has($id)) {
            return response()->json(['message' => 'Product type not found.'], 404);
        }

        $type = $this->typeRegistry->get($id);

        return response()->json([
            'data' => [
                'id' => $type->getId(),
                'name' => $type->getName(),
                'description' => $type->getDescription(),
                'capabilities' => $type->getCapabilities(),
            ],
        ]);
    }
}
