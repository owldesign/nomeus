<?php

namespace App\Http\Resources;

use App\Services\ServiceManager;
use App\Support\ServiceInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ServiceInstance $resource */
class ServiceInstanceResource extends JsonResource
{
    public function __construct(ServiceInstance $resource, private readonly ?int $logLines = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $services = app(ServiceManager::class);
        $data = $this->resource->toArray() + [
            'status' => $services->status($this->resource),
            'env' => $services->env($this->resource),
        ];
        if ($this->logLines !== null) {
            $data['log'] = $services->logTail($this->resource, $this->logLines);
        }

        return $data;
    }
}
