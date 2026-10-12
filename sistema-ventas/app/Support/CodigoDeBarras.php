<?php

namespace App\Support;

/**
 * Dibuja un código de barras EAN-13 o EAN-8 como SVG, sin librerías.
 *
 * Es lo que lleva casi todo lo que se vende en un minimarket, y lo que lee
 * cualquier lector del mostrador. Un UPC-A (12 dígitos) es un EAN-13 con un cero
 * delante y se dibuja igual. Un código interno o de otro tipo no se dibuja: es
 * mejor imprimir solo el número que unas barras que ningún lector entiende.
 *
 * Solo se dibuja si el último dígito (el de control) cuadra: un código mal
 * escrito daría una etiqueta que el lector rechaza en plena venta.
 */
class CodigoDeBarras
{
    /** Cada dígito en los tres alfabetos, en módulos (1 = barra, 0 = espacio). */
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Qué alfabeto usan los seis dígitos de la izquierda según el primero. */
    private const PARIDAD = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** El dígito de control que le toca a los dígitos anteriores. */
    public static function digitoDeControl(string $digitos): int
    {
        $suma = 0;
        $n = strlen($digitos);

        // De derecha a izquierda, los lugares impares valen ×3.
        for ($i = 0; $i < $n; $i++) {
            $suma += (int) $digitos[$n - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $suma % 10) % 10;
    }

    /** ¿Es un EAN-13, EAN-8 o UPC-A con su dígito de control correcto? */
    public static function esValido(?string $codigo): bool
    {
        if ($codigo === null || ! preg_match('/^\d{8}$|^\d{12,13}$/', $codigo)) {
            return false;
        }

        return self::digitoDeControl(substr($codigo, 0, -1)) === (int) substr($codigo, -1);
    }

    /**
     * Los módulos del código: una cadena de 0 y 1 (95 para EAN-13, 67 para EAN-8).
     * Null si no es un código que se pueda dibujar.
     */
    public static function modulos(?string $codigo): ?string
    {
        if (! self::esValido($codigo)) {
            return null;
        }

        // UPC-A: el mismo dibujo que un EAN-13 que empieza en cero.
        if (strlen($codigo) === 12) {
            $codigo = '0'.$codigo;
        }

        if (strlen($codigo) === 8) {
            $izquierda = '';
            $derecha = '';

            for ($i = 0; $i < 4; $i++) {
                $izquierda .= self::L[(int) $codigo[$i]];
                $derecha .= self::R[(int) $codigo[$i + 4]];
            }

            return '101'.$izquierda.'01010'.$derecha.'101';
        }

        $paridad = self::PARIDAD[(int) $codigo[0]];
        $izquierda = '';
        $derecha = '';

        for ($i = 0; $i < 6; $i++) {
            $izquierda .= ($paridad[$i] === 'L' ? self::L : self::G)[(int) $codigo[$i + 1]];
            $derecha .= self::R[(int) $codigo[$i + 7]];
        }

        return '101'.$izquierda.'01010'.$derecha.'101';
    }

    /**
     * El SVG del código, o null si no se puede dibujar. Con una zona en blanco a
     * cada lado (los lectores la necesitan) y sin el número: ese lo pone la
     * etiqueta en letra normal.
     */
    public static function svg(?string $codigo, int $alto = 40): ?string
    {
        $modulos = self::modulos($codigo);

        if ($modulos === null) {
            return null;
        }

        $margen = 9;
        $ancho = strlen($modulos) + 2 * $margen;
        $barras = '';

        // Una barra por cada racha de unos, para no escribir 95 rectángulos.
        preg_match_all('/1+/', $modulos, $rachas, PREG_OFFSET_CAPTURE);

        foreach ($rachas[0] as [$racha, $inicio]) {
            $barras .= sprintf('<rect x="%d" y="0" width="%d" height="%d"/>', $inicio + $margen, strlen($racha), $alto);
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="Código de barras %s" shape-rendering="crispEdges"><rect width="%d" height="%d" fill="#fff"/><g fill="#000">%s</g></svg>',
            $ancho, $alto, e($codigo), $ancho, $alto, $barras,
        );
    }
}
