<?php

namespace App\Services\Sellers;

use App\Data\Response\ApiResponseData;
use App\Data\Seller\RequestClientUser;
use App\Data\Seller\RequestSearchClient;
use App\Services\ClienteService;

class SellerService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly ClienteService $clienteService
    )
    {}

    public function registrarCliente(RequestClientUser $reques):ApiResponseData{
        $data = $this->clienteService->registerClienteWithUser($reques);
        if(!$data){
            return new ApiResponseData(
                success:false,
                message:'Error al registrar al cliente'
            );
        }
        return new ApiResponseData(
            success:true,
            message:'Cliente registrado correctamente',
            data: $data
        );
    }

    public function obtenerClientePorTelefono(RequestSearchClient $request){
        if(is_null($request->telefono) || empty($request->telefono)){
            return new ApiResponseData(
                    success:false,
                    message:'Telefono no recibido'
                );
        }
        $data = $this->clienteService->getByPhone($request->telefono);
        if(!$data){
            return new ApiResponseData(
                    success:false,
                    message:'Cliente no encontrado'
                );
        }
        return new ApiResponseData(
            success:true,
            message:'Cliente encontrado',
            data:$data
        );
    }

    public function obtenerClientePorRfc(RequestSearchClient $request){
        if(is_null($request->rfc) || empty($request->rfc)){
            return new ApiResponseData(
                    success:false,
                    message:'RFC no recibido'
                );
        }
        $data = $this->clienteService->getByRfc($request->rfc);
        if(!$data){
            return new ApiResponseData(
                    success:false,
                    message:'Cliente no encontrado'
                );
        }
        return new ApiResponseData(
            success:true,
            message:'Cliente encontrado',
            data:$data
        );
    }
}
