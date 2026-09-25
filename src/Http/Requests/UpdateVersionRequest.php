<?php

namespace Controlink\Templates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving a layout. Only `project_data` is authoritative; `html` and `css` are the
 * editor's derived export, kept so that rendering never needs the editor.
 *
 * `authorize()` always allows: there is no route model binding here to authorise
 * against (`$this->route('version')` is a plain string id, not a resolved model), so
 * the controller runs `$this->authorize('update', $template)` itself, once it has
 * resolved both ids.
 */
class UpdateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_data'       => ['required', 'array'],
            'project_data.pages' => ['required', 'array'],
            'html'               => ['required', 'string'],

            // Nullable, not just present: a layout with no styles of its own is
            // legitimate, and `ConvertEmptyStringsToNull` turns the editor's empty
            // string into null before validation ever sees it.
            'css' => ['present', 'nullable', 'string'],
        ];
    }

    /**
     * The layout, whole.
     *
     * `validated()` must NOT be used for this: declaring a nested rule such as
     * `project_data.pages` narrows what `validated()` returns to the keys that have
     * rules, so everything else in the document — `styles`, `assets`, `symbols` — would
     * be dropped on every save. `project_data` is one opaque document from the editor,
     * not a set of fields, and it is stored whole or not at all.
     *
     * @return array<string, mixed>
     */
    public function projectData(): array
    {
        /*
         * Read from the raw body, not from the request's input.
         *
         * `ConvertEmptyStringsToNull` and `TrimStrings` walk the request in depth, so
         * every empty string anywhere inside the layout — an unset format, an empty
         * text node — would reach the database as `null`. The document the user drew is
         * not a set of form fields to be tidied up; it round trips byte for byte or it
         * is not the same layout.
         */
        $decoded = json_decode((string) $this->getContent(), true);

        if (is_array($decoded) && is_array($decoded['project_data'] ?? null)) {
            return $decoded['project_data'];
        }

        return (array) $this->input('project_data');
    }

    public function attributes(): array
    {
        return __('templates::editor.attributes');
    }
}
