<?php

namespace Tests\Unit;

use App\Services\Recuperacion\FragmentadorTexto;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FragmentadorTextoExpedienteTest extends TestCase
{
    public function test_divides_long_text_with_bounded_fragments_and_overlap(): void
    {
        $texto = implode(' ', range(1, 900));
        $fragmentos = (new FragmentadorTexto)->fragmentar($texto, 500, 50);

        $this->assertGreaterThan(1, count($fragmentos));
        foreach ($fragmentos as $fragmento) {
            $this->assertLessThanOrEqual(500, mb_strlen($fragmento, 'UTF-8'));
            $this->assertNotSame('', $fragmento);
        }

        $ultimoTramo = mb_substr($fragmentos[0], -20, null, 'UTF-8');
        $this->assertStringContainsString($ultimoTramo, $fragmentos[1]);
    }

    public function test_empty_text_produces_no_fragments_and_invalid_limits_are_rejected(): void
    {
        $fragmentador = new FragmentadorTexto;

        $this->assertSame([], $fragmentador->fragmentar(" \n\t "));
        $this->assertSame(['Medida cautelar y declaración.'], $fragmentador->fragmentar('  Medida cautelar y declaración.  '));

        $this->expectException(InvalidArgumentException::class);
        $fragmentador->fragmentar('texto', 10, 10);
    }
}
