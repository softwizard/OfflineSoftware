<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * S2-10 — one validation contract used by both the push endpoint (this
 * sprint) and the Livewire customer form (Sprint 5), so the server never
 * rejects data the local UI already accepted.
 *
 * A push payload can be partial (an update only carries the fields that
 * changed — absent means "don't touch", §3.2), so 'name'/'owner_user_id'
 * are only actually required for a create. rules() defaults to create's
 * strict set, since that's what a form-request type-hint on a "new
 * customer" route will normally want; rulesFor('update') is what the push
 * path uses for update payloads.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rulesFor('create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rulesFor(string $op): array
    {
        $required = $op === 'create' ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:255'],
            'name' => [$required, 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'owner_user_id' => [$required, 'uuid', 'exists:users,id'],
        ];
    }

    /**
     * Validate a raw wire payload — the same rules, applied to data that
     * didn't arrive as $_POST fields.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validatePayload(array $payload, string $op = 'create'): array
    {
        return static::validatorFor($payload, $op)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function validatorFor(array $payload, string $op = 'create'): Validator
    {
        return validator($payload, (new self)->rulesFor($op));
    }
}
