<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IpRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'record_code' => $this->record_code, 'record_type' => $this->record_type,
            'jurisdiction' => $this->jurisdiction, 'title' => $this->title, 'client_reference' => $this->client_reference,
            'legal_status' => $this->legal_status, 'status_date' => $this->status_date?->toDateString(),
            'data_quality_status' => $this->data_quality_status, 'tags' => $this->tags ?? [],
            'client' => $this->whenLoaded('client', fn () => ['id' => $this->client->id, 'client_code' => $this->client->client_code, 'name' => $this->client->company_name ?: $this->client->legal_name]),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser?->only(['id', 'name'])),
            'backup_user' => $this->whenLoaded('backupUser', fn () => $this->backupUser?->only(['id', 'name'])),
            'uins' => $this->whenLoaded('projects', fn () => $this->projects
                ->pluck('docket_number')->filter()->unique()->values()->all()),
            'patent' => $this->whenLoaded('patentApplication'),
            'trademark' => $this->whenLoaded('trademarkApplication'),
            'projects' => $this->whenLoaded('projects'),
        ];
    }
}
