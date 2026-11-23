<?php

namespace App\Http\Requests;

use App\Models\Inventor;
use Illuminate\Foundation\Http\FormRequest;

/** Create or update an inventor's name and IPO Form 1 particulars. */
class SaveInventorRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('id') === null) {
            return $this->user()->can('create', Inventor::class);
        }
        $inventor = Inventor::find($this->route('id'));

        return $inventor !== null && $this->user()->can('update', $inventor);
    }

    public function rules(): array
    {
        $creating = $this->route('id') === null;

        return [
            // Every inventor is managed under a client; it can be moved on edit.
            'client_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:clients,id',
            'full_name' => 'required|string|max:255',
        ] + Inventor::particularRules($creating, $this->all());
    }

    public function messages(): array
    {
        return [
            'address_house.required_without'  => 'Enter the house no. / building or the street / area.',
            'address_street.required_without' => 'Enter the house no. / building or the street / area.',
            'state.required'                  => 'Enter the state (needed for an Indian address).',
        ];
    }
}
