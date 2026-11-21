<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Cms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateBablysSiteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('settings.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $text = ['nullable', 'string'];
        $phone = ['nullable', 'string', 'max:40', 'regex:/^[+0-9() .-]*$/D'];
        $https = ['nullable', 'string', 'max:300', 'url', 'starts_with:https://'];
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'branding' => ['required', 'array'],
            'branding.site_name' => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[<>]/'],
            'branding.tagline' => [...$text, 'max:160', 'not_regex:/[<>]/'],
            'branding.legal_name' => [...$text, 'max:120', 'not_regex:/[<>]/'],
            'contact' => ['required', 'array'],
            'contact.email' => ['nullable', 'email:rfc', 'max:254'],
            'contact.phone' => $phone,
            'contact.whatsapp' => $phone,
            'contact.office_address' => [...$text, 'max:500', 'not_regex:/[<>]/'],
            'contact.business_hours' => [...$text, 'max:240', 'not_regex:/[<>]/'],
            'social' => ['required', 'array'],
            'social.facebook' => $https,
            'social.instagram' => $https,
            'social.youtube' => $https,
            'social.linkedin' => $https,
            'legal' => ['required', 'array'],
            'legal.copyright_notice' => [...$text, 'max:240', 'not_regex:/[<>]/'],
            'legal.privacy_url' => $https,
            'legal.terms_url' => $https,
        ];
    }
}
