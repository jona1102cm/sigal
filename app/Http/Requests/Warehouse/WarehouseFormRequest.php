<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/** Mensajes y nombres de campos comunes para no exponer claves técnicas al usuario. */
abstract class WarehouseFormRequest extends FormRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'required_if' => 'El campo :attribute es obligatorio para esta operación.',
            'required_unless' => 'El campo :attribute es obligatorio para esta operación.',
            'required_without' => 'El campo :attribute es obligatorio cuando no se selecciona un material del catálogo.',
            'exists' => 'La selección realizada en :attribute no es válida o ya no está disponible.',
            'unique' => 'El valor de :attribute ya se encuentra registrado.',
            'integer' => 'El campo :attribute debe contener un identificador válido.',
            'numeric' => 'El campo :attribute debe ser numérico.',
            'decimal' => 'El campo :attribute admite como máximo :decimal decimales.',
            'gt' => 'El campo :attribute debe ser mayor que :value.',
            'min' => 'El campo :attribute debe ser como mínimo :min.',
            'max' => 'El campo :attribute no puede superar el límite de :max.',
            'string' => 'El campo :attribute debe ser texto.',
            'array' => 'El campo :attribute debe contener una lista válida.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
            'date_format' => 'El campo :attribute debe tener una fecha válida.',
            'after_or_equal' => 'El campo :attribute debe ser igual o posterior a :date.',
            'in' => 'El valor seleccionado en :attribute no está permitido.',
            'enum' => 'El valor seleccionado en :attribute no está permitido.',
            'not_in' => 'El valor seleccionado en :attribute no está permitido.',
            'distinct' => 'No repita el mismo :attribute.',
            'file' => 'El respaldo de :attribute debe ser un archivo válido.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'requesting_office_id' => 'oficina solicitante',
            'justification' => 'justificación',
            'office_reference' => 'número o CITE propio',
            'items' => 'materiales solicitados',
            'items.*.warehouse_item_id' => 'material',
            'items.*.measurement_unit_id' => 'unidad de medida',
            'items.*.item_name' => 'nombre del material',
            'items.*.requested_quantity' => 'cantidad solicitada',
            'items.*.notes' => 'detalle del material',
            'action' => 'decisión',
            'notes' => 'fundamento',
            'receiver_user_id' => 'funcionario receptor',
            'reason' => 'justificación',
            'lines' => 'detalle de la operación',
            'lines.*.material_request_item_id' => 'renglón solicitado',
            'lines.*.warehouse_item_id' => 'material',
            'lines.*.delivered_quantity' => 'cantidad entregada',
            'lines.*.over_delivery_reason' => 'justificación de entrega excedente',
            'supplier_name' => 'proveedor',
            'supplier_tax_id' => 'NIT del proveedor',
            'reference_type' => 'tipo de respaldo',
            'reference_number' => 'número de respaldo',
            'reference_date' => 'fecha del respaldo',
            'received_on' => 'fecha de ingreso',
            'lines.*.quantity' => 'cantidad ingresada',
            'lines.*.unit_cost' => 'costo unitario',
            'lines.*.lot_number' => 'lote',
            'lines.*.expires_on' => 'fecha de vencimiento',
            'lines.*.physical_location' => 'ubicación física',
            'attachments' => 'respaldos adjuntos',
            'attachments.*' => 'respaldo adjunto',
            'parent_id' => 'categoría superior',
            'warehouse_category_id' => 'categoría',
            'measurement_unit_id' => 'unidad de medida',
            'code' => 'código',
            'name' => 'nombre',
            'description' => 'descripción',
            'minimum_stock' => 'stock mínimo',
            'physical_location' => 'ubicación física',
            'status' => 'estado',
            'quantity_delta' => 'variación de existencias',
        ];
    }
}
