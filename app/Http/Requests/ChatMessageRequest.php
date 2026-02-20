<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['nullable', 'uuid'],
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', Rule::in(['system', 'user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ];
    }
}
