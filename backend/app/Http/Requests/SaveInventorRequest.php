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
        $rules = [
            // Every inventor is managed under a client; it can be moved on edit.
            'client_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:clients,id',
            'full_name' => 'required|string|max:255',
        ] + Inventor::particularRules($creating, $this->all());

        if ($creating) {
            // Either an existing portal user of the client picked as the inventor (keeps their login),
            // or a new inventor-role login for a new person (email + password).
            $rules['user_id'] = 'nullable|integer|exists:users,id|unique:inventors,user_id';
            $rules['portal_password'] = 'nullable|prohibits:user_id|string|min:8|max:100';
            if (filled($this->input('portal_password'))) {
                $rules['email'] = 'required|email|max:255|unique:users,email';
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'address_house.required_without'  => 'Enter the house no. / building or the street / area.',
            'address_street.required_without' => 'Enter the house no. / building or the street / area.',
            'state.required'                  => 'Enter the state (needed for an Indian address).',
            'email.required'                  => 'Enter the email; it is the inventor\'s portal login.',
            'email.unique'                    => 'This email already has a login. Pick that user from the name list instead.',
            'user_id.unique'                  => 'This user is already an inventor.',
        ];
    }
}
