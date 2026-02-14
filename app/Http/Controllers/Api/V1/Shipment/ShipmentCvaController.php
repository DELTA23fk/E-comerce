<?php

namespace App\Http\Controllers\Api\V1\Shipment;

use App\Data\Request\RequestCotizarcionFleteCvaData;
use App\Http\Controllers\Controller;
use App\Services\Shipments\ShipmentCvaService;
use Illuminate\Http\Request;

class ShipmentCvaController extends Controller
{
    public function __construct(
        private readonly ShipmentCvaService $shipmentCvaService
    ) {
    }

    public function cotizarEnvioProductosCva(RequestCotizarcionFleteCvaData $data){
        $result = $this->shipmentCvaService->cotizarPedido($data);
        return response()->json($result);
    }   
}
