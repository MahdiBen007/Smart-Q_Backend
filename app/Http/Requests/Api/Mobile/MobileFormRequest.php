<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

abstract class MobileFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'required_with' => 'The :attribute field is required when :values is present.',
            'array' => 'The :attribute must be a valid array.',
            'boolean' => 'The :attribute field must be true or false.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'date' => 'The :attribute must be a valid date.',
            'date_format' => 'The :attribute format is invalid.',
            'email' => 'The :attribute must be a valid email address.',
            'exists' => 'The selected :attribute is invalid.',
            'max.string' => 'The :attribute may not be greater than :max characters.',
            'min.string' => 'The :attribute must be at least :min characters.',
            'string' => 'The :attribute must be a valid string.',
            'uuid' => 'The :attribute format is invalid.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'This action is unauthorized.',
        ], Response::HTTP_FORBIDDEN));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first() ?: 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}

