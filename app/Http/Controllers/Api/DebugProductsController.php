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
        $export = (bool) $request->query('export', false);
        $limit = (int) $request->query('limit', 5);

        $data = [
            'total' => Product::count(),
        ];

        // Export all products as JSON
        if ($export) {
            $products = Product::limit($limit)->get();
            $data['exported'] = $products->count();
            $data['products'] = $products->map(fn($p) => [
                'id'                  => $p->id,
                'article'             => $p->article,
                'parent_article'      => $p->parent_article,
                'title'               => $p->title,
                'title_json'          => $p->title_json,
                'price'               => $p->price,
                'price_old'           => $p->price_old,
                'category_path'       => $p->category_path,
                'slug'                => $p->slug,
                'link'                => $p->link,
                'images'              => $p->images,
                'raw'                 => $p->raw,
                'search_index'        => $p->search_index,
                'orders_count'        => $p->orders_count,
                'views_count'         => $p->views_count,
                'added_to_cart_count' => $p->added_to_cart_count,
                'in_stock'            => $p->in_stock,
                'presence'            => $p->presence,
                'quantity'            => $p->quantity,
                'popularity'          => $p->popularity,
                'we_recommended'      => $p->we_recommended,
                'color'               => $p->color,
                'brand'               => $p->brand,
                'display_in_showcase' => $p->display_in_showcase,
            ])->toArray();
            return response()->json($data);
        }

        if ($query !== '') {
            $data['query']   = $query;
            $data['results'] = $productService->searchByText($query, null, 'uk');
        } else {
            $data['sample'] = Product::limit($limit)->get();
        }

        return response()->json($data);
    }
}
