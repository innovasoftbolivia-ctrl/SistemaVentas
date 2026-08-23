<?php

namespace App\Http\Controllers;

use App\Models\UnidadMedida;
use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnidadMedidaController extends Controller
{
    public function index(): View
    {
        return view('unidades.index', [
            'title' => 'Unidades de medida',
            'trail' => ['Catálogo' => route('productos.index')],
            'unidades' => UnidadMedida::withCount('productos')->orderBy('codigo')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $unidad = UnidadMedida::create($datos);

        Auditor::registrar('UNIDAD_CREADA', 'unidades_medida', $unidad->id, $datos);

        return redirect()->route('unidades.index')
            ->with('exito', "Unidad «{$unidad->codigo}» creada.");
    }

    public function update(Request $request, UnidadMedida $unidad): RedirectResponse
    {
        $datos = $this->validar($request, $unidad);

        $unidad->update($datos);

        Auditor::registrar('UNIDAD_ACTUALIZADA', 'unidades_medida', $unidad->id, $datos);

        return redirect()->route('unidades.index')
            ->with('exito', "Unidad «{$unidad->codigo}» actualizada.");
    }

    /**
     * A diferencia de categorías o cargos, la unidad no tiene bandera de
     * activo: o se usa, o no existe. Con productos asociados no se toca.
     */
    public function destroy(UnidadMedida $unidad): RedirectResponse
    {
        if ($unidad->productos()->exists()) {
            return redirect()->route('unidades.index')
                ->with('error', "La unidad «{$unidad->codigo}» está en uso por productos del catálogo y no se puede eliminar.");
        }

        $codigo = $unidad->codigo;
        $unidad->delete();

        Auditor::registrar('UNIDAD_ELIMINADA', 'unidades_medida', null, ['codigo' => $codigo]);

        return redirect()->route('unidades.index')->with('exito', "Unidad «{$codigo}» eliminada.");
    }

    private function validar(Request $request, ?UnidadMedida $unidad = null): array
    {
        return $request->validate([
            'codigo' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('unidades_medida', 'codigo')->ignore($unidad?->id),
            ],
            'nombre' => ['required', 'string', 'max:40'],
            'permite_decimal' => ['boolean'],
        ], [
            'codigo.regex' => 'El código va en mayúsculas, sin espacios: UND, KG, CAJA.',
        ], [
            'codigo' => 'código',
            'nombre' => 'nombre',
            'permite_decimal' => 'admite decimales',
        ]);
    }
}
