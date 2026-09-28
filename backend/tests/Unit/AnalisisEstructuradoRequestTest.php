<?php

namespace Tests\Unit;

use App\Http\Requests\Api\V1\GuardarAnalisisEstructuradoRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AnalisisEstructuradoRequestTest extends TestCase
{
    public function test_empty_optional_summary_and_stage_pass_the_strict_contract(): void
    {
        $payload = [
            'result' => [
                'schema_version' => '1.0',
                'summary' => null,
                'procedural_stage' => null,
                'participants' => [],
                'offenses' => [],
                'facts' => [],
                'evidence' => [],
                'chronology' => [],
                'missing_information' => [],
                'uncertainties' => [],
            ],
        ];
        $request = GuardarAnalisisEstructuradoRequest::create('/interno', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_a_populated_summary_requires_a_literal_source_reference(): void
    {
        $payload = [
            'result' => [
                'schema_version' => '1.0',
                'summary' => ['text' => 'Síntesis sin citas', 'certainty' => 'textual'],
                'procedural_stage' => null,
                'participants' => [],
                'offenses' => [],
                'facts' => [],
                'evidence' => [],
                'chronology' => [],
                'missing_information' => [],
                'uncertainties' => [],
            ],
        ];
        $request = GuardarAnalisisEstructuradoRequest::create('/interno', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('result.summary.sources', $validator->errors()->toArray());
    }
}
