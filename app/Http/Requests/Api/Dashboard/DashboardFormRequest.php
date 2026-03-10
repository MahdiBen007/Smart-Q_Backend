<?php

namespace App\Http\Requests\Api\Dashboard;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

abstract class DashboardFormRequest extends FormRequest
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
            'between.numeric' => 'The :attribute must be between :min and :max.',
            'between.integer' => 'The :attribute must be between :min and :max.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'email' => 'The :attribute must be a valid email address.',
            'exists' => 'The selected :attribute is invalid.',
            'file' => 'The :attribute must be a valid file.',
            'image' => 'The :attribute must be an image.',
            'in' => 'The selected :attribute is invalid.',
            'integer' => 'The :attribute must be an integer.',
            'max.array' => 'The :attribute may not contain more than :max items.',
            'max.file' => 'The :attribute may not be greater than :max kilobytes.',
            'max.numeric' => 'The :attribute may not be greater than :max.',
            'max.string' => 'The :attribute may not be greater than :max characters.',
            'min.array' => 'The :attribute must contain at least :min items.',
            'min.file' => 'The :attribute must be at least :min kilobytes.',
            'min.numeric' => 'The :attribute must be at least :min.',
            'min.string' => 'The :attribute must be at least :min characters.',
            'numeric' => 'The :attribute must be a valid number.',
            'string' => 'The :attribute must be a valid string.',
            'unique' => 'The :attribute has already been taken.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This action is unauthorized.',
        ], Response::HTTP_FORBIDDEN));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first() ?: 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
