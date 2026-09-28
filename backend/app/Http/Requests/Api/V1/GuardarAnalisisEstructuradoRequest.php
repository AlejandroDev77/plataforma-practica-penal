<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class GuardarAnalisisEstructuradoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $findingKeys = 'text,certainty,sources';
        $sourceRules = [
            'array' => ['required', 'array', 'min:1', 'max:10'],
            'item' => ['required', 'array:page_id,excerpt'],
            'page' => ['required', 'integer', 'min:1'],
            'excerpt' => ['required', 'string', 'min:1', 'max:1000'],
        ];

        $rules = [
            'result' => ['required', 'array:schema_version,summary,procedural_stage,participants,offenses,facts,evidence,chronology,missing_information,uncertainties'],
            'result.schema_version' => ['required', 'string', 'in:1.0'],
            'result.summary' => ['present', 'nullable', 'array:'.$findingKeys],
            'result.procedural_stage' => ['present', 'nullable', 'array:'.$findingKeys],
        ];

        foreach ([
            'summary' => 4_000,
            'procedural_stage' => 100,
        ] as $finding => $maxLength) {
            if (! is_array($this->input('result.'.$finding))) {
                continue;
            }

            $prefix = 'result.'.$finding;
            $rules[$prefix.'.text'] = ['required', 'string', 'min:1', 'max:'.$maxLength];
            $rules[$prefix.'.certainty'] = ['required', 'in:textual,inferido,incierto'];
            $rules[$prefix.'.sources'] = $sourceRules['array'];
            $rules[$prefix.'.sources.*'] = $sourceRules['item'];
            $rules[$prefix.'.sources.*.page_id'] = $sourceRules['page'];
            $rules[$prefix.'.sources.*.excerpt'] = $sourceRules['excerpt'];
        }

        foreach ([
            'participants' => ['max:100'],
            'offenses' => ['max:100'],
            'facts' => ['max:150'],
            'evidence' => ['max:100'],
            'chronology' => ['max:150'],
            'missing_information' => ['max:100'],
            'uncertainties' => ['max:100'],
        ] as $collection => $limits) {
            $rules['result.'.$collection] = ['present', 'array', ...$limits];
        }

        $rules += [
            'result.participants.*' => ['required', 'array:name_as_written,role_as_written,description,certainty,sources'],
            'result.participants.*.name_as_written' => ['required', 'string', 'min:1', 'max:255'],
            'result.participants.*.role_as_written' => ['nullable', 'string', 'max:80'],
            'result.participants.*.description' => ['nullable', 'string', 'max:2000'],
            'result.participants.*.certainty' => ['required', 'in:textual,inferido,incierto'],
            'result.participants.*.sources' => $sourceRules['array'],
            'result.participants.*.sources.*' => $sourceRules['item'],
            'result.participants.*.sources.*.page_id' => $sourceRules['page'],
            'result.participants.*.sources.*.excerpt' => $sourceRules['excerpt'],
            'result.offenses.*' => ['required', 'array:label_as_written,article_as_written,description,certainty,sources'],
            'result.offenses.*.label_as_written' => ['required', 'string', 'min:1', 'max:255'],
            'result.offenses.*.article_as_written' => ['nullable', 'string', 'max:150'],
            'result.offenses.*.description' => ['nullable', 'string', 'max:2000'],
            'result.offenses.*.certainty' => ['required', 'in:textual,inferido,incierto'],
            'result.offenses.*.sources' => $sourceRules['array'],
            'result.offenses.*.sources.*' => $sourceRules['item'],
            'result.offenses.*.sources.*.page_id' => $sourceRules['page'],
            'result.offenses.*.sources.*.excerpt' => $sourceRules['excerpt'],
            'result.facts.*' => ['required', 'array:description,kind,date_as_written,certainty,sources'],
            'result.facts.*.description' => ['required', 'string', 'min:1', 'max:4000'],
            'result.facts.*.kind' => ['required', 'in:alegacion,declaracion,acto_procesal,otro'],
            'result.facts.*.date_as_written' => ['nullable', 'string', 'max:200'],
            'result.facts.*.certainty' => ['required', 'in:textual,inferido,incierto'],
            'result.facts.*.sources' => $sourceRules['array'],
            'result.facts.*.sources.*' => $sourceRules['item'],
            'result.facts.*.sources.*.page_id' => $sourceRules['page'],
            'result.facts.*.sources.*.excerpt' => $sourceRules['excerpt'],
            'result.evidence.*' => ['required', 'array:name_as_written,kind_as_written,description,status_as_written,certainty,sources'],
            'result.evidence.*.name_as_written' => ['required', 'string', 'min:1', 'max:255'],
            'result.evidence.*.kind_as_written' => ['nullable', 'string', 'max:80'],
            'result.evidence.*.description' => ['nullable', 'string', 'max:2000'],
            'result.evidence.*.status_as_written' => ['nullable', 'string', 'max:200'],
            'result.evidence.*.certainty' => ['required', 'in:textual,inferido,incierto'],
            'result.evidence.*.sources' => $sourceRules['array'],
            'result.evidence.*.sources.*' => $sourceRules['item'],
            'result.evidence.*.sources.*.page_id' => $sourceRules['page'],
            'result.evidence.*.sources.*.excerpt' => $sourceRules['excerpt'],
            'result.chronology.*' => ['required', 'array:title,description,date_as_written,certainty,sources'],
            'result.chronology.*.title' => ['required', 'string', 'min:1', 'max:255'],
            'result.chronology.*.description' => ['required', 'string', 'min:1', 'max:2000'],
            'result.chronology.*.date_as_written' => ['nullable', 'string', 'max:200'],
            'result.chronology.*.certainty' => ['required', 'in:textual,inferido,incierto'],
            'result.chronology.*.sources' => $sourceRules['array'],
            'result.chronology.*.sources.*' => $sourceRules['item'],
            'result.chronology.*.sources.*.page_id' => $sourceRules['page'],
            'result.chronology.*.sources.*.excerpt' => $sourceRules['excerpt'],
            'result.missing_information.*' => ['required', 'array:question,relevance,context_sources'],
            'result.missing_information.*.question' => ['required', 'string', 'min:1', 'max:1000'],
            'result.missing_information.*.relevance' => ['required', 'string', 'min:1', 'max:2000'],
            'result.missing_information.*.context_sources' => ['present', 'array', 'max:10'],
            'result.missing_information.*.context_sources.*' => $sourceRules['item'],
            'result.missing_information.*.context_sources.*.page_id' => $sourceRules['page'],
            'result.missing_information.*.context_sources.*.excerpt' => $sourceRules['excerpt'],
            'result.uncertainties.*' => ['required', 'array:issue,explanation,sources'],
            'result.uncertainties.*.issue' => ['required', 'string', 'min:1', 'max:1000'],
            'result.uncertainties.*.explanation' => ['required', 'string', 'min:1', 'max:2000'],
            'result.uncertainties.*.sources' => $sourceRules['array'],
            'result.uncertainties.*.sources.*' => $sourceRules['item'],
            'result.uncertainties.*.sources.*.page_id' => $sourceRules['page'],
            'result.uncertainties.*.sources.*.excerpt' => $sourceRules['excerpt'],
        ];

        return $rules;
    }
}
