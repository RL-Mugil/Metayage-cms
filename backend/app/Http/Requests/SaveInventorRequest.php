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
        return [
            'full_name'            => 'required|string|max:255',
            'gender'               => 'nullable|in:Male,Female,Others,Prefer not to disclose',
            'age'                  => 'nullable|integer|min:1|max:120',
            'nationality'          => 'nullable|string|max:100',
            'country_of_residence' => 'nullable|string|max:100',
            'address_house'        => 'nullable|string|max:255',
            'address_street'       => 'nullable|string|max:500',
            'city'                 => 'nullable|string|max:100',
            'state'                => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'pincode'              => 'nullable|string|max:20',
            'email'                => 'nullable|email|max:255',
            'phone'                => 'nullable|string|max:30',
        ];
    }
}
