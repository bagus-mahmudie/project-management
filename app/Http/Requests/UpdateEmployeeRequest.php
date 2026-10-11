<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $employee */
        $employee = $this->route('user');
        $authenticatedUser = $this->user();

        return $authenticatedUser !== null
            && $employee !== null
            && ($authenticatedUser->isSuperAdmin() || $authenticatedUser->is($employee));
    }

    public function rules(): array
    {
        /** @var User $employee */
        $employee = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($employee->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
