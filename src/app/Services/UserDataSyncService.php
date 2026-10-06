<?php

namespace App\Services;

use App\Contracts\SubsystemServiceInterface;
use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Propaga los datos de contacto del usuario a sus cuentas en subsistemas
 * (Fase 2.7).
 *
 * Se sincronizan **solo atributos de contacto**: nombre, email y teléfonos.
 * Deliberadamente no el `usuario`, la `empresa` ni el `cpf`, porque en los
 * subsistemas son la clave con la que se creó la cuenta (sAMAccountName,
 * userPrincipalName, dirección del mailbox, employeeId). Renombrarlos es otra
 * operación, con otros riesgos, y mezclarla aquí haría que un cambio de
 * nombre disparase una sorpresa que nadie pidió.
 *
 * A diferencia de la suspensión, la baja y el reset, aquí **no se confirma**
 * contra el subsistema con getUserStatus(): no existe un estado que comprobar
 * después de actualizar el nombre de alguien. La confirmación de que la
 * operación ocurrió es la propia respuesta del driver.
 *
 * Los drivers que no pueden actualizar en remoto no implementan el método y
 * heredan el de la base, que devuelve un fallo. Se distinguen de un fallo real
 * con supportsUpdateUser(), para poder informar "no lo admite" en vez de
 * "falló".
 *
 * Desde la Fase 3.2 ya **no itera cuentas**: eso lo hace un job por cuenta.
 * Aquí quedan cuentasASincronizar(), que dice a quién hay que actualizar, y
 * sincronizarCuenta(), que el job llama una vez por cada una.
 */
class UserDataSyncService
{
    /**
     * Los únicos campos que salen a los subsistemas. Se define aquí y no en el
     * driver para que ningún driver pueda inventarse un campo que modifique la
     * identidad de la cuenta.
     *
     * @var array<int, string>
     */
    private const CAMPOS_SINCRONIZABLES = [
        'nombre_completo',
        'email_personal',
        'telefono_personal',
        'telefono_trabajo',
        'direccion_particular',
    ];

    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    /**
     * Cuentas a actualizar, ya filtradas por subsistema.
     *
     * @return Collection<int, UserSubsystemAccount>
     */
    public function cuentasASincronizar(GestorUser $gestorUser, ?array $subsistemas = null): Collection
    {
        $query = $gestorUser->subsystemAccounts()->with('subsystem');

        if (! empty($subsistemas)) {
            $query->whereHas('subsystem', fn ($q) => $q->whereIn('slug', $subsistemas));
        }

        return $query->get();
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    public function sincronizarCuenta(UserSubsystemAccount $cuenta, GestorUser $gestorUser): array
    {
        $subsistema = $cuenta->subsystem;

        $servicio = $this->resolver($cuenta);

        if ($servicio === null) {
            return $this->resultado($cuenta, false, 'No hay ningún driver registrado para el subsistema '.$subsistema->slug.'.');
        }

        // No se informa como fallo: el subsistema nunca iba a aceptar la
        // sincronización. Reportar el mixto de "falló" y "no lo admite"
        // llevaría al operador a reintentar algo que no va a funcionar nunca.
        if (! $servicio->supportsUpdateUser()) {
            return $this->resultado($cuenta, true, 'Este subsistema no admite actualizar los datos del usuario.');
        }

        try {
            $resultado = $servicio->updateUser($cuenta, $this->datosASincronizar($gestorUser));
        } catch (Throwable $exception) {
            report($exception);

            return $this->resultado($cuenta, false, $exception->getMessage());
        }

        return $this->resultado(
            $cuenta,
            $resultado->success,
            $resultado->success
                ? ($resultado->mensaje ?? 'Datos actualizados.')
                : ($resultado->mensaje ?? 'El subsistema no pudo actualizar los datos.'),
        );
    }

    /**
     * Recorta los datos del usuario a los campos sincronizables.
     *
     * Se recorta aquí y no se pasa el modelo entero para que un driver no pueda
     * acabar tocando, sin querer, la contraseña general o el estado de la baja.
     *
     * @return array<string, mixed>
     */
    private function datosASincronizar(GestorUser $gestorUser): array
    {
        $datos = $gestorUser->only(self::CAMPOS_SINCRONIZABLES);

        // Se pasan los atributos sin valor como null en vez de omitirlos, para
        // que un driver pueda distinguir "lo dejé como estaba" de "no me han
        // dicho nada". El campo vacío se trata como null: vaciar un teléfono no
        // tiene sentido propagarlo como cadena vacía a un LDAP.
        return array_map(static fn ($valor) => blank($valor) ? null : $valor, $datos);
    }

    private function resolver(UserSubsystemAccount $cuenta): ?SubsystemServiceInterface
    {
        $subsistema = $cuenta->subsystem;

        if ($subsistema === null) {
            return null;
        }

        try {
            return $this->registry->resolve($subsistema->slug);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{subsistema: ?string, exito: bool, mensaje: ?string, cuenta: ?UserSubsystemAccount}
     */
    private function resultado(UserSubsystemAccount $cuenta, bool $exito, ?string $mensaje): array
    {
        return [
            'subsistema' => $cuenta->subsystem?->slug,
            'exito' => $exito,
            'mensaje' => $mensaje,
            'cuenta' => $cuenta,
        ];
    }
}
