<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnularTermoRequest;
use App\Http\Requests\StoreTermoRequest;
use App\Http\Requests\UploadTermoFirmadoRequest;
use App\Models\GestorUser;
use App\Models\Termo;
use App\Services\AuditService;
use App\Services\Termos\TermoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Emisión, entrega y anulación de los termos de responsabilidad.
 *
 * Todos los endpoints cuelgan de `gestor-users/{gestorUser}`. No es una
 * decisión estética: el termo pertenece a una persona y la ruta lo deja claro.
 * Aun así el controlador vuelve a comprobar la pertenencia antes de tocar un
 * archivo, porque un id de termo manipulado no puede servir para leer el
 * documento de otra persona.
 */
class TermoController extends Controller
{
    public function __construct(
        private readonly TermoService $termos,
        private readonly AuditService $audit,
    ) {}

    /**
     * Los datos del formulario de emisión, resueltos desde GLPI.
     *
     * Se piden al abrir el modal y no se incluyen en la ficha: el detalle de
     * los componentes (procesador, RAM, disco, MAC) son varias llamadas y la
     * ficha se abre en cada consulta.
     */
    public function formulario(
        GestorUser $gestorUser,
        string $tipo,
        string $id,
    ): JsonResponse {
        $this->authorize('generarTermo', $gestorUser);

        return response()->json($this->termos->formulario($gestorUser, $tipo, $id));
    }

    public function store(StoreTermoRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('generarTermo', $gestorUser);

        try {
            $termo = $this->termos->crear($gestorUser, $request->validated(), $request->user());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'No se pudo generar el termo: revisa los datos del medio.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'No se pudo generar el termo. Inténtalo de nuevo o avisa a sistemas.');
        }

        $this->audit->log(AuditService::TERMO_GENERADO, [
            'gestor_user_id' => $gestorUser->id,
            'termo_id' => $termo->id,
            'item_type' => $termo->item_type,
            'item_id' => $termo->item_id,
            'tipo' => $termo->tipo->value,
        ]);

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('success', 'Termo generado correctamente. Ya se puede descargar en el cartorial.');
    }

    /**
     * El PDF generado, servido desde el disco privado después de autorizar.
     */
    public function pdfGenerado(GestorUser $gestorUser, Termo $termo): BinaryFileResponse
    {
        $this->authorize('viewTermos', $gestorUser);
        $this->termoDelGestor($gestorUser, $termo);

        return $this->archivo($termo->pdf_generado, "termo-{$termo->id}.pdf");
    }

    /**
     * El PDF que devolvió el colaborador firmado.
     */
    public function pdfFirmado(GestorUser $gestorUser, Termo $termo): BinaryFileResponse
    {
        $this->authorize('viewTermos', $gestorUser);
        $this->termoDelGestor($gestorUser, $termo);

        return $this->archivo($termo->pdf_firmado, "termo-{$termo->id}-firmado.pdf");
    }

    public function marcarEnviado(GestorUser $gestorUser, Termo $termo): RedirectResponse
    {
        $this->authorize('actualizarTermo', $gestorUser);
        $this->termoDelGestor($gestorUser, $termo);

        try {
            $this->termos->marcarEnviado($termo, request()->user());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->audit->log(AuditService::TERMO_ENVIADO, [
            'gestor_user_id' => $gestorUser->id,
            'termo_id' => $termo->id,
        ]);

        return back()->with('success', 'Termo marcado como enviado al colaborador.');
    }

    public function subirFirmado(
        UploadTermoFirmadoRequest $request,
        GestorUser $gestorUser,
        Termo $termo,
    ): RedirectResponse {
        $this->authorize('actualizarTermo', $gestorUser);
        $this->termoDelGestor($gestorUser, $termo);

        try {
            $this->termos->subirFirmado($termo, $request->file('pdf_firmado'), $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->with('error', $exception->getMessage());
        }

        $this->audit->log(AuditService::TERMO_FIRMADO, [
            'gestor_user_id' => $gestorUser->id,
            'termo_id' => $termo->id,
        ]);

        return back()->with('success', 'PDF firmado guardado correctamente.');
    }

    public function anular(
        AnularTermoRequest $request,
        GestorUser $gestorUser,
        Termo $termo,
    ): RedirectResponse {
        $this->authorize('anularTermo', $gestorUser);
        $this->termoDelGestor($gestorUser, $termo);

        try {
            $this->termos->anular($termo, (string) $request->validated('motivo_anulacion'), $request->user());
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->with('error', $exception->getMessage());
        }

        $this->audit->log(AuditService::TERMO_ANULADO, [
            'gestor_user_id' => $gestorUser->id,
            'termo_id' => $termo->id,
            'motivo_anulacion' => $request->validated('motivo_anulacion'),
        ]);

        return back()->with('success', 'Termo anulado. Ya se puede emitir otro para este medio.');
    }

    /**
     * Un termo de otra persona no existe para esta ruta: 404 y no 403, porque
     * su existencia tampoco es información que deba darse.
     */
    private function termoDelGestor(GestorUser $gestorUser, Termo $termo): void
    {
        abort_unless((int) $termo->gestor_user_id === (int) $gestorUser->id, 404);
    }

    private function archivo(?string $ruta, string $nombre): BinaryFileResponse
    {
        abort_unless(
            is_string($ruta) && $ruta !== '' && Storage::disk('local')->exists($ruta),
            404,
        );

        return response()
            ->file(Storage::disk('local')->path($ruta), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$nombre.'"',
            ]);
    }
}
