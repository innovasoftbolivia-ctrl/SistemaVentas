<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Registrar al cliente es opcional: la venta al paso va sin él. Solo la
 * factura obliga a identificarlo, y para eso tiene que ser persona jurídica
 * con RUC y dirección fiscal.
 */
class ClienteController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'persona' => $request->string('persona')->toString(),
        ];

        $clientes = Cliente::withCount(['ventas as ventas_count' => fn ($q) => $q->where('estado', '<>', 'ANULADA')])
            ->buscar($filtros['buscar'])
            ->when($filtros['persona'], fn ($q, $tipo) => $q->where('tipo_persona', $tipo))
            ->orderBy('nombre')
            ->paginate(12)
            ->withQueryString();

        return view('clientes.index', [
            'title' => 'Clientes',
            'clientes' => $clientes,
            'filtros' => $filtros,
        ]);
    }

    /** Búsqueda para el punto de venta. */
    public function buscar(Request $request): JsonResponse
    {
        $clientes = Cliente::activos()
            ->buscar($request->string('q')->toString())
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json(
            $clientes->map(fn (Cliente $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'etiqueta' => $c->etiqueta,
                'juridica' => $c->esJuridica(),
            ])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $cliente = Cliente::create($datos);

        Auditor::registrar('CLIENTE_CREADO', 'clientes', $cliente->id, [
            'nombre' => $cliente->nombre,
            'tipo_persona' => $cliente->tipo_persona,
        ]);

        // Desde el punto de venta se vuelve al mostrador con el cliente elegido.
        if ($request->boolean('desde_pos')) {
            return redirect()->route('pos.index', ['cliente' => $cliente->id])
                ->with('exito', "Cliente «{$cliente->nombre}» registrado y seleccionado.");
        }

        return redirect()->route('clientes.index')
            ->with('exito', "Cliente «{$cliente->nombre}» registrado.");
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $datos = $this->validar($request, $cliente);

        $cliente->update($datos);

        Auditor::registrar('CLIENTE_ACTUALIZADO', 'clientes', $cliente->id, ['nombre' => $cliente->nombre]);

        return redirect()->route('clientes.index')
            ->with('exito', "Cliente «{$cliente->nombre}» actualizado.");
    }

    /** Con ventas a su nombre se desactiva: los comprobantes lo referencian. */
    public function destroy(Cliente $cliente): RedirectResponse
    {
        $nombre = $cliente->nombre;

        if ($cliente->ventas()->exists()) {
            $cliente->update(['activo' => false]);

            Auditor::registrar('CLIENTE_DESACTIVADO', 'clientes', $cliente->id);

            return redirect()->route('clientes.index')
                ->with('exito', "«{$nombre}» tiene compras registradas, así que se desactivó en lugar de eliminarse.");
        }

        $cliente->delete();

        Auditor::registrar('CLIENTE_ELIMINADO', 'clientes', null, ['nombre' => $nombre]);

        return redirect()->route('clientes.index')->with('exito', "Cliente «{$nombre}» eliminado.");
    }

    /**
     * Las reglas siguen los CHECK del esquema: cada tipo de persona exige sus
     * propios campos y prohíbe los del otro.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $juridica = $request->input('tipo_persona') === 'JURIDICA';

        $datos = $request->validate([
            'tipo_persona' => ['required', Rule::in(Cliente::TIPOS_PERSONA)],
            'tipo_documento' => [
                'required',
                $juridica ? Rule::in(['RUC']) : Rule::in(Cliente::DOCUMENTOS_NATURAL),
            ],
            'documento' => [
                $juridica ? 'required' : 'nullable',
                'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('clientes', 'documento')
                    ->where(fn ($q) => $q->where('tipo_documento', $request->input('tipo_documento')))
                    ->ignore($cliente?->id),
            ],
            'nombres' => [$juridica ? 'nullable' : 'required', 'string', 'max:60'],
            'apellidos' => [$juridica ? 'nullable' : 'required', 'string', 'max:60'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'razon_social' => [$juridica ? 'required' : 'nullable', 'string', 'max:150'],
            'nombre_comercial' => ['nullable', 'string', 'max:120'],
            'representante_legal' => ['nullable', 'string', 'max:120'],
            'direccion' => [$juridica ? 'required' : 'nullable', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'activo' => ['boolean'],
        ], [
            'documento.unique' => 'Ya hay un cliente registrado con ese documento.',
            'documento.required' => 'La persona jurídica necesita RUC para poder emitirle factura.',
            'direccion.required' => 'La factura exige la dirección fiscal de la empresa.',
            'razon_social.required' => 'La persona jurídica se identifica por su razón social.',
            'nombres.required' => 'La persona natural se identifica por sus nombres y apellidos.',
            'apellidos.required' => 'La persona natural se identifica por sus nombres y apellidos.',
        ], [
            'tipo_persona' => 'tipo de persona',
            'tipo_documento' => 'tipo de documento',
            'razon_social' => 'razón social',
            'nombre_comercial' => 'nombre comercial',
            'representante_legal' => 'representante legal',
            'direccion' => 'dirección',
            'email' => 'correo',
        ]);

        // El esquema exige que los campos del otro tipo queden nulos.
        foreach ($juridica ? ['nombres', 'apellidos', 'fecha_nacimiento'] : ['razon_social', 'nombre_comercial', 'representante_legal'] as $campo) {
            $datos[$campo] = null;
        }

        return $datos;
    }
}
