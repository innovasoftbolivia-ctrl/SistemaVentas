<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * La aplicación corre en español pero el repositorio no traía mensajes de
 * validación: una regla sin mensaje propio le mostraba al usuario el texto
 * «validation.required». Estas pruebas impiden que vuelva a pasar.
 */
class MensajesEnEspanolTest extends TestCase
{
    public function test_el_idioma_de_la_aplicacion_es_espanol(): void
    {
        $this->assertSame('es', app()->getLocale());
    }

    public function test_un_campo_obligatorio_vacio_se_explica_en_espanol(): void
    {
        $v = Validator::make(['nombre' => ''], ['nombre' => 'required'], [], ['nombre' => 'nombre']);

        $this->assertSame('El campo nombre es obligatorio.', $v->errors()->first('nombre'));
    }

    public function test_las_reglas_que_usan_los_formularios_no_muestran_su_clave(): void
    {
        $reglas = [
            'a' => ['', 'required'], 'b' => ['x', 'numeric'], 'c' => ['-1', 'numeric|min:0'], 'd' => ['99999', 'numeric|max:10'],
            'e' => ['abc', 'integer'], 'f' => ['abc', 'date'], 'g' => ['zz', 'in:a,b'], 'h' => ['1.234', 'decimal:0,2'],
            'i' => ['1', 'gt:5'], 'j' => ['texto', 'email'], 'k' => ['corta', 'min:8'], 'l' => [str_repeat('x', 300), 'max:255'],
            'm' => ['a', 'confirmed'], 'n' => ['', 'required_with:b'], 'o' => ['12ab', 'regex:/^[0-9]+$/'], 'p' => ['x', 'boolean'],
        ];

        foreach ($reglas as $campo => [$valor, $regla]) {
            $datos = [$campo => $valor] + ($campo === 'n' ? ['b' => 'algo'] : []);
            $mensaje = Validator::make($datos, [$campo => $regla])->errors()->first($campo);

            $this->assertNotSame('', $mensaje, "la regla {$regla} tendría que haber fallado");
            $this->assertStringNotContainsString('validation.', $mensaje, "la regla {$regla} muestra su clave en vez de un mensaje");
        }
    }

    public function test_no_falta_ninguna_clave_de_las_que_trae_laravel_en_ingles(): void
    {
        $ingles = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $espanol = require base_path('lang/es/validation.php');

        $faltan = array_diff(array_keys($ingles), array_keys($espanol));

        $this->assertSame([], array_values($faltan), 'faltan mensajes en español: '.implode(', ', $faltan));
    }
}
