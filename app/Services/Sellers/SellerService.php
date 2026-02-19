<?php

namespace App\Services\Sellers;

use App\Data\Response\ApiResponseData;
use App\Data\Seller\RequestClientUser;
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

    public function obtenerClientePorTelefono(string $telefono){
        if(is_null($telefono) || empty($telefono)){
            return new ApiResponseData(
                    success:false,
                    message:'Telefono no recibido'
                );
        }
        $data = $this->clienteService->getByPhone($telefono);
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

    public function obtenerClientePorRfc(string $rfc){
        if(is_null($rfc) || empty($rfc)){
            return new ApiResponseData(
                    success:false,
                    message:'RFC no recibido'
                );
        }
        $data = $this->clienteService->getByRfc($rfc);
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
