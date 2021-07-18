<?php

namespace Chaos;

/**
 * Guesses namespace of unqualified class name.
 *
 * @param   string $var Unqualified class name.
 * @param   string $prefix Optional.
 *
 * @return  string
 */
function guessNamespace($var, $prefix = '')
{
    if (class_exists($var, false)) {
        return $var;
    }

    $classes = preg_grep('/' . preg_quote($var) . '$/', get_declared_classes());

    return empty($classes) ? $prefix . '\\' . $var : end($classes); // returns the last one if more than one
}

/**
 * Gets unqualified class name.
 *
 * <code>
 * $unqualifiedName = shorten('A\B\C\D'); // returns 'D';
 * </code>
 *
 * @param   string $var Fully qualified class name.
 *
 * @return  string
 */
function shorten($var)
{
    if (false !== ($string = strrchr($var, '\\'))) {
        return substr($string, 1);
    }

    return $var;
}
