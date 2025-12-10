<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Horoshop\ProductService;
use Illuminate\Http\Request;

class DebugProductsController extends Controller
{
    public function index(Request $request, ProductService $productService)
    {
        $query = trim((string) $request->query('q', ''));

        $data = [
            'total' => Product::count(),
        ];

        if ($query !== '') {
            $data['query']   = $query;
            $data['results'] = $productService->searchByText($query, 10);
        } else {
            $data['sample'] = Product::limit(5)->get();
        }

        return response()->json($data);
    }
}
