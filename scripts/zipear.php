<?php

/*
 * Empaqueta una carpeta con barras normales (/) en los nombres.
 *
 * `Compress-Archive` de Windows las escribe invertidas, y al descomprimir en
 * el servidor Linux se convierten en nombres de archivo literales
 * («app\Http\Controllers\…» como UN archivo) en vez de carpetas.
 *
 * Uso:  php scripts/zipear.php <carpeta> <destino.zip>
 *
 * Deja FUERA lo que nunca debe viajar en un paquete: las credenciales (`.env`),
 * los respaldos de la base y la contraseña de primer acceso, las dependencias
 * que se reinstalan, el repositorio git y las sesiones y los logs de quien lo armó.
 */

/** Nombres de archivo o carpeta que se omiten en cualquier nivel. */
const EXCLUIDOS = ['.git', 'node_modules', 'vendor', '.env', '.env.docker', 'PRIMER-ACCESO.txt'];

/** Carpetas (relativas a la raíz del paquete) cuyo CONTENIDO se omite. */
const CARPETAS_EXCLUIDAS = [
    'storage/app/respaldos', 'storage/app/private', 'storage/framework/sessions',
    'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'backups',
];

/** ¿Va fuera del paquete? `.env.example` sí viaja: es la plantilla, sin secretos. */
function excluido(string $relativa): bool
{
    foreach (explode('/', $relativa) as $parte) {
        if (in_array($parte, EXCLUIDOS, true) || (str_starts_with($parte, '.env.') && $parte !== '.env.example')) {
            return true;
        }
    }

    foreach (CARPETAS_EXCLUIDAS as $carpeta) {
        if ($relativa === $carpeta || str_starts_with($relativa, $carpeta.'/')) {
            return true;
        }
    }

    return str_ends_with($relativa, '.sql') && str_contains($relativa, 'respaldo');
}

$origen = rtrim($argv[1] ?? '', "/\\");
$destino = $argv[2] ?? '';

if ($origen === '' || $destino === '' || ! is_dir($origen)) {
    exit("uso: php scripts/zipear.php <carpeta> <destino.zip>\n");
}

@unlink($destino);

$zip = new ZipArchive;

if ($zip->open($destino, ZipArchive::CREATE) !== true) {
    exit("no pude crear el zip\n");
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$archivos = 0;

foreach ($it as $ruta => $info) {
    $relativa = str_replace(DIRECTORY_SEPARATOR, '/', substr($ruta, strlen($origen) + 1));

    if (excluido($relativa)) {
        continue;
    }

    if ($info->isDir()) {
        $zip->addEmptyDir($relativa);
    } else {
        $zip->addFile($ruta, $relativa);
        $archivos++;
    }
}

$zip->close();

printf("   %d archivos, %.1f MB\n", $archivos, filesize($destino) / 1048576);
