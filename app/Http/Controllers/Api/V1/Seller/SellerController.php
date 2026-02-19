<?php

namespace App\Http\Controllers\Api\V1\Seller;

use App\Data\Seller\RequestClientUser;
use App\Data\Seller\RequestSearchClient;
use App\Http\Controllers\Controller;
use App\Services\Sellers\SellerService;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    public function __construct(
        private readonly SellerService $sellerService
    )
    {}

    public function RegistrarCliente(RequestClientUser $request){
        $result = $this->sellerService->registrarCliente($request);
        return response()->json($result,$result->success ? 201 : 400);
    }
    public function obtenerClientePorTelefono(RequestSearchClient $request){
        $result = $this->sellerService->obtenerClientePorTelefono($request);
        return response()->json($result, $result->success? 200 : 400);
    }

    public function obtenerClientePorRfc(RequestSearchClient $request){
        $result = $this->sellerService->obtenerClientePorRfc($request);
        return response()->json($result, $result->success? 200 : 400);
    }

}
