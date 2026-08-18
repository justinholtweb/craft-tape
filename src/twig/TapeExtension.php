<?php

namespace justinholtweb\tape\twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Puts `tape` in scope as a bare global as well as under `craft.tape`.
 *
 * `{{ tape.head() }}` in a layout reads better than the namespaced form, and a layout is where it
 * goes. `getGlobals()` is evaluated lazily by Twig, so this costs nothing on a template that never
 * mentions it.
 */
class TapeExtension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return ['tape' => new TapeVariable()];
    }
}
