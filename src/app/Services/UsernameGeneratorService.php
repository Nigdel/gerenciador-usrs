<?php

namespace App\Services;

use App\Contracts\UsernameAvailabilityInterface;
use App\Exceptions\ProvisioningException;
use App\Models\GestorUser;
use App\Models\Subsystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Propone el login/usuario para un usuario nuevo (que no existe en Adagio
 * por CPF), siguiendo la convención nombre.primerApellido@empresa.com.br.
 *
 * La comprobación de disponibilidad (Fase 2.8) consulta **todos** los subsistemas
 * activos que sepan hacerla, no solo el proveedor de identidad: el login es en
 * todos ellos la clave con la que se crea la cuenta (sAMAccountName, UPN,
 * dirección del buzón, name en GLPI, userName en Slack SCIM), así que un login
 * libre en Adagio puede estar ocupado en el buzón de correo y el alta fallaría
 * más tarde, con un error mucho más difícil de entender que este.
 */
class UsernameGeneratorService
{
    /**
     * Cuántos sufijos numéricos se prueban antes de rendirse. Es una salvaguarda
     * contra el bucle infinito, no un límite de diseño: 99 homónimos con el
     * mismo nombre y apellido ya es un caso patológico. La versión anterior
     * además devolvía el último candidato **sin comprobarlo**, de modo que
     * agotados los intentos proponía un login que ya sabíamos ocupado.
     */
    private const MAX_INTENTOS = 100;

    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    /**
     * @return string Login propuesto, ej: "juan.perez" (sin el dominio de email).
     *
     * @throws ProvisioningException Si no se encuentra ningún login libre tras todos
     *                               los intentos. El alta no se hace a ciegas.
     */
    public function proponer(string $nombreCompleto, string $empresa): string
    {
        return $this->proponerConAviso($nombreCompleto, $empresa)['usuario'];
    }

    /**
     * Igual que proponer(), pero devuelve también qué subsistemas no se pudieron
     * consultar.
     *
     * El llamante (el alta) lo usa para avisar al operador: si el login se eligió
     * sin poder confirmarlo contra algún subsistema, conviene que lo sepa antes
     * de crear la cuenta y no al recibir el error de duplicado.
     *
     * @return array{usuario: string, no_verificados: array<int, string>, mensaje: ?string}
     */
    public function proponerConAviso(string $nombreCompleto, string $empresa): array
    {
        [$nombre, $apellido1, $apellido2] = $this->descomponerNombre($nombreCompleto);

        $consultas = $this->consultasDeDisponibilidad($empresa);

        // Los candidatos se prueban **una sola vez cada uno**: al evaluar el
        // ganador ya se sabe qué subsistemas respondieron y cuáles no, así que
        // no hace falta una segunda pasada de red solo para montar el aviso.
        foreach ($this->candidatos($nombre, $apellido1, $apellido2) as $candidato) {
            // La lista se reinicia en cada candidato a propósito: si Adagio no
            // respondió al probar "juan.perez" pero sí responde al probar
            // "juan.gomez2", el login elegido **sí** está verificado en Adagio
            // y el aviso no debe mencionarlo. Acumular entre candidatos daría
            // un aviso con subsistemas que en realidad respondieron bien.
            $noVerificados = [];

            if ($this->estaLibre($candidato, $consultas, $noVerificados)) {
                return $this->resultado($candidato, $noVerificados);
            }
        }

        throw new ProvisioningException(sprintf(
            'No se encontró ningún login libre para "%s" tras %d intentos. Revisa los homónimos existentes o indica el usuario manualmente.',
            $nombreCompleto,
            self::MAX_INTENTOS,
        ));
    }

    /**
     * Todos los candidatos por orden de preferencia: primero los nombres
     * (nombre.primerApellido y, si existe, nombre.segundoApellido) y después los
     * sufijos numéricos a partir del último nombre válido.
     *
     * @return array<int, string>
     */
    private function candidatos(string $nombre, string $apellido1, ?string $apellido2): array
    {
        $porNombre = [$this->normalizar($nombre).'.'.$this->normalizar($apellido1)];

        if ($apellido2) {
            $porNombre[] = $this->normalizar($nombre).'.'.$this->normalizar($apellido2);
        }

        $candidatos = $porNombre;

        // El sufijo numérico cuelga del último nombre, no del primero: si
        // "juan.perez" está ocupado y "juan.gomez" también, lo natural es
        // "juan.gomez2", no "juan.perez2".
        $base = end($porNombre);

        for ($sufijo = 2; $sufijo < self::MAX_INTENTOS + 1; $sufijo++) {
            $candidatos[] = $base.$sufijo;
        }

        return $candidatos;
    }

    /**
     * Prepara una consulta de disponibilidad por cada subsistema activo que sepa
     * hacerla, dejando fuera los que no la implementen.
     *
     * @return array<int, array{subsistema: Subsystem, servicio: UsernameAvailabilityInterface}>
     */
    private function consultasDeDisponibilidad(string $empresa): array
    {
        $consultas = [];

        foreach (Subsystem::query()->activos()->orderBy('id')->get() as $subsystem) {
            try {
                $servicio = $this->registry->resolve($subsystem->slug);
            } catch (Throwable $exception) {
                // Un slug sin driver no se puede consultar. No es un error del
                // alta: simplemente no aporta información.
                Log::info('[gestor:username] sin driver registrado, se omite la comprobación', [
                    'subsistema' => $subsystem->slug,
                ]);

                continue;
            }

            if ($servicio instanceof UsernameAvailabilityInterface) {
                $consultas[] = [
                    'subsistema' => $subsystem,
                    'servicio' => $servicio,
                    'empresa' => $empresa,
                ];
            }
        }

        return $consultas;
    }

    /**
     * @param  array<int, array{subsistema: Subsystem, servicio: UsernameAvailabilityInterface, empresa: string}>  $consultas
     * @param  array<int, string>  $noVerificados  Se va llenando con los slugs que no
     *                                             respondieron, para el aviso final.
     */
    private function estaLibre(string $login, array $consultas, array &$noVerificados = []): bool
    {
        // Lo local primero: es una consulta a la base de datos frente a las
        // peticiones HTTP a los subsistemas, y gestor_users.usuario es UNIQUE.
        if (GestorUser::where('usuario', $login)->exists()) {
            return false;
        }

        foreach ($consultas as $consulta) {
            try {
                $enUso = $consulta['servicio']->loginEnUso($login, $consulta['empresa'], $consulta['subsistema']);
            } catch (Throwable $exception) {
                // Un driver que lanza no es motivo para abortar el alta: se
                // trata igual que un subsistema caído.
                report($exception);

                $noVerificados[] = $consulta['subsistema']->slug;

                continue;
            }

            if ($enUso === null) {
                $noVerificados[] = $consulta['subsistema']->slug;

                continue;
            }

            if ($enUso) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $noVerificados
     * @return array{usuario: string, no_verificados: array<int, string>, mensaje: ?string}
     */
    private function resultado(string $candidato, array $noVerificados): array
    {
        $noVerificados = array_values(array_unique($noVerificados));

        return [
            'usuario' => $candidato,
            'no_verificados' => $noVerificados,
            'mensaje' => $this->mensajeDeAviso($noVerificados),
        ];
    }

    /**
     * @param  array<int, string>  $noVerificados
     */
    private function mensajeDeAviso(array $noVerificados): ?string
    {
        if ($noVerificados === []) {
            return null;
        }

        return 'No se pudo comprobar la disponibilidad del login en: '.implode(', ', $noVerificados)
            .'. Se propone igualmente, pero conviene confirmarlo antes de crear la cuenta.';
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [nombre, primer_apellido, segundo_apellido|null]
     */
    private function descomponerNombre(string $nombreCompleto): array
    {
        $partes = array_values(array_filter(explode(' ', trim($nombreCompleto))));

        if (count($partes) < 2) {
            throw new ProvisioningException('El nombre completo debe incluir al menos nombre y un apellido');
        }

        if (count($partes) === 2) {
            return [$partes[0], $partes[1], null];
        }

        // Convención: último token = segundo apellido, penúltimo = primer apellido,
        // todo lo anterior se considera parte del nombre (pero solo se usa el primero).
        $segundoApellido = array_pop($partes);
        $primerApellido = array_pop($partes);
        $nombre = $partes[0];

        return [$nombre, $primerApellido, $segundoApellido];
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->lower()->ascii()->replace(' ', '')->toString();
    }
}
