<?php

namespace App\Domain;

use Illuminate\Validation\Rules\Password;

class AccountPassword
{
    public static function rules(): array
    {
        return ['required', 'string', 'max:72', 'confirmed', Password::min(12)->letters()->numbers(),
            function ($attribute, $value, $fail) {
                // Bcrypt limits bytes, while Laravel's string max counts characters.
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('รหัสผ่านยาวเกินไปหรือมีตัวอักษรที่ใช้ไม่ได้ กรุณาตั้งใหม่');
                }
            },
        ];
    }
}
